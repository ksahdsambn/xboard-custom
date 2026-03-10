# 开发进度

## 当前状态

- 当前执行阶段：阶段 16
- 当前阶段结果：已完成阶段 16 最终交付前审查并放行
- 下一阶段：无（全部 16 个阶段已完成）

## 阶段记录

### 阶段 1：建立项目基线与扩展边界

- 完成内容：
  - 阅读并确认 `requirements.md`、`implementation-plan.md`、`task-checklist.md`、`ai-dev-prompt.md`
  - 梳理支付插件接入点、普通订单创建与支付开通路径
  - 梳理插件安装/启用/配置/路由/视图/命令/计划任务接入机制
  - 梳理主题切换、主题配置继承、主题资源发布路径
  - 梳理余额抵扣、续费判定、系统任务与后续 WalletCenter 的边界
  - 输出阶段文档 `stage-01-baseline.md`
- 测试结果：
  - 支付扩展能力验证：通过
  - 自定义主题切换能力验证：通过
  - 插件计划任务接入能力验证：通过
  - 普通订单与余额逻辑区分验证：通过
  - 主要通过插件和自定义主题完成验证：通过
  - 异常处理：首次静态验证脚本对 `v2_order` 表名匹配出现误判，已修正脚本后重测通过
- 是否放行：
  - 是

### 阶段 2：确定资产命名与版本策略

- 完成内容：
  - 固定三插件一主题的资产名、代码标识、目录名和运行时标识
  - 固定职责边界，明确支付插件、WalletCenter、自定义主题各自边界
  - 固定独立版本策略与 `MAJOR.MINOR.PATCH` 递增规则
  - 固定更新、回滚、替换所需的最小交付集合
  - 输出阶段文档 `stage-02-asset-strategy.md`
- 测试结果：
  - 命名冲突验证：通过
  - 独立启用/停用/更新/回滚前提验证：通过
  - 职责边界清晰度验证：通过
  - 异常处理：首次职责边界验证脚本对中文关键词计数出现误判，已调整验证方式后重测通过
- 是否放行：
  - 是

### 阶段 3：实现 Stripe 一次性支付插件

- 完成内容：
  - 新增 `StripePayment` 支付插件资产，目录为 `plugins/StripePayment/`
  - 新增插件元数据 `plugins/StripePayment/config.json`
  - 新增插件主类 `plugins/StripePayment/Plugin.php`
  - 将支付方式键 `Stripe` 注册为普通支付通道
  - 提供后台支付实例配置项：`secret_key`、`webhook_secret`、`currency`、`product_name`、`session_expire_minutes`、`webhook_tolerance_seconds`
  - 支付流程采用 Stripe Checkout 托管跳转，不实现循环扣款、保存卡信息和自动扣费
  - 支付回调最终保持走系统默认地址 `/api/v1/guest/payment/notify/Stripe/{uuid}`，但由插件通过 `payment.notify.before` 提前接管
  - 插件 `notify()` 自行完成验签、事件分流、幂等开通与响应返回
  - 支付成功仅在 webhook 验签通过、金额匹配、币种匹配、订单存在且仍为待支付时映射回普通订阅订单
  - 将取消、失败、超时、未知事件、订单不存在、订单已支付等场景统一处理为“确认接收但不污染订单状态”
  - 在插件内实现订单原子状态迁移与同步开通，避免修改核心 `OrderService`
  - 运行时验证期间曾短暂尝试核心兼容补丁，但已完全撤回；当前阶段 3 最终不保留任何核心目录定制
  - 输出阶段文档 `stage-03-stripe-payment.md`
- 测试结果：
  - 后台插件列表可见 `stripe_payment`，且已可安装、启用：通过
  - 后台支付方式列表可见 `Stripe`，且配置表单字段完整：通过
  - 用户普通订阅结算页可见 `Stripe`：通过
  - 支付成功通知后订单正确开通，`callback_no` 正确写入，订阅到期时间正确延长：通过
  - 重复通知返回 `success` 且不重复开通，订阅到期时间不重复延长：通过
  - 取消事件返回 `success` 且订单保持 `pending`：通过
  - 超时事件返回 `success` 且订单保持 `pending`：通过
  - 非法签名通知返回 `400` 且订单保持 `pending`：通过
  - 插件内原子状态迁移验证：通过
  - 在“容器仅挂载插件目录、不挂载任何核心文件”的条件下重测上述场景：通过
  - 运行时残余风险：本地阶段环境使用占位 `sk_test_placeholder`，未完成真实 Stripe Checkout Session 创建成功联调；该项需在后续真实测试密钥环境中补充，但不影响本阶段强制验证项判定
- 是否放行：
  - 是（阶段 3 强制验证项已完成并通过；当前回合停止于阶段 3，不自动进入阶段 4）

### 阶段 4：实现 BEpusdt 一次性支付插件

- 完成内容：
  - 新增 `BEpusdtPayment` 支付插件资产，运行时目录为 `plugins/BepusdtPayment/`
  - 新增插件元数据 `plugins/BepusdtPayment/config.json`
  - 新增插件主类 `plugins/BepusdtPayment/Plugin.php`
  - 将支付方式键 `BEpusdt` 注册为普通支付通道
  - 提供后台支付实例配置项：`base_url`、`api_token`、`fiat`、`trade_type`、`currencies`、`payment_address`、`order_name`、`timeout_seconds`、`rate`
  - 默认对接 BEpusdt `create-order` 接口创建收银台订单；当配置 `trade_type` 时自动切换到 `create-transaction`，从而支持单网络限制场景
  - 支付回调继续走系统默认地址 `/api/v1/guest/payment/notify/BEpusdt/{uuid}`，但由插件通过 `payment.notify.before` 提前接管
  - 插件 `notify()` 自行完成签名校验、状态分流、幂等开通与响应返回
  - 仅将 `status = 2` 且签名通过、金额匹配、订单存在且仍为待支付的通知映射回普通订阅订单
  - 将 `status = 1` 等待支付、`status = 3` 支付超时、未知状态、订单不存在、订单已支付等场景统一处理为“确认接收但不污染订单状态”
  - 在插件内实现订单原子状态迁移与同步开通，避免修改核心 `OrderService`
  - 输出阶段文档 `stage-04-bepusdt-payment.md`
  - 额外说明：阶段 2 文档中的目录名写为 `plugins/BEpusdtPayment/`，但运行时实际需使用 `plugins/BepusdtPayment/`，原因是 `PluginManager` 对 `bepusdt_payment` 执行 `Str::studly()` 后解析到 `BepusdtPayment`
