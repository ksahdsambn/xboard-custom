# TASK-017 MobileApp 插件骨架运行复核

仅用于开发审查。禁止把本目录放入生产 overlay。

```powershell
pwsh -NoProfile -File tests/task-017/run.ps1 -OfficialRepo ../Xboard -OutputPath ../xboard-mobile/evidence/TASK-017/runtime-results.json
```

在冻结官方提交上安装、启用、停用、再次启用和升级 MobileApp，检查完整 `/api/mobile/v0` 与 `/api/mobile/v1` 前缀、鉴权分组，以及官方 Web 登录/节点/订单仍可用。
