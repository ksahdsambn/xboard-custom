(function () {
  if (window.__xboardCustomWalletCenterLoaded) return;
  window.__xboardCustomWalletCenterLoaded = true;

  var LAST_TOPUP = "xboardCustom.lastTopupTradeNo";
  var EXTRA_I18N = window.xboardCustomI18n || {};
  var RTL_LOCALES = EXTRA_I18N.rtlLocales || ["fa-IR", "ar-SA"];
  var OFFICIAL_PATH = "/profile";
  var text = buildTextCatalog();
  var supportedLocales = Object.keys(text);
  var state = {
    locale: detectLocale(),
    route: parseHash(location.hash),
    section: "",
    token: null,
    authed: false,
    loading: false,
    rid: 0,
    user: null,
    subscribe: null,
    comm: null,
    checkin: {},
    topup: {},
    renew: {},
    pages: { checkin: 1, topup: 1, renew: 1 },
    filters: { topup: "all", renew: "all" }
  };
  var dom = {};
  var placeTimer = 0;
  var topupPollTimer = 0;

  function buildTextCatalog() {
    var base = {
      "zh-CN": {
        wallet: "钱包",
        checkin: "每日签到",
        topup: "余额充值",
        renew: "自动续费",
        title: "钱包扩展",
        subtitle: "签到、充值和余额自动续费。",
        refresh: "刷新",
        loading: "正在加载",
        login: "请先登录。",
        toLogin: "去登录",
        claim: "立即签到",
        claimed: "今日已签到",
        range: "奖励区间",
        amount: "金额",
        create: "创建充值订单",
        next: "下次扫描",
        result: "最近结果",
        history: "历史记录",
        disabledFeature: "该功能当前未启用。",
        empty: "暂无数据",
        refreshHint: "支付完成后会自动同步结果。",
        topupCreated: "充值订单已创建，即将跳转支付。",
        checkinOk: "签到成功，奖励已入账。",
        renewOk: "自动续费设置已更新。",
        failed: "请求失败",
        amountMin: "最小金额",
        amountMax: "最大金额",
        fee: "手续费",
        confirmClaim: "确认领取今日签到奖励？",
        confirmTopup: "确认创建充值订单并跳转支付？",
        confirmRenewOn: "确认开启余额自动续费？续费将创建官方订单。",
        confirmRenewOff: "确认关闭自动续费？",
        streak: "连续签到",
        notice: "说明",
        page: "页",
        prev: "上一页",
        nextPage: "下一页",
        filter: "筛选",
        exportCsv: "导出 CSV",
        all: "全部",
        officialWallet: "我的钱包",
        statusEnabled: "已开启",
        statusDisabled: "未开启",
        enableAction: "开启",
        disableAction: "关闭",
        none: "无"
      },
      "en-US": {
        wallet: "Wallet",
        checkin: "Daily check-in",
        topup: "Balance top-up",
        renew: "Auto renew",
        title: "Wallet extras",
        subtitle: "Check-in, top-up and balance auto-renew.",
        refresh: "Refresh",
        loading: "Loading",
        login: "Please log in first.",
        toLogin: "Go to login",
        claim: "Claim today",
        claimed: "Claimed today",
        range: "Reward range",
        amount: "Amount",
        create: "Create top-up order",
        next: "Next scan",
        result: "Last result",
        history: "History",
        disabledFeature: "This feature is currently disabled.",
        empty: "No data",
        refreshHint: "Payment results sync automatically.",
        topupCreated: "Top-up order created. Redirecting to payment.",
        checkinOk: "Check-in succeeded.",
        renewOk: "Auto renew setting updated.",
        failed: "Request failed",
        amountMin: "Minimum",
        amountMax: "Maximum",
        fee: "Fee",
        confirmClaim: "Claim today's check-in reward?",
        confirmTopup: "Create a top-up order and continue to payment?",
        confirmRenewOn: "Enable balance auto-renew? Renewal will create an official order.",
        confirmRenewOff: "Disable auto-renew?",
        streak: "Streak",
        notice: "Note",
        page: "Page",
        prev: "Previous",
        nextPage: "Next",
        filter: "Filter",
        exportCsv: "Export CSV",
        all: "All",
        officialWallet: "My Wallet",
        statusEnabled: "Enabled",
        statusDisabled: "Disabled",
        enableAction: "Enable",
        disableAction: "Disable",
        none: "None"
      }
    };
    var extra = EXTRA_I18N.wallet || {};
    var out = {};
    Object.keys(base).forEach(function (locale) {
      out[locale] = Object.assign({}, base[locale], extra[locale] || {});
    });
    Object.keys(extra).forEach(function (locale) {
      out[locale] = Object.assign({}, base["en-US"], out[locale] || {}, extra[locale] || {});
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
    return normalizeLocale(readStoredLocale() || document.documentElement.lang || navigator.language || "en-US");
  }

  function localeMessages(locale) {
    return text[normalizeLocale(locale)] || text["en-US"];
  }

  function isRtlLocale(locale) {
    return RTL_LOCALES.indexOf(normalizeLocale(locale)) !== -1;
  }

  function t(key) {
    var current = localeMessages(state.locale);
    return current[key] || text["en-US"][key] || key;
  }

  function esc(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function parseHash(hash) {
    var raw = String(hash || "").replace(/^#/, "");
    var arr = raw.split("?");
    var path = arr[0] || "/";
    if (path.charAt(0) !== "/") path = "/" + path;
    if (path.length > 1) path = path.replace(/\/+$/, "");
    var q = new URLSearchParams(arr[1] || "");
    return {
      path: path,
      isWallet: path === OFFICIAL_PATH || path === "/wallet" || q.get("xc_wallet") === "1",
      section: q.get("section") || "",
      tradeNo: q.get("topup_trade_no") || ""
    };
  }

  function readLastTopupTradeNo() {
    try {
      return sessionStorage.getItem(LAST_TOPUP) || "";
    } catch (error) {
      return "";
    }
  }

  function canonicalizeWalletHash() {
    var parsed = parseHash(location.hash);
    var lastTopup = readLastTopupTradeNo();
    var shouldRecover = parsed.path === "/wallet"
      || (parsed.path === "/dashboard" && (parsed.section || parsed.tradeNo || location.hash.indexOf("xc_wallet=1") !== -1));
    if (parsed.path === "/404" && (parsed.tradeNo || lastTopup)) shouldRecover = true;
    if (!shouldRecover) return;
    var next = "#" + OFFICIAL_PATH;
    var params = [];
    var tradeNo = parsed.tradeNo || lastTopup;
    var section = parsed.section || (tradeNo ? "topup" : "");
    if (section) params.push("section=" + encodeURIComponent(section));
    if (tradeNo) params.push("topup_trade_no=" + encodeURIComponent(tradeNo));
    if (params.length) next += "?" + params.join("&");
    if (location.hash !== next) {
      history.replaceState(null, "", location.pathname + location.search + next);
    }
  }

  function isAuthPath(path) {
    return path === "/login" || path === "/register" || path === "/forgot" || path === "/forget" || path.indexOf("/reset") === 0;
  }

  function money(value) {
    var n = Number(value || 0) / 100;
    var symbol = state.comm && state.comm.currency_symbol ? state.comm.currency_symbol + " " : "";
    try {
      return symbol + n.toLocaleString(normalizeLocale(state.locale), { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    } catch (error) {
      return symbol + n.toFixed(2);
    }
  }

  function time(value) {
    if (!value) return "--";
    var date = typeof value === "number" ? new Date(value * 1000) : new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    try {
      return date.toLocaleString(normalizeLocale(state.locale));
    } catch (error) {
      return date.toLocaleString("en-US");
    }
  }

  function readStoredToken(raw) {
    if (!raw) return null;
    try {
      var parsed = JSON.parse(raw);
      var value = parsed && typeof parsed === "object" && parsed.value != null ? parsed.value : parsed;
      if (typeof value === "string" && value) {
        return /^Bearer /i.test(value) ? value : "Bearer " + value;
      }
      if (parsed && typeof parsed.auth_data === "string") {
        return /^Bearer /i.test(parsed.auth_data) ? parsed.auth_data : "Bearer " + parsed.auth_data;
      }
    } catch (error) {
      var textValue = String(raw).trim();
      var match = textValue.match(/Bearer\s+[A-Za-z0-9._-]+/i);
      if (match) return "Bearer " + match[0].replace(/^Bearer\s+/i, "").trim();
      if (/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/.test(textValue)) return "Bearer " + textValue;
    }
    return null;
  }

  function token() {
    var keys = ["VUE_NAIVE_ACCESS_TOKEN", "ACCESS_TOKEN", "auth_data"];
    var stores = [window.localStorage, window.sessionStorage];
    for (var i = 0; i < stores.length; i += 1) {
      var store = stores[i];
      if (!store) continue;
      for (var k = 0; k < keys.length; k += 1) {
        var found = readStoredToken(store.getItem(keys[k]));
        if (found) return found;
      }
    }
    return null;
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

  function toast(message, tone) {
    ensureToastHost();
    var el = document.createElement("div");
    el.className = "xc-wallet-toast" + (tone ? " is-" + tone : "");
    el.textContent = message;
    dom.toast.appendChild(el);
    setTimeout(function () { el.remove(); }, 3200);
  }

  function ensureToastHost() {
    if (dom.toast && document.body.contains(dom.toast)) return;
    dom.toast = document.createElement("div");
    dom.toast.className = "xc-wallet-toast-stack";
    document.body.appendChild(dom.toast);
  }

  function ensureRoot() {
    if (dom.root && document.body.contains(dom.root) && dom.panel) return;
    if (!dom.root) {
      dom.root = document.createElement("div");
      dom.root.id = "xc-wallet-root";
      dom.root.hidden = true;
    }
    if (!dom.panel) {
      dom.panel = document.createElement("section");
      dom.panel.className = "xc-wallet-panel";
      dom.panel.addEventListener("click", onClick);
      dom.panel.addEventListener("change", onChange);
      dom.panel.addEventListener("submit", onSubmit);
      dom.root.appendChild(dom.panel);
    }
    if (!document.body.contains(dom.root)) document.body.appendChild(dom.root);
  }

  function findOfficialWalletCard() {
    var nodes = document.querySelectorAll("#app .n-card-header__main, #app .n-card-header");
    for (var i = 0; i < nodes.length; i += 1) {
      var label = (nodes[i].textContent || "").trim();
      if (!label) continue;
      if (label.indexOf(t("officialWallet")) !== -1 || /钱包|Wallet|portefeuille|Portemonnaie|billetera|portafoglio|carteira|portemonnee|portfel|Cüzdan|кошел|محفظ|lommebok|plånbok|lompakko|財布|지갑|Ví|کیف/i.test(label)) {
        return nodes[i].closest(".n-card");
      }
    }
    return null;
  }

  function clearWalletCardGap() {
    var marked = document.querySelectorAll("[data-xc-wallet-gap]");
    for (var i = 0; i < marked.length; i += 1) {
      marked[i].style.marginBottom = "";
      marked[i].removeAttribute("data-xc-wallet-gap");
    }
  }

  function sampleTheme(card) {
    if (!dom.panel) return;
    try {
      if (card) {
        var cs = window.getComputedStyle(card);
        if (cs.backgroundColor) dom.panel.style.setProperty("--xc-card-bg", cs.backgroundColor);
        if (cs.borderRadius) dom.panel.style.setProperty("--xc-card-radius", cs.borderRadius);
        if (cs.boxShadow && cs.boxShadow !== "none") dom.panel.style.setProperty("--xc-card-shadow", cs.boxShadow);
        if (parseFloat(cs.borderTopWidth || "0") > 0 && cs.borderTopColor) {
          dom.panel.style.setProperty("--xc-card-border", cs.borderTopColor);
        }
        if (cs.color && cs.color !== "rgb(0, 0, 0)") dom.panel.style.setProperty("--xc-text", cs.color);
      }
      var title = card && card.querySelector(".n-card-header__main");
      if (title) {
        var ts = window.getComputedStyle(title);
        if (ts.color) dom.panel.style.setProperty("--xc-title-color", ts.color);
        if (ts.fontSize) dom.panel.style.setProperty("--xc-title-size", ts.fontSize);
      }
      var btn = document.querySelector("#app .n-button--primary-type");
      if (btn) {
        var bs = window.getComputedStyle(btn);
        if (bs.backgroundColor) dom.panel.style.setProperty("--xc-accent", bs.backgroundColor);
        if (bs.color) dom.panel.style.setProperty("--xc-accent-text", bs.color);
        if (bs.borderRadius) dom.panel.style.setProperty("--xc-btn-radius", bs.borderRadius);
      }
    } catch (error) {}
  }

  function syncPlacement() {
    ensureRoot();
    if (!state.route.isWallet || isAuthPath(state.route.path)) {
      dom.root.hidden = true;
      clearWalletCardGap();
      return false;
    }
    var card = findOfficialWalletCard();
    if (!card) {
      dom.root.hidden = true;
      return false;
    }
    sampleTheme(card);
    var rect = card.getBoundingClientRect();
    if (rect.width < 80) {
      dom.root.hidden = true;
      return false;
    }
    dom.root.hidden = false;
    dom.root.style.left = Math.max(0, rect.left) + "px";
    dom.root.style.width = rect.width + "px";
    dom.root.style.top = (rect.bottom + 16) + "px";
    var height = dom.panel ? dom.panel.offsetHeight : 0;
    card.setAttribute("data-xc-wallet-gap", "1");
    card.style.marginBottom = Math.max(24, height + 24) + "px";
    return true;
  }

  function schedulePlace() {
    if (placeTimer) return;
    placeTimer = window.requestAnimationFrame(function () {
      placeTimer = 0;
      syncPlacement();
    });
  }

  function currentSection() {
    return state.section || state.route.section || "checkin";
  }

  function setSection(section) {
    state.section = section || "checkin";
    var params = ["section=" + encodeURIComponent(state.section)];
    if (state.route.tradeNo && state.section === "topup") {
      params.push("topup_trade_no=" + encodeURIComponent(state.route.tradeNo));
    }
    var next = "#" + OFFICIAL_PATH + "?" + params.join("&");
    if (location.hash !== next) {
      history.replaceState(null, "", location.pathname + location.search + next);
      state.route = parseHash(location.hash);
    }
    render();
  }

  function onClick(event) {
    var btn = event.target.closest("[data-xc]");
    if (!btn || (dom.panel && !dom.panel.contains(btn))) return;
    var tag = (btn.tagName || "").toLowerCase();
    if (tag === "select" || tag === "input" || tag === "textarea") return;
    event.preventDefault();
    event.stopPropagation();
    var action = btn.getAttribute("data-xc");
    if (action === "refresh") load(true);
    if (action === "login") location.hash = "#/login";
    if (action === "claim") claim();
    if (action === "topup") createTopup();
    if (action === "renew") toggleRenew(btn.getAttribute("data-enabled") === "1");
    if (action === "page") {
      var key = btn.getAttribute("data-key");
      var page = Number(btn.getAttribute("data-page") || 1);
      if (key) {
        state.pages[key] = page;
        load(true);
      }
    }
    if (action === "export") exportCsv(btn.getAttribute("data-key"));
    if (action === "section") setSection(btn.getAttribute("data-section") || "checkin");
  }

  function onChange(event) {
    var el = event.target;
    if (!el || el.getAttribute("data-xc") !== "filter") return;
    var key = el.getAttribute("data-key");
    if (!key) return;
    state.filters[key] = el.value || "all";
    state.pages[key] = 1;
    load(true);
  }

  function onSubmit(event) {
    event.preventDefault();
    if ((event.target && event.target.getAttribute("data-xc")) === "topup-form") createTopup();
  }

  function btn(label, attrs, kind) {
    var extra = attrs || "";
    var cls = "xc-wallet-btn" + (kind === "ghost" || kind === "default" ? " xc-wallet-btn--" + kind : "");
    return '<button type="button" class="' + cls + '" ' + extra + ">" + esc(label) + "</button>";
  }

  function isFeatureDisabled(error) {
    if (!error) return false;
    if (error.status === 403) return true;
    return /disabled|未启用|未开启/i.test(String(error.message || ""));
  }

  function pager(key, pack) {
    var page = Number((pack && pack.page) || state.pages[key] || 1);
    var last = Number((pack && pack.last_page) || 1);
    var total = Number((pack && pack.total) || 0);
    return '<div class="xc-wallet-pager">'
      + btn(t("prev"), 'data-xc="page" data-key="' + key + '" data-page="' + Math.max(1, page - 1) + '"' + (page <= 1 ? " disabled" : ""), "ghost")
      + '<span class="xc-wallet-pager__info">' + esc(t("page")) + " " + page + " / " + last + " · " + total + "</span>"
      + btn(t("nextPage"), 'data-xc="page" data-key="' + key + '" data-page="' + Math.min(last, page + 1) + '"' + (page >= last ? " disabled" : ""), "ghost")
      + btn(t("exportCsv"), 'data-xc="export" data-key="' + key + '"', "ghost")
      + "</div>";
  }

  function listItems(items) {
    if (!items || !items.length) {
      return '<div class="xc-wallet-empty">' + esc(t("empty")) + "</div>";
    }
    return '<div class="xc-wallet-list">' + items.join("") + "</div>";
  }

  function paymentIcon(method) {
    var icon = method && method.icon;
    if (!icon) return "";
    if (/^https?:\/\//i.test(icon) || icon.indexOf("/") === 0 || icon.indexOf("data:") === 0) {
      return '<img class="xc-wallet-pay-icon" alt="" src="' + esc(icon) + '" />';
    }
    return '<span class="xc-wallet-pay-icon xc-wallet-pay-icon--text">' + esc(icon) + "</span>";
  }

  function captureDraft() {
    if (!dom.panel) return { amount: "", payment: 0 };
    return {
      amount: (dom.panel.querySelector("#xc-topup-amount") || {}).value || "",
      payment: paymentId()
    };
  }

  function restoreDraft(draft) {
    if (!dom.panel || !draft) return;
    var input = dom.panel.querySelector("#xc-topup-amount");
    if (input && draft.amount) input.value = draft.amount;
    if (draft.payment) {
      var radio = dom.panel.querySelector('input[name="xc_topup"][value="' + String(draft.payment) + '"]');
      if (radio) radio.checked = true;
    }
  }

  function render() {
    ensureRoot();
    if (!state.route.isWallet) {
      dom.root.hidden = true;
      clearWalletCardGap();
      return;
    }
    var draft = state.loading ? null : captureDraft();
    dom.panel.setAttribute("dir", isRtlLocale(state.locale) ? "rtl" : "ltr");

    if (state.loading) {
      dom.panel.innerHTML = '<div class="xc-wallet-head"><div><h2>' + esc(t("title")) + "</h2></div></div><div class=\"xc-wallet-body\"><p class=\"xc-wallet-note\">" + esc(t("loading")) + "</p></div>";
      schedulePlace();
      return;
    }
    if (!state.authed) {
      dom.panel.innerHTML = '<div class="xc-wallet-head"><div><h2>' + esc(t("title")) + "</h2></div></div><div class=\"xc-wallet-body\"><p class=\"xc-wallet-note\">" + esc(t("login")) + "</p>" + btn(t("toLogin"), 'data-xc="login"') + "</div>";
      schedulePlace();
      return;
    }

    var ck = state.checkin.status || {};
    var topMethods = state.topup.methods || {};
    var autoCfg = state.renew.config || {};
    var autoConfig = autoCfg.config || {};
    var autoSub = autoCfg.subscription || {};
    var range = topMethods.amount_range || {};
    var section = currentSection();

    var tabs = ["checkin", "topup", "renew"].map(function (key) {
      return '<button type="button" class="xc-wallet-tab' + (section === key ? " is-active" : "") + '" data-xc="section" data-section="' + key + '">' + esc(t(key)) + "</button>";
    }).join("");

    var checkinBody = isFeatureDisabled(state.checkin.error)
      ? '<span class="xc-wallet-tag xc-wallet-tag--warning">' + esc(t("disabledFeature")) + "</span>"
      : '<p class="xc-wallet-note">' + esc(ck.notice || t("notice")) + "</p>"
        + '<p class="xc-wallet-meta">' + esc(t("range")) + ": " + esc(money((ck.reward_range || {}).min || 0)) + " ~ " + esc(money((ck.reward_range || {}).max || 0)) + "</p>"
        + (ck.streak_days ? '<p class="xc-wallet-meta">' + esc(t("streak")) + ": " + esc(String(ck.streak_days)) + "</p>" : "")
        + '<div class="xc-wallet-actions">' + btn(ck.today_claimed ? t("claimed") : t("claim"), 'data-xc="claim"' + (ck.today_claimed ? " disabled" : "")) + "</div>";

    var methods = (topMethods.payment_channels || []).map(function (method, index) {
      var feeParts = [];
      if (Number(method.handling_fee_percent)) feeParts.push(Number(method.handling_fee_percent) + "%");
      if (Number(method.handling_fee_fixed)) feeParts.push(money(method.handling_fee_fixed));
      var checked = index === 0 ? " checked" : "";
      return '<label class="xc-wallet-pay">'
        + '<input type="radio" name="xc_topup" value="' + esc(String(method.id)) + '"' + checked + " />"
        + paymentIcon(method)
        + "<span><strong>" + esc(method.name || method.payment || "Payment") + "</strong><small>" + esc(method.payment || "") + (feeParts.length ? " · " + esc(t("fee") + ": " + feeParts.join(" + ")) : "") + "</small></span>"
        + "</label>";
    }).join("");

    var topupBody = isFeatureDisabled(state.topup.error)
      ? '<span class="xc-wallet-tag xc-wallet-tag--warning">' + esc(t("disabledFeature")) + "</span>"
      : '<form data-xc="topup-form"><input id="xc-topup-amount" class="xc-wallet-input" inputmode="decimal" placeholder="' + esc((range.min ? String(Number(range.min) / 100) : "10")) + '" />'
        + '<p class="xc-wallet-meta">' + esc(t("amountMin")) + ": " + esc(money(range.min || 0)) + " · " + esc(t("amountMax")) + ": " + esc(money(range.max || 0)) + "</p>"
        + '<p class="xc-wallet-note">' + esc(t("refreshHint")) + "</p>"
        + '<div class="xc-wallet-pay-list">' + methods + "</div>"
        + '<div class="xc-wallet-actions">' + btn(t("create"), 'data-xc="topup"') + "</div></form>";

    var renewBody = isFeatureDisabled(state.renew.error)
      ? '<span class="xc-wallet-tag xc-wallet-tag--warning">' + esc(t("disabledFeature")) + "</span>"
      : '<span class="xc-wallet-tag ' + (autoConfig.enabled ? "xc-wallet-tag--success" : "xc-wallet-tag--warning") + '">' + esc(autoConfig.enabled ? t("statusEnabled") : t("statusDisabled")) + "</span>"
        + '<p class="xc-wallet-meta">' + esc(t("amount")) + ": " + esc(money(autoSub.amount || 0)) + "</p>"
        + '<p class="xc-wallet-meta">' + esc(t("next")) + ": " + esc(autoConfig.next_scan_at ? time(autoConfig.next_scan_at) : "--") + "</p>"
        + '<p class="xc-wallet-meta">' + esc(t("result")) + ": " + esc(autoConfig.last_result || t("none")) + "</p>"
        + '<div class="xc-wallet-actions">' + btn(autoConfig.enabled ? t("disableAction") : t("enableAction"), 'data-xc="renew" data-enabled="' + (autoConfig.enabled ? "1" : "0") + '"', autoConfig.enabled ? "ghost" : "") + "</div>";

    var ckPack = state.checkin.history || {};
    var topPack = state.topup.history || {};
    var renewPack = state.renew.history || {};
    var ckItems = (ckPack.records || []).map(function (record) {
      return '<div class="xc-wallet-row"><div>' + esc(record.claim_date || "--") + " · " + esc(money(record.reward_amount || 0)) + "</div></div>";
    });
    var topItems = (topPack.records || []).map(function (record) {
      return '<div class="xc-wallet-row"><div>' + esc(record.trade_no || "--") + " · " + esc(money(record.amount || 0)) + '</div><span class="xc-wallet-tag">' + esc(record.status_label || "--") + "</span></div>";
    });
    var renewItems = (renewPack.records || []).map(function (record) {
      return '<div class="xc-wallet-row"><div>' + esc(record.status_label || "--") + " · " + esc(money(record.amount || 0)) + "<small>" + esc(record.reason_message || record.reason || "--") + "</small></div></div>";
    });

    var filter = function (key, options) {
      return '<label class="xc-wallet-toolbar">' + esc(t("filter")) + ' <select class="xc-wallet-select" data-xc="filter" data-key="' + key + '">'
        + options.map(function (value) {
          var label = value === "all" ? t("all") : value;
          return '<option value="' + esc(value) + '"' + (state.filters[key] === value ? " selected" : "") + ">" + esc(label) + "</option>";
        }).join("")
        + "</select></label>";
    };

    var bodies = {
      checkin: checkinBody + '<h3 class="xc-wallet-h">' + esc(t("history")) + "</h3>" + listItems(ckItems) + pager("checkin", ckPack),
      topup: topupBody + '<h3 class="xc-wallet-h">' + esc(t("history")) + "</h3>" + filter("topup", ["all", "paid", "pending", "expired"]) + listItems(topItems) + pager("topup", topPack),
      renew: renewBody + '<h3 class="xc-wallet-h">' + esc(t("history")) + "</h3>" + filter("renew", ["all", "success", "failed", "skipped"]) + listItems(renewItems) + pager("renew", renewPack)
    };

    dom.panel.innerHTML = ""
      + '<div class="xc-wallet-head"><div><h2>' + esc(t("title")) + "</h2><p>" + esc(t("subtitle")) + "</p></div>"
      + btn(t("refresh"), 'data-xc="refresh"', "ghost") + "</div>"
      + '<nav class="xc-wallet-tabs">' + tabs + "</nav>"
      + '<div class="xc-wallet-body">' + (bodies[section] || bodies.checkin) + "</div>";
    restoreDraft(draft);
    schedulePlace();
  }

  function amountCents(value) {
    var raw = String(value || "").replace(/,/g, "").trim();
    return raw && !Number.isNaN(Number(raw)) ? Math.round(Number(raw) * 100) : 0;
  }

  function paymentId() {
    var el = dom.panel && dom.panel.querySelector('input[name="xc_topup"]:checked');
    return el ? Number(el.value) : 0;
  }

  async function claim() {
    if (!window.confirm(t("confirmClaim"))) return;
    try {
      await api("/api/v1/wallet-center/checkin/claim", { method: "POST", body: {} });
      toast(t("checkinOk"), "success");
    } catch (error) {
      toast(error.message || t("failed"), "error");
    }
    load(true);
  }

  async function createTopup() {
    var amount = amountCents((dom.panel.querySelector("#xc-topup-amount") || {}).value || "");
    var payment = paymentId();
    if (!amount || !payment) return toast(t("failed"), "error");
    if (!window.confirm(t("confirmTopup"))) return;
    try {
      var res = await api("/api/v1/wallet-center/topup/create", { method: "POST", body: { payment_id: payment, amount: amount } });
      if (res && res.order && res.order.trade_no) {
        try { sessionStorage.setItem(LAST_TOPUP, res.order.trade_no); } catch (error) {}
      }
      toast(t("topupCreated"), "success");
      if (res && res.payment_result && Number(res.payment_result.type) === 1 && res.payment_result.data) {
        var payUrl = String(res.payment_result.data);
        if (/^https?:\/\//i.test(payUrl)) {
          location.href = payUrl;
          return;
        }
      }
    } catch (error) {
      toast(error.message || t("failed"), "error");
    }
    load(true);
  }

  async function toggleRenew(current) {
    if (!window.confirm(current ? t("confirmRenewOff") : t("confirmRenewOn"))) return;
    try {
      await api("/api/v1/wallet-center/auto-renew/config", { method: "POST", body: { enabled: !current } });
      toast(t("renewOk"), "success");
    } catch (error) {
      toast(error.message || t("failed"), "error");
    }
    load(true);
  }

  function csvCell(value) {
    var textValue = String(value == null ? "" : value);
    if (/[",\n\r]/.test(textValue)) return '"' + textValue.replace(/"/g, '""') + '"';
    return textValue;
  }

  function exportCsv(key) {
    var pack = key === "topup" ? state.topup.history : key === "renew" ? state.renew.history : state.checkin.history;
    var rows = (pack && pack.records) || [];
    var csv = rows.map(function (record) {
      return [
        csvCell(record.trade_no || record.claim_date || record.status_label),
        csvCell(record.amount || record.reward_amount),
        csvCell(record.status_label || record.status),
        csvCell(record.reason_message || "")
      ].join(",");
    });
    csv.unshift("id,amount,status,reason");
    var blob = new Blob([csv.join("\n")], { type: "text/csv;charset=utf-8;" });
    var url = URL.createObjectURL(blob);
    var a = document.createElement("a");
    a.href = url;
    a.download = "wallet-" + key + ".csv";
    a.click();
    URL.revokeObjectURL(url);
  }

  function historyQuery(key) {
    var page = state.pages[key] || 1;
    var status = state.filters[key];
    var q = "?page=" + page + "&limit=10";
    if (status && status !== "all") q += "&status=" + encodeURIComponent(status);
    return q;
  }

  async function load(force) {
    state.locale = detectLocale();
    state.token = token();
    if (!state.token) {
      state.authed = false;
      state.loading = false;
      return render();
    }
    if (!state.route.isWallet && !force) {
      return render();
    }
    var showSpinner = !state.authed || !dom.panel || !dom.panel.innerHTML;
    if (showSpinner) {
      state.loading = true;
      render();
    }
    var rid = ++state.rid;
    var tradeNo = state.route.tradeNo || readLastTopupTradeNo();
    var all = await Promise.all([
      safe(function () { return api("/api/v1/user/info"); }),
      safe(function () { return api("/api/v1/user/getSubscribe"); }),
      safe(function () { return api("/api/v1/user/comm/config"); }),
      safe(function () { return api("/api/v1/wallet-center/checkin/status"); }),
      safe(function () { return api("/api/v1/wallet-center/checkin/history" + historyQuery("checkin")); }),
      safe(function () { return api("/api/v1/wallet-center/topup/methods"); }),
      safe(function () { return api("/api/v1/wallet-center/topup/history" + historyQuery("topup")); }),
      safe(function () { return tradeNo ? api("/api/v1/wallet-center/topup/detail?trade_no=" + encodeURIComponent(tradeNo)) : Promise.resolve(null); }),
      safe(function () { return api("/api/v1/wallet-center/auto-renew/config"); }),
      safe(function () { return api("/api/v1/wallet-center/auto-renew/history" + historyQuery("renew")); })
    ]);
    if (rid !== state.rid) return;
    if (!all[0].ok) {
      state.authed = false;
      state.loading = false;
      return render();
    }
    state.authed = true;
    state.user = all[0].data;
    state.subscribe = all[1].ok ? all[1].data : null;
    state.comm = all[2].ok ? all[2].data : null;
    state.checkin = { status: all[3].ok ? all[3].data : null, history: all[4].ok ? all[4].data : null, error: all[3].ok ? null : all[3].error };
    state.topup = { methods: all[5].ok ? all[5].data : null, history: all[6].ok ? all[6].data : null, detail: all[7].ok ? all[7].data : null, error: all[5].ok ? null : all[5].error };
    state.renew = { config: all[8].ok ? all[8].data : null, history: all[9].ok ? all[9].data : null, error: all[8].ok ? null : all[8].error };
    if (state.topup.detail && state.topup.detail.order && /paid|expired|cancelled|refunded/i.test(state.topup.detail.order.status_label || "")) {
      try { sessionStorage.removeItem(LAST_TOPUP); } catch (error) {}
    }
    state.loading = false;
    render();
    scheduleTopupPoll();
  }

  function scheduleTopupPoll() {
    if (topupPollTimer) {
      clearTimeout(topupPollTimer);
      topupPollTimer = 0;
    }
    var order = state.topup && state.topup.detail && state.topup.detail.order;
    if (!order || !state.route.isWallet) return;
    if (/paid|expired|cancelled|refunded/i.test(order.status_label || "")) return;
    topupPollTimer = setTimeout(function () { load(true); }, 3000);
  }

  function sync() {
    state.locale = detectLocale();
    canonicalizeWalletHash();
    state.route = parseHash(location.hash);
    if (state.route.section) state.section = state.route.section;
    load(false);
  }

  function init() {
    canonicalizeWalletHash();
    state.route = parseHash(location.hash);
    if (state.route.section) state.section = state.route.section;
    ensureRoot();
    load(false);
    if (document.body) {
      var observer = new MutationObserver(function () {
        if (!state.route.isWallet) return;
        if (dom.root && !document.body.contains(dom.root)) document.body.appendChild(dom.root);
        schedulePlace();
      });
      observer.observe(document.body, { childList: true, subtree: true });
    }
    addEventListener("hashchange", sync);
    addEventListener("scroll", schedulePlace, true);
    addEventListener("resize", schedulePlace);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init, { once: true });
  } else {
    init();
  }
})();
