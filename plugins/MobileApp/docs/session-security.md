# MobileApp 会话安全说明

- 移动 API 会话唯一凭证是 Sanctum Bearer 个人访问令牌。`v2_user.token` 是订阅令牌，禁止作为 Authorization。
- `AuthService::generateAuthData()` 同时返回 `auth_data`（Sanctum）和 `token`（订阅）。适配器只输出 `tokenType=Bearer`、`sanctumToken` 和 `expiresAtEpochMs`，删除 `token`、`auth_data`、`is_admin`。
- 禁止调用 `AuthService::findUserByBearerToken()`。该方法不检查 `expires_at`。受保护路由继续走官方 `user` 中间件的 Sanctum guard。
- 登录成功签发的令牌默认一年，与官方 `generateAuthData` 一致。
- `opaqueAccountId` 是 `sha256(mobile-app:account:v1:{id})`，不是数字主键，也不是 VLESS UUID。
- 密码重置在官方 `LoginService::resetPassword` 成功后，由适配器调用 `AuthService::removeAllSessions()` 撤销该用户全部 Sanctum 会话。官方服务本身不撤销，这是移动会话策略。
- 退出当前会话、速率限制与枚举防护的 HTTP 面由 TASK-022 交付。
- 日志只记录 `opaqueAccountId` 和 `tokenType`，由 `MobileLogRedactor` 脱敏；不得写入 Sanctum 令牌、订阅令牌、密码或 UUID。
