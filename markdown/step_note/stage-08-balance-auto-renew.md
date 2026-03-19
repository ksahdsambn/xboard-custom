# 阶段 8：实现余额自动续费当前订阅

## 实现范围

- 仅在 `plugins/WalletCenter/` 内实现自动续费正式逻辑，不修改核心目录。
- 仅推进 WalletCenter 自动续费能力，不提前进入阶段 9 调度接入或阶段 11 前台主题入口改造。
- 仅支持对“当前有效且有限期”的订阅执行余额自动续费，不兜底其他支付通道。

## 完成内容

- 新增 `plugins/WalletCenter/Services/AutoRenewService.php`：
  - 统一处理自动续费配置快照、启停设置、用户历史、后台记录和扫描执行。
  - 从用户当前订阅计划与最近一笔已完成核心订阅订单解析当前续费周期与续费金额。
  - 将自动续费窗口固定为到期前 24 小时，并在设置快照中持续维护 `next_scan_at`、最近结果和原因。
  - 扫描执行前检查当前订阅是否有效、是否存在未完成核心订单、余额是否足够，且明确禁止回退到其他支付通道。
  - 续费成功时复用核心 `App\Services\UserService::addBalance()` 扣减余额，同步当前计划属性，并按当前周期延长 `expired_at`。
  - 续费失败或跳过时写入 `wallet_center_auto_renew_records`，保留原因、下次重试时间和执行快照。
- 改造 `plugins/WalletCenter/Controllers/AutoRenewController.php`：
  - `config` 返回正式自动续费配置、当前订阅快照和最近执行结果。
  - `save` 正式写入自动续费启停状态。
  - `history` 返回用户自动续费历史记录。
- 改造 `plugins/WalletCenter/Controllers/AdminController.php`：
  - `autoRenewRecords` 返回后台自动续费记录，并附带用户邮箱信息。
- 改造 `plugins/WalletCenter/Commands/AutoRenewScanCommand.php`：
  - 正式执行自动续费扫描，输出成功、失败、跳过和未到期统计。
- 改造 `plugins/WalletCenter/Models/AutoRenewSetting.php` 与 `plugins/WalletCenter/Models/AutoRenewRecord.php`：
  - 补充时间字段转换、状态标签与关联关系，供前后台记录读取复用。

## 验证结果

### 配置与状态接口验证

- `POST /api/v1/wallet-center/auto-renew/config` 已为以下 5 类测试用户完成启停设置：
  - 成功续费用户：开启
  - 余额不足用户：开启
  - 存在未完成订单用户：开启
  - 禁用用户：先开启再关闭
  - 未到自动续费窗口用户：开启
- `GET /api/v1/wallet-center/auto-renew/config` 验证通过：
  - 成功续费用户返回 `enabled = true`、`period = monthly`、`amount = 1234`
  - 禁用用户返回 `enabled = false`、`last_result = disabled`、`last_result_reason = disabled_by_user`
  - 未到期窗口用户返回未来时间的 `next_scan_at`
  - 存在未完成订单用户返回 `pending_order` 快照

### 首轮扫描验证

- 执行命令 `php artisan wallet-center:auto-renew-scan --limit=100`：
  - `Scanned settings: 4`
  - `Successful renewals: 1`
  - `Failed renewals: 1`
  - `Skipped renewals: 1`
  - `Not due yet: 1`
  - `Processed user IDs: 2,3,4`
- 成功续费用户验证通过：
  - 用户余额从 `5000` 扣减到 `3766`
  - 用户 `expired_at` 从 `1773139308` 延长到 `1775817708`
  - 用户历史记录新增 1 条 `status = success`、`reason = renewed`
  - 历史快照记录了 `balance_before = 5000`、`balance_after = 3766`、`expired_at_before = 1773139308`、`expired_at_after = 1775817708`
- 余额不足用户验证通过：
  - 用户余额保持 `500`
  - 用户 `expired_at` 保持 `1773139308`
  - 用户历史记录新增 1 条 `status = failed`、`reason = insufficient_balance`
- 存在未完成订单用户验证通过：
  - 用户余额保持 `5000`
  - 用户 `expired_at` 保持 `1773139308`
  - 用户历史记录新增 1 条 `status = skipped`、`reason = pending_order_exists`
- 禁用用户验证通过：
  - 未生成自动续费记录
  - `GET /config` 继续返回 `enabled = false`
- 未到期窗口用户验证通过：
  - 未生成自动续费记录
  - `next_scan_at` 保持为未来时间 `2026-03-09T20:41:48+08:00`

### 历史与后台接口验证

- `GET /api/v1/wallet-center/auto-renew/history` 验证通过：
  - 成功续费用户返回 1 条成功记录
  - 余额不足用户返回 1 条失败记录
  - 存在未完成订单用户返回 1 条跳过记录
  - 禁用用户与未到期窗口用户返回空记录集
- `GET /api/v1/wallet-center/admin/auto-renew/records` 验证通过：
  - 首轮扫描后返回 3 条后台记录
  - 记录中正确附带 `user.email`
  - 后台可区分 `renewed`、`insufficient_balance`、`pending_order_exists`

### 幂等与重复扫描验证

- 第二次执行命令 `php artisan wallet-center:auto-renew-scan --limit=100`：
  - `Successful renewals: 0`
  - `Failed renewals: 1`
  - `Skipped renewals: 1`
  - `Not due yet: 2`
  - `Processed user IDs: 3,4`
- 成功续费用户幂等验证通过：
  - 用户余额保持 `3766`
  - 用户 `expired_at` 保持 `1775817708`
  - 历史记录总数仍为 1，未发生二次扣款或二次延长
- 后台记录在第二次扫描后累计为 5 条：
  - 余额不足场景新增 1 条失败记录
  - 未完成订单场景新增 1 条跳过记录
  - 成功续费场景未新增重复成功记录

## 结论

- 阶段 8 余额自动续费当前订阅能力已在 `plugins/WalletCenter/` 内完成闭环实现。
- 强制验证项全部通过，且未修改核心代码。
