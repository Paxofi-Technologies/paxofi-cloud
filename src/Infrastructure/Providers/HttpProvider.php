<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Providers;

use Paxofi\Core\Application;
use Paxofi\Core\Contracts\Configuration;
use Paxofi\Core\Contracts\Container;
use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Contracts\Logger;
use Paxofi\Core\Contracts\Provider;
use Paxofi\Core\Http\ControllerDispatcher;
use Paxofi\Core\Http\Router;
use PaxofiCloud\Http\Controllers\AccountActivityController;
use PaxofiCloud\Http\Controllers\AccountSessionsController;
use PaxofiCloud\Http\Controllers\ChallengeController;
use PaxofiCloud\Http\Controllers\LivenessController;
use PaxofiCloud\Http\Controllers\MfaController;
use PaxofiCloud\Http\Controllers\SignInMfaController;
use PaxofiCloud\Http\Controllers\ReadinessController;
use PaxofiCloud\Http\Controllers\RegisterController;
use PaxofiCloud\Http\Controllers\ResendVerificationController;
use PaxofiCloud\Http\Controllers\SessionController;
use PaxofiCloud\Http\Controllers\SignInController;
use PaxofiCloud\Http\Controllers\SignOutController;
use PaxofiCloud\Http\Controllers\VerifyEmailController;
use PaxofiCloud\Http\Admin\AdminGate;
use PaxofiCloud\Http\Admin\AdminRoutes;
use PaxofiCloud\Http\Controllers\Admin\AdminAccountController;
use PaxofiCloud\Http\Controllers\Admin\AdminAuthController;
use PaxofiCloud\Http\Controllers\Admin\AdminCodeSignInController;
use PaxofiCloud\Http\Controllers\Admin\AdminEnrolmentController;
use PaxofiCloud\Http\Controllers\Admin\AdminStaffController;
use PaxofiCloud\Http\Middleware\AdminApiGuardMiddleware;
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
            $router->controller('POST', '/api/v1/auth/resend-verification', ResendVerificationController::class, $dispatcher);
            $router->controller('GET', '/api/v1/auth/challenge', ChallengeController::class, $dispatcher);
            $router->controller('POST', '/api/v1/auth/sign-in', SignInController::class, $dispatcher);
            $router->controller('POST', '/api/v1/auth/sign-in/mfa', SignInMfaController::class, $dispatcher);
            $mfa = static fn (): MfaController => Resolve::get($c, MfaController::class);
            $router->get('/api/v1/account/mfa', static fn (HttpRequest $r): HttpResponse => $mfa()->status($r));
            $router->post('/api/v1/account/mfa/totp/setup', static fn (HttpRequest $r): HttpResponse => $mfa()->setup($r));
            $router->post('/api/v1/account/mfa/totp/confirm', static fn (HttpRequest $r): HttpResponse => $mfa()->confirm($r));
            $router->post('/api/v1/account/mfa/recovery-codes', static fn (HttpRequest $r): HttpResponse => $mfa()->recoveryCodes($r));
            $router->post('/api/v1/account/mfa/disable', static fn (HttpRequest $r): HttpResponse => $mfa()->disable($r));
            $router->controller('GET', '/api/v1/account/activity', AccountActivityController::class, $dispatcher);
            $accountSessions = static fn (): AccountSessionsController => Resolve::get($c, AccountSessionsController::class);
            $router->get('/api/v1/account/sessions', static fn (HttpRequest $r): HttpResponse => $accountSessions()->list($r));
            $router->post('/api/v1/account/sessions/revoke', static fn (HttpRequest $r): HttpResponse => $accountSessions()->revoke($r));
            $router->post('/api/v1/account/sessions/revoke-others', static fn (HttpRequest $r): HttpResponse => $accountSessions()->revokeOthers($r));
            $router->controller('GET', '/api/v1/auth/session', SessionController::class, $dispatcher);
            $router->controller('POST', '/api/v1/auth/sign-out', SignOutController::class, $dispatcher);

            // The admin API: built only from AdminRoutes, every route behind the gate (§6.18).
            $gate = static fn (): AdminGate => Resolve::get($c, AdminGate::class);
            foreach (self::adminRoutes($c) as $route) {
                $router->add($route->method, $route->path, static fn (HttpRequest $r): HttpResponse => $gate()->handle($route, $r));
            }

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
                new AdminApiGuardMiddleware(self::adminUrl($configuration)),
                new RouteFallbackMiddleware(),
            );
        });

        $container->singleton(ProblemDetailsExceptionHandler::class, static function (Container $c): ProblemDetailsExceptionHandler {
            return new ProblemDetailsExceptionHandler(Resolve::get($c, Logger::class));
        });
    }

    /** @return list<\PaxofiCloud\Http\Admin\AdminRoute> */
    public static function adminRoutes(Container $c): array
    {
        return AdminRoutes::all(
            static fn (): AdminAuthController => Resolve::get($c, AdminAuthController::class),
            static fn (): AdminEnrolmentController => Resolve::get($c, AdminEnrolmentController::class),
            static fn (): AdminStaffController => Resolve::get($c, AdminStaffController::class),
            static fn (): AdminCodeSignInController => Resolve::get($c, AdminCodeSignInController::class),
            static fn (): AdminAccountController => Resolve::get($c, AdminAccountController::class),
        );
    }

    private static function adminUrl(Configuration $configuration): string
    {
        $url = $configuration->get('app.admin_url');

        return is_string($url) ? $url : '';
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
