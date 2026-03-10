# 架构记录

## 用途

记录本次开发过程中新增的每个代码文件及其职责，便于后续升级、回滚和交接。

## 记录规则

- 每新增一个代码文件，追加一条记录。
- 记录字段至少包含：阶段、文件路径、所属资产、职责说明。
- 仅记录代码文件，不记录纯说明文档。

## 新增代码文件清单

- 阶段 3 | `plugins/StripePayment/config.json` | `StripePayment` | Stripe 一次性支付插件元数据，声明支付插件代码、类型和版本。
- 阶段 3 | `plugins/StripePayment/Plugin.php` | `StripePayment` | Stripe Checkout 支付插件主类，负责支付方式注册、后台配置表单、Checkout 会话创建、webhook 验签与成功支付映射。
- 阶段 4 | `plugins/BepusdtPayment/config.json` | `BEpusdtPayment` | BEpusdt 一次性支付插件元数据，声明支付插件代码、类型和版本。
- 阶段 4 | `plugins/BepusdtPayment/Plugin.php` | `BEpusdtPayment` | BEpusdt 支付插件主类，负责支付方式注册、后台配置表单、下单签名、回调验签、状态分流与幂等开通。

## 阶段 3 当前结论

- StripePayment 最终不保留核心目录补丁。
- 阶段 3 的 Stripe 支付定制点已完全收敛到 `plugins/StripePayment/`。

## 阶段 4 当前结论

- BEpusdtPayment 最终不保留核心目录补丁。
- 阶段 4 的 BEpusdt 支付定制点已完全收敛到 `plugins/BepusdtPayment/`。
- 运行时目录使用 `BepusdtPayment`，原因是 `PluginManager` 对插件代码 `bepusdt_payment` 执行 `Str::studly()` 后的解析结果即为该目录名。

