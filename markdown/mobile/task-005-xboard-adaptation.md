# TASK-005 Xboard 扩展点、行为快照与适配约束

- 操作 AI 模型：GPT-5（Codex）
- 审查基线：官方 Xboard `4f48e61a2cbc6db5338872b6bdb45ef954ec1256`，不是当前分支浮动 HEAD。
- 需求基线：Android 2.0.1；实施计划 1.2.0。
- 证据归属：xboard-mobile `evidence/TASK-005/runtime-results.json`、`runtime-review-results.json`；每份包含被审查源码、探针和执行脚本的 SHA-256。
- 执行器归属：xboard-custom `tests/task-005/**`，绝不进入生产 overlay。

## 结论与范围

允许后续通过独立 Mobile 插件适配，不需修改官方核心、官方路由或官方控制器。本阶段只复核，不提前实现 TASK-006 及后续业务。
本结论覆盖已列出的扩展点；未来任务若发现插件无法满足某项需求，仍须停止并提交架构阻塞，不能将本结论视作任意修改的授权。

测试在完整冻结源码迁移出的空 SQLite 库运行，PHP 8.2.30、Laravel 12.54.1、Sanctum v4.2.0。
默认 cache 和名为 redis 的 store 均用 array adapter；不验证真实 Redis 并发语义。HTTP 使用真实 Laravel Kernel，但不经过网络 socket。
验证码测试覆盖关闭/缺 token 的本地分支，不声称第三方验证成功；实际邮件/验证码/节点等按两级资产政策后续验收。

## 1. 插件扩展点清单

| 扩展点与源码 | 冻结行为 | 后续适配约束 / 运行证据 |
| --- | --- | --- |
| `app/Services/Plugin/PluginManager.php` install | 配置校验后迁移、保存停用记录、默认配置、install；迁移早于记录事务 | 迁移必须独立、可重入且可回滚；探针确认停用记录和独立表存在 |
| 同文件 enable / loadPlugin | 载入类型化配置、Providers、api.php、视图；启用并 boot | 业务绑定放插件自身 provider；探针确认 provider/config/boot |
| 同文件 loadRoutes | 插件 api.php 只包 `api`，无隐式 api/v1 前缀 | 必须显式声明 `/api/mobile/v1/**`；受保护路由显式鉴权；探针同时测试无前缀和完整前缀 |
| `app/Http/Middleware/InitializePlugins.php`、`app/Http/Kernel.php` | HTTP 全局中间件初始化启用插件；api 组本身不等于鉴权 | 不把 api 组当作登录检查；Kernel 请求已验证公共 200 与无效会话 403 |
| PluginManager initializeEnabledPlugins | 同一 manager 实例只初始化一次；加载命令；部分初始化异常只记日志 | 必须有插件可用性检查，不以请求未报错推定初始化成功；探针检查幂等和命令输出 |
| PluginManager registerPluginSchedules、`app/Console/Kernel.php` | 加载轻量配置，调用插件 schedule | 用插件自身计划任务；探针产生一个每分钟事件 |
| PluginManager update / disable / uninstall | 递增版本；disable、迁移、update、enable；停用调用 cleanup；卸载清理记录与插件迁移 | 不能假设停用立即清除长驻进程内路由；升级/停用后需重启长驻 worker；探针升级到 1.0.1 后完整停用卸载 |
| `app/Services/Plugin/AbstractPlugin.php` | 生命周期、配置、迁移/资源所在目录的基类契约 | 只扩展插件目录；依赖检查不能当成可信版本兼容门禁（上游检查仍为占位） |
| `app/Providers/RouteServiceProvider.php` | 官方 v1/v2 路由的前缀由官方 provider 设置 | 不编辑此文件；不能将官方前缀行为套用到插件路由 |

## 2. 会话、注册和认证行为

| 源码/方法 | 行为事实与证据 | 适配约束 |
| --- | --- | --- |
| `app/Services/AuthService.php` generateAuthData | 会话为 Sanctum token，默认一年、abilities `*`；返回 `auth_data=Bearer …`，`token` 是独立订阅 token | 移动会话仅用 Sanctum，不以订阅 token 代替。探针验证有效200、订阅token403、过期403 |
| AuthService findUserByBearerToken | 手工查 token 不检查 expires_at，过期仍可返回用户（已复现） | 禁止作为 Mobile 会话鉴权捷径；使用 Sanctum guard 并统一映射失效语义 |
| `app/Http/Middleware/User.php`、`app/Exceptions/Handler.php`、`app/Exceptions/ApiException.php`、`app/Helpers/ApiResponse.php` | 无效会话 HTTP 403；`status/message/data/error`，status=`fail`，不保证稳定业务码 | Mobile 应显式区分未登录与权限不足，包装稳定错误码；不得仅凭所有403一概注销 |
| `app/Services/Auth/LoginService.php` login | 密码错误400，达到缓存阈值429；默认5次/60分钟；不存在用户不增加该错误计数；封禁账号400 | 复用服务并保留限流，避免另写弱鉴权；本次设置2次阈值验证400→429及正确密码成功 |
| `app/Services/Auth/RegisterService.php` validateRegister/register | IP限流、captcha、邮箱白名单、别名限制、停注册、邀请码、邮件码、重复邮箱检查；成功注册触发hooks并清理邮件码 | 复用顺序和站点规则，不能仅调用createUser跳过验证；白名单/邀请码/错误码/成功注册均已运行 |
| RegisterService handleInviteCode、`app/Models/InviteCode.php` | 找未使用邀请码；强制时无效抛异常；非永久邀请码标为使用 | 运行验证返回邀请人并消费；并发消费/事务一致性留业务阶段数据库测试 |
| `app/Services/CaptchaService.php` | 支持 turnstile、recaptcha-v3、recaptcha；缺相应 token 返回400 | 三种缺失分支已测；真实提供商成功/超时/异常不得由本报告冒充 |
| LoginService resetPassword | 错码400并累积，3次后429；正确验证码后哈希密码并删除验证码；不主动撤销已有token（已复现） | Mobile密码变更后显式执行会话撤销策略；邮件发送、并发重放需后续真实集成测试 |
| `app/Helpers/Functions.php`、`app/Support/Setting.php`、`config/cache.php` | admin_setting从Setting读取，Setting固定选择名为redis的store；读取异常回退空设置，保存会刷新缓存 | 本次仅用运行时array适配；后续集成需真实Redis且配置加载错误不得静默放宽安全规则 |

