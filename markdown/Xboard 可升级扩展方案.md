# Xboard 可升级扩展方案

## Summary

- 本项目通过插件和自定义主题实现扩展，不修改官方核心目录。
- 为了保持后续可跟进作者更新，所有定制内容都限制在 `plugins/` 与 `theme/` 源码目录内，生产部署时再同步到官方运行目录的 `plugins/` 与 `storage/theme/`。
- 当前项目仍只支持前台 `Xboard` 主题，不改管理员后台语言。
- 长期维护风险主要集中在前台主题资产 `theme/XboardCustom/`，因为这里复用了官方已编译前端 bundle；支付插件与钱包插件本身不会阻断你继续更新官方底座。

## Key Changes

- 新增 `plugins/StripePayment` 支付插件。
  - 作为普通订阅订单的一次性 Stripe Checkout 支付通道。
  - 支付方式键为 `Stripe`，插件代码为 `stripe_payment`。
  - 插件配置包含：`secret_key`、`webhook_secret`、币种、会话超时、显示名称等。

- 新增 `plugins/BepusdtPayment` 支付插件。
  - 作为普通订阅订单的一次性 BEpusdt 支付通道。
  - 支付方式键为 `BEpusdt`，插件代码为 `bepusdt_payment`。
  - 基于 BEpusdt 的公开接口完成下单、跳转、签名校验和成功入账。
  - 插件配置包含：`base_url`、`api_token`、法币、网络限制、超时、显示名称等。

- 新增 `plugins/WalletCenter` 功能插件，统一承载 3 个余额相关能力。
  - `每日签到随机余额`
  - `余额充值`
  - `余额自动续费`
  - 充值链路复用已启用的支付插件实例，但使用 WalletCenter 自己的订单表、通知路由和服务层处理，不走核心默认 `v2_order` 回调链。

- 新增 `theme/XboardCustom` 自定义主题源码。
  - 源码目录位于仓库 `theme/XboardCustom/`
  - 生产部署目标位于官方运行目录 `storage/theme/XboardCustom`
  - 保留原 7 种语言，并新增 13 种语言
  - 补齐签到、充值、自动续费相关前台入口与文案

## Public Interfaces

- 交付代码入口固定为：
  - `plugins/StripePayment/`
  - `plugins/BepusdtPayment/`
  - `plugins/WalletCenter/`
  - `theme/XboardCustom/`

- WalletCenter 前缀接口统一为 `/api/v1/wallet-center/*`
  - `checkin/status`
  - `checkin/claim`
  - `topup/create`
  - `topup/checkout`
  - `topup/detail`
  - `topup/notify/{method}/{uuid}`
  - `auto-renew/config`

- 新增插件自有表至少包括：
  - `wallet_center_checkin_logs`
  - `wallet_center_topup_orders`
  - `wallet_center_auto_renew_settings`
  - `wallet_center_auto_renew_records`

## Upgrade Strategy

- 官方底座继续按作者 1Panel compose 方案安装和升级。
- 自定义源码独立维护在 `xboard-custom` 仓库中。
- 部署时使用 overlay 同步：
  - `plugins/*` 同步到官方运行目录 `plugins/*`
  - `theme/XboardCustom` 同步到官方运行目录 `storage/theme/XboardCustom`
- 官方更新后，只需要重新执行一次 overlay 同步，即可恢复全部自定义层。
- 若后续官方 `theme/Xboard` 的 `umi.js`、路由或本地存储键发生变化，需要同步刷新 `theme/XboardCustom/` 的主题层补丁资产。

## Test Plan

- Stripe
  - 下单后能跳转到 Stripe Checkout
  - webhook 验签成功/失败逻辑正确
  - 重复回调不重复入账
  - 已取消或超时订单不会误开通

- BEpusdt
  - 创建订单成功并返回收银台地址
  - 回调签名校验通过/失败逻辑正确
  - `status=1` 不入账，`status=2` 入账
  - 重复通知不重复处理

- WalletCenter
  - 签到同用户同日只能领一次
  - 充值能复用多个已启用支付实例
  - 重复充值回调不会双倍加余额
  - 自动续费仅在余额足够且计划允许时触发

- Theme / Upgrade
  - `Xboard` 前台 20 种语言切换正常
  - 签到、充值、自动续费入口在前台可见
  - 按 1Panel 官方更新流程执行后，`plugins/` 与 `storage/theme/` 中的自定义内容仍可通过 overlay 恢复

## Assumptions

- 继续使用官方 1Panel compose 结构，不自建长期维护镜像
- 本期只支持前台 `Xboard` 主题，不做 `v2board`
- Stripe 与 BEpusdt 都只支持一次性支付，不支持循环扣款
- 余额自动续费只使用余额，不回落到其他支付通道
- 真实支付密钥、BEpusdt 网关地址、后台配置仍由管理员在部署后填写