- 测试结果：
  - 后台插件列表可见 `bepusdt_payment`，且已可安装、启用：通过
  - 后台支付方式列表可见 `BEpusdt`，且配置表单字段完整：通过
  - 用户普通订阅结算页可见 `BEpusdt`：通过
  - 下单请求可返回 BEpusdt 收银台跳转地址：通过
  - 等待支付通知返回 `success` 且订单保持 `pending`：通过
  - 支付成功通知返回 `success`，订单正确开通，`callback_no` 正确写入：通过
  - 重复成功通知返回 `success` 且不重复开通：通过
  - 支付超时通知返回 `success` 且订单保持 `pending`：通过
  - 签名异常通知返回 `400` 且订单保持 `pending`：通过
  - 单网络 `trade_type` 分支下单验证：通过
  - 在“容器仅挂载插件目录、不挂载任何核心文件”的条件下完成上述运行时验证：通过
  - 运行时残余风险：当前阶段使用容器内临时 mock BEpusdt 服务验证请求结构、跳转结果与回调分流，尚未与真实已部署 BEpusdt 网关做端到端联调；该项需在后续真实联调阶段补充，但不影响本阶段强制验证项判定
- 是否放行：
  - 是（阶段 4 强制验证项已完成并通过；当前回合停止于阶段 4，不自动进入阶段 5）

### 阶段 5：建立 WalletCenter 插件骨架

- 完成内容：
  - 新增 `WalletCenter` 功能插件资产，目录为 `plugins/WalletCenter/`
  - 新增插件元数据 `plugins/WalletCenter/config.json`
  - 新增插件主类 `plugins/WalletCenter/Plugin.php`
  - 新增能力边界定义、配置服务、支付通道读取服务与骨架清单服务
  - 新增签到、充值、自动续费三类 API 骨架控制器与后台总览控制器
  - 新增插件自有模型：`CheckinLog`、`TopupOrder`、`AutoRenewSetting`、`AutoRenewRecord`
  - 新增插件命令骨架 `wallet-center:auto-renew-scan`
  - 新增插件 API 路由骨架，统一挂载到 `/api/v1/wallet-center/*`
  - 新增插件自有数据表迁移：
    - `wallet_center_checkin_logs`
    - `wallet_center_topup_orders`
    - `wallet_center_auto_renew_settings`
    - `wallet_center_auto_renew_records`
  - 为签到、充值、自动续费三类能力分别规划配置入口、前台入口、记录入口和执行入口
  - 通过 `wallet_center_topup_orders` 与自动续费自有表完成与核心 `v2_order` 的结构解耦
  - 通过 `WalletCenterPaymentChannelService` 复用当前已启用支付实例列表，但不接管普通订阅支付回调链
  - 默认将 `checkin_enabled`、`topup_enabled`、`auto_renew_enabled` 置为 `false`，避免阶段 5 骨架提前暴露未完成业务
  - 输出阶段文档 `stage-05-wallet-center-skeleton.md`
- 测试结果：
  - 插件安装与启用验证：通过
  - 四张插件自有数据表迁移验证：通过
  - `wallet-center:auto-renew-scan` 命令注册验证：通过
  - 启用 WalletCenter 后既有支付方式列表仍包含 `Stripe` 与 `BEpusdt`，且未新增普通订阅支付污染：通过
  - 仅开启签到能力时，签到入口返回 `200`、充值与自动续费入口返回 `403`：通过
  - 仅开启充值能力时，充值入口返回 `200` 且仅列出已启用支付实例 `Stripe Stage3` / `BEpusdt Stage4`，其余能力入口返回 `403`：通过
  - 仅开启自动续费能力时，自动续费入口返回 `200`，其余能力入口返回 `403`：通过
  - 禁用 WalletCenter 后，充值通知路由返回 `404`，自动续费命令不再可用：通过
  - 运行时残余风险：阶段 5 只建立骨架，签到发奖、充值入账、自动续费扣款与调度仍在后续阶段实现；当前通过 `501` 占位响应显式阻断未完成执行链，避免误用：已确认
- 是否放行：
  - 是（阶段 5 强制验证项已完成并通过；当前回合停止于阶段 5，不自动进入阶段 6）

### 阶段 6：实现每日签到随机余额功能

- 完成内容：
  - 新增 `plugins/WalletCenter/Services/CheckinService.php`，统一处理签到状态、奖励区间解析、随机奖励发放、签到历史读取与后台记录读取
  - 在签到执行链路中使用事务与用户行锁，复用核心 `App\Services\UserService::addBalance()` 完成余额原子入账
  - 在签到日志 `meta` 中记录 `balance_before`、`balance_after`、请求 IP 与 User-Agent，保证签到记录可追踪
  - 将 `plugins/WalletCenter/Controllers/CheckinController.php` 从阶段 5 占位实现替换为正式阶段 6 状态/签到/历史接口
  - 将 `plugins/WalletCenter/Controllers/AdminController.php` 中的签到记录入口替换为正式后台签到记录读取，并附带用户邮箱
  - 调整 `plugins/WalletCenter/Models/CheckinLog.php`，补充 `user()` 关联并规范 `claim_date` 对外输出
  - 输出阶段文档 `stage-06-daily-checkin.md`
- 测试结果：
  - 签到开关关闭后，用户签到状态接口与后台签到记录接口均返回 `403`：通过
  - 已登录用户在签到开关开启后可访问签到状态、签到执行和签到历史接口：通过
  - 首次签到返回 `200`，奖励值 `344` 落在配置区间 `100 ~ 500` 内，用户余额从 `0` 增加到 `344`：通过
  - 签到成功后状态接口返回 `today_claimed = true`，用户历史与后台记录均仅显示 1 条正确签到记录：通过
  - 同日重复签到返回 `409`，用户余额保持 `344`，签到日志数量保持 `1`：通过
  - 使用伪造 `UserService::addBalance()` 返回 `false` 模拟余额写入失败时，用户余额保持 `0` 且不会留下错误成功记录：通过
  - 运行时异常处理：验证期间发现 `Octane + SQLite` 在外部 `tinker` 修改配置/数据后可能读取旧连接状态；已将阶段 6 运行时验证统一调整为“修改后重启 `xboard-stage3-web` 容器”流程，重测通过
