# MobileApp overlay 与官方更新流程

1. 解析候选镜像摘要 `ghcr.io/cedar2025/xboard@sha256:<64 hex>`，禁止 `latest`。
2. 将该摘要写入官方 `storage` 下的 compose 镜像钉扎文件（不改官方受管 compose.yaml），再预发布部署：同步 overlay、安装或升级 `mobile_app`、启用、`mobile-app:health`。
3. 健康检查或迁移失败立即中止，不得进入生产。
4. 生产复用同一摘要，并保留上一稳定镜像与数据库备份标记。
5. 三个独立身份必须同时记录：Xboard master 提交、compose 提交、实际镜像摘要。
