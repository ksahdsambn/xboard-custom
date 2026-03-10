# 阶段 2：资产命名与版本策略

## 阶段目标

为三个插件和一个自定义主题固定长期可维护的资产命名、职责边界、版本策略与最小交付集合，避免后续阶段出现命名漂移、职责重叠或回滚困难。

## 1. 命名规则

### 1.1 统一规则

- 插件显示名使用 PascalCase，便于与现有插件目录风格保持一致。
- 插件代码使用 snake_case，满足当前插件系统 `config.json` 的校验规则。
- 插件目录使用 StudlyCase，对齐 `PluginManager` 的目录解析方式。
- 支付方式标识使用第三方通道名本身，不与插件代码绑定，避免支付实例记录混入实现细节。
- 自定义主题名使用 PascalCase，目录名与主题名保持一致。

### 1.2 最终命名定稿

| 资产类型 | 资产名 | 代码标识 | 目录 | 运行时标识 |
| --- | --- | --- | --- | --- |
| 支付插件 | `StripePayment` | `stripe_payment` | `plugins/StripePayment/` | 支付方式键：`Stripe` |
| 支付插件 | `BEpusdtPayment` | `bepusdt_payment` | `plugins/BepusdtPayment/` | 支付方式键：`BEpusdt` |
| 功能插件 | `WalletCenter` | `wallet_center` | `plugins/WalletCenter/` | 插件功能键：`wallet_center` |
| 自定义主题 | `XboardCustom` | 不适用 | `theme/XboardCustom/` | 主题名：`XboardCustom` |

## 2. 命名选择理由

### 2.1 StripePayment

- 与现有支付插件命名方式一致。
- 明确这是“支付插件资产名”，避免与支付方式键 `Stripe` 混淆。
- 后续若出现 `StripeRechargeAdapter` 之类内部组件，也不会与资产名冲突。

### 2.2 BEpusdtPayment

- 保留第三方服务品牌名 `BEpusdt`，避免后续对接时产生别名映射。
- 增加 `Payment` 后缀，强调这是支付插件而不是充值业务本身。

### 2.3 WalletCenter

- 已在需求文档和实施计划中多次使用，继续沿用可降低阶段间认知切换成本。
- 能自然承载签到、充值、自动续费三类钱包相关能力。

### 2.4 XboardCustom

- 已在实施计划阶段 11 中被明确提及，继续沿用能减少后续主题切换与交付描述歧义。
- 表义清楚，既表明继承自 `Xboard`，又表明是独立主题资产。

## 3. 职责边界定稿

### 3.1 StripePayment

- 只负责“普通订阅订单”的 Stripe 一次性支付。
- 负责 Stripe 支付参数生成、回调校验、支付成功回写普通订单。
- 不负责：
  - 充值订单建模
  - 自动续费逻辑
  - 签到逻辑
  - 保存卡信息
  - 循环扣款

### 3.2 BEpusdtPayment

- 只负责“普通订阅订单”的 BEpusdt 一次性支付。
- 负责 BEpusdt 下单、状态识别、签名校验、支付成功回写普通订单。
- 不负责：
  - 充值订单建模
  - 自动续费逻辑
  - 签到逻辑
  - 循环扣款

### 3.3 WalletCenter

- 统一承载：
  - 每日签到
  - 余额充值
  - 余额自动续费当前订阅
- 负责自己的数据表、配置、记录、前台接口、后台接口、计划任务。
- 可以复用“已启用支付通道”作为充值支付能力来源。
- 不负责：
  - 新支付网关实现
  - 普通订阅支付回调处理
  - 主题渲染与前台资源打包

### 3.4 XboardCustom

- 只负责前台 UI 承载：
  - 钱包入口
  - 签到入口
  - 充值入口
  - 自动续费入口与状态展示
  - 新增 13 种语言的前台文案资源
- 不负责：
  - 资金扣减
  - 充值到账
  - 自动续费判定
  - 支付签名校验
  - 计划任务执行

### 3.5 边界裁剪原则

- 任何资金动作只能落在插件后端，不落在主题层。
- 任何支付通道实现只能落在支付插件，不落在 WalletCenter。
- WalletCenter 只消费支付能力，不拥有支付网关实现。
- 主题只消费 WalletCenter 与普通订单的 API，不拥有业务真相。

## 4. 版本策略定稿

### 4.1 版本号格式

- 四个资产统一采用 `MAJOR.MINOR.PATCH`。
- 初始开发版本统一从 `0.1.0` 起步。
- 达到阶段 16 完整上线门槛后，再把首个可上线版本提升到 `1.0.0`。

### 4.2 递增规则

| 级别 | 触发条件 |
| --- | --- |
| `MAJOR` | 破坏兼容的配置变更、数据库结构变更、路由契约变更、回滚不兼容变更 |
| `MINOR` | 向后兼容的新功能、新页面、新配置项、新语言覆盖范围扩大 |
| `PATCH` | Bug 修复、异常处理修复、翻译修正、样式修复、非破坏性重构 |