- 阶段 5 | `plugins/WalletCenter/config.json` | `WalletCenter` | WalletCenter 插件元数据与三类子能力独立开关/基础配置定义。
- 阶段 5 | `plugins/WalletCenter/Plugin.php` | `WalletCenter` | WalletCenter 插件主类，负责承载阶段 5 之后的统一功能资产入口。
- 阶段 5 | `plugins/WalletCenter/Support/WalletCenterFeature.php` | `WalletCenter` | 定义签到、充值、自动续费三类能力的配置键、前台入口、记录入口、执行入口与表边界。
- 阶段 5 | `plugins/WalletCenter/Services/WalletCenterConfigService.php` | `WalletCenter` | 统一读取 WalletCenter 插件配置、默认值与子能力开关状态。
- 阶段 5 | `plugins/WalletCenter/Services/WalletCenterPaymentChannelService.php` | `WalletCenter` | 读取当前已启用支付实例列表，为后续充值功能复用支付通道做准备。
- 阶段 5 | `plugins/WalletCenter/Services/WalletCenterManifestService.php` | `WalletCenter` | 输出 WalletCenter 骨架清单与三类能力边界描述。
- 阶段 5 | `plugins/WalletCenter/Controllers/BaseController.php` | `WalletCenter` | WalletCenter 控制器基类，统一处理子能力开关校验与骨架响应封装。
- 阶段 5 | `plugins/WalletCenter/Controllers/CheckinController.php` | `WalletCenter` | 签到状态、执行占位与签到历史入口控制器。
- 阶段 5 | `plugins/WalletCenter/Controllers/TopupController.php` | `WalletCenter` | 充值通道列表、充值创建占位、充值详情/历史与通知占位入口控制器。
- 阶段 5 | `plugins/WalletCenter/Controllers/AutoRenewController.php` | `WalletCenter` | 自动续费配置、设置写入占位与执行历史入口控制器。
- 阶段 5 | `plugins/WalletCenter/Controllers/AdminController.php` | `WalletCenter` | WalletCenter 后台总览与三类业务记录入口控制器。
- 阶段 5 | `plugins/WalletCenter/Models/CheckinLog.php` | `WalletCenter` | 签到日志模型，对应 `wallet_center_checkin_logs`。
- 阶段 5 | `plugins/WalletCenter/Models/TopupOrder.php` | `WalletCenter` | 充值订单模型，对应 `wallet_center_topup_orders`，与核心 `v2_order` 解耦。
- 阶段 5 | `plugins/WalletCenter/Models/AutoRenewSetting.php` | `WalletCenter` | 自动续费设置模型，对应 `wallet_center_auto_renew_settings`。
- 阶段 5 | `plugins/WalletCenter/Models/AutoRenewRecord.php` | `WalletCenter` | 自动续费执行记录模型，对应 `wallet_center_auto_renew_records`。
- 阶段 5 | `plugins/WalletCenter/Commands/AutoRenewScanCommand.php` | `WalletCenter` | 自动续费扫描命令骨架，为阶段 9 调度注册预留执行入口。
- 阶段 5 | `plugins/WalletCenter/routes/api.php` | `WalletCenter` | WalletCenter API 路由骨架，统一挂载签到、充值、自动续费和后台记录入口。
- 阶段 5 | `plugins/WalletCenter/database/migrations/2026_03_09_000000_create_wallet_center_checkin_logs_table.php` | `WalletCenter` | 创建签到日志表 `wallet_center_checkin_logs`。
- 阶段 5 | `plugins/WalletCenter/database/migrations/2026_03_09_000001_create_wallet_center_topup_orders_table.php` | `WalletCenter` | 创建充值订单表 `wallet_center_topup_orders`。
- 阶段 5 | `plugins/WalletCenter/database/migrations/2026_03_09_000002_create_wallet_center_auto_renew_settings_table.php` | `WalletCenter` | 创建自动续费设置表 `wallet_center_auto_renew_settings`。
- 阶段 5 | `plugins/WalletCenter/database/migrations/2026_03_09_000003_create_wallet_center_auto_renew_records_table.php` | `WalletCenter` | 创建自动续费执行记录表 `wallet_center_auto_renew_records`。
- 阶段 6 | `plugins/WalletCenter/Services/CheckinService.php` | `WalletCenter` | 每日签到正式服务，负责签到状态、奖励区间、随机发奖、余额入账事务、历史记录与后台记录读取。
- 阶段 7 | `plugins/WalletCenter/Services/TopupGatewayService.php` | `WalletCenter` | 余额充值网关适配服务，复用已启用 Stripe / BEpusdt 支付插件，生成充值专用通知/返回地址并校验充值回调。
- 阶段 7 | `plugins/WalletCenter/Services/TopupService.php` | `WalletCenter` | 余额充值正式服务，负责金额范围校验、充值订单创建、前后台记录查询、通知状态流转与余额入账幂等控制。
- 阶段 8 | `plugins/WalletCenter/Services/AutoRenewService.php` | `WalletCenter` | 余额自动续费正式服务，负责当前订阅周期解析、续费金额解析、启停配置快照、扫描执行、失败/跳过记录和余额扣款续期。

## 阶段 5 当前结论

- WalletCenter 最终不保留核心目录补丁。
- 阶段 5 的 WalletCenter 定制点已完全收敛到 `plugins/WalletCenter/`。
- WalletCenter 已通过独立表 `wallet_center_topup_orders`、`wallet_center_auto_renew_settings`、`wallet_center_auto_renew_records` 与核心普通订阅订单体系解耦。
- WalletCenter 只读取已启用支付实例清单，不接管核心普通订阅支付回调链。
- WalletCenter 插件启用后，三类子能力默认仍保持关闭状态，避免阶段 5 骨架提前暴露未完成业务。

## 阶段 6 当前结论

- 阶段 6 的签到正式逻辑仍完全收敛在 `plugins/WalletCenter/`，未引入核心目录补丁。
- WalletCenter 每日签到通过插件内事务与核心 `UserService::addBalance()` 组合完成余额原子入账，未复制核心余额逻辑。
- 同日重复签到通过“用户行锁 + 成功记录检查”实现幂等保护。
- 用户签到历史与后台签到记录均复用 `wallet_center_checkin_logs`，并在 `meta` 中保留入账前后余额与请求上下文。
- 为避免 `Octane + SQLite` 在长连接下读取到外部变更前的旧状态，阶段 6 运行时验证在每次外部修改配置后均通过重启 `xboard-stage3-web` 容器重新建立连接。

