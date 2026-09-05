# 公告 DTO 与内容安全策略

- 仅输出 `show=true` 的官方公告。
- 列表字段：不透明 `id`、`title`、`publishedAt`、`read`。
- 详情增加清理后的 `body`。
- 已读绑定当前用户，唯一约束 `(user_id, notice_id)`。
- 禁止透传 `content`、`img_url`、`tags`、`show`。
- HTML 删除 script/iframe/事件处理/javascript URL，仅允许有限排版标签和 http(s) 链接。