- 是否放行：
  - 是（阶段 6 强制验证项已完成并通过；当前回合停止于阶段 6，不自动进入阶段 7）

### 阶段 7：实现余额充值功能

- 完成内容：
  - 新增 `plugins/WalletCenter/Services/TopupGatewayService.php`，统一复用已启用的 `Stripe` / `BEpusdt` 支付插件实例，生成充值专用通知地址与返回地址，并分别处理两类充值回调验签
  - 新增 `plugins/WalletCenter/Services/TopupService.php`，统一处理充值金额校验、充值订单创建、前后台充值记录查询、回调状态流转与余额入账幂等控制
  - 将 `plugins/WalletCenter/Controllers/TopupController.php` 从阶段 5 占位实现替换为正式阶段 7 充值通道、下单、详情、历史与通知接口
  - 将 `plugins/WalletCenter/Controllers/AdminController.php` 中的充值记录入口替换为正式后台充值记录读取
  - 扩展 `plugins/WalletCenter/Services/WalletCenterPaymentChannelService.php`，补充按支付实例定位启用通道和提取通道快照的能力
  - 调整 `plugins/WalletCenter/Models/TopupOrder.php`，补充充值状态映射、状态标签、时间字段转换与 `user()` / `payment()` 关联
  - 输出阶段文档 `stage-07-balance-topup.md`
- 测试结果：
  - 充值开关开启后，`GET /api/v1/wallet-center/topup/methods` 正确返回启用中的 `Stripe Stage3` / `BEpusdt Stage4` 与金额区间 `100 ~ 500000`：通过
  - 使用 `payment_id = 2`、`amount = 2300` 调用 `POST /api/v1/wallet-center/topup/create`，成功创建独立充值订单并返回收银台地址：通过
  - 对订单 `trade_no = 2026030918034989698446113` 发送 BEpusdt 超时回调后，订单状态变为 `expired`，用户余额保持 `0`：通过
  - 对订单 `trade_no = 2026030918035307522488760` 发送 BEpusdt 成功回调后，用户余额从 `0` 增加到 `2300`，`plan_id = 1` 与 `expired_at = 1786221789` 保持不变：通过
  - 对同一笔 BEpusdt 成功回调重复发送后，接口继续返回 `success`，用户余额保持 `2300`，未重复入账：通过
  - 前台 `detail` / `history` 与后台 `admin/topup/orders` 均可正确展示充值记录、支付方式、到账状态和用户邮箱：通过
  - 临时禁用 `BEpusdt Stage4` 并重启容器后，充值通道列表与后台通道列表均只保留 `Stripe Stage3`；恢复启用后列表恢复：通过
  - 额外创建 Stripe 待支付充值订单 `trade_no = 2026030918041599988877701` 并发送签名正确的 `checkout.session.completed` webhook 后，用户余额从 `2300` 增加到 `3000`，订阅状态不变：通过
  - `TopupGatewayService.php`、`TopupService.php`、`TopupController.php`、`AdminController.php`、`WalletCenterPaymentChannelService.php`、`TopupOrder.php` 语法检查全部通过：通过
  - 运行时残余风险：阶段 7 的 BEpusdt 下单链路使用容器内临时 mock 服务模拟 `create-order` 接口，因为测试环境中的 `127.0.0.1:18080` 未常驻真实网关；Stripe 充值下单仍受占位测试密钥限制，但充值回调验签与入账链路已通过签名 webhook 验证，不影响本阶段强制验证项判定
- 是否放行：
  - 是（阶段 7 强制验证项已完成并通过；当前回合停止于阶段 7，不自动进入阶段 8）

### 阶段 8：实现余额自动续费当前订阅

- 完成内容：
  - 新增 `plugins/WalletCenter/Services/AutoRenewService.php`，统一处理自动续费配置快照、启停设置、用户历史、后台记录和扫描执行
  - 自动续费周期与金额从“当前订阅计划 + 最近已完成核心订阅订单”解析，确保续费对象始终是当前订阅
  - 自动续费窗口固定为到期前 24 小时，启停状态、最近结果、原因和 `next_scan_at` 均写入 `wallet_center_auto_renew_settings`
  - 扫描执行前检查当前订阅有效性、未完成核心订单与余额是否足够，且明确不回退到其他支付通道
  - 续费成功时复用核心 `App\Services\UserService::addBalance()` 扣减余额，并同步当前计划属性、按当前周期延长 `expired_at`
  - 改造 `AutoRenewController`、`AdminController`、`AutoRenewScanCommand`、`AutoRenewSetting`、`AutoRenewRecord`，补齐正式接口、后台记录读取、命令输出与状态映射
  - 输出阶段文档 `stage-08-balance-auto-renew.md`
- 测试结果：
  - `POST /api/v1/wallet-center/auto-renew/config` 与 `GET /api/v1/wallet-center/auto-renew/config` 已覆盖成功续费、余额不足、存在未完成订单、禁用、未到期窗口 5 类场景：通过
  - 首轮执行 `php artisan wallet-center:auto-renew-scan --limit=100` 返回 `Scanned settings: 4`、`Successful renewals: 1`、`Failed renewals: 1`、`Skipped renewals: 1`、`Not due yet: 1`：通过
  - 成功续费用户余额从 `5000` 变为 `3766`，`expired_at` 从 `1773139308` 延长到 `1775817708`，并生成 1 条 `renewed` 成功记录：通过
  - 余额不足用户余额保持 `500`，生成 1 条 `insufficient_balance` 失败记录：通过
  - 存在未完成订单用户余额保持 `5000`，生成 1 条 `pending_order_exists` 跳过记录：通过
  - 禁用用户与未到期窗口用户均未生成记录：通过
  - `GET /api/v1/wallet-center/auto-renew/history` 与 `GET /api/v1/wallet-center/admin/auto-renew/records` 已正确返回前后台记录：通过
  - 第二轮执行扫描命令后，成功续费用户余额和到期时间保持不变，历史记录数仍为 1，未发生二次扣款或二次延期：通过
- 是否放行：
  - 是（阶段 8 强制验证项已完成并通过；当前回合停止于阶段 8，不自动进入阶段 9）

### 阶段 9：注册 WalletCenter 计划任务

