# Xboard 1Panel 部署与更新手册

## 文档目标

本文档面向以下场景：

- 官方底座仓库：`https://github.com/cedar2025/Xboard`
- 自定义插件和主题仓库：`https://github.com/ksahdsambn/xboard-custom`
- 面板环境：`1Panel`
- 部署方式：官方 `compose` 模式

本文档只推荐一套主流程：

- 官方仓库负责安装和升级 Xboard 底座
- `xboard-custom` 仓库负责维护自定义插件、主题和部署脚本
- 部署时将 `xboard-custom` 中的自定义目录同步到官方运行目录

这样做的原因：

- 最稳定：不直接修改官方核心源码
- 最便捷：后续官方更新和自定义更新分开处理
- 最符合开发规范：官方底座和自定义代码职责分离
- 最不怕覆盖：官方更新后，重新同步一次自定义层即可恢复

## 推荐目录设计

服务器建议固定使用两个目录：

- 官方运行目录：`/opt/1panel/www/sites/xboard/index`
- 自定义仓库目录：`/opt/xboard-custom`

你当前这台 1Panel 服务器的实际站点目录就是反向代理网站 `xboard` 对应的：

- `/opt/1panel/www/sites/xboard/index`

判断方法也很简单：哪个目录同时包含 `compose.yaml`、`.env`、`plugins/`、`storage/`，哪个目录就是 Xboard 的实际运行根目录。

在 1Panel 官方 `compose` 结构下，下面两个目录是自定义层的关键挂载点：

- `plugins/` 挂载到容器 `/www/plugins`
- `storage/theme/` 挂载到容器 `/www/storage/theme`

因此本项目的同步目标固定为：

- `xboard-custom/plugins/*` -> 官方运行目录 `plugins/*`
- `xboard-custom/theme/XboardCustom` -> 官方运行目录 `storage/theme/XboardCustom`

不要把 `XboardCustom` 作为长期维护主题直接放进官方运行目录根下的 `theme/XboardCustom`。

脚本职责分工如下：

- `scripts/deploy-overlay.sh`
  - 只负责把当前仓库里的插件和主题同步到官方运行目录
  - 会重启 `web`、`horizon` 并刷新当前主题静态文件
- `scripts/update-overlay-from-git.sh`
  - 先执行 `git fetch` 和快进更新
  - 只有当 `plugins/` 或 `theme/` 发生变化时，才会调用 `deploy-overlay.sh`
  - 如果这次只是 `markdown/` 等非运行时代码变更，会自动跳过覆盖部署和服务重启

## 一、初次部署

### 第 1 步：在 1Panel 安装基础环境

尽量使用鼠标操作：

1. 登录 1Panel。
2. 打开 `应用商店`。
3. 安装以下应用：
   - `OpenResty`
   - `MySQL 5.7`
4. 打开 `数据库`。
5. 创建数据库：
   - 数据库名：`xboard`
   - 用户名：`xboard`
   - 权限：`所有主机 (%)`
6. 记住数据库密码，后面安装向导会用到。

### 第 2 步：在 1Panel 创建站点

1. 打开 `网站`。
2. 点击 `创建网站`。
3. 选择 `反向代理`。
4. 填写：
   - 域名：你的实际域名
   - 代号：`xboard`
   - 代理地址：`127.0.0.1:7001`
5. 保存。

### 第 3 步：将官方仓库部署到站点目录

这一步需要少量命令行，但只做一次。

1. 在 1Panel 中打开 `终端`。
2. 先确认站点目录是空目录。
3. 如果 `index` 目录里已经有默认文件，先在 1Panel `文件` 页面手动清空这个目录，再继续。
4. 执行：

```bash
cd /opt/1panel/www/sites/xboard/index
git clone -b compose --depth 1 https://github.com/cedar2025/Xboard ./
```

如果系统没有 `git` 和 `rsync`，先安装：

