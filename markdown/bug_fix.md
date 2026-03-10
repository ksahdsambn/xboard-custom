# Bug 修复记录

## 2026-03-10 新增文件专项复核

### 1. WalletCenter 充值订单状态并发竞争

- 涉及文件：
  - `plugins/WalletCenter/Services/TopupService.php`
- 问题描述：
  - 原实现中，充值成功回调 `markPaid()` 使用事务和行锁，但取消/超时回调 `markStatus()` 直接无锁读取并更新订单状态。
  - 当成功回调正在处理入账时，后到的取消/超时回调可能在事务提交后把订单从已入账状态改写成 `cancelled` 或 `expired`，造成“余额已增加但订单终态错误”的并发缺陷。
- 修复方案：
  - 将 `markStatus()` 改为事务内 `lockForUpdate()` 读取。
  - 对已进入 `processing`、`paid`、`cancelled`、`expired` 的订单，不再允许后续非成功回调覆盖终态，只记录 `ignored_status_callback` 作为审计信息。
- 修复结果：
  - 充值成功与取消/超时回调之间的竞态覆盖问题已消除，订单终态与资金入账结果保持一致。

### 2. WalletCenter 支付回跳地址信任外部 Referer

- 涉及文件：
  - `plugins/WalletCenter/Services/TopupGatewayService.php`
- 问题描述：
  - 原实现中，`buildReturnUrl()` 只要收到 `Referer` 就直接原样返回，任意外部站点都可能被写入支付回跳地址。
  - 这会导致充值支付完成后跳回非站内页面，属于明显的开放跳转风险。
- 修复方案：
  - 为回跳地址新增来源解析与受信任站点判断。
  - 仅允许 `config('app.url')` 或当前请求主机对应的来源站点作为回跳来源。
  - 对不可信来源统一回退到站内 `/#/wallet?topup_trade_no=...`。
- 修复结果：
  - 支付回跳地址已限制在站内可信来源，不再接受任意外部 `Referer`。

### 3. WalletCenter 订单记录的 return_url 与真实支付请求不一致

- 涉及文件：
  - `plugins/WalletCenter/Services/TopupService.php`
  - `plugins/WalletCenter/Services/TopupGatewayService.php`
- 问题描述：
  - 原实现中，订单 `extra.return_url` 在创建时通过 `source_base_url()` 提前写入，但支付网关实际使用的回跳地址在后续又重新计算。
  - 当回跳地址构建逻辑调整或来源不可信时，订单记录中的 `return_url` 可能与真实发给支付网关的值不一致。
- 修复方案：
  - 下单时先计算实际将要使用的 `return_url`，再同时写入订单 `extra` 并传给支付网关。
- 修复结果：
  - 订单审计信息中的 `return_url` 与支付请求实际使用值保持一致。

## 本轮验证

- 容器内 `php -l` 复核 33 个目标 PHP 文件，全部通过。
- 4 个 `config.json` 文件均可成功解析。
- `theme/XboardCustom/assets/wallet-center.js`、`i18n-extra.js`、`umi.js` 均通过 `node --check`。
