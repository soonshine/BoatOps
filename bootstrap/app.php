<?php

use App\Http\Middleware\RejectPublicDemoWrites;
use App\Http\Middleware\ResolveOperatorMembership;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands()
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(RejectPublicDemoWrites::class);
        $middleware->alias(['operator.membership' => ResolveOperatorMembership::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'request_id' => (string) Str::uuid(),
                'code' => 'VALIDATION_FAILED',
                'retryable' => false,
                'manual_action_required' => false,
                'message' => 'The request payload is invalid.',
            ], 422);
        });
    })->create();
