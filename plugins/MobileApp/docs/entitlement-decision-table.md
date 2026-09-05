# 移动权益决策表

唯一裁决点：`EntitlementService`。客户端不得提交或修改 plan、group、流量、重置时间和到期时间。

| 状态 | 可连接 | 机器码 | 条件 |
| --- | --- | --- | --- |
| maintenance | 否 | SERVICE_MAINTENANCE | 兼容设置为维护 |
| banned | 否 | AUTH_ACCOUNT_BANNED | 用户已封禁 |
| none | 否 | ENTITLEMENT_NONE | 无套餐且无有效 Play 投影 |
| expired | 否 | ENTITLEMENT_EXPIRED | 合并后的到期时间已过 |
| exhausted | 否 | ENTITLEMENT_EXHAUSTED | 服务端计费剩余流量为 0 |
| active | 是 | 无 | 其余条件均满足 |

Play 发行包可售套餐 = `PlanService::getAvailablePlans()` 与已启用 Play 商品映射的交集。Web 权益可连接，但不返回外部购买入口。
