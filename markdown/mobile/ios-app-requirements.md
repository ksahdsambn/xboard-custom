# Xboard iOS APP 产品与技术需求文档

## 1. 文档信息

| 项目 | 要求 |
| --- | --- |
| 文档版本 | 2.0.0 |
| 文档状态 | iOS 1.0 开发基线 |
| 编写日期 | 2026-09-04 |
| 目标平台 | iOS |
| 发行渠道 | Apple App Store |
| 开发者主体 | 香港企业 Apple Developer Program Organization 账户 |
| 服务端 | cedar2025/Xboard 官方底座与 xboard-custom 插件 overlay |
| 首发协议 | VLESS + REALITY + TCP + XTLS Vision |
| 代理内核 | Xray-core + libXray |
| xboard-custom 审查基线 | 1fe92b1e72aedd1a667a764384266face8576124 |
| Xboard 源码审查基线 | 4f48e61a2cbc6db5338872b6bdb45ef954ec1256 |
| Xboard compose 审查基线 | 0a749681bb10056bd60ebe9afe4e8928ff19210c |
| libXray 审查基线 | 15e88365296a6f955e5e38caa2d02c97b499733f |
| Xray-core 审查基线 | v26.7.28，提交 5ca6f4b7d4dc20a881d4330e498892697627ec0c |

本文档定义待开发要求，不表示当前仓库已经包含 MobileApp 插件、iOS 客户端、Packet Tunnel Extension 或移动端构建产物。基线之后的上游版本必须重新执行本文规定的兼容审查和验收，不能依据本文直接认定兼容。

## 2. 产品结论与交付边界

iOS APP 必须采用 Flutter 负责界面和共享业务、Swift 负责 iOS 系统能力、NetworkExtension 负责系统 VPN、libXray XCFramework 承载 Xray-core 的架构。Flutter 只运行在主 APP，不能进入 Packet Tunnel Extension。

服务端必须在本仓库新增独立的 MobileApp 插件。客户端只依赖该插件提供的版本化 Mobile API，不直接依赖 Xboard 控制器返回结构、数据库字段、通用订阅文本或 Web 页面。

仓库边界必须满足以下要求：

- xboard-custom 继续作为服务端定制仓库，保存 MobileApp 插件、数据库迁移、服务端测试及 overlay 部署改动。
- cedar2025/Xboard 保持上游原样，不直接写入项目定制代码。
- 新建一个独立的 xboard-mobile 仓库，同时保存 Flutter 工程、Android 原生层、iOS 原生层、Pigeon 契约、内核构建工具和版本锁。Android 与 iOS 不拆成两个客户端仓库。
- 本文档继续保存在 xboard-custom，作为服务端与客户端共同遵守的需求基线。

## 3. 产品目标与范围

### 3.1 必须实现的目标

- 使用现有 Xboard 账户完成注册、登录、会话恢复和密码重置。
- 展示账户、套餐、订阅有效期、剩余流量、兼容节点、公告和工单。
- 使用 Apple NetworkExtension 建立 VLESS + REALITY VPN。
- 使用 StoreKit 2 完成数字订阅购买、恢复和状态同步。
- 将 Web、App Store 两类权益统一映射为可连接状态，同时保持各自续费渠道独立。
- 在 Xboard、libXray 或 Xray-core 更新后，通过固定版本、适配层、回归测试、分阶段发布和回滚维持兼容。
- 支持当前生产版本和上一主要版本客户端同时访问新版 Mobile API，避免 App Review 周期造成断连。

### 3.2 首发明确不实现的范围

- 不支持 VMess、Trojan、Shadowsocks、Hysteria、TUIC、AnyTLS 或其他代理协议。
- 不支持 WebSocket、gRPC、XHTTP 等传输；首发只接受 TCP。
- 不解析 Clash、sing-box、通用 Xray 配置、订阅链接、二维码或第三方分享链接。
- 不引入 tun2socks、第二套代理内核或第二个 Go runtime。
- 不支持动态下载、热更新或远程替换代理内核。
- App Store 发行包不提供 Stripe、BEpusdt、钱包充值或外部数字订阅购买入口。
- 不实现按应用分流。
- 不采集或分析用户访问内容、访问域名、DNS 查询历史或流量载荷。

## 4. 最终技术栈

| 层级 | 最终选择 | 约束 |
| --- | --- | --- |
| 界面 | Flutter | Android 与 iOS 共享页面和设计系统 |
| 状态管理 | Dart + Riverpod | 业务状态不得承担 VPN 系统状态的唯一事实源 |
| 网络访问 | Dio | 统一鉴权、超时、重试、请求标识和错误映射 |
| 路由 | go_router | 登录、强制升级和无权益状态必须可守卫 |
| 数据模型 | freezed + json_serializable | Mobile API 模型必须版本化 |
| Flutter 与原生桥接 | Pigeon | 只用于 Flutter 与主 APP Swift 层 |
| iOS 原生层 | Swift + Swift Concurrency | 负责系统配置、会话、状态和安全存储 |
| VPN 管理 | NETunnelProviderManager 与 NETunnelProviderSession | 主 APP 管理系统 VPN 配置和会话 |
| VPN 扩展 | NEPacketTunnelProvider | 独立 Extension 承担 TUN 和内核生命周期 |
| 主 APP 与 Extension 通信 | NetworkExtension Provider Message | 使用版本化消息和超时 |
| 共享数据 | App Groups + Keychain Access Groups | 非敏感状态与敏感凭证分离 |
| 内核产物 | libXray cgo XCFramework | 使用固定 C ABI 并由 Swift Adapter 封装 |
| 代理协议 | VLESS + REALITY + TCP + XTLS Vision | 不启用其他协议和传输 |
| 支付 | StoreKit 2 | 客户端只发起购买和提交已验证交易 |
| 服务端支付能力 | App Store Server API + App Store Server Notifications V2 | 服务端验证并裁决最终权益 |
| 构建版本 | FVM 与统一上游版本锁 | 所有工具、源码和二进制均使用精确版本 |
| 测试 | Flutter Test、XCTest、StoreKit Test、真机 Packet Tunnel 测试 | P0 场景必须自动化或形成可重复真机用例 |

