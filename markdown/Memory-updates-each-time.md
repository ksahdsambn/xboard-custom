# Memory Updates Each Time

## 文档用途

- 这个文件用于长期记录 `xboard-custom` 仓库内所有重要的代码更新、功能调整、部署流程调整和文档口径修正。
- 后续每次有新增功能、代码修复、部署脚本改动、1Panel 流程变化、主题或插件行为变化时，都应在这里追加一条记录。
- 这份文档的定位是“持续更新的变更记忆”，不是需求文档，也不是 Bug 清单替代品。

## 后续记录规则

- 每次更新尽量追加，不要覆盖旧记录。
- 每条记录建议包含：
  - 日期
  - 变更类型
  - 涉及文件或目录
  - 变更原因
  - 对部署、升级、回滚的影响
- 如果某次更新同时影响代码和文档，应同时记录两部分。

## 变更记录

### 2026-03-10 仓库与部署流程统一整理

#### 1. 代码托管方式统一为 overlay 模式

- 确认 `xboard-custom` 是自定义源码真源仓库，不再把“zip 上传”作为主发布方式。
- 当前长期推荐结构固定为：
  - `plugins/StripePayment/`
  - `plugins/BepusdtPayment/`
  - `plugins/WalletCenter/`
  - `theme/XboardCustom/`
  - `scripts/`
  - `markdown/`
- 官方仓库 `cedar2025/Xboard` 只作为运行底座与升级来源。

#### 2. 充值链路关键修复

- 修复 `plugins/WalletCenter/Services/TopupService.php`
  - 创建充值订单时补齐并持久化真实 `return_url`
  - `markStatus()` 改为事务加行锁，避免 `paid` 与 `cancelled/expired` 并发回调相互覆盖
- 修复 `plugins/WalletCenter/Services/TopupGatewayService.php`
  - 不再信任任意外部 `Referer`
  - 仅接受 `config('app.url')` 或当前请求主机对应来源作为回跳依据

#### 3. 主题部署路径修正

- 修复 `scripts/deploy-overlay.sh`
  - 自定义主题不再同步到根目录 `theme/XboardCustom`
  - 正确同步目标为 `storage/theme/XboardCustom`
  - 同步后自动清理残留的 `theme/XboardCustom`，避免主题优先级冲突
- 这一调整与当前 1Panel compose 挂载方式保持一致。

#### 4. 主题发布资产一致性修复

- 重建：
  - `theme/XboardCustom/assets/umi.js.gz`
  - `theme/XboardCustom/assets/umi.js.br`
- 确保它们与：
  - `theme/XboardCustom/assets/umi.js`
  保持一致，避免浏览器命中旧压缩副本。

#### 5. 文档同步修正

- 修正 `markdown/DEPLOY.md`
  - 路径统一为当前服务器实际站点目录 `/opt/1panel/www/sites/xboard/index`
  - 1Panel 脚本任务说明改为更稳的 Bash 版本
  - 补充“任务类型 / 用户 / 解释器 / 是否在容器中执行”的表单填写说明
- 重写 `markdown/代码托管方案.md`
  - 从旧的 `plugins-src/theme-src + zip 上传主流程` 切换为当前 overlay 主流程
- 重写 `markdown/Xboard 可升级扩展方案.md`
  - 对齐当前插件目录名、主题部署目标和升级策略
- 修正阶段文档中的错误路径：
  - `markdown/stage-01-baseline.md`
  - `markdown/stage-02-asset-strategy.md`
  - 统一将旧写法 `plugins/BEpusdtPayment/` 更正为实际运行目录 `plugins/BepusdtPayment/`

#### 6. 新增“按变更类型决定是否部署”的包装脚本

- 新增 `scripts/update-overlay-from-git.sh`
- 脚本职责：
  - 先执行 `git fetch`
  - 对比本地和远端提交差异
  - 如果本次更新只涉及 `markdown/` 等非运行时代码，则只更新仓库，不执行覆盖部署，不重启服务
  - 如果本次更新涉及 `plugins/` 或 `theme/`，则调用 `scripts/deploy-overlay.sh` 执行完整部署
  - 若官方底座更新后需要强制重放自定义层，可通过 `FORCE_DEPLOY=1` 强制执行

#### 7. 1Panel 计划任务行为优化

- `xboard-custom-sync` 任务调整为调用：
  - `scripts/update-overlay-from-git.sh`