```bash
apt update && apt install -y git rsync
```

CentOS/RHEL 可改为：

```bash
yum update -y && yum install -y git rsync
```

### 第 4 步：确认 compose 挂载是否正确

在 1Panel 中打开 `文件`，进入站点目录：

- `/opt/1panel/www/sites/xboard/index`

打开 `compose.yaml`，确认至少包含以下挂载关系：

- `./plugins:/www/plugins`
- `./storage/theme:/www/storage/theme`
- `./storage/logs:/www/storage/logs`
- `./.env:/www/.env`

如果你是严格按照官方 `compose` 分支拉取，通常已经带好，不需要手工改。

### 第 5 步：初始化安装官方 Xboard

在 1Panel `终端` 执行：

```bash
cd /opt/1panel/www/sites/xboard/index
docker compose run -it --rm web php artisan xboard:install
docker compose up -d
```

安装向导中建议这样填：

- 数据库：
  - Host：优先用 1Panel 数据库容器连接信息里显示的容器主机
  - Port：`3306`
  - Database：`xboard`
  - User：`xboard`
  - Password：你刚创建数据库时设置的密码
- Redis：
  - 选择内置 Redis
- 管理员：
  - 按提示设置管理员邮箱和密码

安装完成后，先确认：

- 域名能打开前台
- 后台能登录

### 第 6 步：把自定义仓库拉到服务器

建议独立目录，不要混进官方站点目录。

在 1Panel `终端` 执行：

```bash
git clone https://github.com/ksahdsambn/xboard-custom.git /opt/xboard-custom
```

### 第 7 步：将自定义插件和主题导入官方运行目录

你的仓库已经包含同步脚本 `scripts/deploy-overlay.sh`。

在 1Panel `终端` 执行：

```bash
OFFICIAL_ROOT=/opt/1panel/www/sites/xboard/index /bin/bash /opt/xboard-custom/scripts/deploy-overlay.sh
```

这条命令会自动做以下事情：

- 同步 `StripePayment` 到官方运行目录 `plugins/StripePayment`
- 同步 `BepusdtPayment` 到官方运行目录 `plugins/BepusdtPayment`
- 同步 `WalletCenter` 到官方运行目录 `plugins/WalletCenter`
- 同步 `XboardCustom` 到官方运行目录 `storage/theme/XboardCustom`
- 如果服务器残留了旧的 `theme/XboardCustom`，脚本会自动清理，避免主题优先级冲突
- 重启 `web` 和 `horizon`
- 刷新当前主题静态文件

### 第 8 步：进入后台完成可视化配置

这一步尽量全部用鼠标完成。

#### 8.1 插件安装与启用

进入 Xboard 后台：

1. 打开 `插件管理`
2. 找到以下插件：
   - `stripe_payment`
   - `bepusdt_payment`
   - `wallet_center`
3. 依次点击：
   - `安装`
   - `启用`

#### 8.2 支付配置

继续在后台操作：

1. 进入支付配置界面
2. 配置 Stripe 相关参数
3. 配置 BEpusdt 相关参数

#### 8.3 主题切换

1. 进入 `主题管理`
2. 找到 `XboardCustom`
3. 点击 `切换`

#### 8.4 WalletCenter 配置

1. 进入 WalletCenter 后台配置页
2. 根据业务需要启用：
   - 签到
   - 充值
   - 自动续费

### 第 9 步：首轮验收

上线前至少做一轮完整检查：

1. 后台能正常登录
2. 插件管理中 3 个插件状态正常
3. 主题管理中当前主题为 `XboardCustom`
4. 前台页面正常打开
5. Stripe 下单流程可用
6. BEpusdt 下单流程可用
7. WalletCenter 页面可见
8. 签到、充值、自动续费相关入口显示正常

## 二、后续只更新自定义仓库

这是最常见的日常操作。

### 推荐方式：在 1Panel 创建脚本任务