iOS 最低系统版本固定为 iOS 15。构建使用的 Xcode、SDK 和 StoreKit 能力必须在每次提审前满足 Apple 当时生效的要求。

Pigeon 契约必须只包含准备 VPN、安装或更新 Profile、连接、断开、读取状态、读取会话信息、状态事件和可恢复错误。Pigeon 定义、固定版本生成器及生成后的 Dart 和 Swift 文件必须一并提交；CI 重新生成后出现未提交差异时必须失败。

## 5. 系统职责

| 组件 | 必须承担的职责 | 禁止承担的职责 |
| --- | --- | --- |
| Flutter | 页面、导航、表单、业务状态、Mobile API 调用、用户可见错误 | 直接调用 libXray、管理 utun、进入 Packet Tunnel Extension |
| Swift 主 APP 层 | Pigeon 实现、VPN 配置管理、会话控制、系统状态转换 | 在主 APP 启动 Xray 实例 |
| Packet Tunnel Extension | 网络设置、utun、Xray 生命周期、网络变化和 Provider Message | 加载 Flutter 引擎、支付 SDK 或非必要分析 SDK |
| MobileApp 插件 | 稳定移动 API、DTO、Profile 转换、权益、支付验证、设备和删除请求 | 把 Xboard ORM 或节点内部配置原样返回客户端 |
| Xboard 官方底座 | 用户、套餐、节点可用性、流量与现有 Web 业务 | 承担客户端专用契约的稳定性 |
| Apple App Store | 商品销售、订阅生命周期和服务端通知 | 直接修改 Xboard 用户字段 |

## 6. 功能需求

优先级定义：P0 是 iOS 1.0 上架阻断项；P1 是已明确可延后的增强项。除特别标注外，本节要求均为 P0。

### IOS-FR-001 启动与远程配置

- APP 启动时必须取得 Mobile API 版本、Profile Schema 版本、维护状态、功能开关、最低支持 APP 版本、最低支持 iOS 版本和禁用内核版本。
- 必须分别处理正常、离线、维护、区域不可用、建议升级和强制升级状态。
- 强制升级状态下不得建立新连接，但必须允许用户查看隐私政策、服务条款、账号删除说明和客服信息。
- 远程配置只能控制业务开关和兼容策略，不得下发或执行代码。

验收结果：每种启动状态均有唯一、可测试的页面和稳定错误码；网络恢复后可以无须重启 APP 重新加载。

### IOS-FR-002 隐私披露与 VPN 同意

- 首次保存 VPN 配置前必须展示独立且显著的 VPN 数据使用说明。
- 说明必须覆盖 VPN 用途、处理的数据、日志范围、保留期限、共享对象和删除方式。
- 用户必须主动同意后才可进入 Apple 系统 VPN 配置授权流程。
- 披露版本发生变化时必须重新取得同意并保存版本和时间。
- App Store 隐私信息、隐私政策和 APP 实际行为必须一致。

验收结果：拒绝披露或拒绝系统 VPN 配置时不启动 Extension，不影响账号查看和删除功能。

### IOS-FR-003 注册、登录与会话

- 必须复用 Xboard 当前的认证、注册、邮箱验证码、邀请码、验证码、注册开关和频率限制规则。
- 登录成功后使用 Xboard Sanctum 颁发的 Bearer 会话，不得把订阅令牌当作 API 登录令牌。
- 会话令牌必须保存到 Keychain；仅在 Extension 确有必要时通过 Keychain Access Group 共享最小凭证。
- Xboard 当前可能使用 HTTP 403 表示会话无效；MobileApp 与客户端必须把带会话失效机器码的 HTTP 401 和 403 统一转为重新登录状态，其他 403 仍按业务拒绝处理。
- 退出登录前必须断开 VPN、清除活动 Profile 和敏感缓存。
- 首发不创建独立的 Apple-only 账号体系；未来新增第三方登录时必须重新评估 Sign in with Apple 要求。

验收结果：注册、登录、密码重置、会话恢复、会话过期和退出流程均能在新旧 Mobile API 兼容窗口内工作。

### IOS-FR-004 首页与系统状态

- 首页必须展示连接按钮、系统真实连接状态、当前节点、连接时长、订阅状态、剩余流量和到期时间。
- NEVPNStatus 和 Extension 状态是连接状态事实源，Flutter 内存状态只能作为展示缓存。
- 主 APP 被系统终止后，重新打开必须从 NETunnelProviderManager 恢复实际状态。
- 无权益、无可用节点、配置未安装、服务维护和内核错误必须展示不同状态。