- 优化后的目标：
  - 远端无更新时，直接结束
  - 只有文档更新时，不重启 `web` / `horizon`
  - 只有插件或主题更新时，才真正执行同步和重启
- `xboard-official-update` 任务应在官方底座更新完成后使用：
  - `FORCE_DEPLOY=1`
  - 重新叠加自定义层并刷新主题

#### 8. 已确认的 1Panel 运行根目录

- 当前服务器的 Xboard 实际运行目录确认为：
  - `/opt/1panel/www/sites/xboard/index`
- 判断依据：
  - 该目录下实际存在 `compose.yaml`
  - 存在 `.env`
  - 存在 `plugins/`
  - 存在 `storage/`
- 因此后续所有部署文档、计划任务脚本、`OFFICIAL_ROOT` 参数都应使用这个路径。

#### 9. 当前建议的 1Panel 脚本任务版本

- 自定义仓库更新任务：

```bash
set -euo pipefail
OFFICIAL_ROOT=/opt/1panel/www/sites/xboard/index /bin/bash /opt/xboard-custom/scripts/update-overlay-from-git.sh
```

- 官方仓库更新任务：

```bash
set -euo pipefail
cd /opt/1panel/www/sites/xboard/index
git pull --ff-only origin compose
docker compose pull
docker compose run --rm -T web php artisan xboard:update
docker compose up -d

FORCE_DEPLOY=1 OFFICIAL_ROOT=/opt/1panel/www/sites/xboard/index /bin/bash /opt/xboard-custom/scripts/update-overlay-from-git.sh
```

#### 10. 后续维护要求

- 从本条记录之后，凡是以下变更都要同步追加到本文件：
  - 插件功能新增或行为变化
  - WalletCenter 业务逻辑修复
  - 支付回调、幂等、路由、数据库迁移调整
  - 主题目录、发布资产、语言包更新
  - `scripts/` 下部署脚本或发布脚本调整
  - 1Panel 计划任务、部署路径、容器服务名变化
  - 与部署、升级、回滚相关的重要文档修订

### 2026-03-10 登录页语言与钱包浮层修复

#### 1. 登录页 RTL 语言切换异常修复

- 修复 `theme/XboardCustom/assets/i18n-extra.js`
- 问题现象：
  - 当登录页切换到 `ar-SA` 这类 RTL 语言后，登录卡片底部的语言切换入口会从右下角翻到左下角
  - 该状态下语言切换入口易出现无法继续点击的问题
- 修复方式：
  - 新增当前 hash 路径识别
  - 在 `#/login`、`#/register`、`#/forgot`、`#/forget`、`#/reset*`、`#/password*` 这类认证页面上，禁止把整页全局方向切换为 `rtl`
  - 仅在非认证页面保留全局 RTL 翻转
  - 新增 `hashchange` 监听，在登录页与已登录页面之间切换时重新应用方向设置

#### 2. 钱包浮层改为仅登录后可见

- 修复 `theme/XboardCustom/assets/wallet-center.js`
- 问题现象：
  - 登录页右下角会出现 WalletCenter 钱包浮层入口
  - 这与“登录前不显示钱包入口、登录后才显示”的预期不一致
- 修复方式：
  - 新增认证页面路径识别
  - 钱包浮层入口改为只有在“已确认登录态”且“当前不在认证页面”时才显示
  - 初始化和 hash 路由切换时，都会重新执行登录态确认，避免登录成功后入口不刷新或退出后入口残留

#### 3. 本次涉及文件

- `theme/XboardCustom/assets/i18n-extra.js`
- `theme/XboardCustom/assets/wallet-center.js`
- `markdown/Memory-updates-each-time.md`

### 2026-03-10 主题静态资源缓存修复

#### 1. 问题定位

- 在修复登录页语言切换和钱包浮层逻辑后，代码已经推送并执行了 `xboard-custom-sync`，但线上页面表现“毫无变化”。
- 实际排查结果是：
  - 线上 HTML 已在使用 `XboardCustom`
  - 但 `https://node.lokiflux.com/theme/XboardCustom/assets/wallet-center.js`
  - 与 `https://node.lokiflux.com/theme/XboardCustom/assets/i18n-extra.js`
    仍返回旧内容
  - 响应头显示 Cloudflare `cf-cache-status: HIT`
