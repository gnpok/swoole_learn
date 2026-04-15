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

对应的真实 Swoole 运行时封装：

- `src/Learning/Runtime/SwooleHttpServerRuntime.php`
- `src/Learning/Runtime/SwooleCoroutineRuntime.php`
- `src/Learning/Runtime/SwooleTimerRuntime.php`

## 4. 示例脚本

> 运行以下脚本前，请先安装 `ext-swoole`。

```bash
php examples/http_server.php
php examples/coroutine_batch.php
php examples/timer_tick.php
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

## 7. 生产化运行建议（最小版）

设置核心环境变量：

```bash
export ADMIN_API_KEY=replace-with-strong-key
export REDIS_VISIT_STREAM=shorturl:visit:stream
export REDIS_VISIT_DLQ_STREAM=shorturl:visit:stream:dlq
```

启动 API 服务：

```bash
php examples/short_url_api_server.php
```

启动访问日志 worker：

```bash
php examples/short_url_visit_log_worker.php
```

## 8. 项目结构

```text
src/
  Learning/
  ShortUrl/
database/
  mysql/
docs/
tests/
examples/
```
