# 阶段 1：项目基线与扩展边界

## 阶段目标

基于当前代码仓库，确认支付、订单、插件、主题、计划任务、余额相关的现有扩展点与边界，并明确本次开发的最小侵入路线。

## 1. 支付扩展基线

### 1.1 接入契约

- 支付插件统一实现 `App\Contracts\PaymentInterface`。
- 统一契约包含 `form()`、`pay($order)`、`notify($params)` 三个入口。
- 现有支付插件样例位于 `plugins/AlipayF2f`、`plugins/Btcpay`、`plugins/Coinbase`、`plugins/CoinPayments`、`plugins/Epay`、`plugins/Mgate`。

### 1.2 支付方式注册机制

- `App\Services\PaymentService::getAvailablePaymentMethods()` 通过 `HookManager::filter('available_payment_methods', $methods)` 收集可用支付方式。
- 现有支付插件在各自 `Plugin.php` 的 `boot()` 中向 `available_payment_methods` 注册自身。
- 后台支付管理通过 `App\Http\Controllers\V2\Admin\PaymentController::getPaymentMethods()` 调用 `PaymentService::getAllPaymentMethodNames()` 获取支付方式标识列表。

### 1.3 支付配置与运行时绑定

- 后台支付方式实例存储在 `v2_payment` 表，对应模型 `App\Models\Payment`。
- `v2_payment.payment` 字段保存支付方式标识，例如 `Coinbase`、`MGate`。
- `PaymentService` 通过支付方式标识找到对应插件，再把 `v2_payment.config`、`enable`、`uuid`、`notify_domain` 注入插件实例。

### 1.4 支付结果回写路径

1. 用户下单：`App\Http\Controllers\V1\User\OrderController::save()`
2. 用户结算：`App\Http\Controllers\V1\User\OrderController::checkout()`
3. 发起支付：`App\Services\PaymentService::pay()`
4. 第三方回调入口：`/api/v1/guest/payment/notify/{method}/{uuid}`
5. 回调验证：`App\Http\Controllers\V1\Guest\PaymentController::notify()`
6. 标记已支付：`App\Services\OrderService::paid()`
7. 同步派发开通：`App\Jobs\OrderHandleJob::dispatchSync()`
8. 正式开通：`App\Services\OrderService::open()`

### 1.5 当前支付扩展边界

- 普通订阅支付插件接入点已经存在，阶段 3 和阶段 4 可以直接复用。
- 核心支付回调处理 `App\Http\Controllers\V1\Guest\PaymentController::handle()` 目前强绑定 `App\Models\Order` 与 `OrderService`。
- 这意味着“余额充值订单”不能直接复用核心回调处理器，否则会被错误映射为普通订阅订单。
- 余额充值若要复用支付插件，应通过 WalletCenter 自己的交易记录表和自己的通知路由承接，再调用支付插件的 `pay()` / `notify()` 能力，而不是复用核心 `guest/payment/notify`。

## 2. 普通订阅订单基线

### 2.1 订单模型与状态

- 普通订阅订单模型为 `App\Models\Order`，表为 `v2_order`。
- 状态常量：
  - `STATUS_PENDING = 0`
  - `STATUS_PROCESSING = 1`
  - `STATUS_CANCELLED = 2`
  - `STATUS_COMPLETED = 3`
  - `STATUS_DISCOUNTED = 4`
- 类型常量：
  - `TYPE_NEW_PURCHASE = 1`
  - `TYPE_RENEWAL = 2`
  - `TYPE_UPGRADE = 3`
  - `TYPE_RESET_TRAFFIC = 4`

### 2.2 创建路径

- 入口为 `OrderController::save()`。
- 实际创建逻辑集中在 `OrderService::createFromRequest()`。
- 购买合法性由 `App\Services\PlanService::validatePurchase()` 控制。
- 创建前会触发 `order.create.before` 钩子，创建后触发 `order.create.after` 与兼容钩子 `order.after_create`。

### 2.3 支付与开通路径

- 结算时如果 `total_amount <= 0`，直接走 `OrderService::paid()`，不经过第三方支付。
- 第三方支付成功后由回调驱动 `OrderService::paid()`。
- `paid()` 仅负责把订单从 `pending` 改成 `processing`，并记录 `paid_at` 与 `callback_no`。
- 真正的用户订阅开通发生在 `OrderService::open()`。

### 2.4 余额抵扣逻辑

