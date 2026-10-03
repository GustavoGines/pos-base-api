<?php

use App\Http\Middleware\CheckAddonPermission;
use App\Http\Middleware\CheckFeatureAccess;
use App\Http\Middleware\EnsurePermissionOrPin;
use App\Http\Middleware\EnsureRoleOrPin;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\ValidateSessionToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'addon' => CheckAddonPermission::class,
            'feature' => CheckFeatureAccess::class,   // [feature-flags] Seguridad modular
            'session.validate' => ValidateSessionToken::class, // [single-session] Sesión única por usuario
            'role.admin' => EnsureUserIsAdmin::class,
            'permission.or.pin' => EnsurePermissionOrPin::class,
            'role.or.pin' => EnsurePermissionOrPin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            if ($request->is('api/*')) {
                return true;
            }

            return $request->expectsJson();
        });
    })->create();
