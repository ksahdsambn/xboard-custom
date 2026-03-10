# 阶段 4：BEpusdt 一次性支付插件

## 阶段目标

将 BEpusdt 作为普通支付通道接入普通订阅订单支付流程，并在插件内部完成签名校验、状态分流、幂等开通与异常响应。

## 完成内容

- 新增支付插件资产目录 `plugins/BepusdtPayment/`
- 新增 `BEpusdtPayment` 插件元数据文件 `config.json`
- 新增 `BEpusdtPayment` 插件主类 `Plugin.php`
- 通过插件 `boot()` 将支付方式键 `BEpusdt` 注册到 `available_payment_methods`
- 通过插件钩子 `payment.notify.before` 在核心默认回调处理前接管 `/api/v1/guest/payment/notify/BEpusdt/{uuid}`
- 提供后台支付实例配置项：
  - `base_url`
  - `api_token`
  - `fiat`
  - `trade_type`
  - `currencies`
  - `payment_address`
  - `order_name`
  - `timeout_seconds`
  - `rate`
- 默认使用 BEpusdt `create-order` 接口创建收银台订单；当配置 `trade_type` 时切换为 `create-transaction`，从而支持“网络限制”场景
- 使用官方文档的参数排序 + 追加 Token + MD5 小写规则实现下单签名与回调验签
- 对回调状态做显式分流：
  - `status = 1`：等待支付，确认接收但不推进订单
  - `status = 2`：支付成功，校验签名、金额、订单存在且仍为 `pending` 后再推进开通
  - `status = 3`：支付超时，确认接收但不推进订单
  - 其它状态：记录告警并确认接收，不污染订单状态
- 在插件内部实现订单原子状态迁移与同步开通，仅在订单仍为 `pending` 时将其切换到 `processing` 并派发开通任务
- 不实现循环扣款、自动续费扣链上资产或其它非本阶段范围能力

## 目录命名说明

- 资产名仍为 `BEpusdtPayment`，插件代码仍为 `bepusdt_payment`
- 运行时目录实际使用 `plugins/BepusdtPayment/`
- 原因：`PluginManager::getPluginPath()` 会对插件代码执行 `Str::studly('bepusdt_payment')`，其结果为 `BepusdtPayment`
- 阶段 4 已按运行时实际解析规则落地，避免插件无法被系统加载

## 测试与验证

### 已执行验证

- 验证后台插件列表可见 `bepusdt_payment`，且插件已安装、启用
- 验证后台支付方式列表可见 `BEpusdt`
- 验证后台支付表单字段完整覆盖本阶段所需配置项
- 验证已启用支付实例存在，用户普通订阅结算场景可选择 `BEpusdt`
- 通过容器内临时 mock BEpusdt 服务验证下单流程返回收银台跳转地址
- 验证 `status = 1` 等待支付通知返回 `success`，订单保持 `pending`
- 验证 `status = 2` 支付成功通知返回 `success`，订单正确开通，`callback_no` 正确写入
- 验证重复成功通知返回 `success` 且不重复开通
- 验证 `status = 3` 支付超时通知返回 `success`，订单保持 `pending`
- 验证签名异常通知返回 `400`，订单保持 `pending`
- 额外验证 `trade_type = usdt.trc20` 的单网络模式可正常走 `create-transaction` 分支并返回收银台地址
- 验证环境仍为“容器仅挂载插件目录，不挂载任何核心文件”的模式，确认阶段 4 未依赖核心目录补丁

### 本阶段残余风险

- 本阶段下单联调使用容器内临时 mock BEpusdt 服务验证请求结构与跳转行为，尚未连接真实已部署的 BEpusdt 服务
- 真实网关环境中的时钟同步、网络连通性、实际汇率/交易类型可用性仍需在后续真实联调阶段补测
- 上述残余风险不影响本阶段强制验证项判定，因为本阶段已完成官方 API 规则对齐、插件内状态分流和幂等回调验证

## 风险与边界

- 当前阶段只实现 BEpusdt 一次性支付，不实现循环扣款
- 普通订阅订单仍走系统既有 `v2_order` / `OrderService` 体系，未混入 WalletCenter 逻辑
- 未对管理员后台语言资源做任何改动
- 当前阶段最终不保留核心目录补丁，BEpusdt 定制点完全收敛在 `plugins/BepusdtPayment/`

## 放行建议

- 结构实现：完成
- 运行时验证：通过（阶段 4 强制验证项全部通过）
- 放行建议：可放行进入阶段 5，但当前回合不自动推进
