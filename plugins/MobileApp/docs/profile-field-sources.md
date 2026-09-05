# Profile Schema 1 字段来源

`GET /profiles/{opaqueProfileId}` 的路径参数必须是 TASK-024 的 `opaqueNodeId`。每次请求重检权益和 `ServerService` 授权集合。

| 字段 | 来源 |
| --- | --- |
| opaqueProfileId | `sha256(mobile-app:node:v1:{id})` |
| server | Server.host |
| port | ServerService 解析后的用户端口 |
| userId | ServerService VLESS 凭证 |
| flow | 仅 xtls-rprx-vision |
| security | Reality |
| network | tcp 或 raw |
| serverName / publicKey / shortId | Reality 设置 |
| fingerprint | 显式 chrome uTLS |
| spiderX | `/` |
| mtu | 1280 |
| entitlementExpiresAtEpochMs | EntitlementService |

禁止返回 Reality 私钥、原始 protocol_settings 和订阅文本。缺字段、已启用 VLESS encryption 或非法值返回 `PROFILE_UNAVAILABLE`，不下发部分凭证。Android 1.0 内核固定 `encryption` 为 `none`。