- 用户余额字段在 `App\Models\User.balance`。
- 订单创建阶段会自动执行余额抵扣：`OrderService::handleUserBalance()`。
- 已抵扣的余额记录在 `Order.balance_amount`。
- 取消订单时 `OrderService::cancel()` 会把 `balance_amount` 返还给用户。

### 2.5 对后续需求的影响

- 当前“余额”只作为普通订阅订单的抵扣手段存在，不存在独立充值订单模型。
- 自动续费功能如果复用普通订单流程，可以调用现有订单创建与开通逻辑，但必须先自行判断余额是否充足，否则会出现“部分余额先扣、剩余金额生成待支付订单”的副作用。
- `UserService::isNotCompleteOrderByUserId()` 会阻止用户同时存在 `pending` / `processing` 的核心订单，WalletCenter 必须避免复用 `v2_order` 承载充值记录，否则会污染普通订阅下单流程。

## 3. 插件系统基线

### 3.1 加载与生命周期

- 全局中间件 `App\Http\Middleware\InitializePlugins` 会在每个 HTTP 请求开始时初始化已启用插件。
- `App\Services\Plugin\PluginManager::initializeEnabledPlugins()` 负责：
  - 加载 `plugins/<StudlyCode>/Plugin.php`
  - 注册插件 Service Provider
  - 注册插件路由
  - 注册插件视图
  - 注册插件命令
  - 调用插件 `boot()`

### 3.2 目录与命名规则

- 插件源码目录：`plugins/<StudlyCode>/`
- 插件主类：`Plugin\<StudlyCode>\Plugin`
- 插件元数据文件：`plugins/<StudlyCode>/config.json`
- 允许扩展：
  - `database/migrations`
  - `routes/web.php`
  - `routes/api.php`
  - `resources/views`
  - `resources/assets`
  - `Commands`

### 3.3 配置与后台入口

- 插件安装、启用、停用、升级、上传、删除、配置读取、配置保存的后台入口已经存在：
  - `App\Http\Controllers\V2\Admin\PluginController`
  - 路由前缀：`/plugin/*`
- 插件配置结构来自 `config.json`，数据库持久化在 `v2_plugins.config`。
- 插件类型已分为 `feature` 和 `payment`，与本次需求吻合。

### 3.4 计划任务接入

- 插件可通过重写 `AbstractPlugin::schedule(Schedule $schedule)` 注册计划任务。
- 应用级调度入口 `App\Console\Kernel::schedule()` 已调用 `PluginManager::registerPluginSchedules($schedule)`。
- 这意味着 WalletCenter 可以用插件方式注册自动续费扫描任务，不需要改动核心调度器结构。

## 4. 主题系统基线

### 4.1 当前主题切换机制

- 前台根路由 `routes/web.php` 读取 `admin_setting('frontend_theme', 'Xboard')`。
- `App\Services\ThemeService` 负责：
  - 主题发现
  - 主题上传
  - 主题切换
  - 主题配置初始化
  - 主题配置更新
  - 公共目录主题资源同步
- 切换入口：
  - 后台基础配置保存 `frontend_theme` 时会触发 `ConfigController::save()` 内的 `ThemeService::switch($v)`
  - 独立主题管理控制器 `ThemeController` 也已存在

### 4.2 主题目录边界

- 系统主题目录：`theme/`
- 用户上传主题目录：`storage/theme/`
- 当前仓库自带系统主题：
  - `theme/Xboard`
  - `theme/v2board`
- 需求范围只允许围绕 `Xboard` 扩展，不处理 `v2board`。

### 4.3 主题配置继承方式

- 每个主题通过 `config.json` 定义配置项。
- `ThemeService::initConfig()` 会把 `config.json` 的默认值写入 `theme_<主题名>` 配置键。
- 主题升级时使用“默认值 + 现有配置”合并策略，已有用户配置可以保留。

### 4.4 自定义主题的现实限制

- `theme/Xboard` 当前只保留了编译产物和 `dashboard.blade.php`，没有可直接维护的前端源码目录。
- 这意味着“新增钱包入口 + 新增 13 种语言”不能只靠简单改 Blade 完成，后续大概率需要完整复制一个自定义主题目录，并维护自己的前端资源产物。

### 4.5 已识别主题风险

- `ThemeService` 内部在多个位置使用 `current_theme`，但前台渲染与后台配置保存使用的是 `frontend_theme`。
- 该键名不一致不会阻塞本次需求启动，但会影响删除、切换、清理逻辑的一致性，后续实现自定义主题时要避免依赖 `current_theme` 作为唯一真实状态源。

## 5. 余额、续费与计划任务基线

