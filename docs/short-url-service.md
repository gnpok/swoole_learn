## Swoole 短地址服务（MySQL + Redis）设计与实现说明

本文档对应代码目录：`src/ShortUrl` 与 `examples/short_url_api_server.php`。

---

### 1. 功能目标

- 生成短链接（自动短码或自定义短码）
- 短码重定向到原始链接
- 查询短链详情和访问统计
- 禁用短链
- 使用 Redis 做缓存、统计、限流
- 使用 MySQL 做持久化与日志落库
- 创建接口支持幂等（`Idempotency-Key`）
- 访问日志异步化（Redis Stream + Worker）
- 提供后台管理接口（分页筛选、批量禁用）

---

### 2. API 设计

Base URL 示例：`http://127.0.0.1:9501`

#### 2.1 创建短链

- **POST** `/api/v1/short-urls`
- Header（可选）：
  - `Idempotency-Key: <unique-key>`：同一个 key 多次提交只创建一次，返回同一 short code
- Request JSON:

```json
{
  "url": "https://example.com/article/123",
  "custom_code": "learn01",
  "expires_at": "2027-01-01T00:00:00+08:00"
}
```

字段说明：
- `url`：必填，必须是 http/https URL
- `custom_code`：可选，4~16 位，正则 `^[A-Za-z0-9_-]{4,16}$`
- `expires_at`：可选，ISO8601 日期时间字符串

成功响应（201）：

```json
{
  "data": {
    "code": "learn01",
    "short_url": "http://127.0.0.1:9501/r/learn01",
    "original_url": "https://example.com/article/123",
    "created_at": "2026-04-14T11:00:00+00:00",
    "expires_at": "2027-01-01T00:00:00+08:00",
    "is_active": true
  }
}
```

常见错误：
- `422` 参数不合法
- `409` 自定义短码冲突
- `429` 限流触发（同一 IP 在固定窗口内创建次数超限）

#### 2.2 访问短链（重定向）

- **GET** `/r/{code}`
- 成功返回 `302`，Header 包含 `Location: <original_url>`
- 可能返回：
  - `404`：短码不存在
  - `410`：短码已禁用或已过期

#### 2.3 查询短链详情

- **GET** `/api/v1/short-urls/{code}`
- 返回包括：
  - 基础信息（原始 URL、过期时间、状态）
  - 总访问数
  - 最近访问明细（Redis 列表）

#### 2.4 查询短链统计（与详情复用）

- **GET** `/api/v1/short-urls/{code}/stats`

#### 2.5 禁用短链

- **DELETE** `/api/v1/short-urls/{code}`
- 成功返回 `204`

#### 2.6 后台分页查询

- **GET** `/api/v1/admin/short-urls`
- Query：
  - `page`：默认 1
  - `per_page`：默认 20，最大 100
  - `keyword`：按 `code/original_url` 模糊检索
  - `is_active`：`true/false/1/0`

响应（200）：

```json
{
  "data": {
    "items": [
      {
        "code": "learn01",
        "short_url": "http://127.0.0.1:9501/r/learn01",
        "original_url": "https://example.com/article/123",
        "is_active": true,
        "total_visits": 15,
        "created_at": "2026-04-14T11:00:00+00:00",
        "expires_at": null,
        "last_visited_at": "2026-04-14T12:00:00+00:00"
      }
    ],
    "page": 1,
    "per_page": 20,
    "total": 1
  }
}
```

#### 2.7 后台批量禁用

- **POST** `/api/v1/admin/short-urls/bulk-disable`
- Request JSON：

```json
{
  "codes": ["learn01", "promo99"]
}
```

响应（200）：

```json
{
  "data": {
    "requested": 2,
    "disabled": 1,
    "missing": ["promo99"]
  }
}
```

---

### 3. MySQL 表设计（核心）

SQL 文件：`database/mysql/short_url_schema.sql`

#### 3.1 `short_urls`（主表）

作用：存储短链基础信息与主统计字段。

核心字段：
- `code`：唯一短码（唯一索引）
- `original_url`：原始长链
- `is_active`：开关状态（支持软删除/禁用）
- `expires_at`：过期时间
- `total_visits`：累计访问次数（可与 Redis 聚合结果对账）
- `last_visited_at`：最近访问时间

#### 3.2 `short_url_visits`（访问日志）

作用：存储明细访问记录，可用于审计、分析、报表。

核心字段：
- `short_url_code`
- `visited_at`
- `client_ip`
- `user_agent`
- `referer`

