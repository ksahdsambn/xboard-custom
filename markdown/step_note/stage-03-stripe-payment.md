# 阶段 3：Stripe 一次性支付插件

## 阶段目标

将 Stripe 以一次性 Checkout 托管跳转的方式接入普通订阅订单支付流程，并补齐阶段内必须具备的幂等与异常处理边界。

## 完成内容

- 新增支付插件资产 `plugins/StripePayment/`
- 新增 `StripePayment` 插件元数据文件 `config.json`
- 新增 `StripePayment` 主类 `Plugin.php`
- 通过插件 `boot()` 将支付方式键 `Stripe` 注册到 `available_payment_methods`
- 支付回调保持使用系统默认路径 `/api/v1/guest/payment/notify/Stripe/{uuid}`
- 通过插件钩子 `payment.notify.before` 在核心回调进入默认处理前接管 Stripe webhook
- 通过插件 `form()` 提供后台支付实例配置项：
  - `secret_key`
  - `webhook_secret`
  - `currency`
  - `product_name`
  - `session_expire_minutes`
  - `webhook_tolerance_seconds`
- 支付发起改为 Stripe Checkout 托管跳转页，不使用卡片直填，不使用保存卡信息，不实现循环扣款
- 成功回调使用 Stripe webhook 验签后，仅在以下条件同时满足时映射回普通订阅订单：
  - 事件类型为 `checkout.session.completed`
  - `payment_status = paid`
  - `trade_no` 可从 metadata / client reference 解析
  - 订单存在且仍为 `pending`
  - 回调金额与订单金额（含手续费）一致
  - 回调币种与实例配置一致
- 对以下场景做了显式忽略或拦截：
  - 取消支付
  - 失败支付
  - 超时事件
  - 未知事件
  - 非法签名
  - 金额不匹配
  - 币种不匹配
  - 订单不存在
  - 订单已支付/处理中
- 插件 `notify()` 内部直接完成 webhook 验签、事件分流、幂等开通与响应返回
- 插件内自带原子状态迁移逻辑，仅在订单仍为 `pending` 时把订单切换到 `processing` 并同步派发开通任务

## 核心改动回收说明

- 阶段 3 运行时验证期间，曾短暂尝试核心兼容补丁，但最终已经全部撤回。
- 当前阶段 3 最终结果：
  - `app/Http/Controllers/V1/Guest/PaymentController.php` 无定制残留
  - `app/Services/OrderService.php` 无定制残留
- Stripe webhook 所需原始 body 与 header 由插件直接从当前 Laravel 请求对象读取，不再依赖核心透传。
- 当前阶段 3 的 Stripe 定制点重新完全收敛到 `plugins/StripePayment/`。

## 测试与验证

### 已执行验证

- 验证新增插件目录、文件、命名、支付方式键与阶段 2 资产命名一致
- 验证插件声明类型为 `payment`
- 验证插件主类实现 `PaymentInterface`
- 验证后台配置项完整覆盖本阶段所需密钥、验签、币种、超时参数
- 验证支付流程为跳转式 Checkout，不依赖 `stripe_token`
- 验证后台插件列表可见 `stripe_payment`，并已可安装、启用
- 验证后台支付方式列表可见 `Stripe`
- 验证后台可获取完整表单字段：
  - `secret_key`
  - `webhook_secret`
  - `currency`
  - `product_name`
  - `session_expire_minutes`
  - `webhook_tolerance_seconds`
- 验证用户结算页支付方式列表可见 `Stripe`
- 验证成功 webhook 返回 `200 success`，订单状态进入 `completed`，`callback_no` 写入成功，订阅到期时间正确延长
- 验证重复成功 webhook 返回 `200 success`，订单与订阅状态不重复推进
- 验证取消事件 `payment_intent.canceled` 返回 `200 success`，订单保持 `pending`
- 验证超时事件 `checkout.session.expired` 返回 `200 success`，订单保持 `pending`
- 验证非法签名通知返回 `400`，订单保持 `pending`
- 验证订单幂等开通逻辑已收敛在插件内，而非核心 `OrderService::paid()`
- 验证在“容器仅挂载插件目录、不挂载任何核心文件”的条件下，以上运行时场景仍全部通过

### 本阶段未覆盖的残余风险

- 未使用真实 Stripe `sk_test_*` 凭证完成 Checkout Session 创建成功联调
- 该残余风险不影响本阶段强制验证项，但在后续真实端到端联调阶段仍需补测

## 风险与边界

- 当前阶段只实现 Stripe 一次性支付，不实现循环扣款、订阅制自动扣费、保存卡信息
- 普通订阅订单仍走核心 `v2_order` 与 `OrderService`，未混入 WalletCenter 逻辑
- 未对管理员后台语言资源做任何改动
- 阶段 3 最终不保留核心目录补丁，后续官方更新时 Stripe 功能不依赖手工回贴核心代码
- 真实联调前仍需在具备真实 Stripe 测试凭证的环境中完成 Checkout Session 创建成功验证

## 放行建议

- 结构实现：完成
- 运行时验证：通过（阶段 3 强制验证项全部通过）
- 放行建议：可放行进入阶段 4；但后续阶段 13 真实联调时仍需补做真实 Stripe 测试密钥验证
