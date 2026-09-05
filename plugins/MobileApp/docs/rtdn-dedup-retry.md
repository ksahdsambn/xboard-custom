# RTDN 去重与重试

- 事件主键：`(platform, event_id)`。重复投递不得二次应用 Developer API 结果。
- 通知正文只用于提取 eventId 和购买令牌哈希；`notificationType`、声称的 playStatus、套餐、时长和权益全部忽略。
- 真实状态只来自 Developer API 重查。
- 重试上限 5 次，退避 60/120/240/480/960 秒；超过上限进入 `dead_letter` 供人工处理。
- 不写 Xboard 用户套餐字段；投影留给 TASK-033。
- 不连接真实 Google Play。