- 结论：
  - 本次不是业务修复失效
  - 而是 CDN/静态资源缓存导致浏览器继续拿到旧版主题脚本

#### 2. 修复方式

- 修复 `theme/XboardCustom/dashboard.blade.php`
- 为以下自定义主题静态资源追加基于 `filemtime(public_path(...))` 的版本参数：
  - `wallet-center.css`
  - `i18n-extra.js`
  - `umi.js`
  - `wallet-center.js`
- 这样在 `refreshCurrentTheme()` 重新发布主题资源后，资源 URL 会随文件时间变化，避免继续命中旧 CDN 缓存。

#### 3. 影响

- 后续只要这些主题静态文件发生变化并被重新发布，前端引用 URL 就会自动变化。
- 这样可以显著降低“代码已同步、页面无变化”的缓存误判概率。

#### 4. 本次涉及文件

- `theme/XboardCustom/dashboard.blade.php`
- `markdown/Memory-updates-each-time.md`

### 2026-03-11 登录页语言选择框视口适配修复

#### 1. 问题现象

- 登录页底部语言切换按钮展开后的语言列表始终向按钮上方弹出。
- 在 PC Web 和移动 Web 上，语言列表高度过高时会超出屏幕顶部。
- 超出屏幕的语言无法看到，也无法正常点击选择。

#### 2. 修复方式

- 修复 `theme/XboardCustom/assets/i18n-extra.js`
- 新增认证页专用的语言弹层补丁，不改动编译后的 `umi.js`。
- 在 `#/login`、`#/register`、`#/forgot`、`#/forget`、`#/reset*`、`#/password*` 页面上：
  - 识别语言下拉面板对应的 `n-dropdown-menu`
  - 为语言面板追加最大高度限制
  - 让超长语言列表在面板内部滚动，而不是继续向屏幕外溢出
  - 在桌面端和移动端都把弹层位置重新约束到可视区域内
  - 在窗口尺寸变化、路由切换、语言面板重新挂载时自动重新计算位置

#### 3. 预期结果

- 用户点击登录页语言按钮后，可以在当前屏幕内看到完整可滚动的语言列表。
- 无论默认语言是什么，弹出的语言面板都不会再因为超出屏幕而导致部分语言不可选。
- 修复仅作用于认证页语言面板，不影响站内其他下拉组件。

#### 4. 本次涉及文件

- `theme/XboardCustom/assets/i18n-extra.js`
- `markdown/Memory-updates-each-time.md`

#### 5. 二次定位修正

- 首次补丁已成功上线，但线上继续出现语言弹层顶部溢出。
- 进一步确认后发现，Naive UI 语言选择器真正控制定位的是上层 `v-binder-follower-container` 及其 `transform`，不是仅修改 `n-dropdown-menu` 本身就能生效。
- 因此补丁调整为：
  - 直接接管 follower 容器的 `position / inset / transform / left / top`
  - 同时限制语言面板高度并保留内部滚动
  - 避免保留原始“向上弹出”的 transform 导致弹层继续跑出屏幕

#### 6. 部署脚本问题定位与修复

- 线上继续无变化后，进一步排查确认：
  - GitHub 仓库中的 `theme/XboardCustom/assets/i18n-extra.js` 已经是第二版修复
  - 但线上运行目录仍可能保留旧主题文件
- 根因不是前端代码没写，而是 `scripts/update-overlay-from-git.sh` 存在部署逻辑缺陷：
  - 当自定义仓库本地 `HEAD` 与远端 `HEAD` 相同的时候，脚本会直接退出
  - 即使传入 `FORCE_DEPLOY=1`，也不会真正执行 `deploy-overlay.sh`
  - 这会导致“仓库代码已是最新，但运行目录主题未被重新覆盖”时，重复执行更新任务仍然无效
- 修复方式：
  - 调整 `scripts/update-overlay-from-git.sh`
  - 当仓库已是最新但 `FORCE_DEPLOY=1` 时，仍然强制执行一次 overlay 部署
  - 用于修复这类“代码已拉取但运行目录未刷新”的状态漂移问题

#### 7. 真实弹层结构复核与第三次修正

- 使用无头 Edge 直接连接线上登录页后，确认语言选择器的真实结构不是 `n-dropdown-menu / n-popover` 主链路，而是：
  - `v-binder-follower-content`
  - `n-base-select-menu`
  - `n-base-select-menu-option-wrapper`
