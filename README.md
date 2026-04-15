# Swoole Learn

一个用于学习 Swoole 常见用法的最小示例项目，包含：

- `Swoole\Http\Server` 请求路由处理
- `Swoole\Coroutine` 协程批量任务
- `Swoole\Timer` 周期定时任务
- 基于 Swoole 的短地址服务 API（MySQL + Redis）
- 接近生产能力（幂等创建、批量落库、重试与死信、管理端鉴权）
- 可直接运行的 PHPUnit 单元测试

## 1. 安装依赖

```bash
composer install
```

## 2. 运行单元测试

```bash
composer test
```

## 3. 学习示例代码位置

- HTTP 路由示例：`src/Learning/HttpRouteRegistrar.php`
- 协程批处理示例：`src/Learning/CoroutineBatchRunner.php`
- 定时器示例：`src/Learning/IntervalTicker.php`
- WebSocket 聊天服务器示例：`src/Learning/Chat/ChatServer.php`

对应的真实 Swoole 运行时封装：

- `src/Learning/Runtime/SwooleHttpServerRuntime.php`
- `src/Learning/Runtime/SwooleCoroutineRuntime.php`
- `src/Learning/Runtime/SwooleTimerRuntime.php`
- `src/Learning/Runtime/SwooleWebSocketServerRuntime.php`

## 4. 示例脚本

> 运行以下脚本前，请先安装 `ext-swoole`。

```bash
php examples/http_server.php
php examples/coroutine_batch.php
php examples/timer_tick.php
php examples/chat_server.php
php examples/short_url_api_server.php
php examples/short_url_visit_log_worker.php
```

## 5. 短地址服务学习模块

短地址服务代码位置：

- 业务层：`src/ShortUrl/Service/ShortUrlService.php`
- API 控制器：`src/ShortUrl/Http/ShortUrlApiController.php`
- Swoole 接入层：`src/ShortUrl/Http/SwooleShortUrlServer.php`
- MySQL 仓储：`src/ShortUrl/Infrastructure/PdoShortUrlRepository.php`
- Redis 缓存/统计/限流：
  - `src/ShortUrl/Infrastructure/RedisShortUrlCache.php`
  - `src/ShortUrl/Infrastructure/RedisStatsStore.php`
  - `src/ShortUrl/Infrastructure/RedisRateLimiter.php`

设计文档与数据库设计：

- `docs/short-url-service.md`
- `docs/openapi-short-url.yaml`
- `database/mysql/short_url_schema.sql`

新增进阶能力：

- 幂等创建（`Idempotency-Key` 请求头）
- Redis Stream 异步访问日志（HTTP 入队 + Worker 消费写 MySQL）
- 访问日志批量落库 + 失败重试 + 死信队列（DLQ）
- 后台管理 API（分页筛选、批量禁用）
- 管理端 API Key 鉴权（`X-Admin-Api-Key`）
- 可观测性基线（结构化日志 + Prometheus 指标 + 健康检查 + Trace ID）

## 6. 可观测性（Observability）快速体验

API 启动后新增观测端点：

- `GET /health`：存活探针（Liveness）
- `GET /readyz`：就绪探针（Readiness，包含 MySQL/Redis 子检查）
- `GET /metrics`：Prometheus 文本格式指标

所有 API 响应都会返回：

- `X-Trace-Id`：请求链路追踪 ID（可由客户端透传 `X-Trace-Id`）

结构化日志（JSON）字段示例：

```json
{
  "timestamp": "2026-04-14T12:34:56+00:00",
  "level": "INFO",
  "message": "HTTP request handled",
  "context": {
    "trace_id": "7e3a0dd7d5d8a8ef",
    "method": "POST",
    "path": "/api/v1/short-urls",
    "route": "create_short_url",
    "status_code": 201,
    "duration_ms": 3.214,
    "client_ip": "127.0.0.1"
  }
}
```

常用指标示例：

- `shorturl_http_requests_total{method,route,status_code}`
- `shorturl_http_request_duration_seconds_bucket{method,route,le}`
- `shorturl_http_in_flight_requests`
- `shorturl_service_create_total{result,error_type?}`
- `shorturl_service_resolve_total{result,error_type?}`
- `shorturl_cache_lookup_total{result=hit|miss}`
- `shorturl_worker_events_processed_total{mode,result}`
- `shorturl_worker_retry_total`
- `shorturl_worker_dead_letter_total`

## 7. 生产化运行建议（最小版）

设置核心环境变量：

```bash
export ADMIN_API_KEY=replace-with-strong-key
export REDIS_VISIT_STREAM=shorturl:visit:stream
export REDIS_VISIT_DLQ_STREAM=shorturl:visit:stream:dlq
export WORKER_METRICS_SNAPSHOT_EVERY=100
```

启动 API 服务：

```bash
php examples/short_url_api_server.php
```

启动访问日志 worker：

```bash
php examples/short_url_visit_log_worker.php
```

## 8. 一键本地环境（生产化演练）

已提供 `docker-compose.yml`，包含：

- `api`：PHP CLI + ext-swoole 容器（运行 Swoole API）
- `worker`：访问日志异步 Worker
- `mysql`：持久化存储
- `redis`：缓存、限流、Stream 队列

启动：

```bash
docker compose up -d --build
```

查看日志：

```bash
docker compose logs -f api
docker compose logs -f worker
```

关闭：

```bash
docker compose down
```

## 9. 项目结构

```text
src/
  Learning/
  ShortUrl/
database/
  mysql/
docs/
docker/
tests/
examples/
```