验收结果：终止并重启主 APP 不得错误显示已断开，也不得重复启动内核。

### IOS-FR-005 节点列表与选择

- 只展示服务端判定当前用户可见、当前 APP 可用且匹配 VLESS + REALITY + TCP + Vision 的节点。
- 节点列表展示名称、地区、可用状态和最近一次延迟，不显示用户 UUID、Reality 公钥、short ID 等连接凭证。
- 用户可手动选择节点；连接中切换节点必须由 Swift VPN Manager 执行一次受控的停止和重启。
- 服务端撤销节点或权益后，客户端不得继续用过期缓存建立新连接。

验收结果：节点分组、显示开关、流量限制和动态端口结果与 Xboard 官方可用节点服务一致。

### IOS-FR-006 移动 Profile

- 客户端只接受 MobileApp 插件生成的结构化、带版本 Profile。
- Profile 必须包含稳定不透明标识、Schema 版本、服务器、端口、用户 UUID、Vision flow、Reality server name、公钥、short ID、固定 uTLS 指纹、SpiderX、MTU 和权益到期信息。
- 协议必须是 VLESS，安全层必须是 REALITY，传输必须是 TCP，flow 必须是 XTLS Vision。
- Xboard 节点必须显式启用 uTLS 并设置固定受支持指纹；首发默认使用 chrome，拒绝缺失值和 random。
- Xboard 当前没有 SpiderX 节点字段，Profile Schema 1 必须由服务端固定为根路径。
- 生产环境必须拒绝允许不安全验证的节点设置。
- Reality 私钥、Xboard 原始协议设置和其他协议字段绝对不得返回客户端。

验收结果：必填字段缺失、版本不支持、节点越权或配置不安全时，服务端不下发完整凭证，客户端不启动 VPN。

### IOS-FR-007 VPN 配置与签名能力

- 主 APP 必须通过 NETunnelProviderManager 创建、保存、读取和更新唯一的 VPN 配置。
- 用户必须通过 Apple 系统界面确认添加 VPN 配置。
- 配置展示名称必须与 App Store 品牌一致，不得重复创建无效系统配置。
- 主 APP 和 Packet Tunnel Extension 必须使用不同且固定的 Bundle Identifier。
- NetworkExtension、App Groups、Keychain Groups、App ID 和 provisioning profile 必须相互一致。
- 发布门禁必须检查最终 Archive 中的签名和 entitlement，不以 Xcode 项目界面勾选状态代替。
- 配置损坏、Extension 版本变化或授权撤销时必须支持删除并重新安装。

验收结果：首次安装、用户拒绝、配置更新、配置删除和重装均能恢复到准确状态。

### IOS-FR-008 Packet Tunnel Extension

- Packet Tunnel 必须使用 Swift NEPacketTunnelProvider 实现。
- Extension 负责启动、停止、网络设置、utun 识别、Xray 生命周期、网络变化和错误上报。
- 必须通过系统 NetworkExtension 接口配置 IPv4、IPv6、DNS、MTU、包含路由和排除路由。
- 系统网络设置完成后，必须使用公开、可审核的 Darwin utun 接口识别当前隧道文件描述符。
- 禁止使用私有 Framework API、私有选择器或基于 KVC 的私有路径获取文件描述符。
- utun 文件描述符必须按 Xray 官方移动 TUN 约定传递给内核，停止时完整释放。
- Extension 中不得加载 Flutter 引擎、支付 SDK、广告 SDK或非必要分析组件。

验收结果：真机通过 TUN 数据收发、停止释放、内存压力和 App Store 静态检查；发现依赖私有 API 时阻断发布。

### IOS-FR-009 libXray 与 C ABI

- 使用 libXray 官方 Apple cgo 模式构建 XCFramework，并固定其公开 C ABI。
- Swift XrayAdapter 必须封装配置校验、启动、停止、运行状态和版本查询。
- 每次 C ABI 返回的非空原生内存必须由适配层按 libXray 契约释放。
- libXray 的外部接口变化只能影响 XrayAdapter，不得扩散到 Flutter 页面和领域模型。
- Extension 进程只允许运行一个 Xray 实例和一个 Go runtime。
- 禁止并发运行用于配置测试的第二个 Xray 实例。
- 禁止运行时下载或动态加载 XCFramework、Go 库或其他可执行代码。

验收结果：地址消毒、内存压力和连续启停测试不出现可归因于 C ABI 所有权的泄漏或崩溃。

### IOS-FR-010 主 APP 与 Extension 通信

- Pigeon 只负责 Flutter 与主 APP Swift 层通信，不能直接跨越到 Packet Tunnel Extension。
- 主 APP 与 Extension 必须通过 NETunnelProviderSession 的 Provider Message 通信。
- 每条消息必须有协议版本、请求标识、类型、大小限制和超时。
- App Group 只保存版本化活动配置和非敏感共享状态；敏感内容必须使用受 Data Protection 保护的文件和 Keychain 密钥。
- Extension 不可用、消息版本不兼容或超时时必须返回明确错误，主 APP 不得无限等待。

验收结果：消息重复、超时、Extension 重启和主 APP 重启后不产生冲突状态或重复内核实例。

### IOS-FR-011 连接状态机

