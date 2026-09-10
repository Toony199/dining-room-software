<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Autenticación SPA de Sanctum: el frontend vive en el mismo dominio que la API, así
        // que la sesión viaja en una cookie httpOnly con protección CSRF, y no en un token
        // guardado en localStorage —que cualquier XSS podría leer—.
        //
        // Sin esto, las rutas de routes/api.php serían stateless y `Auth::login()` no
        // persistiría nada entre peticiones.
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Laravel responde a un permiso faltante con "This action is unauthorized." La
        // interfaz está en español y ese texto llega tal cual al usuario (§5.6).
        //
        // Se intercepta AccessDeniedHttpException y no AuthorizationException: el handler
        // convierte la segunda en la primera ANTES de llamar a estos callbacks, así que un
        // callback tipado a AuthorizationException nunca se ejecuta.
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'No tienes permiso para realizar esta acción.',
                ], 403);
            }

            return null;
        });
    })->create();
