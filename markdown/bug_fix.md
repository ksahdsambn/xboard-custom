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

## 2026-03-10 仓库级回归复核

### 4. 1Panel overlay 主题同步目标错误且可能被旧根主题覆盖

- 涉及文件：
  - `scripts/deploy-overlay.sh`
- 问题描述：
  - 初版同步脚本将 `XboardCustom` 直接同步到官方运行目录根下的 `theme/XboardCustom`，但当前 1Panel 官方 `compose` 方案长期挂载的是 `storage/theme`，主题的可持久化目录应当是 `storage/theme/XboardCustom`。
  - 如果服务器上还残留旧的 `theme/XboardCustom`，`ThemeService` 会优先读取根目录主题，导致刚同步到 `storage/theme` 的新主题实际上不生效。
- 修复方案：
  - 将同步目标改为 `storage/theme/XboardCustom`。
  - 在同步后主动清理残留的 `theme/XboardCustom`，避免主题优先级冲突。
- 修复结果：
  - 当前 overlay 部署流程已与 1Panel 官方挂载结构保持一致，且不会再因为旧根主题目录残留而覆盖掉新的自定义主题。

### 5. umi.js 压缩副本与源码不一致

- 涉及文件：
  - `theme/XboardCustom/assets/umi.js`
  - `theme/XboardCustom/assets/umi.js.gz`
  - `theme/XboardCustom/assets/umi.js.br`
- 问题描述：
  - `umi.js` 曾被修改过，但仓库中的 `.gz` 和 `.br` 压缩副本没有同步重建。
  - 生产环境若优先返回压缩副本，浏览器可能实际加载旧代码，导致“源码已修复但线上仍执行旧逻辑”的发布错误。
- 修复方案：
  - 基于当前 `umi.js` 重新生成 `umi.js.gz` 和 `umi.js.br`。
  - 追加一致性校验，确认两份压缩副本解压后的内容与 `umi.js` 完全一致。
- 修复结果：
  - 当前主题静态资源的源码和压缩发布副本已经一致，不会再因压缩文件滞后导致前端加载旧版本代码。

## 本轮回归验证

- 容器内 `php -l` 再次复核 33 个 PHP 文件，全部通过。
- 4 个 `config.json` 文件再次解析通过。
- `theme/XboardCustom/assets/wallet-center.js`、`i18n-extra.js`、`umi.js` 再次通过 `node --check`。
- `scripts/deploy-overlay.sh` 通过真实 Bash 语法检查。
- `theme/XboardCustom/assets/umi.js.gz` 与 `theme/XboardCustom/assets/umi.js.br` 已确认和 `umi.js` 内容一致。

## 2026-08-23 官方更新任务分支历史分叉修复

### 6. `xboard-official-update` 无法快进更新

- 环境：
  - 1Panel 官方 Xboard 目录：`/opt/1panel/www/sites/xboard/index`
  - 自定义更新仓库：`/opt/xboard-custom`
  - 失败命令：`git pull --ff-only origin compose`
- 问题描述：
  - 服务器本地 `compose` 分支与官方远程显示为 `ahead 10, behind 44`，导致 `--ff-only` 拒绝更新。
  - 本地 HEAD `151d9fa` 与远程 HEAD `0a74968` 的 Git tree ID 均为 `0d06bc2013ecefeed06314b18ffe4cd96a8b7531`，说明两者文件内容完全一致，差异仅来自官方分支改写了提交历史。
  - 生产仓库中 `.env` 存在本地配置，不能使用 `git reset --hard`。
- 修复操作：
  - 先创建备份分支 `backup/compose-before-realign-20260823`，保留修复前的本地 HEAD。
  - 在确认本地与远程 tree ID 完全相同、`compose.yaml` 无本地修改后，执行 `git reset --keep origin/compose` 对齐分支指针。
  - 对齐前后校验 `.env` 的 SHA-256，确认生产配置未被改动。
  - 重新执行 `/opt/1panel/task/shell/xboard-official-update/xboard-official-update.sh`，官方与自定义仓库均成功更新。
  - 拉取 `ghcr.io/cedar2025/xboard:latest` 后重建并启动 `index-xboard-1`，再同步插件和主题 overlay 并重启服务。
- 修复结果：
  - `compose` 分支已与 `origin/compose` 对齐，更新任务不再报 `Not possible to fast-forward`。
  - 容器状态为 `running`，重启计数为 0，未发生 OOM，运行镜像 ID 为 `sha256:881d24a98a6deed5f3c3edb5a641799df148881275789b8e4f3969feb7f8182c`。

## 2026-08-23 线上验证

- `php artisan --version` 正常：Laravel Framework 12.54.1，PHP 8.2.33，维护模式关闭。
- Horizon 状态为 `running`，Redis Unix Socket 返回 `PONG`。
- 数据库迁移状态检查通过，已列出的迁移均为 `Ran`。
- 本机 HTTP 首页返回 200，公网 HTTPS 首页返回 200。
- 公开配置 API 连续 5 次请求均返回 200，响应时间约 49–57 ms。
- Octane、Horizon、Redis、WebSocket 和 Caddy 进程均进入 `RUNNING` 状态。
- 容器完成启动后的日志未发现 `fatal`、`panic`、`exception`、`error` 或 `critical`。切换期间曾有两条 Redis Socket 尚未就绪的瞬时记录，Redis 启动后未再出现。