尽量减少命令行，建议在 1Panel 中创建一个脚本任务。

操作路径：

1. 打开 `计划任务`
2. 选择 `脚本`
3. 新建脚本任务
4. 名称建议：`xboard-custom-sync`

建议表单这样填写：

- 任务类型：`Shell 脚本`
- 任务名称：`xboard-custom-sync`
- 分组：`默认`
- 执行周期：任意低频值即可，例如 `每月 / 1 日 / 04:30`
- `在容器中执行`：不要勾选
- 用户：`root`
- 解释器：勾选 `自定义`，填写 `/bin/bash`

脚本内容：

```bash
set -euo pipefail
OFFICIAL_ROOT=/opt/1panel/www/sites/xboard/index /bin/bash /opt/xboard-custom/scripts/update-overlay-from-git.sh
```

如果你需要显式指定自定义仓库分支，也可以写成：

```bash
set -euo pipefail
CUSTOM_BRANCH=main OFFICIAL_ROOT=/opt/1panel/www/sites/xboard/index /bin/bash /opt/xboard-custom/scripts/update-overlay-from-git.sh
```

这条任务现在的行为是：

- 如果这次 GitHub 更新只改了 `markdown/` 等文档文件：只更新仓库，不重启服务
- 如果这次更新包含 `plugins/` 或 `theme/` 变更：自动执行覆盖同步、容器重启和主题刷新

保存后，以后每次你更新了 GitHub 上的 `xboard-custom` 仓库，只需要：

1. 登录 1Panel
2. 打开 `计划任务`
3. 找到 `xboard-custom-sync`
4. 点击 `执行`

### 执行后检查

执行完成后，去后台做 3 个鼠标检查：

1. `插件管理`
   - 确认 3 个插件仍为启用状态
2. `主题管理`
   - 确认 `XboardCustom` 仍为当前主题
3. 如果你本次修改中提高了某个插件的 `config.json` 版本号：
   - 到 `插件管理`
   - 点击该插件的 `升级`

## 三、后续更新作者官方仓库

这个流程分成“先更新官方底座，再重新同步自定义层”。

### 推荐方式：同样用 1Panel 脚本任务

在 1Panel 中再创建一个脚本任务。

操作路径：

1. 打开 `计划任务`
2. 选择 `脚本`
3. 新建脚本任务
4. 名称建议：`xboard-official-update`

建议表单这样填写：

- 任务类型：`Shell 脚本`
- 任务名称：`xboard-official-update`
- 分组：`默认`
- 执行周期：任意低频值即可，例如 `每月 / 1 日 / 05:00`
- `在容器中执行`：不要勾选
- 用户：`root`
- 解释器：勾选 `自定义`，填写 `/bin/bash`

脚本内容：

```bash
set -euo pipefail
cd /opt/1panel/www/sites/xboard/index
git pull --ff-only origin compose
docker compose pull
docker compose run --rm -T web php artisan xboard:update
docker compose up -d

FORCE_DEPLOY=1 OFFICIAL_ROOT=/opt/1panel/www/sites/xboard/index /bin/bash /opt/xboard-custom/scripts/update-overlay-from-git.sh
```

如果你的 `xboard-custom` 默认分支不是 `main`，可以改成：

```bash
FORCE_DEPLOY=1 CUSTOM_BRANCH=你的分支名 OFFICIAL_ROOT=/opt/1panel/www/sites/xboard/index /bin/bash /opt/xboard-custom/scripts/update-overlay-from-git.sh
```

这里使用 `FORCE_DEPLOY=1` 的原因是：

- 官方底座刚更新完，即使 `xboard-custom` 仓库本身没有新代码，也建议重新执行一次 overlay 同步和主题刷新
- 这样可以保证插件目录、主题目录和发布后的静态资源都按当前官方底座重新落地

### 官方更新时的操作顺序

以后作者仓库有新版本时，推荐按下面顺序点：