- 线上可见的溢出根因是：
  - `v-binder-follower-content` 使用 `transform: matrix(..., ..., ..., ..., x, y)` 把菜单整体移动到了视口上方
  - `n-base-select-menu` 本身没有高度限制，也没有内部滚动
- 因此第三次修正改为：
  - 直接匹配 `n-base-select-menu`
  - 直接接管 `v-binder-follower-content`
  - 用底部语言按钮的实际位置重新计算 `left / top`
  - 给 `n-base-select-menu-option-wrapper` 注入 `max-height + overflow-y:auto`
- 让语言列表在菜单内部滚动，而不是继续整体溢出屏幕

### 2026-03-11 登录页语言面板桌面端滚轮关闭修复

#### 1. 问题现象

- 在 `#/login` 页面中，语言面板已经可以完整显示，但桌面端使用鼠标滚轮滚动语言列表时，面板会被上层定位逻辑重新带偏。
- 点击右侧滚动条或拖动滚动条时，也会触发同样的问题，用户会感觉语言面板突然消失或跳走。
- 移动端触摸滚动正常，问题集中在桌面端的 `wheel / mousedown / pointerdown / scroll` 交互链路。

#### 2. 根因定位

- `n-base-select-menu-option-wrapper` 内部滚动时，Naive UI 的 follower 会再次执行自身的定位更新。
- 前一版补丁只负责把面板首次钉回认证页语言按钮附近，没有继续接管桌面端滚轮和滚动条交互后的二次定位。
- 结果就是：
  - 列表可以滚动；
  - 但滚动一发生，follower 又被上层逻辑挪回错误位置，桌面端看起来像“菜单消失”。

#### 3. 修复方式

- 继续修复 `theme/XboardCustom/assets/i18n-extra.js`
- 对认证页语言面板的内部滚动层 `n-base-select-menu-option-wrapper` 增加交互守卫：
  - `wheel` 时阻止事件继续向上冒泡，并立即重新同步面板位置；
  - `mousedown` / `pointerdown` 时阻止事件继续向上冒泡，避免点击滚动条被外层当成关闭触发；
  - `scroll` 时重新同步面板位置，确保滚动后仍然贴在语言按钮附近。
- 同时补强 `MutationObserver` 的匹配范围，纳入：
  - `v-binder-follower-content`
  - `n-base-select-menu`

#### 4. 本次涉及文件

- `theme/XboardCustom/assets/i18n-extra.js`
- `markdown/Memory-updates-each-time.md`

### 2026-03-11 登录页语言面板桌面端滚动闪屏修复

#### 1. 问题现象

- 登录页语言面板在桌面端已经不再溢出视口，但用户滚动滚轮或操作滚动条后，面板仍会出现明显横向跳动。
- 语言面板一旦横跳，鼠标很容易离开原本的滚动区域，用户会感觉面板在“闪屏”或“突然消失”，导致难以继续选择较靠后的语言。

#### 2. 根因定位

- 旧补丁在每次 `wheel / scroll` 后都会重新执行 `fitAuthLocaleDropdown()`，这是必要的。
- 但宽度计算错误地依赖了菜单容器当前的 `scrollWidth / width`。
- 当桌面端滚动条出现后，容器宽度会被滚动条和当前布局状态放大；下一轮同步又把这个放大的宽度当作新的基准，导致：
  - 面板越滚越宽；
  - 右对齐定位下，面板不断向左跳；
  - 用户鼠标与菜单区域错位，看起来像“闪屏”。

#### 3. 修复方式

- 继续修复 `theme/XboardCustom/assets/i18n-extra.js`
- 新增认证页语言面板的稳定宽度计算逻辑：
  - 不再使用菜单容器当前宽度作为基准；
  - 改为测量真实选项文本的自然宽度；
  - 再叠加桌面端滚动条预留宽度，得到稳定面板宽度。
- 同时补强桌面端滚动交互守卫：
  - `wheel` 在捕获阶段拦截并重新同步定位；
  - `mousedown / pointerdown` 仅在点击滚动层本身时拦截，避免滚动条交互被外层误判；
  - 为滚动层补充 `overflow-x:hidden` 与 `scrollbar-gutter: stable`，减少桌面端滚动条出现时的布局抖动。

#### 4. 预期结果

