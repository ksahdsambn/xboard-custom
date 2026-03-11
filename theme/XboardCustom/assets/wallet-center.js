(function () {
  if (window.__xboardCustomWalletCenterLoaded) return;
  window.__xboardCustomWalletCenterLoaded = true;

  var PREV = "xboardCustom.previousHash";
  var LAST_TOPUP = "xboardCustom.lastTopupTradeNo";
  var DEFAULT_HASH = "#/dashboard";
  var WALLET_HASH = (window.xboardCustom && window.xboardCustom.walletHash) || "#/wallet";
  var EXTRA_I18N = window.xboardCustomI18n || {};
  var RTL_LOCALES = EXTRA_I18N.rtlLocales || ["fa-IR", "ar-SA"];
  var text = buildTextCatalog();
  var supportedLocales = Object.keys(text);
  var state = {
    locale: detectLocale(),
    route: parseHash(location.hash),
    token: null,
    authed: false,
    loading: false,
    rid: 0,
    user: null,
    subscribe: null,
    comm: null,
    checkin: {},
    topup: {},
    renew: {}
  };
  var dom = {};
  var dockSyncFrame = 0;
  var authProbeTimer = 0;
  var authProbeAttempts = 0;
  var MAX_AUTH_PROBE_ATTEMPTS = 12;
  var AUTH_PROBE_DELAY = 700;

  function dockItems() {
    return [
      { key: "wallet", action: "wallet", label: t("wallet") },
      { key: "checkin", action: "section", section: "checkin", label: t("checkin") },
      { key: "topup", action: "section", section: "topup", label: t("topup") },
      { key: "renew", action: "section", section: "renew", label: t("renew") }
    ];
  }

  function createDockIconMarkup(key) {
    if (key === "wallet") {
      return '<svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M3.75 6.25A2.5 2.5 0 0 1 6.25 3.75h8.125A1.875 1.875 0 0 1 16.25 5.625V7.5h-2.5a3.125 3.125 0 0 0 0 6.25h2.5v.625a1.875 1.875 0 0 1-1.875 1.875H6.25a2.5 2.5 0 0 1-2.5-2.5v-7.5Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M13.75 8.75h2.5A1.25 1.25 0 0 1 17.5 10v1.25a1.25 1.25 0 0 1-1.25 1.25h-2.5A1.25 1.25 0 0 1 12.5 11.25V10a1.25 1.25 0 0 1 1.25-1.25Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M13.75 10.625h.625" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';
    }
    if (key === "checkin") {
      return '<svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M6.25 2.917v2.5M13.75 2.917v2.5M4.583 7.083h10.834" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><rect x="3.75" y="4.583" width="12.5" height="11.667" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="m7.5 10 1.667 1.667 3.333-3.334" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    }
    if (key === "topup") {
      return '<svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 3.333v13.334M5.833 7.5 10 3.333 14.167 7.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M4.167 12.5v1.667a2.5 2.5 0 0 0 2.5 2.5h6.666a2.5 2.5 0 0 0 2.5-2.5V12.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';
    }
    return '<svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M15.102 7.083A5.833 5.833 0 1 0 15.833 10h-2.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M15.833 4.583v5h-5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  }

  function getDockActiveKey() {
    if (!state.route.isWallet) return "";
    if (state.route.section === "checkin" || state.route.section === "topup" || state.route.section === "renew") {
      return state.route.section;
    }
    return "wallet";
  }

  function isElementVisible(el) {
    if (!el || !el.isConnected) return false;
    var rect = el.getBoundingClientRect();
    var style = window.getComputedStyle(el);
    return rect.width > 0 && rect.height > 0 && style.display !== "none" && style.visibility !== "hidden";
  }

  function getSidebarMenuRoot() {
    var menus = Array.prototype.filter.call(
      document.querySelectorAll(".n-layout-sider .n-menu, .n-drawer .n-menu"),
      function (menu) {
        return isElementVisible(menu) && !!menu.querySelector(".n-menu-item");
      }
    );
    if (!menus.length) return null;
    menus.sort(function (a, b) {
      return b.getBoundingClientRect().height - a.getBoundingClientRect().height;
    });
    return menus[0];
  }

  function isSidebarCollapsed(menuRoot) {
    var sider = menuRoot && menuRoot.closest(".n-layout-sider, .n-drawer");
    if (sider && sider.classList.contains("n-layout-sider--collapsed")) return true;
    var rect = menuRoot ? menuRoot.getBoundingClientRect() : null;
    return !!(rect && rect.width > 0 && rect.width < 120);
  }

  function stripNodeIds(node) {
    if (!node || node.nodeType !== 1) return;
    node.removeAttribute("id");
    Array.prototype.forEach.call(node.querySelectorAll("[id]"), function (el) {
      el.removeAttribute("id");
    });
  }

  function createFallbackSidebarItem(item, active, collapsed) {
    var node = document.createElement("div");
    node.className = "n-menu-item xc-wallet-dock__item";
    node.setAttribute("role", "menuitem");
    var content = document.createElement("div");
    content.className = "n-menu-item-content" + (active ? " n-menu-item-content--selected" : "") + (collapsed ? " n-menu-item-content--collapsed" : "");
    content.setAttribute("data-xc", item.action);
    if (item.section) content.setAttribute("data-section", item.section);
    content.setAttribute("data-xc-dock-key", item.key);
    content.setAttribute("tabindex", "0");
    content.setAttribute("role", "button");
    content.setAttribute("title", item.label);
    var icon = document.createElement("div");
    icon.className = "n-menu-item-content__icon xc-wallet-dock__icon";
    icon.innerHTML = createDockIconMarkup(item.key);
    var header = document.createElement("div");
    header.className = "n-menu-item-content-header";
    header.textContent = item.label;
    content.appendChild(icon);
    content.appendChild(header);
    node.appendChild(content);
    bindDockInteractiveTarget(node, item);
    bindDockInteractiveTarget(content, item);
    return node;
  }

  function buildSidebarDockItem(sampleItem, item, active, collapsed) {
    if (!sampleItem) return createFallbackSidebarItem(item, active, collapsed);
    var node = sampleItem.cloneNode(true);
    stripNodeIds(node);
    node.classList.add("xc-wallet-dock__item");
    var content = node.querySelector(".n-menu-item-content") || node;
    var icon = content.querySelector(".n-menu-item-content__icon");
    var header = content.querySelector(".n-menu-item-content-header");
    var extra = content.querySelector(".n-menu-item-content-header__extra");
    var arrow = content.querySelector(".n-menu-item-content__arrow");
    if (!icon) {
      icon = document.createElement("div");
      icon.className = "n-menu-item-content__icon xc-wallet-dock__icon";
      content.insertBefore(icon, content.firstChild || null);
    }
    if (!header) {
      header = document.createElement("div");
      header.className = "n-menu-item-content-header";
      content.appendChild(header);
    }
    if (extra) extra.remove();
    if (arrow) arrow.remove();
    icon.classList.add("xc-wallet-dock__icon");
    icon.innerHTML = createDockIconMarkup(item.key);
    header.textContent = item.label;
    content.classList.toggle("n-menu-item-content--selected", !!active);
    content.classList.toggle("n-menu-item-content--collapsed", !!collapsed);
    content.classList.remove("n-menu-item-content--disabled");
    content.classList.remove("n-menu-item-content--child-active");
    content.classList.remove("n-menu-item-content--hover");
    content.setAttribute("data-xc", item.action);
    content.setAttribute("data-xc-dock-key", item.key);
    content.setAttribute("role", "button");
    content.setAttribute("tabindex", "0");
    content.setAttribute("title", item.label);
    if (item.section) {
      content.setAttribute("data-section", item.section);
    } else {
      content.removeAttribute("data-section");
    }
    bindDockInteractiveTarget(node, item);
    bindDockInteractiveTarget(content, item);
    return node;
  }

  function markDockEventHandled(event) {
    if (!event) return false;
    if (event.__xcWalletDockHandled) return true;
    event.__xcWalletDockHandled = true;
    return false;
  }

  function stopDockInteractiveEvent(event, preventDefault) {
    if (!event) return;
    if (preventDefault) event.preventDefault();
    if (typeof event.stopImmediatePropagation === "function") {
      event.stopImmediatePropagation();
    }
    event.stopPropagation();
  }

  function runDockAction(action, section, enabled) {
    if (action === "wallet") openWallet("");
    if (action === "section") openWallet(section || "");
    if (action === "close") closeWallet();
    if (action === "refresh") load(true);
    if (action === "login") location.hash = "#/login";
    if (action === "claim") claim();
    if (action === "topup") createTopup();
    if (action === "renew") toggleRenew(enabled === "1");
  }

  function bindDockInteractiveTarget(target, item) {
    if (!target || target.__xcWalletDockBound) return;
    if (item && item.action) {
      target.setAttribute("data-xc", item.action);
      if (item.section) {
        target.setAttribute("data-section", item.section);
      } else {
        target.removeAttribute("data-section");
      }
      target.setAttribute("data-xc-dock-key", item.key);
    }
    target.addEventListener("pointerdown", function (event) {
      if (markDockEventHandled(event)) return;
      if (typeof event.button === "number" && event.button !== 0) return;
      stopDockInteractiveEvent(event, true);
      target.__xcWalletDockSuppressClickUntil = Date.now() + 450;
      runDockAction(target.getAttribute("data-xc"), target.getAttribute("data-section"), target.getAttribute("data-enabled"));
    }, true);
    target.addEventListener("mousedown", function (event) {
      if (markDockEventHandled(event)) return;
      if (typeof event.button === "number" && event.button !== 0) return;
      stopDockInteractiveEvent(event, true);
      if (typeof window.PointerEvent !== "undefined") return;
      target.__xcWalletDockSuppressClickUntil = Date.now() + 450;
      runDockAction(target.getAttribute("data-xc"), target.getAttribute("data-section"), target.getAttribute("data-enabled"));
    }, true);
    target.addEventListener("click", function (event) {
      if (markDockEventHandled(event)) return;
      stopDockInteractiveEvent(event, true);
      if (target.__xcWalletDockSuppressClickUntil && target.__xcWalletDockSuppressClickUntil > Date.now()) return;
      runDockAction(target.getAttribute("data-xc"), target.getAttribute("data-section"), target.getAttribute("data-enabled"));
    }, true);
    target.addEventListener("keydown", function (event) {
      if (!event || (event.key !== "Enter" && event.key !== " ")) return;
      if (markDockEventHandled(event)) return;
      stopDockInteractiveEvent(event, true);
      runDockAction(target.getAttribute("data-xc"), target.getAttribute("data-section"), target.getAttribute("data-enabled"));
    }, true);
    target.__xcWalletDockBound = true;
  }

  function renderSidebarDock(menuRoot, dockVisible) {
    var sampleItem = menuRoot.querySelector(".n-menu-item");
    var sampleTitle = menuRoot.querySelector(".n-menu-item-group-title");
    var activeKey = getDockActiveKey();
    var collapsed = isSidebarCollapsed(menuRoot);
    dom.dock.className = "xc-wallet-dock xc-wallet-dock--sidebar";
    dom.dock.hidden = !dockVisible;
    dom.dock.dataset.visible = dockVisible ? "true" : "false";
    dom.dock.dataset.collapsed = collapsed ? "true" : "false";
    dom.dock.innerHTML = "";
    dom.dock.setAttribute("role", "group");
    var title = sampleTitle ? sampleTitle.cloneNode(false) : document.createElement("div");
    title.className = sampleTitle ? sampleTitle.className : "n-menu-item-group-title";
    title.textContent = t("title");
    var list = document.createElement("div");
    list.className = "xc-wallet-dock__items";
    dockItems().forEach(function (item) {
      list.appendChild(buildSidebarDockItem(sampleItem, item, item.key === activeKey, collapsed));
    });
    dom.dock.appendChild(title);
    dom.dock.appendChild(list);
    if (dom.dock.parentElement !== menuRoot) {
      menuRoot.appendChild(dom.dock);
    }
  }

  function floatingDockHtml() {
    return ''
      + '<button type="button" data-xc="wallet" data-variant="primary">' + esc(t("wallet")) + "</button>"
      + '<button type="button" data-xc="section" data-section="checkin">' + esc(t("checkin")) + "</button>"
      + '<button type="button" data-xc="section" data-section="topup">' + esc(t("topup")) + "</button>"
      + '<button type="button" data-xc="section" data-section="renew">' + esc(t("renew")) + "</button>";
  }

  function renderFloatingDock(dockVisible) {
    dom.dock.className = "xc-wallet-dock xc-wallet-dock--floating";
    dom.dock.hidden = !dockVisible;
    dom.dock.dataset.visible = dockVisible ? "true" : "false";
    dom.dock.removeAttribute("data-collapsed");
    dom.dock.innerHTML = dockVisible ? floatingDockHtml() : "";
    if (dom.dock.parentElement !== document.body) {
      document.body.appendChild(dom.dock);
    }
  }

  function renderDock() {
    var dockVisible = shouldShowDock();
    var menuRoot = dockVisible ? getSidebarMenuRoot() : null;
    if (menuRoot) {
      renderSidebarDock(menuRoot, dockVisible);
      return;
    }
    if (dockVisible && window.innerWidth > 720) {
      renderFloatingDock(false);
      return;
    }
    renderFloatingDock(dockVisible);
  }

  function onDocumentClick(event) {
    if (dom.dock && event && event.target && dom.dock.contains(event.target)) return;
    scheduleDockSync();
  }

  function scheduleDockSync() {
    if (dockSyncFrame) cancelAnimationFrame(dockSyncFrame);
    dockSyncFrame = requestAnimationFrame(function () {
      dockSyncFrame = 0;
      if (!dom.dock) return;
      renderDock();
    });
  }

  function shouldRefreshDock(records) {
    if (!records || !records.length) return false;
    return records.some(function (record) {
      var nodes = [];
      if (record.target) nodes.push(record.target);
      Array.prototype.push.apply(nodes, Array.prototype.slice.call(record.addedNodes || []));
      Array.prototype.push.apply(nodes, Array.prototype.slice.call(record.removedNodes || []));
      return nodes.some(function (node) {
        if (!node || node.nodeType !== 1) return false;
        if (dom.dock && (node === dom.dock || dom.dock.contains(node))) return false;
        return (node.matches && node.matches(".n-layout-sider, .n-layout-sider-scroll-container, .n-menu, .n-drawer, .n-menu-item, .n-menu-item-group-title"))
          || (node.querySelector && node.querySelector(".n-layout-sider, .n-layout-sider-scroll-container, .n-menu, .n-drawer, .n-menu-item, .n-menu-item-group-title"));
      });
    });
  }

  function buildTextCatalog() {
    var base = {
      "zh-CN": {
        wallet: "钱包",
        checkin: "签到",
        topup: "充值",
        renew: "自动续费",
        title: "WalletCenter",
        subtitle: "集中查看余额、签到、充值和自动续费状态。",
        refresh: "刷新",
        back: "返回",
        loading: "正在加载钱包数据",
        login: "当前未检测到登录状态，请先登录。",
        toLogin: "去登录",
        balance: "账户余额",
        plan: "当前套餐",
        expire: "到期时间",
        renewStatus: "续费状态",
        statusEnabled: "已开启",
        statusDisabled: "未开启",
        enableAction: "开启",
        disableAction: "关闭",
        none: "无",
        claim: "立即签到",
        claimed: "今日已签到",
        range: "奖励区间",
        latest: "最近记录",
        amount: "金额",
        methods: "支付方式",
        create: "创建充值订单",
        next: "下次扫描",
        result: "最近结果",
        reason: "原因",
        history: "历史记录",
        disabledFeature: "该功能当前未启用。",
        empty: "暂无数据",
        refreshHint: "如刚完成支付，可点击刷新同步结果。",
        topupCreated: "充值订单已创建，即将跳转支付。",
        checkinOk: "签到成功，奖励已入账。",
        renewOk: "自动续费设置已更新。",
        failed: "请求失败"
      },
      "en-US": {
        wallet: "Wallet",
        checkin: "Check-in",
        topup: "Top up",
        renew: "Auto renew",
        title: "WalletCenter",
        subtitle: "Review balance, check-in, top-up and auto renew status in one place.",
        refresh: "Refresh",
        back: "Back",
        loading: "Loading wallet data",
        login: "No authenticated session was detected. Please log in first.",
        toLogin: "Go to login",
        balance: "Balance",
        plan: "Current plan",
        expire: "Expiry",
        renewStatus: "Renew status",
        statusEnabled: "Enabled",
        statusDisabled: "Disabled",
        enableAction: "Enable",
        disableAction: "Disable",
        none: "None",
        claim: "Claim today",
        claimed: "Claimed today",
        range: "Reward range",
        latest: "Latest record",
        amount: "Amount",
        methods: "Payment methods",
        create: "Create top-up order",
        next: "Next scan",
        result: "Last result",
        reason: "Reason",
        history: "History",
        disabledFeature: "This feature is currently disabled.",
        empty: "No data",
        refreshHint: "If payment just finished, refresh to sync the result.",
        topupCreated: "Top-up order created. Redirecting to payment.",
        checkinOk: "Check-in succeeded.",
        renewOk: "Auto renew setting updated.",
        failed: "Request failed"
      }
    };
    var extra = EXTRA_I18N.wallet || {};
    var out = {};
    Object.keys(base).forEach(function (locale) {
      out[locale] = Object.assign({}, base[locale], extra[locale] || {});
    });
    Object.keys(extra).forEach(function (locale) {
      out[locale] = Object.assign({}, out[locale] || base["en-US"], extra[locale] || {});
    });
    return out;
  }

  function readStoredLocale() {
    if (typeof window.__xboardCustomGetStoredLocale === "function") {
      return window.__xboardCustomGetStoredLocale();
    }
    var raw = localStorage.getItem("VUE_NAIVE_LOCALE");
    if (!raw) return null;
    try {
      var parsed = JSON.parse(raw);
      return parsed && typeof parsed.value === "string" ? parsed.value : null;
    } catch (error) {
      return null;
    }
  }

  function normalizeLocale(raw) {
    var value = String(raw || "").replace(/_/g, "-").trim();
    if (!value) return "en-US";
    var exact = supportedLocales.find(function (locale) { return locale === value; });
    if (exact) return exact;
    var lower = value.toLowerCase();
    exact = supportedLocales.find(function (locale) { return locale.toLowerCase() === lower; });
    if (exact) return exact;
    var language = lower.split("-")[0];
    exact = supportedLocales.find(function (locale) { return locale.toLowerCase().split("-")[0] === language; });
    if (exact) return exact;
    return language === "zh" ? "zh-CN" : "en-US";
  }

  function detectLocale() {
    return normalizeLocale(
      readStoredLocale() ||
      localStorage.getItem("locale") ||
      localStorage.getItem("lang") ||
      document.documentElement.lang ||
      navigator.language ||
      "en-US"
    );
  }

  function localeMessages(locale) {
    return text[normalizeLocale(locale)] || text["en-US"];
  }

  function isRtlLocale(locale) {
    return RTL_LOCALES.indexOf(normalizeLocale(locale)) !== -1;
  }

  function applyLocaleDirection(locale) {
    if (typeof window.__xboardCustomApplyLocaleDirection === "function") {
      window.__xboardCustomApplyLocaleDirection(normalizeLocale(locale));
      return;
    }
    document.documentElement.lang = normalizeLocale(locale);
    document.documentElement.dir = isRtlLocale(locale) ? "rtl" : "ltr";
  }

  function t(key) {
    var current = localeMessages(state.locale);
    return current[key] || text["en-US"][key] || key;
  }

  function intlLocale() {
    return normalizeLocale(state.locale);
  }

  function esc(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  function parseHash(hash) {
    var raw = String(hash || "").replace(/^#/, "");
    var arr = raw.split("?");
    var path = arr[0] || "/";
    if (path.charAt(0) !== "/") path = "/" + path;
    var q = new URLSearchParams(arr[1] || "");
    return {
      path: path,
      isWallet: path === "/wallet" || q.get("xc_wallet") === "1",
      section: q.get("section") || "",
      tradeNo: q.get("topup_trade_no") || ""
    };
  }

  function isAuthPath(path) {
    return path === "/login"
      || path === "/register"
      || path === "/forgot"
      || path === "/forget"
      || path.indexOf("/reset") === 0
      || path.indexOf("/password") === 0;
  }

  function shouldShowDock() {
    return (!!state.token || state.authed === true) && !isAuthPath(state.route.path);
  }

  function clearAuthProbe() {
    if (authProbeTimer) {
      clearTimeout(authProbeTimer);
      authProbeTimer = 0;
    }
    authProbeAttempts = 0;
  }

  function scheduleAuthProbe() {
    if (authProbeTimer || isAuthPath(state.route.path) || authProbeAttempts >= MAX_AUTH_PROBE_ATTEMPTS) return;
    authProbeAttempts += 1;
    authProbeTimer = setTimeout(function () {
      authProbeTimer = 0;
      load(false);
    }, AUTH_PROBE_DELAY);
  }

  function badge(label, tone) {
    return '<div class="xc-wallet-badge"' + (tone ? ' data-tone="' + esc(tone) + '"' : "") + ">" + esc(label) + "</div>";
  }

  function money(value) {
    var n = Number(value || 0) / 100;
    var symbol = state.comm && state.comm.currency_symbol ? state.comm.currency_symbol + " " : "";
    var code = state.comm && state.comm.currency ? " " + state.comm.currency : "";
    try {
      return symbol + n.toLocaleString(intlLocale(), { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + code;
    } catch (error) {
      return symbol + n.toFixed(2) + code;
    }
  }

  function time(value) {
    if (!value) return "--";
    var date = typeof value === "number" ? new Date(value * 1000) : new Date(value);
    if (Number.isNaN(date.getTime())) return esc(value);
    try {
      return date.toLocaleString(intlLocale());
    } catch (error) {
      return date.toLocaleString("en-US");
    }
  }

  function token() {
    var stores = [window.localStorage, window.sessionStorage];
    for (var i = 0; i < stores.length; i += 1) {
      var store = stores[i];
      if (!store) continue;
      for (var j = 0; j < store.length; j += 1) {
        var raw = store.getItem(store.key(j)) || "";
        var match = raw.match(/Bearer\s+[A-Za-z0-9._-]+/i);
        if (match) return "Bearer " + match[0].replace(/^Bearer\s+/i, "").trim();
        try {
          var parsed = JSON.parse(raw);
          if (parsed && typeof parsed.auth_data === "string" && /^Bearer /i.test(parsed.auth_data)) {
            return parsed.auth_data;
          }
        } catch (error) {}
      }
    }
    return null;
  }

  function remember() {
    if (!state.route.isWallet) sessionStorage.setItem(PREV, location.hash || DEFAULT_HASH);
  }

  function openWallet(section) {
    remember();
    var base = state.route.path && state.route.path !== "/404" && state.route.path !== "/login" ? state.route.path : "/dashboard";
    var hash = "#" + base + "?xc_wallet=1";
    if (section) hash += "&section=" + encodeURIComponent(section);
    location.hash = hash;
  }

  function closeWallet() {
    var prev = sessionStorage.getItem(PREV) || DEFAULT_HASH;
    if (parseHash(prev).isWallet) prev = DEFAULT_HASH;
    location.hash = prev;
  }

  function toast(message, tone) {
    ensure();
    var el = document.createElement("div");
    el.className = "xc-wallet-toast";
    if (tone) el.dataset.tone = tone;
    el.textContent = message;
    dom.toast.appendChild(el);
    setTimeout(function () { el.remove(); }, 3200);
  }

  function headers(method) {
    var h = { Accept: "application/json" };
    if (method !== "GET") h["Content-Type"] = "application/json";
    if (state.token) h.Authorization = state.token;
    return h;
  }

  async function api(url, options) {
    var opt = options || {};
    var method = opt.method || "GET";
    var response = await fetch(url, {
      method: method,
      headers: headers(method),
      body: opt.body ? JSON.stringify(opt.body) : undefined,
      credentials: "same-origin"
    });
    var raw = await response.text();
    var json = null;
    if (raw) {
      try { json = JSON.parse(raw); } catch (error) {}
    }
    if (!response.ok || (json && json.status === "fail")) {
      var err = new Error((json && json.message) || response.statusText || t("failed"));
      err.status = response.status;
      throw err;
    }
    return json ? json.data : null;
  }

  async function safe(fn) {
    try {
      return { ok: true, data: await fn() };
    } catch (error) {
      return { ok: false, error: error };
    }
  }

  function ensure() {
    if (dom.dock) return;
    dom.dock = document.createElement("div");
    dom.dock.className = "xc-wallet-dock xc-wallet-dock--floating";
    dom.dock.addEventListener("click", onClick);
    dom.dock.addEventListener("keydown", onDockKeydown);
    dom.overlay = document.createElement("div");
    dom.overlay.className = "xc-wallet-overlay";
    dom.overlay.addEventListener("click", onClick);
    dom.shell = document.createElement("div");
    dom.shell.className = "xc-wallet-shell";
    dom.overlay.appendChild(dom.shell);
    dom.toast = document.createElement("div");
    dom.toast.className = "xc-wallet-toast-stack";
    document.body.appendChild(dom.dock);
    document.body.appendChild(dom.overlay);
    document.body.appendChild(dom.toast);
  }

  function onDockKeydown(event) {
    if (!event || (event.key !== "Enter" && event.key !== " ")) return;
    var target = event.target.closest("[data-xc]");
    if (!target) return;
    event.preventDefault();
    target.click();
  }

  function onClick(event) {
    var btn = event.target.closest("[data-xc]");
    if (!btn) return;
    runDockAction(btn.dataset.xc, btn.dataset.section, btn.dataset.enabled);
  }

  function layout(inner) {
    return ''
      + '<div class="xc-wallet-header">'
      + '<div class="xc-wallet-title"><h1>' + esc(t("title")) + '</h1><p>' + esc(t("subtitle")) + "</p></div>"
      + '<div class="xc-wallet-actions">'
      + '<button type="button" data-xc="refresh" data-variant="ghost">' + esc(t("refresh")) + "</button>"
      + '<button type="button" data-xc="close">' + esc(t("back")) + "</button>"
      + "</div></div>"
      + inner;
  }

  function card(id, title, desc, body, span) {
    return '<section class="xc-wallet-card" id="xc-section-' + esc(id) + '" data-span="' + esc(span || "4") + '"><h2>' + esc(title) + "</h2><p>" + esc(desc) + "</p>" + body + "</section>";
  }

  function list(items) {
    return items && items.length
      ? '<div class="xc-wallet-list">' + items.join("") + "</div>"
      : '<div class="xc-wallet-empty"><p>' + esc(t("empty")) + "</p></div>";
  }

  function item(title, lines, badgeHtml) {
    return '<div class="xc-wallet-list-item"><div><strong>' + esc(title) + "</strong>" + lines.map(function (line) {
      return "<span>" + esc(line) + "</span>";
    }).join("") + "</div><div>" + (badgeHtml || "") + "</div></div>";
  }

  function isFeatureDisabled(error) {
    if (!error) return false;
    if (error.status === 403) return true;
    var message = String(error.message || "");
    return /disabled/i.test(message) || /未启用|未开启|禁用/.test(message);
  }

  function render() {
    ensure();
    applyLocaleDirection(state.locale);
    renderDock();
    dom.overlay.dataset.open = state.route.isWallet ? "true" : "false";
    dom.overlay.setAttribute("dir", isRtlLocale(state.locale) ? "rtl" : "ltr");
    dom.overlay.dataset.locale = intlLocale();
    document.body.classList.toggle("xc-wallet-open", state.route.isWallet);
    document.body.classList.toggle("xc-rtl", isRtlLocale(state.locale));
    if (!state.route.isWallet) return;
    if (state.loading) {
      dom.shell.innerHTML = layout('<div class="xc-wallet-empty"><div class="xc-wallet-loading">' + esc(t("loading")) + "</div></div>");
      return;
    }
    if (!state.authed) {
      dom.shell.innerHTML = layout('<div class="xc-wallet-empty"><p>' + esc(t("login")) + '</p><div class="xc-wallet-stack"><button type="button" data-xc="login">' + esc(t("toLogin")) + "</button></div></div>");
      return;
    }

    var sub = state.subscribe || {};
    var user = state.user || {};
    var topMethods = state.topup.methods || {};
    var autoCfg = state.renew.config || {};
    var autoConfig = autoCfg.config || {};
    var autoSub = autoCfg.subscription || {};

    var metrics = ''
      + '<div class="xc-wallet-card" data-span="12"><div class="xc-wallet-metrics">'
      + '<div class="xc-wallet-metric"><span>' + esc(t("balance")) + '</span><strong>' + esc(money(user.balance || 0)) + "</strong></div>"
      + '<div class="xc-wallet-metric"><span>' + esc(t("plan")) + '</span><strong>' + esc((sub.plan && sub.plan.name) || t("none")) + "</strong></div>"
      + '<div class="xc-wallet-metric"><span>' + esc(t("expire")) + '</span><strong>' + esc(sub.expired_at ? time(Number(sub.expired_at)) : "--") + "</strong></div>"
      + '<div class="xc-wallet-metric"><span>' + esc(t("renewStatus")) + '</span><strong>' + esc(autoConfig.enabled ? t("statusEnabled") : t("statusDisabled")) + "</strong></div>"
      + "</div></div>";

    var ck = state.checkin.status || {};
    var ckh = (state.checkin.history && state.checkin.history.records) || [];
    var checkinBody = isFeatureDisabled(state.checkin.error)
      ? '<div class="xc-wallet-stack">' + badge(t("disabledFeature"), "warning") + "</div>"
      : '<div class="xc-wallet-stack">'
        + badge(ck.today_claimed ? t("claimed") : t("claim"), ck.today_claimed ? "success" : "warning")
        + "<p>" + esc(t("range")) + ": " + esc(money((ck.reward_range || {}).min || 0)) + " ~ " + esc(money((ck.reward_range || {}).max || 0)) + "</p>"
        + '<button type="button" data-xc="claim"' + (ck.today_claimed ? " disabled" : "") + ' data-variant="success">' + esc(ck.today_claimed ? t("claimed") : t("claim")) + "</button>"
        + "</div>";

    var methods = (topMethods.payment_channels || []).map(function (method, index) {
      return '<label class="xc-wallet-radio"><div><strong>' + esc(method.name || method.payment || "Payment") + '</strong><span>' + esc(method.payment || "") + '</span></div><input type="radio" name="xc_topup" value="' + esc(String(method.id)) + '"' + (index === 0 ? " checked" : "") + " /></label>";
    }).join("");
    var topupBody = isFeatureDisabled(state.topup.error)
      ? '<div class="xc-wallet-stack">' + badge(t("disabledFeature"), "warning") + "</div>"
      : '<div class="xc-wallet-form"><input id="xc-topup-amount" class="xc-wallet-input" inputmode="decimal" placeholder="25" /><p>' + esc(t("refreshHint")) + '</p><div class="xc-wallet-radio-list">' + methods + '</div><button type="button" data-xc="topup">' + esc(t("create")) + "</button></div>";

    var renewBody = isFeatureDisabled(state.renew.error)
      ? '<div class="xc-wallet-stack">' + badge(t("disabledFeature"), "warning") + "</div>"
      : '<div class="xc-wallet-stack">'
        + badge(autoConfig.enabled ? t("statusEnabled") : t("statusDisabled"), autoConfig.enabled ? "success" : "warning")
        + "<p>" + esc(t("amount")) + ": " + esc(money(autoSub.amount || 0)) + "</p>"
        + "<p>" + esc(t("next")) + ": " + esc(autoConfig.next_scan_at ? time(autoConfig.next_scan_at) : "--") + "</p>"
        + "<p>" + esc(t("result")) + ": " + esc(autoConfig.last_result || t("none")) + "</p>"
        + '<button type="button" data-xc="renew" data-enabled="' + (autoConfig.enabled ? "1" : "0") + '" data-variant="' + (autoConfig.enabled ? "ghost" : "success") + '">' + esc(autoConfig.enabled ? t("disableAction") : t("enableAction")) + "</button>"
        + "</div>";

    var ckItems = ckh.slice(0, 5).map(function (record) {
      return item(record.claim_date || "--", [t("amount") + ": " + money(record.reward_amount || 0)], badge("success"));
    });
    var topItems = (((state.topup.history || {}).records) || []).slice(0, 5).map(function (record) {
      var tone = /paid/i.test(record.status_label || "") ? "success" : /expired|cancelled/i.test(record.status_label || "") ? "danger" : "warning";
      return item(record.trade_no || "--", [t("amount") + ": " + money(record.amount || 0), t("latest") + ": " + time(record.created_at)], badge(record.status_label || "--", tone));
    });
    if (state.topup.detail && state.topup.detail.order) {
      topItems.unshift(item(
        (state.topup.detail.order.trade_no || "--") + " *",
        [t("amount") + ": " + money(state.topup.detail.order.amount || 0), t("result") + ": " + (state.topup.detail.order.status_label || "--")],
        badge(state.topup.detail.order.status_label || "--")
      ));
    }
    var renewItems = (((state.renew.history || {}).records) || []).slice(0, 5).map(function (record) {
      var tone = /success/i.test(record.status_label || "") ? "success" : /failed/i.test(record.status_label || "") ? "danger" : "warning";
      return item(record.status_label || "--", [t("amount") + ": " + money(record.amount || 0), t("reason") + ": " + (record.reason_message || record.reason || "--")], badge(record.status_label || "--", tone));
    });

    dom.shell.innerHTML = layout(
      '<div class="xc-wallet-grid">'
      + metrics
      + card("checkin", t("checkin"), t("subtitle"), checkinBody, "4")
      + card("topup", t("topup"), t("methods"), topupBody, "4")
      + card("renew", t("renew"), t("renewStatus"), renewBody, "4")
      + card("checkin-history", t("history"), t("checkin"), list(ckItems), "6")
      + card("topup-history", t("history"), t("topup"), list(topItems), "6")
      + card("renew-history", t("history"), t("renew"), list(renewItems), "12")
      + "</div>"
    );

    if (state.route.section) {
      var sec = document.getElementById("xc-section-" + state.route.section);
      if (sec) requestAnimationFrame(function () { sec.scrollIntoView({ behavior: "smooth", block: "start" }); });
    }
  }

  function amountCents(value) {
    var raw = String(value || "").replace(/,/g, "").trim();
    return raw && !Number.isNaN(Number(raw)) ? Math.round(Number(raw) * 100) : 0;
  }

  function paymentId() {
    var el = dom.shell.querySelector('input[name="xc_topup"]:checked');
    return el ? Number(el.value) : 0;
  }

  async function claim() {
    try {
      await api("/api/v1/wallet-center/checkin/claim", { method: "POST", body: {} });
      toast(t("checkinOk"), "success");
    } catch (error) {
      toast(error.message || t("failed"), "error");
    }
    load(true);
  }

  async function createTopup() {
    var amount = amountCents((dom.shell.querySelector("#xc-topup-amount") || {}).value || "");
    var payment = paymentId();
    if (!amount || !payment) return toast(t("failed"), "error");
    try {
      var res = await api("/api/v1/wallet-center/topup/create", { method: "POST", body: { payment_id: payment, amount: amount } });
      if (res && res.order && res.order.trade_no) sessionStorage.setItem(LAST_TOPUP, res.order.trade_no);
      toast(t("topupCreated"), "success");
      if (res && res.payment_result && Number(res.payment_result.type) === 1 && res.payment_result.data) {
        location.href = res.payment_result.data;
        return;
      }
    } catch (error) {
      toast(error.message || t("failed"), "error");
    }
    load(true);
  }

  async function toggleRenew(current) {
    try {
      await api("/api/v1/wallet-center/auto-renew/config", { method: "POST", body: { enabled: !current } });
      toast(t("renewOk"), "success");
    } catch (error) {
      toast(error.message || t("failed"), "error");
    }
    load(true);
  }

  async function load(force) {
    state.locale = detectLocale();
    state.token = token();
    if (!state.token) {
      state.authed = false;
      state.user = null;
      state.loading = false;
      scheduleAuthProbe();
      return render();
    }
    clearAuthProbe();
    var shouldLoadWallet = state.route.isWallet || force;
    state.loading = shouldLoadWallet;
    render();
    var rid = ++state.rid;
    var base = await safe(function () { return api("/api/v1/user/info"); });
    if (rid !== state.rid) return;
    if (!base.ok) {
      state.authed = false;
      state.user = null;
      state.loading = false;
      scheduleAuthProbe();
      return render();
    }
    state.authed = true;
    state.user = base.data;
    clearAuthProbe();
    if (!shouldLoadWallet) {
      state.loading = false;
      return render();
    }
    var tradeNo = state.route.tradeNo || sessionStorage.getItem(LAST_TOPUP) || "";
    var all = await Promise.all([
      safe(function () { return api("/api/v1/user/getSubscribe"); }),
      safe(function () { return api("/api/v1/user/comm/config"); }),
      safe(function () { return api("/api/v1/wallet-center/checkin/status"); }),
      safe(function () { return api("/api/v1/wallet-center/checkin/history?limit=5"); }),
      safe(function () { return api("/api/v1/wallet-center/topup/methods"); }),
      safe(function () { return api("/api/v1/wallet-center/topup/history?limit=5"); }),
      safe(function () { return tradeNo ? api("/api/v1/wallet-center/topup/detail?trade_no=" + encodeURIComponent(tradeNo)) : Promise.resolve(null); }),
      safe(function () { return api("/api/v1/wallet-center/auto-renew/config"); }),
      safe(function () { return api("/api/v1/wallet-center/auto-renew/history?limit=5"); })
    ]);
    if (rid !== state.rid) return;
    state.subscribe = all[0].ok ? all[0].data : null;
    state.comm = all[1].ok ? all[1].data : null;
    state.checkin = {
      status: all[2].ok ? all[2].data : null,
      history: all[3].ok ? all[3].data : null,
      error: all[2].ok ? (all[3].ok ? null : all[3].error) : all[2].error
    };
    state.topup = {
      methods: all[4].ok ? all[4].data : null,
      history: all[5].ok ? all[5].data : null,
      detail: all[6].ok ? all[6].data : null,
      error: all[4].ok ? (all[5].ok ? null : all[5].error) : all[4].error
    };
    state.renew = {
      config: all[7].ok ? all[7].data : null,
      history: all[8].ok ? all[8].data : null,
      error: all[7].ok ? (all[8].ok ? null : all[8].error) : all[7].error
    };
    if (state.topup.detail && state.topup.detail.order && /paid|expired|cancelled/i.test(state.topup.detail.order.status_label || "")) {
      sessionStorage.removeItem(LAST_TOPUP);
    }
    state.loading = false;
    render();
  }

  function sync() {
    state.locale = detectLocale();
    var next = parseHash(location.hash);
    if (next.isWallet && !state.route.isWallet) remember();
    state.route = next;
    load(false);
  }

  function init() {
    ensure();
    load(false);
    if (document.body) {
      var observer = new MutationObserver(function (records) {
        if (shouldRefreshDock(records)) {
          scheduleDockSync();
        }
      });
      observer.observe(document.body, { childList: true, subtree: true });
    }
    addEventListener("resize", scheduleDockSync, { passive: true });
    document.addEventListener("click", onDocumentClick, true);
    addEventListener("hashchange", sync);
    addEventListener("keydown", function (event) {
      if (event.key === "Escape" && state.route.isWallet) closeWallet();
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init, { once: true });
  } else {
    init();
  }
})();
