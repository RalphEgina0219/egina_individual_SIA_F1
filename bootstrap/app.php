<?php

use App\Exceptions\BusinessRuleException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'permission' => \App\Http\Middleware\PermissionMiddleware::class,
            'integration.key' => \App\Http\Middleware\VerifyIntegrationKey::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Expected validation-style outcomes, not application errors.
        $exceptions->dontReport(BusinessRuleException::class);

        // Team integration (B5): iisang error format sa /api/integration/v1.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/integration/*')) {
                return \App\Support\IntegrationResponse::error('Validation failed', $e->errors(), 422);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/integration/*')) {
                return \App\Support\IntegrationResponse::error('Not found', [], 404);
            }
        });

        $exceptions->render(function (BusinessRuleException $e, Request $request) {
            return response()->json(array_filter([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => $e->data() ?: null,
            ], fn ($value) => $value !== null), $e->status());
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') && $e->getPrevious() instanceof ModelNotFoundException) {
                return response()->json([
                    'success' => false,
                    'message' => 'The requested record was not found.',
                ], 404);
            }
        });

        // Hide raw SQL errors from API clients; details remain in the log.
        $exceptions->render(function (QueryException $e, Request $request) {
            if ($request->is('api/*') && !config('app.debug')) {
                return response()->json([
                    'success' => false,
                    'message' => 'A database error occurred. Please try again or contact the administrator.',
                ], 500);
            }
        });
    })->create();