- 用户在 `#/login` 页面打开语言面板后，滚动列表时面板宽度和 `left/top` 保持稳定。
- 即使滚动到较靠后的语言，鼠标也不会因为面板横跳而脱离可交互区域。
- 用户可以正常选择如 `Suomi`、`Norsk Bokmål` 等较靠后的语言项。

#### 5. 本次涉及文件

- `theme/XboardCustom/assets/i18n-extra.js`
- `markdown/Memory-updates-each-time.md`

### 2026-03-11 认证页语言选择器改为常驻展开列表

#### 1. 需求调整

- 继续收到认证页语言选择器闪屏反馈后，需求改为不再保留“点击按钮 -> 展开下拉”的交互。
- 新要求是：在 `#/login` 等认证页中，语言列表直接展开显示所有语言，去掉滑动选择，效果参考登录后面板中“语言列表已经展开”的状态。

#### 2. 最终实现

- 停止在认证页使用任何原生 Naive UI 语言下拉交互。
- 在 `theme/XboardCustom/assets/i18n-extra.js` 中：
  - 识别认证页底部原生语言按钮；
  - 直接隐藏原生按钮，禁止用户再触发原生下拉；
  - 渲染一个常驻展开的自定义语言列表面板；
  - 面板直接使用 `window.settings.i18n` 和现有 locale 名称映射生成全部语言项；
  - 点击语言项后直接写入 `VUE_NAIVE_LOCALE` / `locale` / `lang`，然后刷新页面应用新语言；
  - 同时强制隐藏可能已出现的原生 `n-base-select-menu / n-dropdown-menu`。

#### 3. 布局策略

- 桌面端：
  - 认证页右上角固定显示单列完整语言列表；
  - 不再需要点击展开；
  - 不再存在下拉滚动容器。
- 窄屏或较矮视口：
  - 自动切换为双列固定列表；
  - 仍然一次性显示全部语言；
  - 不使用内部滚动，避免再次回到“滑动选择”的问题。

#### 4. 运行态回归

- 使用 Playwright 将本地最新 `i18n-extra.js` 注入线上 `https://node.lokiflux.com/#/login` 回归。
- 桌面端回归确认：
  - 原生语言按钮已隐藏；
  - 自定义语言列表常驻显示；
  - 原生 `n-base-select-menu` 不再可见；
  - 可以直接点击 `Suomi`，并正确写入 `VUE_NAIVE_LOCALE` 与 `html lang`。
- 窄屏回归确认：
  - 自定义语言列表自动切换为双列；
  - 仍为常驻展开状态；
  - 原生下拉仍不可见。

#### 5. 本次涉及文件

- `theme/XboardCustom/assets/i18n-extra.js`
- `markdown/Memory-updates-each-time.md`

### 2026-03-11 认证页语言选择器替换为自定义固定面板

#### 1. 最终结论

- 多轮修复后继续闪屏的根本原因是：登录页原生语言选择器建立在 Naive UI 的 portal + follower + transition 机制之上。
- 即使依次压制了 `n-base-select-menu`、`v-binder-follower-content`、`v-binder-follower-container` 的宽度与动画，认证页下的原生选择器仍然存在被组件内部重新接管的风险。
- 继续在 follower 链路上打补丁，属于“追症状”，稳定性不足。

#### 2. 最终修复方式

- 停止在认证页继续使用原生语言下拉弹层。
- 在 `theme/XboardCustom/assets/i18n-extra.js` 中：
  - 拦截认证页底部原生语言按钮的 `pointerdown / mousedown / click`；
  - 阻止 Naive UI 原生语言弹层打开；
  - 改为挂载一个自定义固定定位语言面板；
  - 面板直接使用 `window.settings.i18n` 与现有 locale 名称映射渲染；
  - 选择语言后直接写入 `VUE_NAIVE_LOCALE`，同步 `locale/lang`，然后刷新页面应用新语言。
- 同时如果原生 follower 已经出现，则在认证页下强制隐藏对应原生语言弹层，避免和自定义面板并存。

#### 3. 运行态回归

- 使用 Playwright 将本地最新 `i18n-extra.js` 注入线上登录页 `https://node.lokiflux.com/#/login` 回归。
- 回归结果确认：
  - 点击语言按钮后打开的是自定义固定面板；
  - 原生 `n-base-select-menu` 不再可见；
  - 自定义面板滚轮滚动正常，`scrollTop` 正常变化；
  - 面板 `transition: none`、`opacity: 1`；
  - 可成功选择 `Suomi`，并正确写入 `VUE_NAIVE_LOCALE` 与 `html lang`。