### 4.3 独立版本原则

- `StripePayment`、`BEpusdtPayment`、`WalletCenter`、`XboardCustom` 四者独立递增版本，不做“全仓统一版本绑定”。
- 只改某一资产时，只提升该资产版本。
- 例如：
  - 只修复 Stripe 回调幂等问题，只提升 `StripePayment`
  - 只补齐阿拉伯语文案，只提升 `XboardCustom`
  - 只增加签到历史筛选，只提升 `WalletCenter`

### 4.4 迁移与回滚约束

- 插件数据库迁移只允许放在各自插件目录下。
- 出现破坏性表结构调整时，必须提升 `MAJOR`，并提供可回滚迁移或明确备份方案。
- 主题资源升级不修改 `theme/Xboard` 原目录，始终只更新 `theme/XboardCustom`。

## 5. 最小交付集合定稿

### 5.1 StripePayment 最小集合

- `plugins/StripePayment/config.json`
- `plugins/StripePayment/Plugin.php`
- `plugins/StripePayment/routes/`
- `plugins/StripePayment/resources/`
- `plugins/StripePayment/database/migrations/`（如有）
- `plugins/StripePayment/Commands/`（如有）
- 第三方 SDK 或本地 library 目录（如有）

### 5.2 BEpusdtPayment 最小集合

- `plugins/BepusdtPayment/config.json`
- `plugins/BepusdtPayment/Plugin.php`
- `plugins/BepusdtPayment/routes/`
- `plugins/BepusdtPayment/resources/`
- `plugins/BepusdtPayment/database/migrations/`（如有）
- `plugins/BepusdtPayment/Commands/`（如有）
- 第三方 SDK 或本地 library 目录（如有）

### 5.3 WalletCenter 最小集合

- `plugins/WalletCenter/config.json`
- `plugins/WalletCenter/Plugin.php`
- `plugins/WalletCenter/routes/`
- `plugins/WalletCenter/resources/`
- `plugins/WalletCenter/database/migrations/`
- `plugins/WalletCenter/Commands/`
- WalletCenter 自有模型、服务、记录查询与通知处理代码

### 5.4 XboardCustom 最小集合

- `theme/XboardCustom/config.json`
- `theme/XboardCustom/dashboard.blade.php`
- `theme/XboardCustom/assets/`
- `theme/XboardCustom/images/`（如有）
- 主题语言资源与自定义脚本产物

### 5.5 回滚时必须同时保留的运行时状态

- 对应插件或主题上一版本完整目录包
- 对应版本号记录
- 对应插件配置快照
- 对应主题配置快照
- 对应插件迁移执行记录
- 对应支付方式实例配置快照（适用于 Stripe / BEpusdt）

## 6. 资产独立更新/停用/替换策略

### 6.1 支付插件

- 启用/停用：走后台插件系统与支付实例开关
- 更新：走插件目录替换 + 版本递增 + 插件升级流程
- 回滚：恢复上一版本插件目录并执行对应回滚/兼容逻辑
- 替换：不影响 WalletCenter 和主题名称

### 6.2 WalletCenter

- 启用/停用：独立插件开关
- 更新：只影响钱包相关记录与接口，不影响普通订阅支付插件
- 回滚：依赖自身数据表迁移与配置快照
- 替换：允许未来替换为新的钱包插件，只要前台主题入口切换到新的接口即可

### 6.3 XboardCustom

- 启用/停用：通过 `frontend_theme` 切换
- 更新：只覆盖 `theme/XboardCustom`
- 回滚：切回 `Xboard` 或上传旧版 `XboardCustom`
- 替换：不修改 `theme/Xboard` 原始目录

## 7. 与现有仓库资产的冲突检查结论

### 7.1 现有插件资产

- 已存在插件代码：
  - `alipay_f2f`
  - `btcpay`
  - `coinbase`
  - `coin_payments`
  - `epay`
  - `mgate`
  - `telegram`

### 7.2 现有主题资产

- 已存在主题目录：
  - `Xboard`
  - `v2board`

### 7.3 冲突结论

- `stripe_payment`：未冲突
- `bepusdt_payment`：未冲突
- `wallet_center`：未冲突
- `XboardCustom`：未冲突

## 8. 阶段 2 验证结果

| 验证项 | 结果 | 说明 |
| --- | --- | --- |
| 命名不与现有插件或主题冲突 | 通过 | 提议代码与现有插件代码、主题目录均不重复 |
| 每个资产可独立启用、停用、更新和回滚 | 通过 | 三插件走插件系统，主题走主题切换与独立目录升级 |
| 职责边界清晰，没有交叉污染 | 通过 | 支付、钱包、主题三层边界已固定 |

## 9. 阶段 2 放行结论

- 命名：固定
- 职责：固定
- 版本策略：固定
- 放行建议：允许进入阶段 3