- 完成内容：
  - 改造 `plugins/WalletCenter/Plugin.php`，正式实现插件级 `schedule()`，仅在 `auto_renew_enabled = true` 时注册 `wallet-center:auto-renew-scan --limit=100 --due-only`
  - 为 WalletCenter 计划任务补充 `everyMinute()`、`onOneServer()`、`withoutOverlapping(10)`，确保调度注册稳定且不与系统原有任务并发互扰
  - 改造 `plugins/WalletCenter/Commands/AutoRenewScanCommand.php`，新增 `--due-only` 选项，支持调度层只扫描已到执行窗口的设置
  - 改造 `plugins/WalletCenter/Services/AutoRenewService.php`，将计划任务扫描范围收紧为 `next_scan_at <= now()` 或 `next_scan_at is null` 的设置
  - 为 `pending_order_exists`、`insufficient_balance` 场景增加 5 分钟退避，为 `runtime_error` 与不可续费上下文场景增加 30 分钟退避，避免短时间重复触发刷记录
  - 输出阶段文档 `stage-09-wallet-center-schedule.md`
- 测试结果：
  - `php artisan schedule:list` 可见 `php artisan wallet-center:auto-renew-scan --limit=100 --due-only`：通过
  - 在容器内通过 Laravel 调度事件对象单独执行 WalletCenter 计划任务后，`user_id = 6` 成功续费，余额从 `5000` 变为 `3766`，`expired_at` 从 `1773146508` 延长到 `1775824908`：通过
  - 同次执行中，`user_id = 3` 生成 `insufficient_balance` 失败记录、`user_id = 4` 生成 `pending_order_exists` 跳过记录，且两者 `next_scan_at` 均推进到 5 分钟后的 `2026-03-09 23:00:56`：通过
  - 紧接首次执行后重复触发同一计划任务，自动续费记录总数保持 `8` 不变，`user_id = 6` 未发生二次扣款或二次延期：通过
  - 临时禁用 `wallet_center` 插件后，`php artisan schedule:list` 中不再出现 WalletCenter 调度任务；恢复启用后任务重新出现：通过
- 是否放行：
  - 是（阶段 9 强制验证项已完成并通过；当前回合停止于阶段 9，不自动进入阶段 10）

## 文档产物

- `markdown/stage-01-baseline.md`
- `markdown/stage-02-asset-strategy.md`
- `markdown/stage-03-stripe-payment.md`
- `markdown/stage-04-bepusdt-payment.md`
- `markdown/stage-05-wallet-center-skeleton.md`
- `markdown/stage-06-daily-checkin.md`
- `markdown/stage-07-balance-topup.md`
- `markdown/stage-08-balance-auto-renew.md`
- `markdown/stage-09-wallet-center-schedule.md`
- `markdown/stage-10-admin-config-records.md`
- `markdown/stage-11-xboard-custom-theme.md`
- `markdown/stage-13-end-to-end.md`
- `markdown/stage-15-upgrade-compatibility.md`
- `markdown/stage-16-final-delivery-review.md`
- `markdown/architecture.md`
- `markdown/progress.md`

## 阶段 10（补充记录）

### 阶段 10：实现后台配置与记录体系

- 完成内容：
  - 新增 `StripePayment` 后台总览接口 `GET /api/v1/stripe-payment/admin/overview`，输出插件启用状态、支付实例状态、必填配置、回调地址与后台配置入口。
  - 新增 `BepusdtPayment` 后台总览接口 `GET /api/v1/bepusdt-payment/admin/overview`，输出插件启用状态、支付实例状态、必填配置、回调地址与后台配置入口。
  - 为 `WalletCenter` 新增后台总览接口、后台配置读取接口、后台配置保存接口，并保留签到记录、充值订单、自动续费记录三类后台记录入口。
  - 将 `WalletCenter` 后台总览扩展为“配置分组 + 功能开关状态 + 可用充值通道 + 资金活动流 + 各业务摘要”的聚合输出。
  - 为 `WalletCenter` 的签到、充值、自动续费三类后台记录接口补充 `summary` 聚合信息。
  - 为充值订单与自动续费记录补充状态标签、资金活动类型、失败原因/失败说明等字段，满足后台追踪要求。
  - 强化 `WalletCenterConfigService` 配置写入逻辑，改为基于现有配置合并更新，避免局部保存时覆盖未提交配置项。
  - 后台配置职责保持边界清晰：`Stripe` / `BEpusdt` 仍复用核心支付实例管理入口完成配置写入，`WalletCenter` 配置读写完全落在插件目录内；本阶段未修改核心业务代码。

- 测试结果：
  - 插件 PHP 语法检查：通过。
  - 路由检查：`stripe-payment`、`bepusdt-payment`、`wallet-center/admin` 阶段 10 新增接口均已注册，结果通过。
  - 后台总览接口检查：`/api/v1/stripe-payment/admin/overview`、`/api/v1/bepusdt-payment/admin/overview`、`/api/v1/wallet-center/admin/overview` 均返回预期结构，结果通过。
  - WalletCenter 后台配置接口检查：`GET /api/v1/wallet-center/admin/config` 与 `POST /api/v1/wallet-center/admin/config` 均通过，且配置变更能正确影响前台入口与通知入口放行状态，结果通过。
  - 后台记录接口检查：签到记录、充值订单、自动续费记录接口均返回分页记录与摘要信息，结果通过。
  - 失败原因与资金活动类型检查：充值订单、自动续费记录的派生字段输出正确，且能区分 `subscription_payment`、`balance_topup`、`auto_renew_execution`，结果通过。

- 是否放行：
  - 是
## 阶段 11

### 阶段 11：创建 XboardCustom 自定义主题

- 完成内容：
  - 新增独立主题目录 `theme/XboardCustom/`
  - 新增 `theme/XboardCustom/config.json`
  - 新增 `theme/XboardCustom/dashboard.blade.php`
  - 新增 `theme/XboardCustom/assets/wallet-center.css`
  - 新增 `theme/XboardCustom/assets/wallet-center.js`
  - 复制官方 `Xboard` 的 `umi.js`、环境文件和背景资源到 `XboardCustom`
  - 通过主题注入层实现签到、充值、自动续费入口与状态展示，未修改核心业务代码与官方 `umi.js`
  - 将钱包入口收敛为“官方已存在 hash 路由 + `xc_wallet=1` 查询参数”模式，规避 `#/wallet` 被官方 SPA 改写为 `#/404` 的问题
  - 运行态验证结束后已将容器恢复到系统主题 `Xboard`