#### 4. 本次涉及文件

- `theme/XboardCustom/assets/i18n-extra.js`
- `markdown/Memory-updates-each-time.md`

### 2026-03-11 登录页语言面板外层 follower 容器动画修复

#### 1. 问题现象

- 即使认证页语言面板本身已经固定宽度、固定位置，用户仍反馈滚动时会“整块跳到左侧并半透明闪烁”。
- 该现象说明问题不只发生在 `n-base-select-menu` 或 `v-binder-follower-content`，而是更外层的跟随容器仍在参与动画。

#### 2. 根因定位

- 继续用线上 DOM 结构复核后确认：`n-base-select-menu` 的祖先层 `v-binder-follower-container` 仍然保留 `transition: all`。
- 当 Naive UI 在滚动交互期间重写 follower 容器的定位/transform 时，即使内部内容层已经被手动钉住，外层容器的过渡动画仍会导致：
  - 整个语言面板向左飘；
  - 面板出现半透明动画态；
  - 用户看到“闪屏”和“跳屏”。

#### 3. 修复方式

- 继续修复 `theme/XboardCustom/assets/i18n-extra.js`
- 新增认证页语言面板专用容器类 `xc-auth-locale-container`，直接接管 `v-binder-follower-container`。
- 对以下三层统一强制关闭动画与过渡：
  - `v-binder-follower-container`
  - `v-binder-follower-content`
  - `n-base-select-menu`
- 脚本运行时为 container / follower / panel / menu / view 统一写入：
  - `transform: none`
  - `transition: none`
  - `animation: none`
  - `opacity: 1`
- 同时把外层 container 固定为 `position: fixed; inset: 0; pointer-events: none; z-index: 3200`，避免 Naive UI 在桌面端滚动期间再用祖先层动画干扰语言面板。

#### 4. 运行态回归

- 使用 Playwright 将本地最新 `i18n-extra.js` 注入线上登录页 `https://node.lokiflux.com/#/login` 回归。
- 回归结果确认：
  - `n-base-select-menu`、`v-binder-follower-content`、`v-binder-follower-container` 的计算样式均为 `transition: none`；
  - 三层均保持 `transform: none` 与 `opacity: 1`；
  - 滚轮后语言面板位置稳定；
  - 仍可成功选择 `Suomi`。

#### 5. 本次涉及文件

- `theme/XboardCustom/assets/i18n-extra.js`
- `markdown/Memory-updates-each-time.md`

### 2026-03-11 登录页语言面板最终回归与 1Panel 更新命令确认

#### 1. 最终问题确认

- 继续复核后确认，登录页语言选择框“滚一下就闪屏/跳屏”的直接表现，不只是纵向滚动后重新定位。
- 真正影响可用性的是：语言面板在桌面端滚轮或滚动条交互后会发生横向跳动，导致鼠标很容易脱离面板可交互区域，用户看起来会误以为下拉框突然闪掉，实际上是 follower 宽度和位置在抖动。

#### 2. 最终修复确认

- 最终保留的修复仍收敛在 `theme/XboardCustom/assets/i18n-extra.js`。
- 这次确认有效的修复点是：
  - 为认证页语言面板新增“稳定宽度”计算，不再使用菜单容器当前 `scrollWidth / width` 作为下一轮定位基准。
  - 改为按真实语言项文本自然宽度 + 桌面端滚动条预留宽度来计算面板宽度，避免滚轮后面板越滚越宽、越滚越向左跳。
  - 保留认证页下拉层的 `wheel / mousedown / pointerdown / scroll` 本地交互守卫，并为滚动层补充 `overflow-x:hidden` 与 `scrollbar-gutter: stable`，减少滚动条出现时的布局抖动。

#### 3. 运行态回归

- 使用 Playwright 将本地修复后的 `i18n-extra.js` 注入线上登录页 `https://node.lokiflux.com/#/login` 进行回归。
- 回归结果确认：
  - 滚轮后语言列表 `scrollTop` 正常变化；
  - 面板宽度保持稳定，不再横向跳动；
  - 滚动后可以成功选择 `Suomi`；
  - `VUE_NAIVE_LOCALE` 与 `document.documentElement.lang` 都正确切换到 `fi-FI`。

#### 4. 1Panel 更新命令确认

