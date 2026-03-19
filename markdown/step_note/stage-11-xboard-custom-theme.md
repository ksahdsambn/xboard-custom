# 阶段 11：创建 XboardCustom 自定义主题

## 1. 阶段目标

- 基于官方 `theme/Xboard` 创建独立主题副本 `theme/XboardCustom`
- 在不修改核心业务代码的前提下，为前台增加签到、余额充值、自动续费入口与状态展示
- 保持官方 `umi.js` 主体不变，避免污染系统主题，确保后续可独立切换、更新与回滚

## 2. 本阶段实现

### 2.1 新增主题目录与元数据

- 新增 `theme/XboardCustom/config.json`
- 新增 `theme/XboardCustom/dashboard.blade.php`
- 复制官方主题的 `umi.js`、`env.js`、`index.html` 和背景资源到 `theme/XboardCustom/`

### 2.2 自定义主题注入层

- 新增 `theme/XboardCustom/assets/wallet-center.css`
- 新增 `theme/XboardCustom/assets/wallet-center.js`
- 在 `dashboard.blade.php` 中保留官方主题初始化参数与 `umi.js` 加载顺序
- 仅额外挂载自定义样式与脚本，不修改官方编译产物

### 2.3 钱包中心交互策略

- 新增固定悬浮入口：`钱包`、`签到`、`充值`、`自动续费`
- 使用“已存在的官方 hash 路由 + `xc_wallet=1` 查询参数”承载钱包浮层
- 原因：官方 SPA 会把未知的 `#/wallet` 改写为 `#/404`，因此改为基于现有路由叠加钱包浮层，保证钱包页可访问且不触发 404
- 钱包中心直接复用以下现有接口：
  - `/api/v1/user/info`
  - `/api/v1/user/getSubscribe`
  - `/api/v1/user/comm/config`
  - `/api/v1/wallet-center/checkin/*`
  - `/api/v1/wallet-center/topup/*`
  - `/api/v1/wallet-center/auto-renew/*`

### 2.4 兼容与隔离

- 自定义主题全部变更收敛在 `theme/XboardCustom/`
- 官方 `theme/Xboard/` 未做任何修改
- 切回系统主题后，首页不再引用 `wallet-center.css`、`wallet-center.js` 与 `XboardCustom` 资源

## 3. 阶段测试

### 3.1 静态检查

- `node --check theme/XboardCustom/assets/wallet-center.js`：通过
- 主题目录完整性检查：通过
  - `config.json`、`dashboard.blade.php`、`assets/wallet-center.css`、`assets/wallet-center.js` 均存在

### 3.2 主题识别与切换

- 容器内 `ThemeService::getList()` 可识别 `XboardCustom`：通过
- 切换到 `XboardCustom` 后，首页 HTML 正确引用：
  - `/theme/XboardCustom/assets/umi.js`
  - `/theme/XboardCustom/assets/wallet-center.css`
  - `/theme/XboardCustom/assets/wallet-center.js`

### 3.3 浏览器回归

- 使用 Playwright 注入登录态后验证以下路由：
  - `#/dashboard`
  - `#/plan`
  - `#/order`
  - `#/dashboard?xc_wallet=1&section=checkin`
- 验证结果：
  - 以上 4 个页面均成功加载
  - 自定义 4 个悬浮入口均可见
  - 钱包浮层标题 `WalletCenter` 可见
  - 页面无前端运行时异常

### 3.4 回切验证

- 切回系统主题 `Xboard` 后，首页恢复只加载：
  - `/theme/Xboard/assets/umi.js`
- 系统主题页面中不再出现 `XboardCustom` 资源引用：通过

## 4. 风险与说明

- 运行环境使用 `Octane + SQLite`，通过控制台修改主题配置后，Web 进程可能读取旧状态
- 本阶段验证中通过重启 `xboard-stage3-web` 容器完成状态同步，已记录为运行时注意事项

## 5. 本阶段结论

- 本阶段完成内容：已完成 `XboardCustom` 独立主题、前台 3 类新入口与状态浮层、主题切换隔离
- 测试结果：通过
- 是否放行：是
