# 阶段 13 端到端联调记录

## 文档目的

记录阶段 13 “执行端到端联调”的实际执行结果、关键订单号、余额变化、有效期变化与阻塞项，作为阶段放行判断依据。

## 联调环境

- 运行容器：`xboard-stage3-web`
- 数据库：`.stage3-runtime/.docker/.data/database.sqlite`
- 当前启用支付实例：
  - `Stripe Stage3`（`payment = Stripe`，`uuid = pljo1uAf`）
  - `BEpusdt Stage4`（`payment = BEpusdt`，`uuid = Ou8NVdjY`）
- 当前计划：
  - `Stage3 Monthly Plan`
  - 月付金额：`1234` 分
- 阶段 13 测试账号：
  - `stage13-stripe@example.com`
  - `stage13-wallet@example.com`

## 执行结果

### 1. 支付插件并存检查

- `GET /api/v1/user/order/getPaymentMethod` 同时返回 `Stripe Stage3` 与 `BEpusdt Stage4`
- `GET /api/v1/wallet-center/topup/methods` 同时返回 `Stripe Stage3` 与 `BEpusdt Stage4`
- 结论：支付插件并存显示正常

### 2. Stripe 普通订阅联调

- 订单号：`2026031005035608771897869`
- `POST /api/v1/user/order/save`：成功
- `POST /api/v1/user/order/checkout` 选择 `Stripe`：返回 `HTTP 400`
- 同订单补充发送签名正确的 `checkout.session.completed` webhook 后：
  - 订单状态：`pending -> completed`
  - `callback_no`：`evt_stage13_subscription_success`
  - 用户订阅：成功开通
- 结论：
  - Stripe webhook 回写链路可用
  - Stripe 实际 Checkout Session 创建未打通

### 3. BEpusdt 普通订阅联调

- 订单号：`2026031005032422719334714`
- `POST /api/v1/user/order/save`：成功
- `POST /api/v1/user/order/checkout`：返回 `http://127.0.0.1:18080/pay?trade_no=2026031005032422719334714`
- 成功通知后：
  - 订单状态：`pending -> completed`
  - `callback_no`：`stage13-bep-sub-block-1`
  - 用户订阅：成功开通
- 结论：BEpusdt 普通订阅链路在当前环境已打通

### 4. 签到 -> 充值 -> 自动续费 联调

- 签到：
  - 奖励：`380`
  - 余额：`0 -> 380`
- 充值：
  - 订单号：`2026031005033432135054785`
  - 金额：`1000`
  - 支付方式：`BEpusdt`
  - 成功通知后余额：`380 -> 1380`
- 自动续费：
  - 开启自动续费成功
  - 将订阅推进到到期前 24 小时窗口后，系统成功执行续费
  - 续费金额：`1234`
  - 余额：`1380 -> 146`
  - `expired_at`：`2026-03-10T05:57:11+08:00 -> 2026-04-09T05:57:11+08:00`
  - 自动续费记录数：`1`
- 结论：签到、充值、自动续费顺序串联正常

## 记录隔离检查

- 核心普通订阅订单：`v2_order`
  - `2026031005035608771897869`（Stripe）
  - `2026031005032422719334714`（BEpusdt）
- 签到记录：`wallet_center_checkin_logs`
  - 1 条成功签到记录，奖励 `380`
- 充值记录：`wallet_center_topup_orders`
  - 1 条成功充值记录，金额 `1000`
- 自动续费记录：`wallet_center_auto_renew_records`
  - 1 条成功续费记录，金额 `1234`
- 结论：三类资金动作记录与核心订阅订单记录未混淆

## 阻塞项

- `Stripe Stage3` 当前配置中的 `secret_key` 仍为占位值 `sk_test_placeholder`
- 因此，阶段 13 无法完成“Stripe 普通订阅链路完整可用”的放行条件
- 当前 `BEpusdt` 联调依赖容器内临时 mock 网关 `http://127.0.0.1:18080`

## 阶段结论

- 本阶段主要业务闭环已覆盖：
  - Stripe webhook 回写开通
  - BEpusdt 普通订阅
  - 签到加余额
  - 充值加余额
  - 自动续费扣减余额并延长有效期
  - 记录隔离与插件并存显示
- 本阶段未满足放行条件：
  - `Stripe` 实际 Checkout Session 创建未打通
- 放行结论：`否`