- 当前 1Panel 服务器计划任务使用以下命令从 GitHub 拉取并部署 overlay：
```bash
set -euo pipefail
OFFICIAL_ROOT=/opt/1panel/www/sites/xboard/index /bin/bash /opt/xboard-custom/scripts/update-overlay-from-git.sh
```
- 该命令会在仓库存在新的 `plugins/` 或 `theme/` 运行时改动时完成拉取、overlay 同步、服务重启和主题静态资源刷新。
- 如后续遇到“GitHub 无新提交，但需要强制重发当前 overlay” 的场景，可在同一命令前额外设置 `FORCE_DEPLOY=1`。

#### 5. 本次涉及文件

- `theme/XboardCustom/assets/i18n-extra.js`
- `markdown/Memory-updates-each-time.md`
- `scripts/update-overlay-from-git.sh`

### 2026-03-11 登录页语言面板半透明横跳修复

#### 1. 问题现象

- 在确认线上已经加载最终宽度修复版脚本后，登录页语言面板仍有用户反馈“滚动时跳到左侧、变成半透明、难以点击”的问题。
- 该现象与前面的“宽度越滚越宽”不同，表现更像弹层在滚动交互期间触发了过渡动画。

#### 2. 根因定位

- 线上实测时，认证页语言 follower 虽然已经被重新定位为稳定的 `left / top / width`，但其计算样式仍然保留 `transition: all`。
- 在桌面端滚轮或滚动条交互期间，`left / top / width / opacity` 一旦参与过渡，浏览器会把语言面板短暂显示成“移动中的半透明层”，用户视觉上就会看到：
  - 弹层向左飘；
  - 透明度变化；
  - 像闪屏一样难以继续选择语言。

#### 3. 修复方式

- 继续修复 `theme/XboardCustom/assets/i18n-extra.js`
- 为认证页语言面板相关节点强制关闭过渡与动画：
  - `v-binder-follower-content`
  - `n-base-select-menu`
  - `n-base-select-menu-option-wrapper`
- 同时在脚本中为 follower / panel / menu / view 直接写入：
  - `transition: none`
  - `animation: none`
  - `opacity: 1`
- 这样即使滚动期间继续执行定位同步，弹层也不会再以“半透明动画态”横向飘移。

#### 4. 运行态回归

- 使用 Playwright 将本地最新 `i18n-extra.js` 注入线上 `https://node.lokiflux.com/#/login` 进行回归。
- 回归结果确认：
  - 语言面板 `transition` 已变为 `none`；
  - `opacity` 保持 `1`；
  - 滚轮后 `left` 和 `width` 保持稳定；
  - 仍可成功选择 `Suomi`，并正确写入 `VUE_NAIVE_LOCALE`。

#### 5. 本次涉及文件

- `theme/XboardCustom/assets/i18n-extra.js`
- `markdown/Memory-updates-each-time.md`
### 2026-03-11 Auth login locale trigger UX correction

#### 1. Context

- The previous custom auth-page locale patch solved the follower flicker path, but it rendered the expanded locale list permanently on screen.
- The required behavior is different: keep the original locale button in its original position, and only expand the full locale list after clicking that button.

#### 2. Implementation

- Updated `theme/XboardCustom/assets/i18n-extra.js`.
- Removed the auth-page always-open flow from `syncAuthLocaleDropdowns()`.
- Bound the original auth locale button to a custom toggle flow that suppresses the native Naive UI dropdown before it can open.
- Kept the original trigger button visible in place.
- Reworked custom panel positioning so it opens next to the original trigger and dynamically increases column count from the actual space above or below the trigger.
- The expanded panel now shows all locales without internal scrolling, and closes on second click or outside click.

#### 3. Verification

- `node --check theme/XboardCustom/assets/i18n-extra.js`
- Playwright injection regression on `https://node.lokiflux.com/#/login`
- Confirmed:
  - the custom panel is hidden on initial load
  - clicking the original locale button opens the expanded locale panel
  - the native dropdown remains hidden
  - the panel closes on second click or outside click
  - selecting `Suomi` still updates `VUE_NAIVE_LOCALE` and `document.documentElement.lang`
  - desktop `1600x900` and mobile `390x844` both open without internal scrolling

#### 4. Files

- `theme/XboardCustom/assets/i18n-extra.js`
- `markdown/Memory-updates-each-time.md`
