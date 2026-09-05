# 脱敏与审计规则

结构化日志和 `mobile_app_security_audits` 只保存 requestId、errorCode、延迟、路由、操作、结果、购买验证摘要、权益到期差异和 Profile 拒绝原因。

禁止写入会话令牌、购买令牌、用户 UUID、Reality 私钥、完整 Profile、DNS 查询和流量内容。密钥名在去除分隔符后匹配敏感清单即替换为 `[redacted]`。
