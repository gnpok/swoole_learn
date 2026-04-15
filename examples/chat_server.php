<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SwooleLearn\Learning\Chat\ChatServer;
use SwooleLearn\Learning\Runtime\SwooleWebSocketServerRuntime;

$host = getenv('SWOOLE_HOST') ?: '0.0.0.0';
$port = (int) (getenv('SWOOLE_PORT') ?: 9502);

$server = new ChatServer(new SwooleWebSocketServerRuntime($host, $port));
$server->configure([
    'worker_num' => (int) (getenv('SWOOLE_WORKER_NUM') ?: 1),
    'max_request' => (int) (getenv('SWOOLE_MAX_REQUEST') ?: 10000),
]);
$server->registerDefaultHandlers();
$server->start();
