<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Predis\Client;
use SwooleLearn\ShortUrl\Infrastructure\PdoFactory;
use SwooleLearn\ShortUrl\Infrastructure\PdoShortUrlRepository;
use SwooleLearn\ShortUrl\Infrastructure\RedisVisitEventQueue;
use SwooleLearn\ShortUrl\Observability\InMemoryPrometheusCollector;
use SwooleLearn\ShortUrl\Observability\JsonStructuredLogger;
use SwooleLearn\ShortUrl\Observability\StdoutMetricsExporter;
use SwooleLearn\ShortUrl\Service\ShortUrlVisitLogWorker;

$redis = new Client([
    'scheme' => getenv('REDIS_SCHEME') ?: 'tcp',
    'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('REDIS_PORT') ?: 6379),
    'password' => getenv('REDIS_PASSWORD') ?: null,
    'database' => (int) (getenv('REDIS_DATABASE') ?: 0),
]);

$queue = new RedisVisitEventQueue(
    redis: $redis,
    stream: getenv('REDIS_VISIT_STREAM') ?: 'shorturl:visit:stream',
    retryStream: getenv('REDIS_VISIT_RETRY_STREAM') ?: 'shorturl:visit:stream:retry',
    deadLetterStream: getenv('REDIS_VISIT_DLQ_STREAM') ?: 'shorturl:visit:stream:dlq',
    consumerGroup: getenv('VISIT_LOG_CONSUMER_GROUP') ?: 'visit-log-workers',
    consumerName: getenv('VISIT_LOG_CONSUMER_NAME') ?: 'worker-1'
);

$logger = new JsonStructuredLogger();
$metrics = new InMemoryPrometheusCollector();
$worker = new ShortUrlVisitLogWorker(
    queue: $queue,
    repository: new PdoShortUrlRepository(PdoFactory::fromEnv()),
    maxAttempts: (int) (getenv('VISIT_LOG_MAX_ATTEMPTS') ?: 5),
    metrics: $metrics,
    logger: $logger
);

$batchSize = (int) (getenv('VISIT_LOG_BATCH_SIZE') ?: 100);
$blockMs = (int) (getenv('VISIT_LOG_BLOCK_MS') ?: 5000);
$reclaimIdleMs = (int) (getenv('VISIT_LOG_RECLAIM_IDLE_MS') ?: 60000);
$reclaimCount = (int) (getenv('VISIT_LOG_RECLAIM_COUNT') ?: 100);
$snapshotEvery = (int) (getenv('WORKER_METRICS_SNAPSHOT_EVERY') ?: 0);
$metricsExporter = new StdoutMetricsExporter($metrics);

$iterations = 0;
while (true) {
    $worker->processOnce(
        batchSize: max(1, $batchSize),
        blockMs: max(1, $blockMs),
        reclaimIdleMs: max(1, $reclaimIdleMs),
        reclaimCount: max(1, $reclaimCount)
    );
    $iterations++;
    if ($snapshotEvery > 0 && $iterations % $snapshotEvery === 0) {
        $metricsExporter->exportSnapshot('# worker_metrics_snapshot');
    }
}