建议：
- 大流量场景应异步批量写入，避免每次跳转都同步落库导致延迟上升。
- 可按时间分区（按月/按周）降低大表维护成本。

#### 3.3 `short_url_daily_stats`（按日统计）

作用：通过异步任务将日志聚合到天粒度，供 BI 或看板查询。

---

### 4. Redis 组件设计（常用）

#### 4.1 短链缓存

- Key：`shorturl:meta:{code}`
- Value：JSON（短链元数据）
- TTL：默认 24h，若有过期时间则取剩余生命周期

作用：
- 降低 MySQL 热点查询压力
- 访问短链时优先读取缓存

#### 4.2 访问计数

- Key：`shorturl:stats:count:{code}`
- Value：整数（`INCR`）

作用：
- 支持高并发计数
- 与 MySQL `total_visits` 做兜底与对账

#### 4.3 最近访问记录

- Key：`shorturl:stats:recent:{code}`
- Value：Redis List（`LPUSH` JSON + `LTRIM`）
- TTL：默认 7 天

作用：
- 快速展示最近 N 条访问（不必每次查 MySQL 日志）

#### 4.4 限流

- Key：`shorturl:rl:create:{ip}`
- Value：窗口内计数（`INCR` + 首次 `EXPIRE`）
- 窗口：默认 60s
- 阈值：默认 30 次

作用：
- 防止恶意刷创建接口

#### 4.5 幂等键

- Key：`shorturl:idem:create:{idempotency_key}`
- Value：短码 `code`
- TTL：默认 24h

作用：
- 防止客户端重试导致重复创建
- 配合 Header `Idempotency-Key` 使用

#### 4.6 异步访问日志队列

- Stream：`shorturl:visit:stream`
- Group：`visit-log-workers`
- Consumer：`worker-<name>`

消息字段：
- `short_url_code`
- `visited_at`
- `client_ip`
- `user_agent`

说明：
- API 请求只入队，不同步写 `short_url_visits`
- Worker 异步消费并入库

---

### 5. Swoole 架构说明

入口：`examples/short_url_api_server.php`

流程：
1. 创建 PDO（MySQL）
2. 创建 Redis 客户端（Predis）
3. 组装仓储/缓存/统计/限流组件
4. 组装幂等存储与访问日志队列组件
5. 组装 `ShortUrlService`
5. 组装 `ShortUrlApiController`
6. 使用 `SwooleShortUrlServer` 注册 HTTP request 回调并启动

> `SwooleShortUrlServer` 基于项目已有 `HttpServerInterface` 封装，便于测试时替换为 fake server。

---

### 6. 环境变量

示例：

```bash
export SWOOLE_HOST=0.0.0.0
export SWOOLE_PORT=9501
export PUBLIC_BASE_URL=http://127.0.0.1:9501

export MYSQL_HOST=127.0.0.1
export MYSQL_PORT=3306
export MYSQL_DATABASE=swoole_short_url
export MYSQL_USERNAME=root
export MYSQL_PASSWORD=root

export REDIS_HOST=127.0.0.1
export REDIS_PORT=6379
export REDIS_DATABASE=0
```

启动：

```bash
php examples/short_url_api_server.php
```

访问日志 worker：

```bash
php examples/short_url_visit_log_worker.php
```

---

### 7. 生产优化建议（进阶）

1. **访问日志异步化**
   - 已实现 Redis Stream 入队 + Worker 消费模式，可按实例横向扩展 Worker。
2. **写扩散优化**
   - `total_visits` 采用“Redis 计数 + 定时回刷 MySQL”模式，减少行锁竞争。
3. **唯一短码生成**
   - 可改为 Snowflake + Base62，降低随机碰撞。
4. **热点短码保护**
   - 对超热点短码提前预热缓存，必要时本地 LRU 二级缓存。
5. **安全防刷**
   - 可叠加 IP + UA + Referer 策略，或接入验证码/风控网关。
6. **高可用**
   - Redis Sentinel/Cluster，MySQL 主从 + 自动故障切换，Swoole 多 worker + 进程守护。

---

### 8. 测试策略

本项目单测不依赖 ext-swoole、MySQL、Redis 实例，通过 fake 实现验证：

- 创建逻辑（URL 校验、短码冲突、限流）
- 跳转逻辑（访问计数、最近访问记录）
- 幂等创建（重复请求返回同一短码）
- 后台管理接口（分页筛选、批量禁用）
- 访问日志 Worker（消费、ack、异常容错）
- API 控制器（状态码、JSON、路由分发）

