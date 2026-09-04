# TASK-005 上游扩展点运行复核

仅用于开发审查，禁止放入部署 overlay、正式插件目录或生产环境。

```powershell
pwsh -NoProfile -File tests/task-005/run.ps1 -OfficialRepo ../Xboard -OutputPath ../xboard-mobile/evidence/TASK-005/runtime-results.json
```

要求 PowerShell 7、Git、tar、Docker；上游本地对象必须包含冻结提交 `4f48e61a2cbc6db5338872b6bdb45ef954ec1256`。
脚本拒绝脏的官方工作树，从 `git archive` 创建临时源码副本，镜像只提供 PHP 运行环境与依赖下载缓存。
安装阶段允许访问 Composer 包源，使用原始锁文件且不执行项目安装脚本；不运行 `composer update`。
正式运行前断开容器网络，没有宿主目录挂载和发布端口。测试结束核对 32 个被审查文件的 SHA-256，销毁本次容器和临时源码。

运行环境为 SQLite 和进程内 array cache；上游 Setting 显式选择的 redis 缓存名称也在运行时配置为 array adapter。
因此证据不覆盖 MySQL/Redis 并发、真实邮件、人机验证服务、真实 HTTP socket、Android、REALITY 协议或 Google Play。
HTTP 状态由完整 Laravel HTTP Kernel 产生，不是自定义伪响应。
密码、APP_KEY、会话均随机且不写入报告；节点只用保留测试域名、虚构公开配置，禁止放入真实凭据。

插件使用独立 `task005_probe` 表、独立路由/命令，只在空测试数据库创建虚构用户和节点。
审查也测试升级、停用和卸载；复核必须使用新的容器和数据库，不依赖上轮状态。
上游风险断言通过意味着“该风险已被复现”，不表示风险已经在上游修复。替代方案见 `markdown/mobile/task-005-xboard-adaptation.md`。

## 再审查保护

执行器拒绝将输出写入官方或定制源码目录、非JSON文件或重解析路径；开始时即使旧成功报告失效，初始化/清理失败不会遗留可放行的旧成功。每轮具有独立runId，依赖同时核对锁定版本和源码引用。

源码导出显式使用单次命令配置 `core.autocrlf=false`、`core.eol=lf`，使 Windows/Linux 的归档源码哈希均与冻结 Git blob 一致；不修改本机全局配置或官方仓库配置。

`pwsh -NoProfile -File tests/task-005-runner-review.ps1 -OutputPath ../xboard-mobile/evidence/TASK-005/runner-review-results.json` 只在临时副本验证初始化失败、官方/定制源码保护和输出扩展名，不启动Docker或修改实际官方仓库。
