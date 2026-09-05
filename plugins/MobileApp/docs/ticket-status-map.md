# 工单权限与状态映射

- 官方 `status` 0=open，1=closed。
- `reply_status` 0 表示等待工作人员回复。
- 用户只能读写自己的工单；越权读取或回复为 `AUTH_FORBIDDEN`。
- 关闭后回复 `TICKET_CLOSED`；重复关闭 `TICKET_ALREADY_CLOSED`。
- 官方等待回复开关开启时，用户连续回复 `TICKET_WAIT_REPLY`。
- 首发无附件，不得返回 `attachmentUpload`。