## 阶段 7 当前结论

- 阶段 7 的充值正式逻辑仍完全收敛在 `plugins/WalletCenter/`，未引入核心目录补丁。
- WalletCenter 充值继续使用独立表 `wallet_center_topup_orders` 承载订单、状态和记录查询，与核心普通订阅订单体系保持解耦。
- WalletCenter 充值通过 `TopupGatewayService` 复用已启用支付实例，但使用独立通知地址 `/api/v1/wallet-center/topup/notify/{method}/{uuid}` 与独立回调校验逻辑，不接管核心普通订阅支付回调链。
- 充值成功只通过核心 `UserService::addBalance()` 增加余额，不触发订阅开通或订阅变更逻辑。
- 充值入账通过“充值订单锁 + 用户锁 + 状态检查”实现幂等保护，重复成功回调不会重复增加余额。
- 阶段 7 运行时验证继续采用“外部修改 SQLite 数据后重启 `xboard-stage3-web` 容器”的规范；BEpusdt 下单链路使用容器内临时 mock 服务完成请求结构与回调链验证。

## 阶段 8 当前结论

- 阶段 8 的自动续费正式逻辑仍完全收敛在 `plugins/WalletCenter/`，未引入核心目录补丁。
- WalletCenter 自动续费继续使用独立表 `wallet_center_auto_renew_settings` 与 `wallet_center_auto_renew_records` 承载配置快照、扫描计划和执行记录，与核心普通订阅订单体系保持解耦。
- 自动续费只面向用户当前有效且有限期的订阅；续费周期通过最近已完成核心订阅订单解析，续费金额通过当前计划价格解析。
- 自动续费执行前必须先检查核心未完成订单；若存在待支付或处理中订单，则仅记录 `pending_order_exists`，不会创建替代支付链路。
- 自动续费扣款只复用核心 `UserService::addBalance()` 完成余额原子扣减，续期逻辑在插件内同步当前计划属性并延长 `expired_at`，不修改核心 `OrderService`。
- 自动续费成功后，用户的下一次扫描时间会移动到新的到期窗口；重复扫描不会对同一成功续费再次扣款或再次延期。

## 阶段 9 当前结论

- 阶段 9 的计划任务接入仍完全收敛在 `plugins/WalletCenter/`，未引入核心目录补丁。
- `plugins/WalletCenter/Plugin.php` 已承担 WalletCenter 自动续费计划任务注册职责，并仅在插件启用且 `auto_renew_enabled = true` 时注册调度。
- WalletCenter 计划任务以 `wallet-center:auto-renew-scan --limit=100 --due-only` 的形式注册到系统调度器，频率固定为每分钟一次。
- 调度扫描范围已收紧为 `next_scan_at <= now()` 或 `next_scan_at is null` 的设置，避免每轮扫描遍历全部已启用自动续费用户。
- 重复调度保护由三层组成：调度器 `onOneServer()`、`withoutOverlapping(10)`，设置/用户行锁，以及成功/失败/跳过后的 `next_scan_at` 推进。
- 异常记录退避规则已内聚到 `plugins/WalletCenter/Services/AutoRenewService.php`：余额不足与待支付订单 5 分钟后重试，运行时错误与不可续费上下文 30 分钟后重试。
## 阶段 10（补充记录）

### 新增代码文件