无效会话实际外层快照：`{"status":"fail","message":"未登录或登陆已过期","data":null,"error":null}`，HTTP 403。
公共探针的200是测试插件直接响应；不代表所有官方成功接口采用统一外层。

## 3. 用户、套餐、节点、公告、工单

| 源码 | 可调用接口和已确认差异 | 安全适配方式 |
| --- | --- | --- |
| `app/Services/UserService.php`、`app/Models/User.php` | createUser返回未保存模型；isAvailable检查封禁、额度非零和有效期，但不检查u+d耗尽；assignPlan按GB换算字节 | 必须另外判断剩余流量；读取方法可能有重置副作用，不能把所有Service调用当只读 |
| `app/Services/PlanService.php`、`app/Models/Plan.php` | 构造需要Plan；列表检查show/sell/capacity，单项getAvailablePlan只查sell/renew；month_price映射monthly；容量0表示售罄、null无限 | 保留列表/续费区别、整数金额和周期映射；本次验证隐藏/容量过滤。getAvailablePeriods将数组当键引发TypeError已复现，改用模型getPriceList或按prices键构造插件DTO |
| `app/Services/ServerService.php` getAvailableServers | 用用户group_id的字符串匹配group_ids，同时过滤show、节点配额u+d；排序；端口区间运行时取整数；保留ports原值；用户凭证由generateServerPassword生成 | 先检用户权益，再复用授权集合，不自行扩大节点查询；探针5节点只有2个授权，保持排序/动态端口和VLESS UUID |
| `app/Http/Resources/NodeResource.php` | 摘要字段仅id/type/version/name/rate/tags/is_online/cache_key/last_check_at；没有host、用户凭证、Reality配置 | 摘要可用于列表，不可用于连接。Profile使用同一已授权集合中选定节点，通过白名单映射构造 |
| `app/Models/Server.php` | protocol_settings包含tls/network/flow/reality_settings/utls等，Reality含server_name/server_port/public_key/private_key/short_id等字段 | 客户端只取所需公开字段；绝不序列化服务端private_key或直接输出buildNodeConfig；缺字段拒绝连接。SpiderX并无可依赖上游字段，后续配置契约须明确默认与校验 |
| `app/Http/Controllers/V1/User/NoticeController.php` | show过滤、分页、排序；响应为data/total，无统一status（已运行） | 插件单独映射分页DTO，不让客户端依赖多种官方外层 |
| `app/Services/TicketService.php`、`app/Models/Ticket.php`、`app/Models/TicketMessage.php` | 创建限制单个未结工单，事务/锁；reply返回消息或false；关系messages（兼容message别名） | 复用服务并保留用户归属和速率限制；本次创建/回复通过，数据库并发另测 |
| `app/Http/Controllers/V1/User/TicketController.php` | fetch指定不存在ID时first()->load()空引用（已复现） | 插件先按用户范围查询并显式404，不直接透传该控制器；不得泄露其他用户工单 |

节点对比的原始凭据只在内存比较，证据只记录字段名、数量和布尔结果，不记录UUID/token/密码或私钥。

## 4. 风险、替代方案和后续归属

- 高：过期token手工查找、密码变更不撤销会话、用户额度耗尽漏判——TASK-021/022/023 的认证/会话/权益适配必须覆盖负向测试。
- 高：节点摘要不能生成连接、完整模型含私钥——TASK-024/025 使用授权集合与白名单Profile，验收不得泄露私钥。
- 中：套餐周期方法TypeError、单项/列表筛选语义不同——TASK-023 使用经过测试的模型/服务组合与DTO，不改官方PlanService。
- 中：工单不存在ID空引用——TASK-027 在插件按所属用户显式检查404。
- 中：插件迁移早于事务、升级/停用长驻内存、初始化异常吞掉、依赖检查占位——TASK-018/036/037/063 分别承担幂等迁移、插件健康、集成与升级回退检查。
- 中：冻结composer.json与composer.lock的content-hash不一致，Composer报告lock过时；本次严格安装lock并逐包检查，未运行update、未修改官方文件。正式集成前需验证目标上游版本的可重复安装，不以缓存vendor树替代版本锁。
- 范围限制：SQLite/array契约测试不是MySQL/Redis并发验收、真机/协议验收或生产就绪声明；这些测试在计划对应业务/正式验收阶段补齐。

上述问题均有源码或运行复现，并有插件内替代路径，因此不构成必须改官方核心的架构阻塞。报告并不声称上游无缺陷，也不将风险发现冒充业务修复。
