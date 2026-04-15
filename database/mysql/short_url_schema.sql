-- 短地址服务 MySQL Schema (MySQL 8.0+)
-- 建库建议：
-- CREATE DATABASE IF NOT EXISTS swoole_short_url DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE swoole_short_url;

CREATE TABLE IF NOT EXISTS short_urls (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
    code VARCHAR(16) NOT NULL COMMENT '短码，唯一',
    original_url TEXT NOT NULL COMMENT '原始长链接',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否启用，0=禁用，1=启用',
    expires_at DATETIME NULL COMMENT '过期时间，NULL 表示永久有效',
    total_visits BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '总访问次数（主存统计）',
    last_visited_at DATETIME NULL COMMENT '最后访问时间',
    created_at DATETIME NOT NULL COMMENT '创建时间',
    updated_at DATETIME NOT NULL COMMENT '更新时间',
    PRIMARY KEY (id),
    UNIQUE KEY uk_short_urls_code (code),
    KEY idx_short_urls_created_at (created_at),
    KEY idx_short_urls_expires_at (expires_at),
    KEY idx_short_urls_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='短链接主表';

CREATE TABLE IF NOT EXISTS short_url_visits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
    event_key CHAR(64) NOT NULL COMMENT '访问事件幂等键（sha256）',
    short_url_code VARCHAR(16) NOT NULL COMMENT '短码（逻辑外键）',
    visited_at DATETIME NOT NULL COMMENT '访问时间',
    client_ip VARCHAR(45) NOT NULL COMMENT '客户端 IP（支持 IPv6）',
    user_agent VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'User-Agent 截断保存',
    referer VARCHAR(255) NOT NULL DEFAULT '' COMMENT '来源页面，可选',
    PRIMARY KEY (id),
    UNIQUE KEY uk_short_url_visits_event_key (event_key),
    KEY idx_short_url_visits_code_time (short_url_code, visited_at),
    KEY idx_short_url_visits_visited_at (visited_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='短链接访问日志';

CREATE TABLE IF NOT EXISTS short_url_daily_stats (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
    short_url_code VARCHAR(16) NOT NULL COMMENT '短码',
    stat_date DATE NOT NULL COMMENT '统计日期',
    visit_count BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '当日访问次数',
    updated_at DATETIME NOT NULL COMMENT '更新时间',
    PRIMARY KEY (id),
    UNIQUE KEY uk_short_url_daily_stats_code_date (short_url_code, stat_date),
    KEY idx_short_url_daily_stats_stat_date (stat_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='按天统计表（可由异步任务聚合）';

CREATE TABLE IF NOT EXISTS short_url_idempotency (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
    idem_key VARCHAR(96) NOT NULL COMMENT '幂等键，建议带业务前缀',
    short_code VARCHAR(16) NOT NULL COMMENT '对应短码',
    created_at DATETIME NOT NULL COMMENT '创建时间',
    expires_at DATETIME NOT NULL COMMENT '过期时间',
    PRIMARY KEY (id),
    UNIQUE KEY uk_short_url_idempotency_key (idem_key),
    KEY idx_short_url_idempotency_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='幂等键持久化（可选，主流程用 Redis）';

-- 可选：历史日志归档策略
-- 1) 定时任务将 short_url_visits 中 N 天前数据归档到历史库
-- 2) 仅保留最近 30/90 天明细日志，主统计留在 short_urls + short_url_daily_stats