- 业务状态必须覆盖未连接、准备、连接中、已连接、重连中、断开中和错误。
- 每次状态事件必须包含时间、Profile 标识和脱敏错误码。
- NEVPNStatus 是系统连接状态事实源，Extension 自定义状态只能补充内核细节。
- 所有连接、断开和重复操作必须幂等，并具有明确超时。

验收结果：快速重复点击、主 APP 重建、Extension 重建和系统停止 VPN 时均保持唯一确定状态。

### IOS-FR-012 网络变化、睡眠与恢复

- 必须处理 Wi-Fi、蜂窝网络、无网络和网络重新可用场景。
- 必须处理锁屏、睡眠、唤醒、设备网络路径变化和 Extension 被系统终止后的状态恢复。
- 自动重连必须使用有上限的退避；用户主动断开后不得自动重连。
- 网络变化期间不得启动第二个 Xray 实例。
- On Demand 为后续 P1 能力，首发默认由用户主动连接。

验收结果：网络切换二十次、锁屏唤醒和长时间后台运行后，连接状态与实际流量路径一致。

### IOS-FR-013 DNS、IPv6 与路由

- NetworkExtension 与 Xray 的 DNS、路由、MTU 和 IP 栈策略必须一致。
- 必须防止 DNS 从 VPN 外泄漏，不记录 DNS 查询内容。
- 首发 IPv6 启用与否必须由真机验证结果冻结；如禁用，必须阻断 IPv6 泄漏而不是静默绕过。
- 节点服务器地址和必要系统网络必须避免形成 VPN 回环。

验收结果：IPv4-only、IPv6-only 或双栈中产品声明支持的场景全部通过，未支持场景明确失败且不泄漏。

### IOS-FR-014 延迟检测

- 首发使用 TCP 或 HTTPS 近似延迟与服务端健康结果，不为测速启动并发 Xray 实例。
- 延迟失败不得自动删除节点，也不得被误判为账户无权益。
- 连接期间的测速不得明显影响隧道稳定性。

验收结果：无网络、节点不可达和请求超时均返回独立结果，页面不会无限等待。

### IOS-FR-015 流量展示

- 1.0 必须展示连接时长、Xboard 服务端累计用量、剩余流量和到期时间。
- 必须清楚区分服务端计费用量与本地当前会话用量。
- 精确本地会话上传和下载为 P1；libXray 当前固定接口没有该查询能力，未实现时不得显示伪造数值。
- 如后续实现精确会话统计，扩展必须编译进同一 libXray XCFramework 并保持一个 Go runtime。

验收结果：刷新、重登和跨设备后的服务端流量一致；未知本地会话数据明确显示不可用。

### IOS-FR-016 套餐与权益

- 必须展示当前套餐、状态、到期时间、剩余流量和重置时间。
- App Store 发行包只展示已在服务端映射到有效 App Store Product ID 的套餐。
- 没有有效权益时不得取得完整 Profile 或建立连接。
- Web 购买形成的现有权益可在 APP 使用，但 APP 不显示 Web 购买或外部付款入口。

验收结果：Web 权益和 App Store 权益均能正确连接，失效、撤销和退款后不能建立新连接。

### IOS-FR-017 StoreKit 2

- 使用 StoreKit 2 展示商品、发起购买、监听交易更新、恢复购买和打开系统订阅管理页面。
- APP 只处理 StoreKit 已验证交易，不信任本地计算的价格、套餐、时长或权益结果。
- appAccountToken 必须由 MobileApp 为账户生成独立随机 UUID，不得使用 Xboard 内部用户 ID、邮箱或 VLESS 用户 UUID。
- 同一 original transaction 只能绑定一个 Xboard 账号，交易标识和通知标识必须幂等。
- 客户端提交交易后，服务端必须验证签名，并在需要时使用 App Store Server API 重查真实订阅状态。
- App Store Server Notifications V2 只作为状态变化触发器；服务端验证签名后必须重查并计算最终权益。
- 已验证交易只有在服务端安全持久化并成功交付后才能完成；网络失败时必须持久化重试。
- 恢复购买必须请求 App Store 同步，并重新提交当前已验证权益，最终结果仍由服务端裁决。
- 必须处理首次购买、续费、取消、过期、退款、撤销、宽限期、账单重试、升级、降级和恢复。

验收结果：StoreKit 测试与沙盒中的完整生命周期、重复通知和乱序通知均不造成重复或错误权益。

### IOS-FR-018 统一权益裁决

- MobileApp 的 EntitlementService 必须是所有移动 API 权益输出的唯一裁决点。
- Web 订单继续遵循 Xboard 现有流程；App Store 交易先写入插件独立账本，再由适配层投影到 Xboard 用户订阅字段。
- 投影必须在数据库事务和用户行锁中执行，重复事件不得重复延长权益。
- 合并权益不得缩短用户已有的更长期有效权益。
- 活跃的 App Store 管理权益不得被 WalletCenter 余额自动续费处理。
- 客户端不得直接提交或修改 plan、group、流量额度和到期时间。

验收结果：Web 与 App Store 事件并发、重复和乱序时，最终权益确定且可审计。

### IOS-FR-019 钱包与签到

本项优先级为 P1。

