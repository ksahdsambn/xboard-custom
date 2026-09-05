# 请求标识追踪

Mobile API 每个响应都带 `requestId` 和 `X-Request-Id`。

- 合法 UUID 头原样传播。
- 非法头丢弃并新生成，禁止把令牌或 Profile 回显到信封。
- 日志只记录 requestId、errorCode、路由名和异常类，不含凭证。