### 5.1 余额能力

- 用户余额存储在 `v2_user.balance`。
- 原子加减余额入口为 `App\Services\UserService::addBalance()`，内部对用户记录执行 `lockForUpdate()`。
- 这是后续签到奖励入账、充值到账、自动续费扣款可复用的最小核心能力。

### 5.2 续费判定能力

- 普通订阅续费判定已经存在于 `OrderService::setOrderType()`：
  - 当前订阅未过期
  - 本次购买 plan 与当前用户 plan 相同
  - 则判定为 `TYPE_RENEWAL`

### 5.3 系统已有计划任务

- 核心调度里已经存在：
  - `check:order`
  - `check:commission`
  - `check:ticket`
  - `reset:traffic`
  - 其他系统任务
- `check:order` 每分钟扫描 `pending` / `processing` 订单并分发 `OrderHandleJob`。
- WalletCenter 的自动续费任务可以作为独立插件任务并行存在，不必侵入现有命令。

### 5.4 当前缺口

- 现有系统没有：
  - 独立充值订单模型
  - 独立充值状态流转
  - 独立签到记录模型
  - 独立自动续费设置与执行记录模型
- 以上内容都需要在 WalletCenter 插件内独立建模。

## 6. 允许触达目录与应避免目录

### 6.1 允许触达

- `plugins/StripePayment/`
- `plugins/BepusdtPayment/`
- `plugins/WalletCenter/`
- `theme/XboardCustom/`
- `public/plugins/` 下的插件已发布静态资源
- `public/theme/XboardCustom/` 下的已发布主题资源
- `markdown/` 下的实施文档、进度文档、架构文档

### 6.2 原则上避免触达

- `app/Services/PaymentService.php`
- `app/Services/OrderService.php`
- `app/Http/Controllers/V1/Guest/PaymentController.php`
- `app/Console/Kernel.php`
- `routes/web.php`
- `theme/Xboard/`
- `theme/v2board/`
- 管理后台语言相关目录和资源

### 6.3 仅在无法通过插件/主题达成时才评估的最小核心侵入点

- 支付能力若必须共用统一交易抽象，再评估 `PaymentService` 的极小范围扩展。
- 自定义主题若必须复用主题切换状态一致性，再评估 `ThemeService` 的状态键统一。
- 在进入任何核心改动前，必须先证明插件路由、插件表、插件任务、自定义主题方案无法满足目标。

## 7. 阶段 1 验证结果

| 验证项 | 结果 | 依据 |
| --- | --- | --- |
| 项目中存在支付扩展能力 | 通过 | `PaymentInterface`、`PaymentService::getAvailablePaymentMethods()`、现有支付插件 `boot()` 注册 |
| 项目中存在自定义主题切换能力 | 通过 | `ThemeService`、`ConfigController::save()`、`routes/web.php` |
| 项目中存在插件计划任务接入能力 | 通过 | `AbstractPlugin::schedule()`、`PluginManager::registerPluginSchedules()`、`Console\Kernel::schedule()` |
| 普通订阅订单与用户余额逻辑可以被清晰区分 | 通过 | 普通订单在 `v2_order`，余额在 `v2_user.balance`；当前系统尚无充值订单模型，边界清晰 |
| 本次需求可主要通过插件和自定义主题完成 | 通过 | 支付插件接入、插件路由、插件迁移、插件任务、自定义主题切换均已具备；充值回调需走插件自有路由 |

## 8. 本阶段风险与异常处理结论

### 8.1 核心风险

1. 核心支付回调只认 `v2_order`，充值不能复用核心回调控制器。
2. 普通订单创建会自动抵扣余额，自动续费必须先做余额充足校验。
3. `UserService::isNotCompleteOrderByUserId()` 会阻塞并发核心订单，充值记录不能落到 `v2_order`。
4. `theme/Xboard` 只有编译产物，前台功能扩展与多语言补齐工作量会集中在自定义主题资源维护。
5. `frontend_theme` 与 `current_theme` 键不一致，主题切换与删除逻辑存在一致性风险。

### 8.2 本阶段异常处理结果

- 本阶段未执行运行时变更，只进行了静态代码与目录基线核查。
- 未发现会直接阻断阶段 3 以前实施的结构性缺口。
- 已确认后续阶段可以以“新增三插件 + 新增一个自定义主题”为主线推进。

## 9. 阶段 1 放行结论

- 扩展边界：清晰
- 核心风险点：清晰
- 最小侵入路线：清晰
- 放行建议：允许进入阶段 2
