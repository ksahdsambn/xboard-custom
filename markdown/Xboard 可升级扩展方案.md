# Xboard 可升级扩展方案

## Summary

- 可以实现这 6 个功能。
- 可以尽量保持后续正常跟进作者更新，但前提是严格把定制内容限制在 `plugins/` 和 `storage/theme/` 两个挂载目录内，不改官方镜像里的核心 `app/` 与内置 `theme/`。这和 [1Panel 安装文档](https://github.com/cedar2025/Xboard/blob/master/docs/en/installation/1panel.md) 的挂载方式一致。
- 本期范围按你的确认只支持前台 `Xboard` 主题；管理员后台语言不改。
- 唯一需要人工持续合并的部分是 `Xboard` 前台主题，因为仓库里提供的是已编译资产，不是前端源码；上游如果后续改了 `Xboard` 前台 bundle，你的自定义主题要手工比对合并。支付插件和钱包功能插件本身不影响你继续 `pull` 上游更新。

## Key Changes

- 新增 `plugins/StripeCheckout` 支付插件。
  - 使用 Stripe Checkout 托管跳转页，不做循环扣款，不做卡片直填。
  - 走通用“下单后跳转支付页”流程，避免依赖当前 `Xboard` 前台并不存在的 `StripeCredit` 专用表单逻辑。
  - 插件配置包含：`secret key`、`webhook secret`、币种、成功/取消跳转地址、超时与显示名称。

- 新增 `plugins/BEpusdt` 支付插件。
  - 基于 BEpusdt 的 [README](https://github.com/v03413/BEpusdt) 与 [API 文档](https://github.com/v03413/BEpusdt/blob/master/docs/api/api.md) 对接。
  - 采用 `create-order` 或 `create-transaction` 获取 `payment_url` 跳转，回调按文档签名算法验签，只把成功支付状态作为入账条件。
  - 插件配置包含：`API base URL`、`API Token`、法币、币种/网络限制、超时、显示名称。

- 新增 1 个功能插件 `plugins/WalletCenter`，统一承载 3 个余额相关功能。
  - `每日签到随机余额`：后台配置最小值、最大值、开关；用户每天只能领取一次；写独立签到日志表。
  - `余额充值`：用户输入自定义金额；列出当前已启用支付通道；创建插件自有充值订单，不复用核心 `v2_order`；支付成功后幂等增加 `v2_user.balance`。
  - `余额自动续费`：用户可开关并选择续费周期；定时任务在到期前 24 小时尝试；仅当余额足够全额覆盖时才创建并完成续费订单，余额不足直接跳过，不生成待支付残单、不冻结部分余额。
  - 充值与回调复用现有支付插件机制，直接调用当前支付插件实例与配置；不要走核心 [PaymentService.php](D:\codex\Xboard-master\app\Services\PaymentService.php) 的默认订单回调处理链去匹配 `v2_order`，而是使用插件自己的充值通知路由与订单表。插件加载与支付注册继续依赖 [PluginManager.php](D:\codex\Xboard-master\app\Services\Plugin\PluginManager.php)。

- 新增 `storage/theme` 下的自定义 `Xboard` 主题副本。
  - 保留现有 7 种语言，再补 13 种：`fr-FR`、`de-DE`、`es-ES`、`it-IT`、`pt-PT`、`nl-NL`、`pl-PL`、`tr-TR`、`ru-RU`、`ar-SA`、`nb-NO`、`sv-SE`、`fi-FI`。
  - 补齐充值、签到、自动续费相关前台入口与文案。
  - 由于 `Xboard` 主题当前是编译产物，需基于 [dashboard.blade.php](D:\codex\Xboard-master\theme\Xboard\dashboard.blade.php) 和其对应 bundle 做主题覆盖，管理员后台语言完全不动。

## Public Interfaces

- 新支付方式代码：`StripeCheckout`、`BEpusdt`。
- 新功能接口前缀建议统一为 `/api/v1/wallet-center/*`。
  - `checkin/status`
  - `checkin/claim`
  - `topup/create`
  - `topup/checkout`
  - `topup/detail`
  - `topup/notify/{method}/{uuid}`
  - `auto-renew/config`
- 新插件自有表建议至少包含：
  - `wallet_center_checkin_logs`
  - `wallet_center_topup_orders`
  - `wallet_center_auto_renew_settings`

## Test Plan

- Stripe：
  - 下单跳转成功。
  - webhook 验签成功/失败。
  - 重复回调不重复入账。
  - 已取消或超时订单不会误开通。

- BEpusdt：
  - 创建订单成功并返回收银台地址。
  - 回调签名校验通过/失败。
  - `status=1` 不入账，`status=2` 入账，超时状态不入账。
  - 重复通知不重复处理。

- WalletCenter：
  - 签到同用户同日只能领一次，奖励值始终落在配置区间内。
  - 充值可复用多个已启用通道，到账金额与手续费规则正确。
  - 重复充值回调不会双倍加余额。
  - 自动续费仅在“余额足够 + 计划允许续费 + 无未完成订单”时触发。
  - 到期前 24 小时续费成功后，用户有效期正确延长；余额不足时不产生脏订单。

- Theme / Upgrade：
  - `Xboard` 前台 20 种语言切换正常，原有 7 种不回归。
  - 签到、充值、自动续费入口在 `Xboard` 前台可见且文案完整。
  - 按 [1Panel 更新命令](https://github.com/cedar2025/Xboard/blob/master/docs/en/installation/1panel.md) 执行升级后，`plugins/` 与 `storage/theme/` 自定义内容仍保留。

## Assumptions

- 继续使用官方 1Panel compose 结构，不改为自建长期维护镜像。
- 本期只支持前台 `Xboard` 主题，不做 `v2board`。
- Stripe 采用托管跳转页。
- 余额充值采用自定义金额。
- 自动续费只用余额，不回落到其他支付通道。
- 你已部署好 BEpusdt 服务，后续可提供 API Token 与访问地址。
