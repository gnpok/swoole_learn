<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SwooleLearn\Learning\HttpRouteRegistrar;
use SwooleLearn\Learning\Runtime\SwooleHttpServerRuntime;

$runtime = new SwooleHttpServerRuntime('127.0.0.1', 9501);
$learningServer = new HttpRouteRegistrar($runtime);

$learningServer->configure([
    'worker_num' => 1,
    'max_request' => 1000,
]);
$learningServer->registerDefaultRoutes();
$learningServer->start();
