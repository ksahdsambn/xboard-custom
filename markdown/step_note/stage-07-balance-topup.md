# 阶段 7：实现余额充值功能

## 实现范围

- 仅在 `plugins/WalletCenter/` 内实现充值正式逻辑，不修改核心目录。
- 仅推进 WalletCenter 充值能力，不提前进入阶段 8 自动续费或阶段 11 前台主题入口改造。

## 完成内容

- 新增 `plugins/WalletCenter/Services/TopupGatewayService.php`：
  - 复用当前已启用支付插件实例，支持 `Stripe` 与 `BEpusdt` 两种充值通道。
  - 为充值场景生成独立通知地址 `/api/v1/wallet-center/topup/notify/{method}/{uuid}`。
  - 为充值场景生成独立返回地址，并在回调内分别完成 Stripe webhook 验签与 BEpusdt 签名校验。
  - 统一输出充值回调结果，避免混入核心普通订单通知链。
- 新增 `plugins/WalletCenter/Services/TopupService.php`：
  - 统一处理充值金额范围校验、可用支付通道过滤、充值订单创建、前后台记录查询和通知状态流转。
  - 使用 `wallet_center_topup_orders` 作为充值独立订单表，不复用核心 `v2_order`。
  - 充值成功时使用事务、订单锁和用户锁，复用核心 `App\Services\UserService::addBalance()` 完成余额原子入账。
  - 对重复成功回调做幂等保护，避免重复加余额。
- 改造 `plugins/WalletCenter/Controllers/TopupController.php`：
  - `methods` 返回正式充值通道清单与金额区间。
  - `create` 返回正式充值下单结果。
  - `detail` / `history` 返回用户充值订单详情与历史。
  - `notify` 接入正式充值回调处理。
- 改造 `plugins/WalletCenter/Controllers/AdminController.php`：
  - `topupOrders` 返回后台充值记录，并附带用户邮箱与支付方式信息。
- 改造 `plugins/WalletCenter/Services/WalletCenterPaymentChannelService.php`：
  - 补充按 `payment_id`、按 `payment + uuid` 查找启用支付实例的能力。
  - 补充充值创建时所需的支付通道快照输出。
- 改造 `plugins/WalletCenter/Models/TopupOrder.php`：
  - 补充充值状态映射、状态标签、时间字段转换和 `user()` / `payment()` 关联。

## 验证结果

### 成功链路

- `GET /api/v1/wallet-center/topup/methods` 返回：
  - 已启用充值通道 `Stripe Stage3`、`BEpusdt Stage4`
  - 充值金额区间 `100 ~ 500000`
- 使用 `payment_id = 2`、`amount = 2300` 调用 `POST /api/v1/wallet-center/topup/create`：
  - 返回 `200`
  - 成功创建充值订单 `trade_no = 2026030918035307522488760`
  - 返回独立支付跳转地址 `http://127.0.0.1:18080/pay?trade_no=2026030918035307522488760`
- 对该订单发送 BEpusdt 成功回调：
  - 返回 `success`
  - 订单状态从 `pending` 变为 `paid`
  - 用户余额从 `0` 增加到 `2300`
  - 用户 `plan_id` 保持 `1`
  - 用户 `expired_at` 保持 `1786221789`
- 额外创建 Stripe 充值测试订单 `trade_no = 2026030918041599988877701`，并发送签名正确的 `checkout.session.completed` webhook：
  - 返回 `success`
  - 订单状态从 `pending` 变为 `paid`
  - 用户余额从 `2300` 增加到 `3000`
  - 用户 `plan_id` 与 `expired_at` 均保持不变

### 失败链路与幂等验证

- 对充值订单 `trade_no = 2026030918034989698446113` 发送 BEpusdt 超时回调：
  - 订单状态变为 `expired`
  - 用户余额保持 `0`
  - 用户订阅状态保持不变
- 对已成功入账的 BEpusdt 订单重复发送同一条成功回调：
  - 返回 `success`
  - 用户余额保持 `2300`
  - 未发生重复加余额

### 前后台记录与通道开关验证

- `GET /api/v1/wallet-center/topup/detail` 可查看单笔充值订单详情。
- `GET /api/v1/wallet-center/topup/history` 可查看前台充值历史，已正确展示：
  - 成功充值记录
  - 超时充值记录
  - 通道与金额信息
- `GET /api/v1/wallet-center/admin/topup/orders` 可查看后台充值记录，并附带：
  - `user.email`
  - `payment.name`
  - 订单状态、金额、到账时间
- 临时禁用 `BEpusdt Stage4` 并重启 `xboard-stage3-web` 容器后：
  - `GET /api/v1/wallet-center/topup/methods` 仅返回 `Stripe Stage3`
  - 后台充值记录接口中的 `payment_channels` 也仅返回 `Stripe Stage3`
- 恢复启用 `BEpusdt Stage4` 并重启容器后：
  - 充值通道列表恢复为 `Stripe + BEpusdt`

### 静态检查

- 以下文件均通过 `php -l` 语法检查：
  - `plugins/WalletCenter/Services/TopupGatewayService.php`
  - `plugins/WalletCenter/Services/TopupService.php`
  - `plugins/WalletCenter/Controllers/TopupController.php`
  - `plugins/WalletCenter/Controllers/AdminController.php`
  - `plugins/WalletCenter/Services/WalletCenterPaymentChannelService.php`
  - `plugins/WalletCenter/Models/TopupOrder.php`

## 边界与异常处理

- 充值订单、充值状态和充值记录全部收敛在 `wallet_center_topup_orders`，与核心普通订阅订单体系保持结构隔离。
- 充值成功只调用核心 `UserService::addBalance()` 入账，不调用核心订阅开通逻辑，因此不会修改当前订阅。
- 充值回调按支付方式分别验签，只有验签通过且订单仍为待支付时才允许入账。
- 阶段验证期间继续沿用“外部修改 SQLite 数据后重启 `xboard-stage3-web` 容器”的运行规范，避免 `Octane + SQLite` 旧连接导致读取旧状态。
- 当前阶段 BEpusdt 下单验证使用容器内临时 mock 服务模拟 `create-order` 接口，因为测试环境中的 `127.0.0.1:18080` 未常驻真实网关；Stripe 充值下单仍受占位测试密钥限制，因此本阶段对 Stripe 充值重点验证了回调验签与入账链路。

## 放行结论

- 充值订单与普通订阅订单完全区分：满足
- 充值成功只影响余额：满足
- 充值幂等性成立：满足
- 阶段 7 放行：是
