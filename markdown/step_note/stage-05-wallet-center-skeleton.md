# 阶段 5：WalletCenter 插件骨架

## 阶段目标

建立统一承载签到、余额充值、余额自动续费的 WalletCenter 功能插件骨架，并在不修改核心支付/订单代码的前提下，明确三类业务的配置入口、前台入口、记录入口与执行入口。

## 完成内容

- 新增功能插件资产目录 `plugins/WalletCenter/`
- 新增插件元数据文件 `plugins/WalletCenter/config.json`
- 新增插件主类 `plugins/WalletCenter/Plugin.php`
- 新增 WalletCenter 能力边界定义 `plugins/WalletCenter/Support/WalletCenterFeature.php`
- 新增配置读取服务 `plugins/WalletCenter/Services/WalletCenterConfigService.php`
- 新增支付通道读取服务 `plugins/WalletCenter/Services/WalletCenterPaymentChannelService.php`
- 新增骨架清单服务 `plugins/WalletCenter/Services/WalletCenterManifestService.php`
- 新增前台/后台控制器骨架：
  - `CheckinController`
  - `TopupController`
  - `AutoRenewController`
  - `AdminController`
- 新增插件自有模型：
  - `CheckinLog`
  - `TopupOrder`
  - `AutoRenewSetting`
  - `AutoRenewRecord`
- 新增插件命令骨架 `wallet-center:auto-renew-scan`
- 新增插件 API 路由 `plugins/WalletCenter/routes/api.php`
- 新增插件自有数据表迁移：
  - `wallet_center_checkin_logs`
  - `wallet_center_topup_orders`
  - `wallet_center_auto_renew_settings`
  - `wallet_center_auto_renew_records`

## 本阶段骨架设计结论

### 业务边界

- `每日签到` 只使用 `wallet_center_checkin_logs` 记录签到相关数据，不触碰核心 `v2_order`。
- `余额充值` 只使用 `wallet_center_topup_orders` 承载充值交易，不复用核心普通订阅订单。
- `余额自动续费` 只使用 `wallet_center_auto_renew_settings` 和 `wallet_center_auto_renew_records` 承载配置与执行记录。

### 配置入口

- 继续复用现有后台插件配置入口 `/plugin/config`。
- 通过插件配置项为三类能力分别提供独立开关：
  - `checkin_enabled`
  - `topup_enabled`
  - `auto_renew_enabled`
- 同时为后续阶段预留奖励区间、充值金额范围和续费窗口配置字段。

### 前台入口

- 签到：
  - `/api/v1/wallet-center/checkin/status`
  - `/api/v1/wallet-center/checkin/claim`
  - `/api/v1/wallet-center/checkin/history`
- 充值：
  - `/api/v1/wallet-center/topup/methods`
  - `/api/v1/wallet-center/topup/create`
  - `/api/v1/wallet-center/topup/detail`
  - `/api/v1/wallet-center/topup/history`
  - `/api/v1/wallet-center/topup/notify/{method}/{uuid}`
- 自动续费：
  - `/api/v1/wallet-center/auto-renew/config`
  - `/api/v1/wallet-center/auto-renew/history`

### 记录入口

- 用户侧：
  - 签到历史 `/api/v1/wallet-center/checkin/history`
  - 充值历史 `/api/v1/wallet-center/topup/history`
  - 自动续费历史 `/api/v1/wallet-center/auto-renew/history`
- 后台侧：
  - `/api/v1/wallet-center/admin/checkin/logs`
  - `/api/v1/wallet-center/admin/topup/orders`
  - `/api/v1/wallet-center/admin/auto-renew/records`

### 执行入口

- 签到执行入口保留为 `/api/v1/wallet-center/checkin/claim`，阶段 6 再补正式逻辑。
- 充值执行入口保留为 `/api/v1/wallet-center/topup/create` 与 `/api/v1/wallet-center/topup/notify/{method}/{uuid}`，阶段 7 再补正式逻辑。
- 自动续费执行入口保留为命令 `wallet-center:auto-renew-scan`，阶段 8/9 再补正式逻辑与调度注册。

### 默认暴露策略

- 插件启用后，三类子能力默认仍保持 `false`，避免阶段 5 骨架在前台提前暴露未完成业务。
- 当单独开启某一能力时，仅该能力对应入口返回骨架响应，其余能力继续返回禁用结果。

## 测试与验证

### 已执行验证

- 验证 `wallet_center` 插件可安装、可启用
- 验证插件启用后四张插件自有数据表全部创建成功
- 验证 `wallet-center:auto-renew-scan` 命令在插件启用时可被识别并执行
- 验证启用 WalletCenter 后，`PaymentService::getAllPaymentMethodNames()` 仍返回既有支付方式，包含 `Stripe` 与 `BEpusdt`
- 验证当仅开启 `checkin_enabled = true` 时：
  - `/api/v1/wallet-center/checkin/status` 返回 `200`
  - `/api/v1/wallet-center/topup/methods` 返回 `403`
  - `/api/v1/wallet-center/auto-renew/config` 返回 `403`
- 验证当仅开启 `topup_enabled = true` 时：
  - `/api/v1/wallet-center/topup/methods` 返回 `200`
  - 返回的充值可选通道仅包含当前已启用支付实例 `Stripe Stage3` 与 `BEpusdt Stage4`
  - `/api/v1/wallet-center/checkin/status` 返回 `403`
  - `/api/v1/wallet-center/auto-renew/config` 返回 `403`
- 验证当仅开启 `auto_renew_enabled = true` 时：
  - `/api/v1/wallet-center/auto-renew/config` 返回 `200`
  - `/api/v1/wallet-center/checkin/status` 返回 `403`
  - `/api/v1/wallet-center/topup/methods` 返回 `403`
- 验证禁用 `wallet_center` 插件后：
  - `/api/v1/wallet-center/topup/notify/Stripe/test-disabled` 返回 `404`
  - `wallet-center:auto-renew-scan` 命令不再可用

### 骨架占位行为

- 签到执行接口当前返回 `501` 占位响应，等待阶段 6 实现
- 充值创建与充值通知接口当前返回 `501` 占位响应，等待阶段 7 实现
- 自动续费设置写入接口当前返回 `501` 占位响应，等待阶段 8 实现
- 自动续费命令当前只验证命令注册与插件状态，不执行调度扫描

## 风险与边界

- 本阶段只建立骨架，不提前实现签到发奖、充值入账、自动续费扣款逻辑
- 本阶段未修改 `PaymentService`、`OrderService`、`PaymentController`、`Console\Kernel` 等核心目录
- 充值只是读取已启用支付实例清单，真正的充值下单与充值回调处理将在阶段 7 实现
- 自动续费命令已注册但未接入调度，计划任务注册仍保留到阶段 9

## 放行建议

- 结构实现：完成
- 运行时验证：通过（阶段 5 强制验证项全部通过）
- 放行建议：可放行进入阶段 6，但当前回合不自动推进