- 测试结果：
  - `ThemeService::getList()` 可识别 `XboardCustom`：通过
  - 切换到 `XboardCustom` 后首页正确引用自定义样式与脚本：通过
  - Playwright 注入登录态后验证 `#/dashboard`、`#/plan`、`#/order`、`#/dashboard?xc_wallet=1&section=checkin`：通过
  - 钱包、签到、充值、自动续费 4 个前台入口可见：通过
  - 钱包浮层 `WalletCenter` 可见且无前端运行时异常：通过
  - 切回系统主题 `Xboard` 后首页不再引用 `XboardCustom` 资源：通过
  - 运行态补充说明：`Octane + SQLite` 下切换主题后需重启 `xboard-stage3-web` 容器同步最新状态
- 是否放行：
  - 是

## 当前状态补充（阶段 11）

- 当前执行阶段：阶段 11
- 当前阶段结果：已完成代码实现并通过阶段 11 强制验证
- 下一阶段：阶段 12（本回合不自动推进）
## 阶段 12

### 阶段 12：补齐 13 种新增语言

- 完成内容：
  - 在 `theme/XboardCustom/dashboard.blade.php` 保留原有 7 种语言的基础上，补齐 `fr-FR`、`de-DE`、`es-ES`、`it-IT`、`pt-PT`、`nl-NL`、`pl-PL`、`tr-TR`、`ru-RU`、`ar-SA`、`nb-NO`、`sv-SE`、`fi-FI` 共 13 种新增语言入口。
  - 新增 `theme/XboardCustom/assets/i18n-extra.js`，集中承载 13 种新增语言的界面文案、支付/公告/错误文案、钱包中心文案和 RTL 方向辅助逻辑。
  - 调整 `theme/XboardCustom/assets/umi.js`，在不修改核心业务代码的前提下，通过主题层补丁扩展 locale 装载逻辑，并为新增语言补齐 Naive UI locale fallback，避免前端因缺少原生 locale pack 崩溃。
  - 调整 `theme/XboardCustom/assets/wallet-center.js` 与 `theme/XboardCustom/assets/wallet-center.css`，让钱包中心悬浮入口与弹层复用新增语言词条，并支持 `ar-SA` 的 RTL 布局。
  - 本阶段全部改动均收敛在 `theme/XboardCustom/` 主题目录内，未修改 `app/`、`routes/`、`plugins/` 等核心代码目录。

- 测试结果：
  - `node --check theme/XboardCustom/assets/i18n-extra.js`：通过
  - `node --check theme/XboardCustom/assets/wallet-center.js`：通过
  - `node --check theme/XboardCustom/assets/umi.js`：通过
  - Playwright 顺序验收 13 个新增 locale：
    - 静态覆盖检查：`window.settings.i18n` 已包含全部 13 个新增 locale，合计 20 种前端语言；新增语言的首页、订阅、订单、支付、公告、错误文案覆盖通过；钱包中心 `wallet/checkin/topup/renew/topupCreated/checkinOk/renewOk/failed` 覆盖通过。
    - 登录态路由检查：13 个新增 locale 下的 `#/dashboard`、`#/plan`、`#/order` 页面均可正常进入并显示对应语言文案。
    - 钱包入口检查：13 个新增 locale 下的钱包悬浮入口均正确显示 `wallet / checkin / topup / renew` 本地化文案。
    - RTL 检查：`ar-SA` 下 `document.documentElement.dir = rtl` 且 `body.xc-rtl` 生效，通过。

- 是否放行：
  - 是（阶段 12 强制验证项已完成并通过；当前回合停止于阶段 12，不自动进入阶段 13）

## 当前状态补充（阶段 12）

- 当前执行阶段：阶段 12
- 当前阶段结果：已完成代码实现并通过阶段 12 强制验证
- 下一阶段：阶段 13（本回合不自动推进）

## 阶段 13

### 阶段 13：执行端到端联调

- 完成内容：
  - 使用阶段 13 专用测试账号分别执行普通订阅的 `Stripe` 与 `BEpusdt` 支付联调，并核对两种支付插件并存时的前台展示。
  - 使用 `stage13-wallet@example.com` 按顺序执行“签到 -> 充值 -> 开启自动续费 -> 到期前续费”链路，确认余额与订阅有效期联动正确。
  - 核对核心订阅订单、签到记录、充值记录、自动续费记录的分表存储和字段隔离情况，确认不存在状态串线和记录串线。
  - 输出阶段文档 `markdown/stage-13-end-to-end.md`。

- 测试结果：
  - `GET /api/v1/user/order/getPaymentMethod` 与 `GET /api/v1/wallet-center/topup/methods` 均同时返回 `Stripe Stage3` / `BEpusdt Stage4`：通过
  - Stripe 普通订阅联调：
    - `POST /api/v1/user/order/save` 成功创建订单 `2026031007034077292515259`：通过
    - `POST /api/v1/user/order/checkout` 选择 `Stripe` 返回真实 Checkout 地址 `https://checkout.stripe.com/c/pay/cs_test_...`：通过
    - 从容器内向 `/api/v1/guest/payment/notify/Stripe/pljo1uAf` 发送签名正确的 `checkout.session.completed` webhook 后，订单状态更新为 `completed`、`callback_no = evt_stage13_checkout_live`，用户订阅成功开通：通过
  - BEpusdt 普通订阅联调：
    - `POST /api/v1/user/order/save` 成功创建订单 `2026031005032422719334714`：通过
    - `POST /api/v1/user/order/checkout` 返回收银台地址 `http://127.0.0.1:18080/pay?trade_no=2026031005032422719334714`：通过
    - 成功通知后订单状态更新为 `completed`，`callback_no = stage13-bep-sub-block-1`，用户订阅成功开通：通过
  - 签到增加余额：奖励 `380`，余额 `0 -> 380`：通过
  - BEpusdt 充值增加余额：订单 `2026031005033432135054785` 成功通知后余额 `380 -> 1380`，订阅状态未被充值链路污染：通过
  - 自动续费联调：开启成功；到期窗口内续费后余额 `1380 -> 146`，`expired_at` 从 `2026-03-10T05:57:11+08:00` 延长到 `2026-04-09T05:57:11+08:00`，且仅生成 1 条成功续费记录：通过
  - 记录隔离：`wallet_center_checkin_logs`、`wallet_center_topup_orders`、`wallet_center_auto_renew_records` 与核心 `v2_order` 各自记录类型正确：通过
  - 运行态补充说明：
    - 2026-03-10 已将 `Stripe Stage3` 运行态配置切换为真实测试密钥并重启 `xboard-stage3-web`，真实 Checkout Session 创建已打通
    - 当前 `BEpusdt` 联调依赖容器内临时 mock 网关 `http://127.0.0.1:18080`
    - 阶段 9 已注册自动续费计划任务，重启容器后到期窗口内的续费任务可能先被调度器消费；随后手动执行 `wallet-center:auto-renew-scan --due-only` 会看到 `Scanned settings: 0`