- 阶段 10 | `plugins/StripePayment/Controllers/AdminController.php` | `StripePayment` | Stripe 后台总览控制器，提供插件级后台状态与配置总览接口。
- 阶段 10 | `plugins/StripePayment/Services/AdminOverviewService.php` | `StripePayment` | 聚合 Stripe 支付实例、配置定义、回调地址、启用状态与后台管理入口，并对敏感配置做脱敏输出。
- 阶段 10 | `plugins/StripePayment/routes/api.php` | `StripePayment` | 注册 Stripe 后台总览接口路由。
- 阶段 10 | `plugins/BepusdtPayment/Controllers/AdminController.php` | `BEpusdtPayment` | BEpusdt 后台总览控制器，提供插件级后台状态与配置总览接口。
- 阶段 10 | `plugins/BepusdtPayment/Services/AdminOverviewService.php` | `BEpusdtPayment` | 聚合 BEpusdt 支付实例、配置定义、回调地址、启用状态与后台管理入口，并对敏感配置做脱敏输出。
- 阶段 10 | `plugins/BepusdtPayment/routes/api.php` | `BEpusdtPayment` | 注册 BEpusdt 后台总览接口路由。
- 阶段 10 | `plugins/WalletCenter/Services/WalletCenterAdminOverviewService.php` | `WalletCenter` | 聚合 WalletCenter 后台配置分组、功能状态、可用通道、业务摘要与资金活动流，形成阶段 10 总览输出。

### 阶段 10 职责调整

- 阶段 10 | `plugins/WalletCenter/Controllers/AdminController.php` | `WalletCenter` | 新增后台总览、配置读取、配置保存接口，并为签到/充值/自动续费后台记录接口补充摘要输出。
- 阶段 10 | `plugins/WalletCenter/Services/WalletCenterConfigService.php` | `WalletCenter` | 新增配置分组读取与按键读取能力，并将配置保存调整为“读取现有配置后合并写入”，避免局部提交覆盖其它配置项。
- 阶段 10 | `plugins/WalletCenter/Services/CheckinService.php` | `WalletCenter` | 补充签到后台摘要统计能力。
- 阶段 10 | `plugins/WalletCenter/Services/TopupService.php` | `WalletCenter` | 补充充值后台摘要统计能力。
- 阶段 10 | `plugins/WalletCenter/Services/AutoRenewService.php` | `WalletCenter` | 补充自动续费后台摘要统计能力，并对失败原因文案输出提供统一入口。
- 阶段 10 | `plugins/WalletCenter/Models/TopupOrder.php` | `WalletCenter` | 为后台记录输出追加状态标签、资金活动类型、失败原因与失败说明字段。
- 阶段 10 | `plugins/WalletCenter/Models/AutoRenewRecord.php` | `WalletCenter` | 为后台记录输出追加状态标签、资金活动类型与原因说明字段。
- 阶段 10 | `plugins/WalletCenter/routes/api.php` | `WalletCenter` | 注册 WalletCenter 后台总览与后台配置接口路由。

### 阶段 10 当前结论

- 阶段 10 的后台配置与记录体系仍全部收敛在插件目录内，未修改核心业务代码。
- `StripePayment` 与 `BepusdtPayment` 的后台配置写入继续复用核心支付实例管理入口，本阶段仅新增插件侧后台总览与状态聚合。
- `WalletCenter` 在插件内补齐后台配置读取/保存、记录摘要、失败原因与资金活动分类输出，形成独立的后台配置与记录体系。
## 阶段 11

### 新增代码文件

- 阶段 11 | `theme/XboardCustom/config.json` | `XboardCustom` | 自定义主题元数据与可配置项定义，确保主题可被系统识别并独立切换
- 阶段 11 | `theme/XboardCustom/dashboard.blade.php` | `XboardCustom` | 自定义主题入口模板，保留官方 `umi.js` 加载顺序并额外挂载钱包中心样式与脚本
- 阶段 11 | `theme/XboardCustom/assets/wallet-center.css` | `XboardCustom` | 钱包中心浮动入口、全屏浮层、状态卡片、历史列表和移动端适配样式
- 阶段 11 | `theme/XboardCustom/assets/wallet-center.js` | `XboardCustom` | 钱包中心前端注入脚本，负责入口渲染、登录态识别、WalletCenter API 调用、充值跳转和自动续费状态展示

### 阶段 11 当前结论

