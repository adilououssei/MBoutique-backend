<?php

use App\Modules\Features\Http\Middleware\EnsureFeatureEnabled;
use App\Modules\Subscriptions\Exceptions\SubscriptionLimitExceededException;
use App\Modules\Tenancy\Http\Middleware\ResolveStoreContext;
use App\Shared\Http\Responses\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->alias([
            'store' => ResolveStoreContext::class,
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
            'feature' => EnsureFeatureEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::error(
                    'Les données fournies sont invalides.',
                    $e->errors(),
                    422,
                    'VALIDATION_ECHOUEE'
                );
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::error('Non authentifié.', [], 401, 'NON_AUTHENTIFIE');
            }
        });

        // Laravel's prepareException() turns AuthorizationException into
        // AccessDeniedHttpException (and ModelNotFoundException into
        // NotFoundHttpException) BEFORE render callbacks run, so these two
        // must target the HTTP exceptions actually thrown by then.
        $exceptions->render(function (AccessDeniedHttpException|UnauthorizedException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::error('Action non autorisée.', [], 403, 'ACCES_INTERDIT');
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::error('Ressource introuvable.', [], 404, 'INTROUVABLE');
            }
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::error('Trop de requêtes. Réessayez plus tard.', [], 429, 'TROP_DE_REQUETES');
            }
        });

        $exceptions->render(function (SubscriptionLimitExceededException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::error($e->getMessage(), [], 403, 'LIMITE_ABONNEMENT_ATTEINTE');
            }
        });
    })->create();
