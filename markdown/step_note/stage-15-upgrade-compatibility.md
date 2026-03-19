# 阶段 15：执行升级兼容性检查

## 目标

验证当前实现不会明显破坏后续持续获取官方更新的能力，并确认自定义插件、主题、后台入口与记录查询仍建立在官方扩展机制之上。

## 完成内容

- 复查当前自定义实现的源码分布，确认主要资产仍收敛在：
  - `plugins/StripePayment/`
  - `plugins/BepusdtPayment/`
  - `plugins/WalletCenter/`
  - `theme/XboardCustom/`
- 复查核心扩展点依赖关系：
  - `PluginManager` 通过 `plugins/<StudlyCode>/config.json` 与 `Plugin.php` 识别插件，并负责加载路由、视图、命令与计划任务。
  - `PaymentController` 在核心支付通知处理前保留 `payment.notify.before` 钩子，支付插件通过该钩子接管各自回调。
  - `ThemeService` 通过 `theme/` 与 `storage/theme/` 目录扫描主题，并在切换/刷新时将当前主题复制到 `public/theme/<theme>`。
  - `xboard:update` 在更新完成后调用 `ThemeService::refreshCurrentTheme()`，用于重新发布当前主题的静态副本。
- 复查插件与主题的独立升级能力：
  - `PluginManager` 具备插件 `enable`、`disable`、`uninstall`、`update`、`upload` 能力。
  - `ThemeService` 具备主题 `upload`、`switch`、`delete`、`refreshCurrentTheme` 能力。
- 复查当前仓库的核心目录是否出现反向写入的自定义标识，并确认官方 `theme/Xboard/` 未被注入 WalletCenter/Stripe/BEpusdt/XboardCustom 相关定制标识。

## 验证测试

- 静态分布检查：
  - 对 `app/`、`bootstrap/`、`config/`、`database/`、`resources/`、`routes/` 执行关键字搜索 `WalletCenter|StripePayment|BepusdtPayment|XboardCustom|xc_wallet|wallet-center|i18n-extra`：未发现命中，结果通过。
  - 对官方主题目录 `theme/Xboard/` 执行同样的关键字搜索：未发现命中，结果通过。
- 插件识别检查：
  - 容器内执行插件数据库查询，返回 `stripe_payment`、`bepusdt_payment`、`wallet_center` 且均为已启用状态：通过。
  - `php artisan route:list --path=stripe-payment --json` 返回 `Plugin\\StripePayment\\Controllers\\AdminController@overview`：通过。
  - `php artisan route:list --path=bepusdt-payment --json` 返回 `Plugin\\BepusdtPayment\\Controllers\\AdminController@overview`：通过。
  - `php artisan route:list --path=wallet-center --json` 返回 WalletCenter 前后台接口集合：通过。
  - `php artisan schedule:list --json` 返回 `php artisan wallet-center:auto-renew-scan --limit=100 --due-only`：通过。
  - `php artisan hook:list` 返回 `payment.notify.before` 钩子：通过。
- 主题识别检查：
  - 容器内执行 `ThemeService::getList()`，返回 `Xboard`、`XboardCustom`、`v2board`：通过。
  - 容器内执行 `admin_setting('current_theme')`，当前值为 `XboardCustom`：通过。
- 升级流程兼容性检查：
  - 静态复查 `xboard:update` 实现，确认更新流程结束时会执行 `ThemeService::refreshCurrentTheme()`，可重新发布当前主题到 `public/theme/`：通过。

## 风险与结论

- 当前仓库 `git` 分支尚无首个提交，无法基于提交差异直接生成“最终变更集中度”报告；本阶段改用“源码目录分布 + 关键字反查 + 运行态注册结果”完成兼容性验证。
- `XboardCustom` 当前通过复制官方 `umi.js` 作为前端基线实现自定义注入。若后续官方前端运行时、hash 路由或本地存储键发生变更，需要同步更新 `theme/XboardCustom/` 中的前端补丁文件；风险被限制在主题层，不会污染核心 PHP 业务代码。
- 支付插件的回调接管依赖核心钩子 `payment.notify.before` 与插件加载约定；如果未来官方移除该钩子或调整插件目录解析规则，需要同步升级插件实现。
- `public/theme/XboardCustom/` 属于主题切换后的发布副本，不是源码；官方更新或部署覆盖该目录后，可通过 `xboard:update`、重新切换主题或执行 `ThemeService::refreshCurrentTheme()` 恢复。
- 综合当前静态与运行态证据，升级兼容性风险可控，自定义内容具备持续维护能力。

## 放行结论

- 本阶段完成内容：已完成插件/主题分布复查、核心扩展点依赖复查、运行态识别验证、升级流程兼容性复查与风险归档。
- 测试结果：通过。
- 是否放行：是。