- 阶段 11 的所有定制均收敛在 `theme/XboardCustom/`，未修改 `app/`、`routes/`、`plugins/` 等核心业务目录
- 自定义主题继续复用官方 `theme/Xboard/assets/umi.js` 作为主前端运行时，自定义能力通过独立注入层叠加，降低后续升级冲突
- 钱包中心入口最终采用“官方已存在 hash 路由 + `xc_wallet=1` 查询参数”承载浮层，原因是官方 SPA 会把未知 `#/wallet` 改写为 `#/404`
- 切换回系统主题 `Xboard` 后，首页不再引用 `wallet-center.css`、`wallet-center.js` 和 `XboardCustom` 路径资源，主题污染风险已隔离
- 运行态验证继续受到 `Octane + SQLite` 外部修改可见性影响；阶段 11 已通过重启 `xboard-stage3-web` 容器完成主题切换状态同步
## 阶段 12

### 新增代码文件

- 阶段 12 | `theme/XboardCustom/assets/i18n-extra.js` | `XboardCustom` | 统一承载 13 种新增语言的前端文案扩展、钱包中心文案、locale 名称映射以及 `ar-SA` RTL 方向辅助逻辑。

### 阶段 12 职责调整

- 阶段 12 | `theme/XboardCustom/dashboard.blade.php` | `XboardCustom` | 将主题层语言列表从原 7 种扩展到 20 种，并在官方 `umi.js` 前注入 `i18n-extra.js`，使新增语言通过主题扩展层加载。
- 阶段 12 | `theme/XboardCustom/assets/umi.js` | `XboardCustom` | 继续复用官方前端运行时作为基线，仅在主题层补丁中扩展 locale 装载逻辑、合并新增语言名称映射，并为缺少 Naive UI locale pack 的新增语言提供 `en-US` fallback。
- 阶段 12 | `theme/XboardCustom/assets/wallet-center.js` | `XboardCustom` | 钱包中心前端脚本改为读取 `window.xboardCustomI18n.wallet`，按当前 locale 渲染钱包、签到、充值、自动续费等入口文案，并同步 RTL 状态。
- 阶段 12 | `theme/XboardCustom/assets/wallet-center.css` | `XboardCustom` | 为钱包悬浮入口和弹层补充 RTL 对齐规则，确保 `ar-SA` 下入口位置、文本方向和通知堆栈方向正确。

### 阶段 12 当前结论

- 阶段 12 的多语言扩展仍然全部收敛在 `theme/XboardCustom/` 主题目录内，未修改核心 Laravel 业务代码、插件代码和官方主题目录。
- `theme/XboardCustom/assets/umi.js` 的可靠做法是以 `theme/Xboard/assets/umi.js` 为可执行基线，再叠加主题层 locale 补丁；直接在已损坏的编译产物上继续修补风险过高。
- 运行态验证表明：容器内 Blade 模板读取路径为 `/www/theme/XboardCustom`，静态资源读取路径为 `/www/public/theme/XboardCustom`；主题层前端改动要同步到这两个目录，浏览器才会拿到最新资产。
- WalletCenter 前端鉴权读取的是本地存储键 `VUE_NAIVE_ACCESS_TOKEN`，并将其原样写入 `Authorization` 请求头；验收注入登录态时必须写入 `Bearer <token>` 形式，否则前端会被 403 重定向回登录页。

## 阶段 13

### 阶段 13 当前结论

