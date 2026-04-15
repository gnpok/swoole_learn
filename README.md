# Swoole Learn

一个用于学习 Swoole 常见用法的最小示例项目，包含：

- `Swoole\Http\Server` 请求路由处理
- `Swoole\Coroutine` 协程批量任务
- `Swoole\Timer` 周期定时任务
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
```

## 5. 项目结构

```text
src/
  Learning/
tests/
examples/
```
