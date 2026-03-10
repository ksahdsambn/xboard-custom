# 阶段 10：实现后台配置与记录体系

## 目标

在不修改核心业务代码的前提下，为 Stripe、BEpusdt、WalletCenter 补齐后台配置、状态展示、记录查询与失败原因查看能力，并保证关闭单项功能后前台入口与后台执行同步失效。

## 完成内容

- 为 `plugins/StripePayment/` 新增后台总览接口：
  - `GET /api/v1/stripe-payment/admin/overview`
  - 展示支付插件状态、后台配置字段、实例列表、通知地址、实例就绪状态与资金动作类型 `subscription_payment`
- 为 `plugins/BepusdtPayment/` 新增后台总览接口：
  - `GET /api/v1/bepusdt-payment/admin/overview`
  - 展示支付插件状态、后台配置字段、实例列表、运行模式、通知地址、实例就绪状态与资金动作类型 `subscription_payment`
- 为 `plugins/WalletCenter/` 新增后台配置与总览接口：
  - `GET /api/v1/wallet-center/admin/config`
  - `POST /api/v1/wallet-center/admin/config`
  - `GET /api/v1/wallet-center/admin/overview`
  - 支持按插件内白名单安全合并配置，单独控制 `checkin_enabled`、`topup_enabled`、`auto_renew_enabled`
- 增强 WalletCenter 后台记录接口：
  - `GET /api/v1/wallet-center/admin/checkin/logs`
  - `GET /api/v1/wallet-center/admin/topup/orders`
  - `GET /api/v1/wallet-center/admin/auto-renew/records`
  - 返回记录摘要、最新失败记录、状态统计
- 为 WalletCenter 资金动作记录补齐可读字段：
  - 充值记录新增 `status_label`、`fund_activity_type`、`failure_reason`、`failure_message`
  - 自动续费记录新增 `status_label`、`fund_activity_type`、`reason_message`
- 在 WalletCenter 后台总览中显式区分三类资金动作：
  - `subscription_payment`
  - `balance_topup`
  - `auto_renew_execution`

## 验证测试

- `php artisan route:list --path=api/v1/stripe-payment`：通过
- `php artisan route:list --path=api/v1/bepusdt-payment`：通过
- `php artisan route:list --path=api/v1/wallet-center/admin`：通过
- 容器内对新增与修改文件执行 `php -l` 语法检查：全部通过
- `GET /api/v1/stripe-payment/admin/overview` 返回 `200`，包含已启用 Stripe 实例、后台配置字段与 `subscription_payment` 资金动作类型：通过
- `GET /api/v1/bepusdt-payment/admin/overview` 返回 `200`，包含已启用 BEpusdt 实例、后台配置字段与 `subscription_payment` 资金动作类型：通过
- `POST /api/v1/wallet-center/admin/config` 可单独关闭 `topup_enabled`，随后：
  - `GET /api/v1/wallet-center/topup/methods` 返回 `403`：通过
  - `GET /api/v1/wallet-center/admin/topup/orders` 返回 `403`：通过
  - `GET /api/v1/wallet-center/topup/notify/BEpusdt/{uuid}` 返回 `403`：通过
- 恢复 `topup_enabled = true` 后，`GET /api/v1/wallet-center/topup/methods` 恢复返回 `200`：通过
- `GET /api/v1/wallet-center/admin/checkin/logs`、`GET /api/v1/wallet-center/admin/topup/orders`、`GET /api/v1/wallet-center/admin/auto-renew/records` 均返回 `200`：通过
- `GET /api/v1/wallet-center/admin/overview` 可同时看到三类资金动作映射，且充值失败原因与自动续费失败原因可读：通过
- `POST /api/v2/{secure_path}/payment/getPaymentForm` 对 `Stripe` 与 `BEpusdt` 实例均可返回后台配置表单：通过

## 风险与边界

- Stripe 与 BEpusdt 的后台配置写入仍复用核心支付实例管理入口；本阶段新增的是插件内后台状态与配置可视化接口，不复制核心支付保存逻辑。
- WalletCenter 的后台配置写入口仅写入插件 `config.json` 已声明字段，不接受未声明键，避免污染插件配置。
- 本阶段未改动核心订单、支付、订阅开通逻辑，所有新增后台能力继续收敛在插件目录内。

## 放行结论

- 阶段 10 强制验证项：全部通过
- 是否放行进入阶段 11：是