- 阶段 13 未新增任何代码文件，所有验证均基于现有 `StripePayment`、`BepusdtPayment`、`WalletCenter` 与 `XboardCustom` 资产执行，保持“不修改核心代码”的边界。
- 阶段 13 联调确认：普通订阅订单仍全部落在核心 `v2_order`；签到、充值、自动续费分别落在 `wallet_center_checkin_logs`、`wallet_center_topup_orders`、`wallet_center_auto_renew_records`，四类记录未混表、未串状态。
- `WalletCenter` 充值链路继续复用已启用支付实例列表；阶段 13 实测前台订阅支付方式列表与钱包充值方式列表可同时展示 `Stripe Stage3` 与 `BEpusdt Stage4`，插件并存不会污染各自显示。
- `StripePayment` 在 2026-03-10 运行态复核中已将 `v2_payment.id = 1` 的 `secret_key` / `webhook_secret` 切换为真实测试值，并通过重启 `xboard-stage3-web` 解除 `Octane + SQLite` 长连接缓存影响；普通订阅已可返回真实 Stripe Checkout Session 地址。
- `BepusdtPayment` 阶段 13 运行态继续依赖容器内临时 mock 网关 `http://127.0.0.1:18080`；普通订阅与钱包充值两条链路均已验证下单、收银台地址返回、成功回调和状态回写。
- `WalletCenter` 自动续费在阶段 13 实测中继续沿用最近一次核心订阅订单作为 `source_order`；成功续费后仅扣减余额并延长 `expired_at`，不会创建新的核心待支付订单，也不会污染充值记录。
- 由于阶段 9 已将 `wallet-center:auto-renew-scan --limit=100 --due-only` 注册为计划任务，阶段 13 在重启 `xboard-stage3-web` 容器后，到期窗口内的续费任务可能被调度器先行消费；此时随后手动执行同一命令会看到 `Scanned settings: 0`，但数据库中已经存在成功续费记录。
- 2026-03-10 运行态复核追加确认：使用 `stage13-stripe@example.com` 重新生成 `auth_data` 后，`GET /api/v1/user/order/getPaymentMethod` 仍正常返回 `Stripe Stage3` 与 `BEpusdt Stage4`，说明支付插件并存状态未变化。
- 2026-03-10 运行态复核追加确认：取消旧待支付 Stripe 订单 `2026031006032004984156241` 后，重新创建普通订阅订单 `2026031007034077292515259`，调用 `POST /api/v1/user/order/checkout` 选择 `Stripe` 已返回真实 Checkout 地址 `https://checkout.stripe.com/c/pay/cs_test_...`。
- 2026-03-10 运行态复核追加确认：从容器内向 `/api/v1/guest/payment/notify/Stripe/pljo1uAf` 发送签名正确的 `checkout.session.completed` webhook 后，接口返回 `HTTP 200 success`；订单变为 `completed`、`callback_no = evt_stage13_checkout_live`，用户 `expired_at` 从 `1775769706` 延长到 `1778361706`。
- 2026-03-10 运行态复核追加确认：阶段 13 的 Stripe 放行阻塞已经解除，阶段 14 已具备进入条件。

## 阶段 14

### 阶段 14 当前结论

- 阶段 14 未新增任何代码文件，所有结论均来自现有 `StripePayment`、`BepusdtPayment` 与 `WalletCenter` 运行态验证，继续保持“不修改核心代码”的边界。
- `WalletCenter` 充值链路在阶段 14 进一步确认了资金幂等：Stripe 充值订单 `2026031007034642272511952` 的重复成功回调只会入账一次；Stripe 充值订单 `2026031007032796887835072` 的取消回调仅会将订单记为 `cancelled`，不会误加余额。
- 核心普通订阅链路在阶段 14 进一步确认了开通幂等：Stripe 订单 `2026031007033186864814008` 与 BEpusdt 订单 `2026031007031649364021294` 的重复成功通知均只会开通一次，不会重复延长 `expired_at`。
- `WalletCenter` 签到链路在阶段 14 复核中继续依赖“用户行锁 + 当日成功记录检查”保护；当 `2026-03-10` 已存在成功签到记录时，重复请求返回 `HTTP 409`，不会新增签到记录，也不会再次入账。
- `WalletCenter` 自动续费链路在阶段 14 复核中确认：同一用户进入到期窗口后，首次扫描会扣款并延长订阅，随后 `next_scan_at` 推进到下一窗口；第二次立即重扫不会对同一用户再次扣款或再次延期。
- 普通订阅异常通知在阶段 14 复核中继续满足“确认接收但不污染状态”的原则：Stripe 非法签名返回 `HTTP 400` 且订单保持 `pending`；`checkout.session.expired` 返回 `success` 且订单保持 `pending`；不存在的订单号接收成功通知后仅返回 `success`，不会创建脏数据。
- `wallet_center` 插件停用后，插件路由与命令会随容器重启一并移除；重新启用并重启后，前台入口与 `wallet-center:auto-renew-scan` 命令均能恢复，说明插件停用/恢复不会残留半启用状态。
- 2026-03-10 阶段 14 运行态补充说明：容器内临时 `BEpusdt` mock 网关 `http://127.0.0.1:18080` 在本阶段执行时不可连接，因此本阶段未重新验证 BEpusdt 收银台地址创建；但通过对新建待支付订单直接发送签名正确的成功通知，已独立确认 BEpusdt 回调幂等逻辑仍然成立。

