<?php

// The only PHP file under the web root. Everything else lives outside it.

declare(strict_types=1);

use Paxofi\Core\Contracts\Configuration;
use PaxofiCloud\Bootstrap\AppFactory;
use PaxofiCloud\Http\HttpRuntime;
use PaxofiCloud\Http\RequestFactory;
use PaxofiCloud\Http\ResponseEmitter;
use PaxofiCloud\Infrastructure\Container\Resolve;

require dirname(__DIR__) . '/vendor/autoload.php';

$application = AppFactory::create(dirname(__DIR__));
$maxBody = Resolve::get($application->container(), Configuration::class)->get('app.max_body_bytes', 1_048_576);

$input = fopen('php://input', 'rb');
$request = (new RequestFactory(is_int($maxBody) ? $maxBody : 1_048_576))->fromGlobals($_SERVER, $_GET, $input === false ? null : $input);

(new ResponseEmitter())->emit((new HttpRuntime($application))->handle($request));
