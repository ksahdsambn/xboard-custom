# MobileApp 服务端安全基线

- 生产环境 Mobile API 必须 HTTPS；拒绝明文。
- 请求超时 10 秒；下游 Developer API 最多 3 次尝试，退避 0/50/100 ms，禁止请求风暴。
- 普通请求体不超过 64 KiB，RTDN 不超过 128 KiB。
- 分页 `perPage` 大于 1000 或非数字直接拒绝。
- 认证公开且限流；Profile/购买/删号/诊断需要用户会话；RTDN 只接受平台回调鉴权；安全审计只给管理员。
