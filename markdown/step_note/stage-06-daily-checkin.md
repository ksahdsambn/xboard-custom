# 阶段 6：实现每日签到随机余额功能

## 实现范围

- 仅在 `plugins/WalletCenter/` 内实现签到正式逻辑，不修改核心目录。
- 保持充值与自动续费仍为阶段 5 骨架，不提前推进阶段 7/8。

## 完成内容

- 新增 `plugins/WalletCenter/Services/CheckinService.php`：
  - 统一处理签到状态读取、奖励区间解析、随机奖励生成、签到历史读取与后台记录读取。
  - 使用数据库事务包裹签到流程。
  - 在签到执行前对当前用户做 `lockForUpdate()`，避免同日重复签到并发穿透。
  - 复用核心 `App\Services\UserService::addBalance()` 做余额原子入账，不自行修改核心余额逻辑。
  - 在签到日志 `meta` 中记录 `balance_before`、`balance_after`、请求 IP 与 User-Agent，保证记录可追踪。
- 改造 `plugins/WalletCenter/Controllers/CheckinController.php`：
  - `status` 返回正式阶段 6 状态数据。
  - `claim` 返回正式签到结果，并在重复签到时返回 `409`。
  - `history` 返回用户签到历史记录。
- 改造 `plugins/WalletCenter/Controllers/AdminController.php`：
  - `checkinLogs` 返回后台签到记录，并附带用户邮箱。
- 改造 `plugins/WalletCenter/Controllers/BaseController.php`：
  - 新增统一正式阶段 payload 组装方法与分页上限解析方法。
- 改造 `plugins/WalletCenter/Models/CheckinLog.php`：
  - 新增 `user()` 关联。
  - 规范 `claim_date` 对外输出为 `YYYY-MM-DD`。
  - 为 `reward_amount` 增加整型转换。

## 验证结果

### 成功链路

- 已登录用户在签到开关开启后可访问：
  - `GET /api/v1/wallet-center/checkin/status`
  - `POST /api/v1/wallet-center/checkin/claim`
  - `GET /api/v1/wallet-center/checkin/history`
- 首次签到成功：
  - 返回 `200`
  - 奖励值为 `344`
  - 奖励值位于配置区间 `100 ~ 500` 内
  - 用户余额从 `0` 增加到 `344`
  - `wallet_center_checkin_logs` 生成 1 条 `success` 记录
- 签到后状态接口返回：
  - `today_claimed = true`
  - `can_claim = false`
  - `today_record.id = 3`
- 用户历史与后台记录接口均返回相同签到记录：
  - 历史记录数为 `1`
  - 后台记录包含 `user.email = stage3-admin@example.com`

### 重复请求与开关验证

- 在同日重复调用 `POST /api/v1/wallet-center/checkin/claim`：
  - 返回 `409`
  - 消息为 `Already checked in today.`
  - 用户余额保持 `344`
  - 签到日志数量保持 `1`
- 在将 `checkin_enabled` 置为 `false` 并重启运行容器后：
  - 用户签到状态接口返回 `403`
  - 后台签到记录接口返回 `403`

### 失败链路

- 通过伪造 `UserService::addBalance()` 返回 `false`，模拟余额写入失败：
  - 抛出 `RuntimeException: WalletCenter checkin reward credit failed.`
  - 用户余额保持 `0`
  - `wallet_center_checkin_logs` 保持 `0`
  - 不会留下错误成功记录

## 边界与异常处理

- 同日重复签到通过“用户行锁 + 成功记录检查”拦截，不依赖核心代码改造。
- 奖励区间配置若无效（最小值/最大值非正数），签到接口会直接失败，不会写入签到记录。
- 阶段验证期间发现 `Octane + SQLite` 在使用外部 `tinker` 修改配置/数据后可能读取旧连接状态，因此运行时验证统一采用“修改后重启 `xboard-stage3-web` 容器”策略，保证结果可重复。

## 放行结论

- 签到流程可用：满足
- 签到记录完整：满足
- 签到幂等性成立：满足
- 阶段 6 放行：是
