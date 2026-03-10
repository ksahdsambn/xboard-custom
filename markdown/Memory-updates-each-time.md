# Memory Updates Each Time

## 文档用途

- 这个文件用于长期记录 `xboard-custom` 仓库内所有重要的代码更新、功能调整、部署流程调整和文档口径修正。
- 后续每次有新增功能、代码修复、部署脚本改动、1Panel 流程变化、主题或插件行为变化时，都应在这里追加一条记录。
- 这份文档的定位是“持续更新的变更记忆”，不是需求文档，也不是 Bug 清单替代品。

## 后续记录规则

- 每次更新尽量追加，不要覆盖旧记录。
- 每条记录建议包含：
  - 日期
  - 变更类型
  - 涉及文件或目录
  - 变更原因
  - 对部署、升级、回滚的影响
- 如果某次更新同时影响代码和文档，应同时记录两部分。

## 变更记录

### 2026-03-10 仓库与部署流程统一整理

#### 1. 代码托管方式统一为 overlay 模式

- 确认 `xboard-custom` 是自定义源码真源仓库，不再把“zip 上传”作为主发布方式。
- 当前长期推荐结构固定为：
  - `plugins/StripePayment/`
  - `plugins/BepusdtPayment/`
  - `plugins/WalletCenter/`
  - `theme/XboardCustom/`
  - `scripts/`
  - `markdown/`
- 官方仓库 `cedar2025/Xboard` 只作为运行底座与升级来源。

#### 2. 充值链路关键修复

- 修复 `plugins/WalletCenter/Services/TopupService.php`
  - 创建充值订单时补齐并持久化真实 `return_url`
  - `markStatus()` 改为事务加行锁，避免 `paid` 与 `cancelled/expired` 并发回调相互覆盖
- 修复 `plugins/WalletCenter/Services/TopupGatewayService.php`
  - 不再信任任意外部 `Referer`
  - 仅接受 `config('app.url')` 或当前请求主机对应来源作为回跳依据

#### 3. 主题部署路径修正

- 修复 `scripts/deploy-overlay.sh`
  - 自定义主题不再同步到根目录 `theme/XboardCustom`
  - 正确同步目标为 `storage/theme/XboardCustom`
  - 同步后自动清理残留的 `theme/XboardCustom`，避免主题优先级冲突
- 这一调整与当前 1Panel compose 挂载方式保持一致。

#### 4. 主题发布资产一致性修复

- 重建：
  - `theme/XboardCustom/assets/umi.js.gz`
  - `theme/XboardCustom/assets/umi.js.br`
- 确保它们与：
  - `theme/XboardCustom/assets/umi.js`
  保持一致，避免浏览器命中旧压缩副本。

#### 5. 文档同步修正

- 修正 `markdown/DEPLOY.md`
  - 路径统一为当前服务器实际站点目录 `/opt/1panel/www/sites/xboard/index`
  - 1Panel 脚本任务说明改为更稳的 Bash 版本
  - 补充“任务类型 / 用户 / 解释器 / 是否在容器中执行”的表单填写说明
- 重写 `markdown/代码托管方案.md`
  - 从旧的 `plugins-src/theme-src + zip 上传主流程` 切换为当前 overlay 主流程
- 重写 `markdown/Xboard 可升级扩展方案.md`
  - 对齐当前插件目录名、主题部署目标和升级策略
- 修正阶段文档中的错误路径：
  - `markdown/stage-01-baseline.md`
  - `markdown/stage-02-asset-strategy.md`
  - 统一将旧写法 `plugins/BEpusdtPayment/` 更正为实际运行目录 `plugins/BepusdtPayment/`

#### 6. 新增“按变更类型决定是否部署”的包装脚本

- 新增 `scripts/update-overlay-from-git.sh`
- 脚本职责：
  - 先执行 `git fetch`
  - 对比本地和远端提交差异
  - 如果本次更新只涉及 `markdown/` 等非运行时代码，则只更新仓库，不执行覆盖部署，不重启服务
  - 如果本次更新涉及 `plugins/` 或 `theme/`，则调用 `scripts/deploy-overlay.sh` 执行完整部署
  - 若官方底座更新后需要强制重放自定义层，可通过 `FORCE_DEPLOY=1` 强制执行

#### 7. 1Panel 计划任务行为优化

- `xboard-custom-sync` 任务调整为调用：
  - `scripts/update-overlay-from-git.sh`
- 优化后的目标：
  - 远端无更新时，直接结束
  - 只有文档更新时，不重启 `web` / `horizon`
  - 只有插件或主题更新时，才真正执行同步和重启
- `xboard-official-update` 任务应在官方底座更新完成后使用：
  - `FORCE_DEPLOY=1`
  - 重新叠加自定义层并刷新主题

#### 8. 已确认的 1Panel 运行根目录

- 当前服务器的 Xboard 实际运行目录确认为：
  - `/opt/1panel/www/sites/xboard/index`
- 判断依据：
  - 该目录下实际存在 `compose.yaml`
  - 存在 `.env`
  - 存在 `plugins/`
  - 存在 `storage/`
- 因此后续所有部署文档、计划任务脚本、`OFFICIAL_ROOT` 参数都应使用这个路径。

#### 9. 当前建议的 1Panel 脚本任务版本

- 自定义仓库更新任务：

```bash
set -euo pipefail
OFFICIAL_ROOT=/opt/1panel/www/sites/xboard/index /bin/bash /opt/xboard-custom/scripts/update-overlay-from-git.sh
```

- 官方仓库更新任务：

```bash
set -euo pipefail
cd /opt/1panel/www/sites/xboard/index
git pull --ff-only origin compose
docker compose pull
docker compose run --rm -T web php artisan xboard:update
docker compose up -d

FORCE_DEPLOY=1 OFFICIAL_ROOT=/opt/1panel/www/sites/xboard/index /bin/bash /opt/xboard-custom/scripts/update-overlay-from-git.sh
```

#### 10. 后续维护要求

- 从本条记录之后，凡是以下变更都要同步追加到本文件：
  - 插件功能新增或行为变化
  - WalletCenter 业务逻辑修复
  - 支付回调、幂等、路由、数据库迁移调整
  - 主题目录、发布资产、语言包更新
  - `scripts/` 下部署脚本或发布脚本调整
  - 1Panel 计划任务、部署路径、容器服务名变化
  - 与部署、升级、回滚相关的重要文档修订