- 在服务端功能开关允许时，APP 可以展示 WalletCenter 提供的余额、签到状态和签到记录，并允许用户执行现有签到操作。
- 所有签到资格、奖励和频率限制必须由 WalletCenter 服务端裁决，客户端不得计算或提交奖励数值。
- App Store 发行包不得提供余额充值、Stripe、BEpusdt、外部付款链接或使用余额购买数字订阅的入口。
- WalletCenter 自动续费不得处理由 App Store 管理的活跃权益；Web 用户仍在网站使用原有钱包和付款能力。

验收结果：余额和签到结果与 WalletCenter 一致，重复签到不会重复发放奖励，APP 内不存在绕过 StoreKit 的购买路径。

### IOS-FR-020 公告、工单与客服

- 公告必须支持列表、详情、已读状态和服务端分页。
- 工单必须支持创建、列表、详情、回复和关闭。
- 工单附件如不在首发范围，界面不得展示不可用入口。
- 客服诊断信息默认脱敏，用户主动确认后才可导出。

验收结果：当前和上一主要 APP 版本均可读取新版 Mobile API 的公告与工单 DTO。

### IOS-FR-021 账号删除

- APP 内必须提供容易找到的账号删除入口，不能只提供停用或退出登录。
- 删除前必须说明 App Store 订阅不会因删除 Xboard 账号自动取消，并提供订阅管理入口。
- 删除请求必须由服务端执行并支持身份再确认、幂等和审计。
- 必须删除或匿名化非必要个人数据，依法必须保留的交易记录应隔离保存并说明期限。
- 完成删除后必须断开 VPN、撤销会话并清除本地敏感数据。

验收结果：删除后的账号不能继续登录或获得 Profile，重复请求不会造成服务端错误。

### IOS-FR-022 设置与诊断

- 必须支持语言、主题、自动重连、诊断日志开关、重新安装 VPN 配置和重置连接状态。
- 必须显示 APP、Mobile API、Profile Schema、libXray 和 Xray-core 版本。
- 诊断包只能包含运行版本、状态时间线、脱敏错误、设备与网络类别。
- 诊断包不得包含令牌、用户 UUID、完整 Profile、访问目标、DNS 历史或流量内容。

验收结果：安全检查确认日志、剪贴板、通知和导出文件中没有连接凭证。

### IOS-FR-023 熔断与兼容控制

- 服务端必须能按 iOS 版本、APP 版本、libXray 版本和 Xray-core 版本禁止新连接或暂停购买。
- 服务端必须为当前和上一主要 APP 版本保留其支持的 Mobile API 与 Profile Schema。
- 熔断只能控制服务端响应和业务能力，不能替换客户端内核。
- 安全问题需要强制升级时，仍必须保留账号删除、法律信息和客服入口。

验收结果：禁用指定内核版本不会误伤其他已验证版本，关闭购买不会影响已有有效用户查看账号。

## 7. MobileApp 插件需求

### 7.1 必须交付的服务端能力

MobileApp 插件必须新增以下版本化业务操作：

- 启动配置查询。
- 登录、注册、邮箱验证码和密码重置。
- 当前账户与统一权益查询。
- 可售套餐、兼容节点和单个连接 Profile 查询。
- 公告查询和工单全生命周期。
- 设备登记。
- App Store 购买验证与购买恢复。
- App Store Server Notifications V2 接收和处理。
- 账号删除申请和执行。

插件路由必须声明完整的移动 API 版本前缀，因为当前 Xboard 插件加载器只自动附加 API 中间件，不自动附加官方 API 版本前缀。公开认证操作、登录后操作和平台回调必须使用不同的鉴权策略。

### 7.2 Xboard 适配要求

| 领域 | 必须复用的 Xboard 能力 | MobileApp 的稳定边界 |
| --- | --- | --- |
| 认证 | AuthService、RegisterService、LoginService 和 Sanctum | 输出移动端会话 DTO 与稳定错误码 |
| 用户与套餐 | User、UserService、Plan、PlanService | 输出版本化账户和套餐 DTO |
| 节点 | ServerService 的当前用户可用节点结果 | 二次筛选 VLESS + REALITY + TCP + Vision 并生成 Profile |
| 公告与工单 | 官方 Notice、Ticket 及相应服务 | 输出独立分页 DTO |
| 钱包 | 本仓库 WalletCenter | 只读取允许展示的数据，并排除 App Store 权益自动续费 |

插件不得直接通过节点主键查询绕过用户分组、显示状态、流量限制和动态端口规则。Xboard 当前用户节点摘要接口不含完整 Reality 参数，因此不得直接用其响应建立连接。

### 7.3 Profile 字段来源

| 移动 Profile 内容 | 唯一来源或规则 |
| --- | --- |
| 稳定 Profile 标识 | 插件生成的不透明标识，并在每次访问时重新校验权限 |
| 服务器 | Xboard Server 的 host |
| 端口 | ServerService 为该用户解析后的端口 |
| 用户 UUID | ServerService 为 VLESS 生成的用户凭证 |
| flow | Xboard 协议设置，且只接受 xtls-rprx-vision |
| 安全层 | Xboard Reality 标志，且只接受 Reality |
| 传输 | Xboard network，且只接受 TCP 或等价 RAW 表示 |
| server name、公钥、short ID | Xboard Reality 设置 |
| uTLS 指纹 | Xboard 显式启用且固定的受支持指纹 |
| SpiderX | Profile Schema 1 固定为根路径 |
| MTU | MobileApp 的 iOS 平台配置 |
| 到期时间 | EntitlementService 计算的有效权益到期时间 |

