# Play 权益投影

- 事务内锁定用户行后读取 Play 账本、当前 Xboard 权益和历史投影。
- `idempotency_key = play:{ledgerId}:{status}:{expire_at}`，重复事件直接返回已有投影。
- 到期合并取 Web 基线、当前用户行和 Play 到期中的最大值，不得缩短更长期权益。
- 退款/撤销/过期后停用该令牌的 Play 投影，并在没有其他有效 Play 投影时恢复 Web 基线。
- Mobile API 仍只通过 EntitlementService 输出权益。
- WalletCenter 排除留给 TASK-034。
