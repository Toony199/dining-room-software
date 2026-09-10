<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registrarPermisos();
    }

    /**
     * Conecta los permisos granulares de §5.3 con el sistema de autorización de Laravel, para
     * que las rutas puedan protegerse con `can:pagos.confirmar` y demás (§5.6).
     *
     * Los permisos son datos, no código: se dan de alta en la tabla `permisos` conforme se
     * incorporan módulos, así que no pueden declararse uno por uno con `Gate::define`. Este
     * `before` los resuelve todos contra el rol de la cuenta.
     *
     * Devuelve `true` o `null`, nunca `false`: un `false` aquí cortaría en seco cualquier otra
     * comprobación de autorización que se agregue después (una Policy, por ejemplo).
     */
    private function registrarPermisos(): void
    {
        Gate::before(function (User $usuario, string $permiso) {
            // `tienePermiso` ya niega si la persona está de baja, la cuenta suspendida o el
            // rol desactivado (§3.3): un cambio de estado corta el acceso en la siguiente
            // petición, sin esperar a que caduque la sesión.
            return $usuario->tienePermiso($permiso) ? true : null;
        });
    }
}