### 7.4 插件数据要求

插件必须使用独立数据表保存设备登记、App Store 商品与 Xboard 套餐映射、appAccountToken 账户关联、交易账本、Notifications V2 事件去重、权益投影和删号请求。插件迁移只允许新增自有表或兼容字段，不得修改或删除 Xboard 官方迁移。

以下数据必须具备数据库唯一约束：平台与交易标识组合、平台通知事件标识、original transaction 与 Xboard 用户的唯一绑定。商品映射必须由服务端后台配置并校验 Bundle ID、Product ID 和环境；客户端上传的价格、套餐 ID、时长和到期时间一律不可信。

### 7.5 响应与错误要求

- 保持 Xboard 现有成功或失败外层语义，同时增加不随语言变化的机器错误码和请求标识。
- 客户端业务只能依赖 HTTP 语义和机器错误码，不得解析本地化消息。
- 会话失效、无权益、Profile 不可用、Schema 不支持、APP 版本不支持、内核禁用、购买待处理、购买无效、重复购买和服务维护必须有独立错误码。
- 新字段默认可选；删除字段必须经过完整兼容窗口。

## 8. 安全、隐私与许可证

- 所有 Mobile API 通信必须使用 HTTPS，并执行合理的超时、重试上限和服务端证书有效性检查。
- 不在客户端保存 Reality 私钥、Xboard 原始节点配置或通用订阅文本。
- 活动 Profile 如需供 Extension 读取，必须使用受 Data Protection 保护的 App Group 文件并加密；密钥保存在 Keychain。
- App Group UserDefaults 只允许保存版本、Profile 标识和非敏感状态。
- Extension 日志必须使用隐私标记并限制容量；生产日志、分析和崩溃报告必须脱敏。
- 不在 Packet Tunnel Extension 集成非必要广告、分析、营销或崩溃 SDK。
- App Store 隐私信息必须覆盖账号、购买、设备登记、诊断和 VPN 数据处理的实际范围。
- 不使用私有 Apple Framework API、私有选择器或规避平台限制的技术。
- Xray-core 使用 MPL-2.0；如修改其受覆盖源文件，必须履行相应源文件公开义务。
- libXray 使用 MIT；发布包必须保留其版权和许可文本。
- APP 内必须提供开源许可证页面，构建流水线必须输出完整软件物料清单，并逐项审查全部 Go、Flutter 和 Apple 依赖许可证。

## 9. 非功能需求与验收门槛

### 9.1 性能和稳定性

- 冷启动至可操作首页目标不超过三秒，不计不可控网络等待。
- 用户发起连接后十秒内必须成功或返回明确错误。
- 连续连接和断开一百次不得出现资源泄漏、重复 Xray 或残留 utun 状态。
- 真机持续连接二十四小时不得发生非预期 Extension 终止。
- Wi-Fi 与蜂窝网络切换二十次后状态和路由必须正确。
- Extension 稳态内存必须持续低于实测系统终止阈值并保留安全余量。
- CPU、内存和耗电相对上一发布版本显著回退时阻断发布。

### 9.2 功能验收矩阵

| 场景 | 必须结果 |
| --- | --- |
| 正确 VLESS + REALITY + Vision 配置 | 成功连接并通过 VPN 访问测试目标 |
| 错误 UUID、公钥、short ID 或 server name | 明确失败并完整清理资源 |
| 无权益或节点越权 | 服务端不下发完整 Profile |
| VPN 配置拒绝、删除或损坏 | 不启动 Extension 并提供恢复流程 |
| DNS、IPv4、IPv6、TCP、UDP 与 QUIC 应用流量 | 符合已冻结的路由策略且无非预期泄漏 |
| 主 APP 或 Extension 重建 | 恢复真实状态且不重复启动内核 |
| App Store 重复或乱序事件 | 权益只应用一次并得到确定最终状态 |
| 删除账号 | 服务端删除生效、本地数据清除、VPN 停止 |
| 静态和运行时平台检查 | 不使用私有 API，不加载 Flutter 到 Extension |

### 9.3 版本兼容矩阵

| 客户端 | 后端 | 发布门槛 |
| --- | --- | --- |
| 当前 iOS 正式版 | 当前生产后端 | 必须通过 |
| 上一主要 iOS 正式版 | 待发布后端 | 必须通过 |
| 当前 iOS 正式版 | 待发布后端 | 必须通过 |
| 新 iOS 候选版 | 当前生产后端 | 提审前必须通过 |
| 新 iOS 候选版 | 待发布后端 | 必须通过 |

## 10. 上游更新要求

### 10.1 统一版本锁

xboard-mobile 仓库根目录必须维护机器可校验的上游版本锁。版本锁至少记录 Flutter、Dart、Pigeon、Xcode、Swift、iOS Deployment Target、Go、libXray 提交、Xray-core 提交、Xboard master 提交、Xboard compose 提交、实际 Xboard 容器镜像摘要、Mobile API 版本、Profile Schema 版本和 XCFramework 校验和。

所有值必须是精确版本或不可变摘要。CI 必须拒绝缺失值、占位值、latest、main、master 或未经锁定的动态依赖。