1. 登录 1Panel
2. 打开 `计划任务`
3. 执行 `xboard-official-update`
4. 等脚本执行完成
5. 回后台检查系统状态

如果脚本里这一步失败：

```bash
docker compose run --rm -T web php artisan xboard:update
```

说明你的旧安装仍在使用旧服务名。把脚本任务中的 `web` 改成 `xboard` 后再执行一次：

```bash
docker compose run --rm -T xboard php artisan xboard:update
```

### 官方更新后必须检查的内容

全部用鼠标完成：

1. 后台是否还能登录
2. `插件管理` 中 3 个插件是否还在
3. 如果插件版本有变化，是否需要点 `升级`
4. `主题管理` 中当前主题是否仍是 `XboardCustom`
5. 前台首页是否正常
6. WalletCenter 页面是否正常
7. Stripe 和 BEpusdt 支付方式是否还可见

## 四、推荐的日常维护方式

### 平时开发时

只在本地仓库开发：

- 本地仓库：`https://github.com/ksahdsambn/xboard-custom`

不要在服务器里直接手改文件作为长期方案。

标准流程：

1. 本地改代码
2. 本地测试
3. `git commit`
4. `git push`
5. 登录 1Panel
6. 执行 `xboard-custom-sync`

### 作者更新时

标准流程：

1. 看作者是否发布新版本
2. 登录 1Panel
3. 执行 `xboard-official-update`
4. 检查前后台
5. 如有兼容问题，在本地修 `xboard-custom`
6. 推送后再次执行 `xboard-custom-sync`

## 五、你应该避免的做法

不要这样做：

1. 不要把自定义代码长期直接写进作者仓库目录里维护
2. 不要把官方仓库 fork 成你的日常开发主仓库
3. 不要同时维护多套主题来源
   - 例如一份放 `storage/theme/XboardCustom`
   - 另一份又通过后台上传同名主题
4. 不要在服务器上临时手改文件后不回写 GitHub
5. 不要把“后台 zip 上传”作为主发布方式和“仓库同步覆盖”混着长期使用

## 六、最少命令版本总结

### 初次部署只需要的关键命令

```bash
cd /opt/1panel/www/sites/xboard/index
git clone -b compose --depth 1 https://github.com/cedar2025/Xboard ./
docker compose run -it --rm web php artisan xboard:install
docker compose up -d

git clone https://github.com/ksahdsambn/xboard-custom.git /opt/xboard-custom
OFFICIAL_ROOT=/opt/1panel/www/sites/xboard/index /bin/bash /opt/xboard-custom/scripts/deploy-overlay.sh
```

### 后续更新自定义层只需要执行的脚本

```bash
set -euo pipefail
OFFICIAL_ROOT=/opt/1panel/www/sites/xboard/index /bin/bash /opt/xboard-custom/scripts/update-overlay-from-git.sh
```

### 后续更新官方仓库只需要执行的脚本

```bash
set -euo pipefail
cd /opt/1panel/www/sites/xboard/index
git pull --ff-only origin compose
docker compose pull
docker compose run --rm -T web php artisan xboard:update
docker compose up -d

FORCE_DEPLOY=1 OFFICIAL_ROOT=/opt/1panel/www/sites/xboard/index /bin/bash /opt/xboard-custom/scripts/update-overlay-from-git.sh
```

## 七、最终结论

本项目推荐的唯一主流程是：

1. 用 1Panel 按官方文档部署 `cedar2025/Xboard`
2. 用独立仓库维护 `xboard-custom`
3. 用 `deploy-overlay.sh` 将自定义层同步到官方运行目录
4. 用 1Panel 脚本任务承载后续更新动作，尽量减少命令行

这套方式最适合当前项目：

- 操作上尽量可视化
- 部署上足够稳定
- 结构上符合开发规范
- 作者更新后不会丢失你的自定义源码
