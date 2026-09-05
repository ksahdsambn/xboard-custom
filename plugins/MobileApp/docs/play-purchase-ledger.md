# 购买令牌账本

- 先写入 `mobile_app_purchase_tokens`（只存 SHA-256），再查询开发夹具中的 Developer API 结果。
- 首次购买：持久化 → 账本授予标记 `granted_at` → 三日内 acknowledge。续费不得再次确认。
- 客户端价格、套餐、时长和权益声明忽略。
- 不把 Play 账本写回 Xboard 用户套餐字段；投影留给 TASK-033。
- 不连接真实 Google Play。
