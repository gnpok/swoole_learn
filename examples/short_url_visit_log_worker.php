<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Predis\Client;
use SwooleLearn\ShortUrl\Infrastructure\PdoFactory;
use SwooleLearn\ShortUrl\Infrastructure\PdoShortUrlRepository;
use SwooleLearn\ShortUrl\Infrastructure\RedisVisitEventQueue;
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
    consumerGroup: getenv('VISIT_LOG_CONSUMER_GROUP') ?: 'visit-log-workers',
    consumerName: getenv('VISIT_LOG_CONSUMER_NAME') ?: 'worker-1'
);

$worker = new ShortUrlVisitLogWorker(
    queue: $queue,
    repository: new PdoShortUrlRepository(PdoFactory::fromEnv())
);

$batchSize = (int) (getenv('VISIT_LOG_BATCH_SIZE') ?: 100);
$blockMs = (int) (getenv('VISIT_LOG_BLOCK_MS') ?: 5000);

$worker->runLoop(max(1, $batchSize), max(1, $blockMs));
