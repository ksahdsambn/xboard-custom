# 兼容节点筛选拒绝原因

授权集合来自 `ServerService::getAvailableServers`。二次筛选只减少、不新增。摘要 DTO 不含连接凭证。

| 原因 | 条件 |
| --- | --- |
| protocol_not_vless | 节点 type 不是 vless |
| security_not_reality | `tls` 不是 2（Reality） |
| network_not_tcp | network 不是 tcp 或 raw |
| flow_not_vision | flow 不是 xtls-rprx-vision |
| utls_disabled | 未显式启用 uTLS |
| fingerprint_random | 指纹为 random |
| fingerprint_unsupported | 指纹缺失或不是 chrome |
| allow_insecure | 协议、Reality 或 TLS 设置允许不安全验证 |
| missing_host | host 为空 |
| invalid_port | 解析后的端口不在 1-65535 |
| encryption_enabled | VLESS encryption 已启用；Schema 1 只允许 none |
| missing_server_name | Reality server name 为空 |
| missing_public_key | Reality 公钥为空 |
| missing_short_id | Reality short ID 为空 |

隐藏、错分组和节点配额耗尽由 `ServerService` 在授权集合阶段排除，不进入二次筛选。