## 阶段 15

### 阶段 15 当前结论

- 阶段 15 未新增任何代码文件，所有结论均来自现有代码结构复查、运行态识别验证与升级流程静态复核，继续保持“不修改核心代码”的边界。
- 当前自定义实现的源码入口仍主要收敛在 `plugins/StripePayment/`、`plugins/BepusdtPayment/`、`plugins/WalletCenter/` 与 `theme/XboardCustom/`；对 `app/`、`bootstrap/`、`config/`、`database/`、`resources/`、`routes/` 以及官方 `theme/Xboard/` 的关键字反查未发现 `WalletCenter`、`StripePayment`、`BepusdtPayment`、`XboardCustom`、`xc_wallet`、`wallet-center`、`i18n-extra` 等反向写入标识。
- 插件继续建立在官方 `PluginManager` 约定之上：插件目录按 `Str::studly($pluginCode)` 解析，`config.json` 与 `Plugin.php` 作为识别基线，路由、视图、命令与计划任务均通过 `PluginManager` 在运行时注册；阶段 15 容器内复核确认 `stripe_payment`、`bepusdt_payment`、`wallet_center` 已在插件表中注册并保持启用。
- 支付插件继续建立在核心支付钩子之上：`PaymentController` 在核心通知处理前保留 `payment.notify.before`，阶段 15 复核 `hook:list` 仍可见该钩子，因此 Stripe/BEpusdt 的回调接管机制仍与官方扩展点对齐。
- 自定义主题继续建立在官方 `ThemeService` 约定之上：`ThemeService::getList()` 仍可识别 `XboardCustom`，当前运行态主题配置仍为 `XboardCustom`；`XboardUpdate` 在更新流程结尾会调用 `ThemeService::refreshCurrentTheme()`，因此 `public/theme/XboardCustom/` 这类发布副本在升级后可通过官方流程重新发布。
- 当前可控风险主要集中在主题层而非核心层：`theme/XboardCustom/assets/umi.js` 仍以官方 `umi.js` 的复制版本为前端基线，若后续官方前端运行时、hash 路由或浏览器本地存储键发生变化，需要同步刷新 `theme/XboardCustom/` 对应补丁文件；该风险不会污染核心 PHP 业务代码，但会增加主题层维护成本。
- 当前仓库尚无首个 `git` 提交，阶段 15 无法通过提交差异直接量化“最终变更集中度”；因此本阶段以“源码目录分布 + 关键字反查 + route/schedule/theme/plugin 运行态识别结果”作为升级兼容性验证依据。

## 阶段 16

### 阶段 16 当前结论

- 阶段 16 未新增任何代码文件，本阶段仅完成最终交付前审查与文档归档，继续保持“不修改核心代码”的边界。
- 最终可交付源码资产仍固定为四个根入口：`plugins/StripePayment/`、`plugins/BepusdtPayment/`、`plugins/WalletCenter/`、`theme/XboardCustom/`；未发现新的隐藏资产入口或反向写入核心目录的情况。
- 后台配置与记录体系的源码入口已固定：
  - `plugins/StripePayment/routes/api.php`
  - `plugins/BepusdtPayment/routes/api.php`
  - `plugins/WalletCenter/routes/api.php`
  - `plugins/WalletCenter/database/migrations/*`
- WalletCenter 的记录边界在最终审查中再次确认：签到、充值、自动续费分别由 `wallet_center_checkin_logs`、`wallet_center_topup_orders`、`wallet_center_auto_renew_records` 承载，未与核心 `v2_order` 混表。
- 交接资料边界已固定：代码文件职责继续以 `architecture.md` 为索引，阶段 1 至阶段 16 的测试与放行证据继续以各阶段文档和 `progress.md` 为索引；其中阶段 12 与阶段 14 的详细结果保留在 `progress.md`，不存在证据缺口。
- 最终已知限制与范围外能力仍严格对齐需求边界：不支持 `v2board`、管理员后台多语言、Stripe/BEpusdt 循环扣款，以及自动续费回落到其他支付通道。

