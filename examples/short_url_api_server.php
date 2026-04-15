<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Predis\Client;
use SwooleLearn\Learning\Runtime\SwooleHttpServerRuntime;
use SwooleLearn\ShortUrl\Http\ShortUrlApiController;
use SwooleLearn\ShortUrl\Http\SwooleShortUrlServer;
use SwooleLearn\ShortUrl\Infrastructure\PdoFactory;
use SwooleLearn\ShortUrl\Infrastructure\PdoShortUrlRepository;
use SwooleLearn\ShortUrl\Infrastructure\RedisIdempotencyStore;
use SwooleLearn\ShortUrl\Infrastructure\RedisRateLimiter;
use SwooleLearn\ShortUrl\Infrastructure\RedisShortUrlCache;
use SwooleLearn\ShortUrl\Infrastructure\RedisStatsStore;
use SwooleLearn\ShortUrl\Infrastructure\RedisVisitEventQueue;
use SwooleLearn\ShortUrl\Service\ShortUrlService;
use SwooleLearn\ShortUrl\Support\Base62CodeGenerator;

$redis = new Client([
    'scheme' => getenv('REDIS_SCHEME') ?: 'tcp',
    'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('REDIS_PORT') ?: 6379),
    'password' => getenv('REDIS_PASSWORD') ?: null,
    'database' => (int) (getenv('REDIS_DATABASE') ?: 0),
]);

$service = new ShortUrlService(
    repository: new PdoShortUrlRepository(PdoFactory::fromEnv()),
    cache: new RedisShortUrlCache($redis),
    statsStore: new RedisStatsStore($redis),
    rateLimiter: new RedisRateLimiter($redis),
    codeGenerator: new Base62CodeGenerator(),
    idempotencyStore: new RedisIdempotencyStore($redis),
    visitEventQueue: new RedisVisitEventQueue(
        $redis,
        stream: getenv('REDIS_VISIT_STREAM') ?: 'shorturl:visit:stream',
        consumerGroup: getenv('REDIS_VISIT_CONSUMER_GROUP') ?: 'visit-log-workers',
        consumerName: getenv('REDIS_VISIT_CONSUMER_NAME') ?: 'worker-api'
    ),
    publicBaseUrl: getenv('PUBLIC_BASE_URL') ?: 'http://127.0.0.1:9501'
);

$controller = new ShortUrlApiController($service);
$server = new SwooleShortUrlServer(
    new SwooleHttpServerRuntime(
        getenv('SWOOLE_HOST') ?: '0.0.0.0',
        (int) (getenv('SWOOLE_PORT') ?: 9501)
    ),
    $controller
);

$server->configure([
    'worker_num' => (int) (getenv('SWOOLE_WORKER_NUM') ?: 1),
    'max_request' => (int) (getenv('SWOOLE_MAX_REQUEST') ?: 10000),
]);
$server->registerRoutes();
$server->start();