- 是否放行：
  - 是（阶段 13 放行条件已全部满足，允许进入阶段 14）

## 当前状态补充（阶段 13）

- 当前执行阶段：阶段 14
- 当前阶段结果：已完成阶段 13 联调执行，并于 2026-03-10 完成 Stripe 真实 Checkout 复核后放行
- 下一阶段：阶段 14（已满足进入条件，正在执行）

## 阶段 13 放行复核（2026-03-10）

- 完成内容：
  - 复读 `ai-dev-prompt.md`、`requirements.md`、`implementation-plan.md`、`task-checklist.md`、`stage-13-end-to-end.md`、`progress.md` 与 `architecture.md`，确认“阶段未放行不得进入下一阶段”的串行约束仍然有效。
  - 在运行中容器 `xboard-stage3-web` 内将 `Stripe Stage3` 的运行态 `secret_key` / `webhook_secret` 切换为真实测试密钥，并按 `Octane + SQLite` 规范重启容器以刷新长连接状态。
  - 使用 `stage13-stripe@example.com` 重新生成 `auth_data`，复核 `GET /api/v1/user/order/getPaymentMethod` 仍同时返回 `Stripe Stage3` 与 `BEpusdt Stage4`。
  - 取消此前遗留的待支付 Stripe 订单 `2026031006032004984156241`，避免旧阻塞样本干扰复测。
  - 使用 `plan_id = 1`、`period = month_price` 调用 `POST /api/v1/user/order/save`，成功创建新的普通订阅订单 `2026031007034077292515259`。
  - 对订单 `2026031007034077292515259` 调用 `POST /api/v1/user/order/checkout` 并选择 `Stripe`，成功返回真实 Checkout 地址 `https://checkout.stripe.com/c/pay/cs_test_...`。
  - 从容器内生成 `checkout.session.completed` 事件载荷并按当前 `webhook_secret` 完成签名，发送到 `/api/v1/guest/payment/notify/Stripe/pljo1uAf` 后收到 `HTTP 200 success`。
  - 复核订单 `2026031007034077292515259` 与用户运行态结果：订单变为 `completed`、`callback_no = evt_stage13_checkout_live`，`expired_at` 从 `1775769706` 延长到 `1778361706`，说明 Stripe 普通订阅链路已完整打通。
- 测试结果：
  - 运行中容器与阶段 13 测试账号可继续用于复核：通过
  - `Stripe Stage3` / `BEpusdt Stage4` 并存展示复核：通过
  - Stripe 普通订阅重新建单：通过
  - Stripe 普通订阅重新结账：通过（`POST /api/v1/user/order/checkout` 已返回真实 Checkout 地址）
  - Stripe 成功回调复核：通过（订单已完成、`callback_no` 正确写入、订阅有效期正确延长）
  - 阶段进入条件检查：通过（阶段 13 已放行，可进入阶段 14）
- 是否放行：
  - 是（阶段 13 阻塞已解除，阶段 14 可开始执行）

## 阶段 14

### 阶段 14：执行异常与幂等专项检查

- 完成内容：
  - 按阶段 14 清单顺序，分别对充值重复回调、普通订阅重复回调、签到重复请求、自动续费重复调度、支付取消、支付超时、插件停用以及异常参数/失效订单场景执行运行态验证。
  - 复用 `stage13-stripe@example.com` 与 `stage13-wallet@example.com` 两个阶段测试账号，逐项核对余额、`expired_at`、订单状态、`callback_no` 与记录数量，确认不会发生二次入账、二次开通或状态污染。
  - 在不修改核心代码的前提下，仅通过插件运行态开关、数据库状态与容器重启验证插件停用后的入口/命令变化，并在测试结束后恢复 `wallet_center` 启用态。

- 测试结果：
  - Stripe 充值重复回调：创建充值订单 `2026031007034642272511952` 后首次 `checkout.session.completed` 回调使余额 `146 -> 1646`，第二次同回调仍返回 `success` 且余额保持 `1646`：通过
  - Stripe 普通订阅重复回调：创建普通订阅订单 `2026031007033186864814008` 并成功结账后，首次成功回调使 `expired_at` `1778361706 -> 1781040106`，第二次同回调仍返回 `success` 且 `expired_at` 不再变化：通过
  - BEpusdt 普通订阅重复回调：对普通订阅订单 `2026031007031649364021294` 连续两次发送相同成功通知后，订单仅开通一次，`callback_no = stage14-bep-dup-1`，`expired_at` `1781040106 -> 1783632106` 后保持不变：通过
  - 同日签到重复请求：在 `2026-03-10` 已存在成功签到记录 `id = 4` 的前提下，再次调用 `POST /api/v1/wallet-center/checkin/claim` 返回 `HTTP 409`，余额保持 `1646`、当日签到记录数保持 `1`：通过
  - 自动续费重复调度：将 `user_id = 8` 人工推进到到期窗口内后，首次执行 `wallet-center:auto-renew-scan --limit=1 --due-only` 仅对该用户续费一次，余额 `1646 -> 412`、`expired_at` `1773102127 -> 1775780527`；第二次立即执行返回 `Scanned settings: 0`，余额和到期时间保持不变：通过
  - 支付取消场景：创建 Stripe 充值订单 `2026031007032796887835072` 并发送 `payment_intent.canceled` 后，订单状态变为 `cancelled`、`callback_no = evt_stage14_topup_cancel_1`，余额保持 `412`：通过
  - 支付超时场景：创建 Stripe 普通订阅订单 `2026031007033072464448335` 并发送 `checkout.session.expired` 后，订单仍为 `pending`、`callback_no = null`，用户 `expired_at` 保持 `1783632106` 不变；随后已调用取消接口清理挂单：通过
  - 插件停用后入口和执行状态：将 `wallet_center` 设为停用并重启 `xboard-stage3-web` 后，`GET /api/v1/wallet-center/checkin/status` 返回 `HTTP 404`，`php artisan list` 中不再出现 `wallet-center:auto-renew-scan`；恢复启用并再次重启后，签到入口恢复 `HTTP 200`、命令重新出现：通过
  - 异常参数和失效订单处理：
    - `POST /api/v1/wallet-center/topup/create` 使用 `amount = 99` 返回 `HTTP 400`，充值订单总数保持 `9` 不变：通过
    - 普通订阅订单 `2026031007032936285313157` 收到非法 Stripe 签名通知后返回 `HTTP 400 Invalid Stripe webhook signature`，订单仍为 `pending`、`callback_no = null`、`expired_at` 保持不变；随后已取消清理：通过
    - 对不存在的订单号 `stage14-missing-order` 发送签名正确的 Stripe 成功通知后返回 `HTTP 200 success`，数据库未新增同号订单，用户订阅状态未受影响：通过
  - 运行态补充说明：
    - 本阶段执行时，容器内临时 `BEpusdt` mock 网关 `http://127.0.0.1:18080` 出现连接失败，因此 `BEpusdt` 重复回调项采用“创建核心待支付订单 + 直接发送签名正确的成功通知”方式验证幂等，不影响“重复回调只开通一次”的阶段判定

