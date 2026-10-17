<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Providers;

use Paxofi\Core\Application;
use Paxofi\Core\Contracts\Configuration;
use Paxofi\Core\Contracts\Container;
use Paxofi\Core\Contracts\Logger;
use Paxofi\Core\Contracts\Provider;
use Paxofi\Core\Http\ControllerDispatcher;
use Paxofi\Core\Http\Router;
use PaxofiCloud\Http\Controllers\LivenessController;
use PaxofiCloud\Http\Controllers\ReadinessController;
use PaxofiCloud\Http\Controllers\RegisterController;
use PaxofiCloud\Http\Controllers\VerifyEmailController;
use PaxofiCloud\Http\Middleware\ApiWriteGuardMiddleware;
use PaxofiCloud\Http\Middleware\BodySizeLimitMiddleware;
use PaxofiCloud\Http\Middleware\ErrorBoundaryMiddleware;
use PaxofiCloud\Http\Middleware\RouteFallbackMiddleware;
use PaxofiCloud\Http\Middleware\SecurityHeadersMiddleware;
use PaxofiCloud\Http\MiddlewareStack;
use PaxofiCloud\Http\ProblemDetailsExceptionHandler;
use PaxofiCloud\Infrastructure\Container\Resolve;

/** Router, middleware order and the kernel's fallback exception handler. */
final class HttpProvider implements Provider
{
    public function register(Application $application): void
    {
        $container = $application->container();

        $container->singleton(Router::class, static function (Container $c): Router {
            $router = new Router();
            $dispatcher = new ControllerDispatcher($c);
            $router->controller('GET', '/health/live', LivenessController::class, $dispatcher);
            $router->controller('GET', '/health/ready', ReadinessController::class, $dispatcher);
            $router->controller('POST', '/api/v1/auth/register', RegisterController::class, $dispatcher);
            $router->controller('POST', '/api/v1/auth/verify-email', VerifyEmailController::class, $dispatcher);

            return $router;
        });

        $container->singleton(MiddlewareStack::class, static function (Container $c): MiddlewareStack {
            $logger = Resolve::get($c, Logger::class);
            $configuration = Resolve::get($c, Configuration::class);

            return new MiddlewareStack(
                // Outermost: headers apply even to error responses.
                new SecurityHeadersMiddleware(),
                new ErrorBoundaryMiddleware($logger, $configuration->get('app.debug', false) === true),
                new BodySizeLimitMiddleware(),
                new ApiWriteGuardMiddleware(self::appUrl($configuration)),
                new RouteFallbackMiddleware(),
            );
        });

        $container->singleton(ProblemDetailsExceptionHandler::class, static function (Container $c): ProblemDetailsExceptionHandler {
            return new ProblemDetailsExceptionHandler(Resolve::get($c, Logger::class));
        });
    }

    private static function appUrl(Configuration $configuration): string
    {
        $url = $configuration->get('app.url');

        return is_string($url) ? $url : '';
    }

    public function boot(Application $application): void
    {
    }
}