同一产品发布系列的 Android 与 iOS 必须使用相同的 libXray 和 Xray-core 源码提交；AAR 与 XCFramework 允许独立构建和独立发布，但不得在没有兼容说明和专项审批时使用不同内核基线。

### 10.2 libXray 与 Xray-core 更新

- libXray 与其 Go 模块实际锁定的 Xray-core 必须作为一个组合升级，禁止只升级其中一个。
- 上游监测只能创建报告和升级分支，禁止自动合并、自动替换生产 XCFramework 或自动提交商店。
- 每次构建必须归档源码提交、Go 模块图、Go、Xcode、Swift、构建参数、XCFramework、校验和、软件物料清单和许可证清单。
- 升级影响必须由 Swift XrayAdapter 吸收，不得改变 Flutter 的业务级 VPN 契约，除非明确提升契约版本。
- 必须先完成配置转换、C ABI 所有权、适配层和安全扫描测试，再完成 iOS 真机连接、网络切换、锁屏、Extension 重建、内存压力、二十四小时长稳和一百次启停测试。
- 每次升级必须重新执行私有 API 扫描和最终 Archive entitlement 检查。
- 至少保留最近两个已验证 XCFramework、对应源码锁和可重复构建环境。

### 10.3 Xboard 更新

- Xboard master 源码提交、compose 分支提交和实际 GHCR 镜像摘要是三个不同身份，升级记录必须同时保存。
- compose 分支只表示部署文件版本，不能用其提交号推断运行中的 Xboard 源码版本。
- 现有直接拉取 latest 并重建生产环境的方式不能作为 Mobile API 上线后的正式升级流程。
- 更新脚本必须先解析候选镜像摘要，在预发布环境使用该摘要部署，通过门禁后，生产环境复用同一摘要。
- 必须保留上一稳定镜像摘要和数据库备份恢复方案。
- 预发布环境升级 Xboard 后，必须重新应用 xboard-custom overlay，并确认 MobileApp 插件完成安装、迁移、启用和路由加载。
- overlay 部署脚本必须新增 MobileApp 插件同步和安装验证，不得手工修改 Xboard 官方路由或核心文件。
- 必须执行认证、节点筛选、Profile 映射、权益投影、WalletCenter 排除、App Store 通知、账号删除和新旧客户端兼容测试。
- 只有当前正式版和上一主要正式版均通过后，才能升级生产环境。

### 10.4 兼容与废弃规则

- Mobile API 和 Profile Schema 至少同时支持当前版本与上一版本。
- 后端先增加兼容字段和能力，客户端后发布；旧字段只能在兼容窗口结束且活跃旧客户端低于批准阈值后删除。
- Android 与 iOS 可独立发版，后端不得要求两端同日升级。
- iOS 客户端处于 App Review 期间时，生产后端必须继续满足已提交二进制的契约。
- 强制升级仅用于安全漏洞、平台硬性要求或已证明无法兼容的情况。
- 任一上游变更无法通过 P0 回归时必须停止升级，生产继续使用上一锁定组合。

### 10.5 固定更新顺序

每次更新必须按以下顺序执行，不得颠倒：

1. 创建独立升级分支，更新上游版本锁并生成变更、许可证和风险报告。
2. 先在预发布环境升级 MobileApp 或 Xboard，使后端同时兼容现有客户端与候选客户端。
3. 使用当前正式版和上一主要正式版验证登录、Profile、连接、权益、支付恢复和账号删除。
4. 构建候选 XCFramework 和 iOS APP，完成自动化、真机、TestFlight 和回滚演练。
5. Android 与 iOS 按各自审核进度独立发布；任一平台未发布时，后端继续保留旧契约。
6. 只有兼容窗口结束、活跃旧版本低于批准阈值且无回滚依赖后，才允许废弃旧字段或旧 Schema。

Xboard 更新、libXray 与 Xray-core 组合更新、iOS 发版是三条独立变更线。除非同一功能确有依赖，不得把三者强制合并为一次生产变更。

## 11. App Store 发版要求

### 11.1 账户、资质与地区

- 公开上架必须使用 Apple Developer Program 的 Organization 账户，不得使用 Apple Developer Enterprise Program 公开分发。
- 企业法定名称、Seller 名称、网站、隐私政策、客服和付款资料必须一致。
- 主 APP、Packet Tunnel Extension、NetworkExtension、App Groups 和 Keychain Groups 能力必须由同一组织正确签名。
- VPN APP 必须满足 Apple App Review Guidelines 对 VPN 服务、数据使用和组织主体的要求。
- 上架国家或地区必须逐一完成 VPN 服务、消费者保护、税务和数据合规评估；香港企业账户不自动代表所有 storefront 均可合法提供 VPN。
- 要求 VPN 许可或备案的地区，只有在材料有效并可向 Apple 提供时才允许勾选销售。

### 11.2 构建与签名

- 正式 Archive 必须使用版本锁中的 Xcode、XCFramework 和依赖，并验证所有校验和。
- 主 APP 与 Extension 必须分别使用正确 Bundle ID、签名、provisioning profile 和 entitlement。
- 市场版本号遵循项目版本规则，构建号必须单调递增。
- 发布归档必须包含 Archive、dSYM、软件物料清单、开源许可证、内核版本和构建来源记录。
- 正式包不得包含测试服务器、调试证书、StoreKit 测试配置、明文密钥或未启用的外部支付入口。
- App Review Notes 必须提供有效测试账号、完整 VPN 操作步骤、订阅测试说明、隐私说明和适用地区许可材料。

