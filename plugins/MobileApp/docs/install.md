# MobileApp 安装、启用、停用和升级

插件代码 `mobile_app`，目录 `plugins/MobileApp`。类型 `feature`。路由必须自行声明 `/api/mobile/v0` 与 `/api/mobile/v1`，官方 RouteServiceProvider 不会补此前缀。

## 安装

在已应用 overlay 的 Xboard 中：

```text
php artisan plugin:install mobile_app
```

若无该命令，则通过后台插件安装或 `PluginManager::install('mobile_app')`。安装会运行插件自有迁移（TASK-018 起），并写入停用记录。安装后默认停用，不暴露移动路由。

## 启用

```text
PluginManager::enable('mobile_app')
```

启用后加载 Provider、完整版本前缀路由、命令 `mobile-app:health` 和 boot 钩子。长驻 worker（Octane）启用后需要重启进程才会加载新路由。

## 停用

```text
PluginManager::disable('mobile_app')
```

停用后新进程不再注册移动路由。未重启的长驻进程可能仍有旧路由；控制器会在插件停用时返回不可用。停用不得影响官方 Web 登录、节点、订单和其他插件。

## 再次启用

对已安装记录再次 `enable`。幂等于“启用状态为真且路由重新加载”。

## 升级

将 `config.json` 的 `version` 提高到更高的 semver，然后 `PluginManager::update('mobile_app')`。更新会停用、跑迁移、调用 `Plugin::update`、写新版本并重新启用。禁止修改或删除官方迁移。

## 鉴权矩阵

| 分组 | 前缀内路径 | 中间件 |
| --- | --- | --- |
| public | bootstrap、legal/*、auth/register、auth/login、auth/email-code、auth/password-reset | 仅 api 组 |
| user | 账户、权益、节点、Profile、公告、工单、设备、Play 上报、删号 | `user` |
| admin | admin/play-products、admin/compat | `admin` + `log` |
| google_platform | platform/google/rtdn | `mobile.google.rtdn` |

不得为受保护路径提供无鉴权副本。