- 是否放行：
  - 是（阶段 14 放行条件已满足，可进入阶段 15）

## 当前状态补充（阶段 14）

- 当前执行阶段：阶段 15
- 当前阶段结果：已完成阶段 14 异常与幂等专项检查并放行
- 下一阶段：阶段 15（本回合不自动推进）

## 阶段 15

### 阶段 15：执行升级兼容性检查

- 完成内容：
  - 复查当前自定义实现的源码分布，确认主要资产仍收敛在 `plugins/StripePayment/`、`plugins/BepusdtPayment/`、`plugins/WalletCenter/` 与 `theme/XboardCustom/`。
  - 复查核心扩展点依赖关系，确认插件继续依赖 `PluginManager` 的目录扫描、路由/视图/命令/计划任务注册能力，支付回调继续依赖核心 `payment.notify.before` 钩子，自定义主题继续依赖 `ThemeService` 的主题识别与发布流程。
  - 复查 `xboard:update` 更新流程，确认更新完成后会调用 `ThemeService::refreshCurrentTheme()` 重新发布当前主题静态副本，避免 `public/theme/<theme>` 在升级后失效。
  - 复查插件与主题的独立升级能力，确认 `PluginManager` 具备插件启用、停用、卸载、上传与升级能力，`ThemeService` 具备主题上传、切换、删除与刷新能力。
  - 补充阶段文档 `markdown/stage-15-upgrade-compatibility.md`，记录本阶段静态证据、运行态验证、剩余风险与放行判断。

- 测试结果：
  - 核心目录关键字反查：对 `app/`、`bootstrap/`、`config/`、`database/`、`resources/`、`routes/` 搜索 `WalletCenter|StripePayment|BepusdtPayment|XboardCustom|xc_wallet|wallet-center|i18n-extra` 未发现命中：通过
  - 官方主题目录反查：对 `theme/Xboard/` 搜索上述关键字未发现命中：通过
  - 插件识别验证：容器内查询插件表返回 `stripe_payment`、`bepusdt_payment`、`wallet_center` 且均为启用状态：通过
  - 插件路由验证：`php artisan route:list --path=stripe-payment --json`、`--path=bepusdt-payment --json`、`--path=wallet-center --json` 均返回对应插件控制器：通过
  - 插件计划任务验证：`php artisan schedule:list --json` 返回 `wallet-center:auto-renew-scan --limit=100 --due-only`：通过
  - 支付钩子验证：`php artisan hook:list` 返回 `payment.notify.before`：通过
  - 主题识别验证：容器内 `ThemeService::getList()` 返回 `Xboard`、`XboardCustom`、`v2board`，且当前主题配置为 `XboardCustom`：通过
  - 升级流程验证：静态复查 `xboard:update`，确认更新完成后会调用 `ThemeService::refreshCurrentTheme()`：通过
  - 运行态补充说明：
    - 当前仓库 `git` 分支尚无首个提交，无法基于提交差异直接生成“最终变更集中度”报告；本阶段改用“源码目录分布 + 关键字反查 + 运行态注册结果”完成兼容性验证
    - `XboardCustom` 仍以复制官方 `umi.js` 为前端基线，后续若官方 SPA 路由或前端运行时发生变化，需要同步刷新 `theme/XboardCustom/` 对应补丁文件；风险被限制在主题层

- 是否放行：
  - 是（阶段 15 放行条件已满足，可进入阶段 16）

## 当前状态补充（阶段 15）

- 当前执行阶段：阶段 15
- 当前阶段结果：已完成阶段 15 升级兼容性检查并放行
- 下一阶段：阶段 16（已满足进入条件，正在执行）

## 阶段 16

### 阶段 16：完成最终交付前审查

- 完成内容：
  - 新增最终审查文档 `markdown/stage-16-final-delivery-review.md`，集中整理交付物清单、功能完成度、测试证据、已知限制和范围外能力。
  - 对仓库实际目录执行交付物核对，确认三个插件源码资产分别固定在 `plugins/StripePayment/`、`plugins/BepusdtPayment/`、`plugins/WalletCenter/`，自定义主题资产固定在 `theme/XboardCustom/`。
  - 对插件元数据与路由进行抽查，确认 `StripePayment`、`BepusdtPayment`、`WalletCenter` 的 `config.json` 与 `routes/api.php` 均存在且可对应到后台/前台入口。
  - 对后台配置与记录体系进行抽查，确认 `stripe-payment/admin/overview`、`bepusdt-payment/admin/overview`、`wallet-center/admin/overview`、`wallet-center/admin/config` 以及三类后台记录入口均已落地到插件代码。
  - 对阶段文档与 `progress.md` 执行交接审计，确认阶段 1 至阶段 15 的完成内容、测试结果和放行判断均已保留；阶段 12 与阶段 14 虽未独立拆分文档，但测试证据已完整沉淀在 `progress.md`。
  - 按功能重新核对成功验证与异常/边界验证覆盖，确认 `StripePayment`、`BepusdtPayment`、签到、充值、自动续费、后台配置记录、自定义主题和新增语言均存在对应证据。
  - 归档已知限制与明确不纳入本次范围的能力，确认其与 `requirements.md`、`implementation-plan.md`、`task-checklist.md` 以及 `ai-dev-prompt.md` 的边界保持一致。

