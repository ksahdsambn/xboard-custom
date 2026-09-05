# Play 商品映射

后台以 `package name + Product ID + environment` 唯一映射到可售 Xboard 套餐。

- Package 必须等于 Android `applicationId`：`dev.xboard.xboard_mobile`。
- `sandbox` 与 `production` 隔离；当前运行环境只输出对应启用映射。
- 停用或套餐不再可售时，`GET /plans` 不返回该商品。
- 客户端价格、套餐 ID、时长和到期时间不能写入映射，也不能授予权益。
- 映射变更写入 `mobile_app_play_product_audits`。
- 购买令牌验证留给 TASK-031。
