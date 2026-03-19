# 阶段 9：注册 WalletCenter 计划任务

## 实现范围

- 仅在 `plugins/WalletCenter/` 内补齐自动续费调度注册与调度防护，不修改核心目录。
- 仅推进 WalletCenter 自动续费计划任务，不提前进入阶段 10 后台配置扩展或阶段 11 前台主题入口改造。
- 仅为已启用的 WalletCenter 插件、且已开启 `auto_renew_enabled` 开关的场景注册计划任务。

## 完成内容

- 改造 `plugins/WalletCenter/Plugin.php`：
  - 正式实现插件级 `schedule()` 方法。
  - 仅在 `auto_renew_enabled = true` 时向系统注册 `wallet-center:auto-renew-scan --limit=100 --due-only`。
  - 为计划任务补充 `everyMinute()`、`onOneServer()` 与 `withoutOverlapping(10)`，避免与系统现有调度互相阻塞或并发重入。
- 改造 `plugins/WalletCenter/Commands/AutoRenewScanCommand.php`：
  - 新增 `--due-only` 选项，允许调度层仅扫描已到执行窗口的自动续费设置。
  - 保留原有 `--limit` 参数，兼容阶段 8 的人工全量扫描验证方式。
- 改造 `plugins/WalletCenter/Services/AutoRenewService.php`：
  - 扫描查询支持“仅扫描 `next_scan_at <= now()` 或 `next_scan_at is null` 的设置”，缩小调度扫描范围。
  - 在处理单个设置前增加“未来时间窗口短路判断”，避免同一设置在重复触发时被再次处理。
  - 为 `pending_order_exists`、`insufficient_balance` 场景增加 5 分钟退避。
  - 为 `runtime_error` 以及订阅上下文不可续费场景增加 30 分钟退避。
  - 保持成功续费场景仍将 `next_scan_at` 推进到新的到期前窗口，确保成功续费后不会再次重复扣款。

## 调度策略

- 扫描频率：每分钟执行一次。
- 扫描范围：仅扫描已启用且 `next_scan_at <= now()` 或 `next_scan_at is null` 的自动续费设置。
- 重复执行防护：
  - 调度级：`onOneServer()` + `withoutOverlapping(10)`。
  - 记录级：自动续费设置行锁、用户行锁、成功续费后推进 `next_scan_at`。
  - 异常级：失败/跳过场景写入退避后的下一次扫描时间，避免短时间内重复刷记录。
- 异常记录规则：
  - `pending_order_exists`、`insufficient_balance`：保留失败或跳过记录，并在 5 分钟后重试。
  - `runtime_error`、订阅不可续费类错误：保留失败记录，并在 30 分钟后重试。

## 验证结果

### 系统识别验证

- 执行 `php artisan schedule:list`，任务列表中出现：
  - `php artisan wallet-center:auto-renew-scan --limit=100 --due-only`
- 说明 WalletCenter 计划任务已被系统调度器识别。

### 单任务执行验证

- 为避免把系统其他每分钟任务一并拉起，本阶段未直接执行全局 `schedule:run`。
- 改为在容器内通过 Laravel 调度事件对象，仅执行 WalletCenter 单一计划任务。
- 首次执行后验证通过：
  - 用户 `stage8-notdue@example.com`（`id = 6`）余额从 `5000` 变为 `3766`
  - 用户 `id = 6` 的 `expired_at` 从 `1773146508` 延长到 `1775824908`
  - 自动续费记录总数从 `5` 增加到 `8`
  - 新增记录包括：
    - `user_id = 6`：`status = success`、`reason = renewed`
    - `user_id = 3`：`status = failed`、`reason = insufficient_balance`
    - `user_id = 4`：`status = skipped`、`reason = pending_order_exists`
  - `user_id = 3` 与 `user_id = 4` 的 `next_scan_at` 均推进到 `2026-03-09 23:00:56`，验证 5 分钟退避生效

### 重复触发验证

- 紧接首次执行后再次触发同一计划任务：
  - 自动续费记录总数保持 `8`，未新增记录
  - 用户 `id = 6` 余额保持 `3766`
  - 用户 `id = 6` 的 `expired_at` 保持 `1775824908`
  - 最近成功记录仍仅有 1 条
- 说明短时间重复触发不会重复续费同一用户，也不会对已退避的失败/跳过场景重复刷记录。

### 插件停用验证

- 临时将 `wallet_center` 插件状态置为禁用后再次执行 `php artisan schedule:list`：
  - WalletCenter 自动续费计划任务从任务列表中消失
- 通过调度事件集合再次验证：
  - 不再存在包含 `wallet-center:auto-renew-scan` 的计划任务事件
- 恢复插件启用后：
  - `php artisan schedule:list` 中重新出现 WalletCenter 计划任务

## 结论

- 阶段 9 的 WalletCenter 计划任务已完成注册，并通过插件启用/停用状态参与系统调度。
- 强制验证项全部通过，且变更仍完全收敛在 `plugins/WalletCenter/`，未修改核心代码。