- 测试结果：
  - 交付物清单与实际目录核对：通过
  - 三个插件完成度核对：通过
  - 自定义主题完成度核对：通过
  - 后台配置完整性核对：通过
  - 记录体系完整性核对：通过
  - 阶段测试结果保留完整性核对：通过
  - 每个目标功能至少一组成功验证结果核对：通过
  - 每个目标功能至少一组异常/边界验证结果核对：通过
  - 已知限制与需求边界一致性核对：通过
  - 交付清单可直接供下一位 AI 开发者或维护者接手核对：通过

- 是否放行：
  - 是（阶段 16 放行条件已满足；全部 16 个阶段执行完成，可交付）

## 当前状态补充（阶段 16）

- 当前执行阶段：阶段 16
- 当前阶段结果：已完成阶段 16 最终交付前审查并放行
- 下一阶段：无（全部阶段完成）

## 2026-03-10 新增文件专项复核补充

### 本轮复核范围

- 复核 `plugins/StripePayment/`、`plugins/BepusdtPayment/`、`plugins/WalletCenter/` 与 `theme/XboardCustom/` 下本次新增文件。
- 重点检查支付回调幂等、充值订单状态流转、回跳地址安全、后台总览接口结构以及前端脚本可执行性。

### 本轮修复

- `plugins/WalletCenter/Services/TopupService.php`
  - 将 `markStatus()` 改为事务内 `lockForUpdate()` 加锁读取，避免充值成功回调与取消/超时回调并发时，后到的非成功状态覆盖已入账订单状态。
  - 对已进入 `processing`、`paid`、`cancelled`、`expired` 的订单改为记录忽略的状态回调信息，不再重复改写终态。
  - 充值下单时改为记录并复用实际发给支付网关的 `return_url`，避免订单扩展字段中的回跳地址与真实支付请求不一致。
- `plugins/WalletCenter/Services/TopupGatewayService.php`
  - `buildReturnUrl()` 改为仅信任 `config('app.url')` 或当前请求主机对应的来源地址，拒绝将任意外部 `Referer` 原样作为支付回跳地址。
  - 新增安全回跳地址构建与来源解析逻辑，在不可信来源场景下回退到站内 `/#/wallet?topup_trade_no=...` 地址。

### 本轮验证结果

- PHP 语法复核：
  - 通过容器内 `docker exec xboard-stage3-web php -l` 复核 33 个目标 PHP 文件，结果全部通过。
- JSON 结构复核：
  - `plugins/StripePayment/config.json`
  - `plugins/BepusdtPayment/config.json`
  - `plugins/WalletCenter/config.json`
  - `theme/XboardCustom/config.json`
  - 以上 4 个 JSON 文件均可成功解析。
- 前端脚本复核：
  - `theme/XboardCustom/assets/wallet-center.js` 通过 `node --check`
  - `theme/XboardCustom/assets/i18n-extra.js` 通过 `node --check`
  - `theme/XboardCustom/assets/umi.js` 通过 `node --check`

### 本轮结论

- `StripePayment`、`BepusdtPayment` 后台总览链路本轮未发现新的确定性 bug。
- `WalletCenter` 本轮确认并修复 2 项后端问题：充值状态并发竞争、支付回跳地址对外部 `Referer` 的过度信任。
- `theme/XboardCustom` 本轮未修改编译产物与压缩副本，现有脚本在语法层面可通过；`umi.js.br` 与 `umi.js.gz` 继续作为 `umi.js` 的发布副本保留。

## 当前状态补充（2026-03-10 专项复核）

- 当前执行阶段：阶段 16 后置专项复核
- 当前阶段结果：已完成新增文件专项审查，确认修复 2 项后端问题并补齐审计记录
- 下一阶段：无（如需继续推进，仅建议补充真实支付网关联调回归）

## 2026-03-10 仓库级回归复核补充

### 本轮复核范围

- 基于当前 `xboard-custom` 仓库重新复核可执行代码、部署脚本与主题发布副本。
- 重点检查 1Panel overlay 部署路径、主题加载优先级以及 `umi.js` 与压缩静态副本的一致性。

### 本轮修复

- `scripts/deploy-overlay.sh`
  - 将主题同步目标固定为 1Panel `compose` 挂载目录 `storage/theme/XboardCustom`，避免继续写入非持久化主路径。
  - 增加对遗留 `theme/XboardCustom` 根目录的清理逻辑，避免 `ThemeService` 优先读取旧根主题，导致新同步的 `storage/theme/XboardCustom` 实际不生效。
- `theme/XboardCustom/assets/umi.js.gz`
  - 基于当前 `theme/XboardCustom/assets/umi.js` 重新生成 gzip 压缩副本，消除压缩资源滞后风险。
- `theme/XboardCustom/assets/umi.js.br`
  - 基于当前 `theme/XboardCustom/assets/umi.js` 重新生成 brotli 压缩副本，确保压缩发布产物与源码一致。

### 本轮验证结果

- PHP 语法回归：
  - 使用容器镜像 `ghcr.io/cedar2025/xboard:new` 对仓库内 33 个 PHP 文件执行 `php -l`，全部通过。
- JSON 结构回归：
  - 4 个 `config.json` 文件均再次解析通过。
- 前端脚本回归：
  - `theme/XboardCustom/assets/wallet-center.js` 通过 `node --check`
  - `theme/XboardCustom/assets/i18n-extra.js` 通过 `node --check`
  - `theme/XboardCustom/assets/umi.js` 通过 `node --check`
- 发布资产一致性回归：
  - `theme/XboardCustom/assets/umi.js.gz` 已确认与 `umi.js` 内容一致
  - `theme/XboardCustom/assets/umi.js.br` 已确认与 `umi.js` 内容一致
- 部署脚本回归：
  - `scripts/deploy-overlay.sh` 已通过真实 Bash 语法检查

### 本轮结论

- 当前 `xboard-custom` 仓库新增确认并修复 2 项发布层问题：主题同步目标错误且可能被旧根主题覆盖、`umi.js` 压缩副本滞后于源码。
- 修复后，仓库的插件代码、主题源码、主题压缩副本与 1Panel overlay 部署脚本在静态层面已保持一致，可作为当前可交付版本继续维护。

## 当前状态补充（2026-03-10 仓库级回归）

- 当前执行阶段：阶段 16 后置仓库回归复核
- 当前阶段结果：已完成仓库级回归审查并修复 2 项发布层问题
- 下一阶段：无（如需继续推进，建议补充 1Panel 真实部署回归）
