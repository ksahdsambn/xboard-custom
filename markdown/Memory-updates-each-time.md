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