## 2026-03-10 新增文件专项复核补充

### 职责修正

- 2026-03-10 复核 | `plugins/WalletCenter/Services/TopupService.php` | `WalletCenter` | 充值订单服务补充“非成功回调状态写入事务加锁”职责：`markStatus()` 现在通过订单行锁避免 `paid` 与 `cancelled/expired` 并发回调互相覆盖，并记录被忽略的终态/处理中回调。
- 2026-03-10 复核 | `plugins/WalletCenter/Services/TopupService.php` | `WalletCenter` | 充值下单服务补充“记录并复用真实回跳地址”职责：订单 `extra.return_url` 改为与实际发给支付网关的 `return_url` 保持一致。
- 2026-03-10 复核 | `plugins/WalletCenter/Services/TopupGatewayService.php` | `WalletCenter` | 充值网关适配服务补充“安全回跳来源校验”职责：仅信任 `config('app.url')` 或当前请求主机对应的来源站点；遇到不可信 `Referer` 时回退到站内钱包页。

### 文件洞察补充

- `plugins/StripePayment/Controllers/AdminController.php`、`plugins/StripePayment/Services/AdminOverviewService.php`、`plugins/StripePayment/routes/api.php` 本轮复核未发现新的结构性职责偏差，仍聚焦 Stripe 插件后台状态总览。
- `plugins/BepusdtPayment/Controllers/AdminController.php`、`plugins/BepusdtPayment/Services/AdminOverviewService.php`、`plugins/BepusdtPayment/routes/api.php` 本轮复核未发现新的结构性职责偏差，仍聚焦 BEpusdt 插件后台状态总览。
- `theme/XboardCustom/assets/wallet-center.js`、`theme/XboardCustom/assets/i18n-extra.js`、`theme/XboardCustom/assets/umi.js` 本轮通过脚本语法复核；`theme/XboardCustom/assets/umi.js.br` 与 `theme/XboardCustom/assets/umi.js.gz` 继续作为 `umi.js` 的压缩发布副本保留，本轮因未修改 `umi.js` 源码而无需重建。

### 本轮结论

- 本轮新增文件专项复核发现的确定性问题集中在 `WalletCenter` 充值链路，而非 `StripePayment`、`BepusdtPayment` 或主题注入层的结构设计。
- 修复后，`WalletCenter` 充值链路的关键边界已补强为：“支付成功入账优先”“终态不被后续非成功回调覆盖”“支付回跳地址不再接受任意外部来源”。

## 2026-03-10 仓库级回归复核补充

### 职责修正

- 2026-03-10 回归 | `scripts/deploy-overlay.sh` | `xboard-custom` | overlay 部署脚本职责修正为“同步插件到 `plugins/`、同步主题到 `storage/theme/`、清理遗留根主题目录、重启 compose 服务并刷新当前主题静态文件”，从而与 1Panel 官方 `compose` 挂载结构保持一致。
- 2026-03-10 回归 | `theme/XboardCustom/assets/umi.js.gz` | `XboardCustom` | gzip 压缩副本职责修正为当前 `umi.js` 的发布镜像，不再允许滞后于源码。
- 2026-03-10 回归 | `theme/XboardCustom/assets/umi.js.br` | `XboardCustom` | brotli 压缩副本职责修正为当前 `umi.js` 的发布镜像，不再允许滞后于源码。

### 文件洞察补充

- `scripts/deploy-overlay.sh` 是当前 `xboard-custom` 仓库面向 1Panel 生产环境的唯一主部署入口，职责不再只是“目录同步”，还承担“消除旧主题优先级干扰”的收尾动作。
- `theme/XboardCustom/assets/umi.js`、`umi.js.gz`、`umi.js.br` 现在应视为一个逻辑整体维护；只改 `umi.js` 而不重建两份压缩副本，会直接形成生产资源版本分裂。

### 本轮结论

- 本轮仓库级回归确认：除插件/主题源码本身外，发布脚本与压缩静态资源也是可导致线上行为偏差的第一类资产，后续维护中必须与源码一起审查和回归。