### 11.3 发布阶段与门禁

- 候选版必须依次通过本地与 CI、TestFlight Internal、TestFlight External、App Review 和 Phased Release。
- 每个阶段必须监控崩溃、Extension 终止、连接成功率、内存、耗电、购买验证失败和权益差异。
- 任一 P0 指标超过已批准阈值时必须停止发布或暂停 Phased Release。
- 生产后端、节点和审核账号必须在 App Review 期间持续可用。
- App Store 隐私信息、VPN 披露、账号删除和订阅说明必须与提交二进制实际行为一致。
- 数字订阅默认只使用 StoreKit 2；任何 storefront 外部购买例外必须在开发前完成 Apple 当时项目规则、entitlement 和法律专项评审。

### 11.4 回滚与事故处理

- iOS 不能依赖即时恢复旧二进制，后端向后兼容和远程熔断是首要恢复手段。
- 发现客户端问题时必须暂停 Phased Release，并按 APP 或内核版本禁用新连接或购买。
- 已安装问题版本仍必须能够查看账号、删除账号、管理订阅和取得客服帮助。
- 内核问题必须通过重新构建和提交新版 APP 修复，禁止远程替换 XCFramework。
- Xboard 服务端问题必须回退到已验证镜像摘要，并按数据库迁移的已批准恢复方案处理。
- 每次事故后必须记录受影响版本、根因、用户影响、回滚结果和新增回归用例。

## 12. 开发阶段与交付物

### 阶段 A：技术验证

- 建立可重复的 libXray cgo XCFramework 构建链。
- 在真实 iPhone 和 iPad 跑通 NetworkExtension、utun 文件描述符、Xray 原生 TUN 和 VLESS + REALITY。
- 验证 C ABI 内存所有权、Extension 内存、私有 API 扫描、锁屏、网络切换、二十四小时连接和资源释放。

### 阶段 B：MobileApp 插件

- 实现版本化移动 API、Xboard Adapter、ProfileService、EntitlementService、App Store 服务端验证、Notifications V2、独立数据表和自动化测试。
- 更新 overlay 部署脚本，加入 MobileApp 同步、迁移、启用和健康检查。
- 完成新 Xboard 与当前、上一客户端的契约回归。

### 阶段 C：iOS 客户端

- 完成 Flutter 页面和 Dart 业务层。
- 完成 Pigeon、Swift VPN Manager、Provider Message 和 Packet Tunnel Extension。
- 完成 Keychain、App Group、诊断和状态恢复。

### 阶段 D：支付与合规

- 完成 StoreKit 2、App Store Server API、Notifications V2 和权益幂等。
- 完成隐私信息、VPN 披露、账号删除、App Review Notes 和地区许可材料。

### 阶段 E：验收与发布

- 完成功能、内核、稳定性、安全、许可证、兼容和回滚演练。
- 进入 TestFlight Internal、TestFlight External、App Review 和 Phased Release。

## 13. 完成定义

iOS 1.0 只有在以下条件全部满足后才可标记完成：

- 所有 P0 功能和验收矩阵通过，P1 延后项有已批准版本计划。
- MobileApp 插件、独立迁移、自动化测试和 overlay 部署改动已在 xboard-custom 中完成。
- 官方 Xboard 工作树没有未管理的项目定制修改。
- 真机 VLESS + REALITY、DNS、路由、网络切换、锁屏、Extension 重建、二十四小时连接和一百次启停通过。
- Extension 内存留有安全余量，C ABI 内存所有权测试通过，不使用私有 API。
- StoreKit 购买、续费、取消、恢复、退款、撤销及重复事件测试通过。
- WalletCenter 不会处理 App Store 管理权益。
- 当前和上一主要 iOS 客户端均通过待发布后端兼容测试。
- APP 中不存在外部数字订阅付款、通用订阅导入、第二内核、Extension 内 Flutter 或远程代码加载。
- 隐私、VPN 披露、账号删除、审核账号、签名能力和地区材料齐全。
- 上游版本锁、可重复构建、XCFramework 校验和、软件物料清单和许可证归档完整。
- 已验证服务端熔断、Phased Release 暂停和 Xboard 镜像回退流程。

## 14. 参考基线

- [Xboard 官方仓库](https://github.com/cedar2025/Xboard)
- [Xray-core 官方仓库](https://github.com/XTLS/Xray-core)
- [libXray 官方仓库](https://github.com/XTLS/libXray)
- [Apple App Review Guidelines](https://developer.apple.com/app-store/review/guidelines/)
- [NEPacketTunnelProvider 官方文档](https://developer.apple.com/documentation/networkextension/nepackettunnelprovider)
- [Network Extension Entitlement 官方文档](https://developer.apple.com/documentation/bundleresources/entitlements/com.apple.developer.networking.networkextension)
- [App Store Server API 官方文档](https://developer.apple.com/documentation/AppStoreServerAPI)
- [App Store Server Notifications 官方文档](https://developer.apple.com/documentation/appstoreservernotifications)
- [Apple 应用内账号删除要求](https://developer.apple.com/support/offering-account-deletion-in-your-app/)
- [本项目 Xboard 可升级扩展方案](../Xboard%20可升级扩展方案.md)
