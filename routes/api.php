<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DepartamentosController;
use App\Http\Controllers\PermisosController;
use App\Http\Controllers\PersonasController;
use App\Http\Controllers\RolesController;
use App\Http\Controllers\UsuariosController;
use Illuminate\Support\Facades\Route;

// Sesión (§3.1). `login` es público por definición; `me` y `logout` requieren sesión activa.
Route::post('/login', [AuthController::class, 'login']);
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
});

/*
 * Endpoints protegidos (§5.6).
 *
 * `auth:sanctum` exige sesión; `can:<clave>` exige el permiso concreto que otorga el rol de
 * esa cuenta. Ambas comprobaciones ocurren en el backend a propósito: ocultar un botón en la
 * interfaz no protege nada, porque cualquiera puede lanzar la petición a mano.
 *
 * Las claves son las de §5.3, sembradas por PermisoSeeder. `colaboradores.*` es la
 * nomenclatura de la spec para lo que aquí es el módulo de personas (§3).
 */
Route::middleware('auth:sanctum')->group(function () {

    // Departamentos (§3.4)
    Route::get('/departamentos', [DepartamentosController::class, 'index'])->middleware('can:departamentos.ver');
    Route::post('/departamentos', [DepartamentosController::class, 'store'])->middleware('can:departamentos.crear');
    Route::get('/departamentos/{departamento}', [DepartamentosController::class, 'show'])->middleware('can:departamentos.ver');
    Route::put('/departamentos/{departamento}', [DepartamentosController::class, 'update'])->middleware('can:departamentos.editar');
    Route::patch('/departamentos/{departamento}/desactivar', [DepartamentosController::class, 'desactivar'])->middleware('can:departamentos.desactivar');
    Route::patch('/departamentos/{departamento}/activar', [DepartamentosController::class, 'activar'])->middleware('can:departamentos.desactivar');

    // Personas (§3). Sin DELETE: la baja es lógica (§3.3).
    Route::get('/personas', [PersonasController::class, 'index'])->middleware('can:colaboradores.ver');
    Route::post('/personas', [PersonasController::class, 'store'])->middleware('can:colaboradores.crear');
    Route::get('/personas/{persona}', [PersonasController::class, 'show'])->middleware('can:colaboradores.ver');
    Route::put('/personas/{persona}', [PersonasController::class, 'update'])->middleware('can:colaboradores.editar');
    Route::patch('/personas/{persona}/desactivar', [PersonasController::class, 'desactivar'])->middleware('can:colaboradores.desactivar');
    Route::patch('/personas/{persona}/activar', [PersonasController::class, 'activar'])->middleware('can:colaboradores.desactivar');

    // Roles (§5.2). Sin DELETE: un rol se desactiva (§5.5).
    Route::get('/roles', [RolesController::class, 'index'])->middleware('can:roles.ver');
    Route::post('/roles', [RolesController::class, 'store'])->middleware('can:roles.crear');
    Route::get('/roles/{rol}', [RolesController::class, 'show'])->middleware('can:roles.ver');
    Route::put('/roles/{rol}', [RolesController::class, 'update'])->middleware('can:roles.editar');
    Route::patch('/roles/{rol}/desactivar', [RolesController::class, 'desactivar'])->middleware('can:roles.desactivar');
    Route::patch('/roles/{rol}/activar', [RolesController::class, 'activar'])->middleware('can:roles.desactivar');

    // Catálogo de permisos (§5.3). Solo lectura: los define el código, no el usuario. Lo pide
    // la pantalla de asignación de permisos, así que basta con poder ver roles.
    Route::get('/permisos', [PermisosController::class, 'index'])->middleware('can:roles.ver');

    // Cuentas de sistema (§3.1, §5). Sin DELETE: una cuenta se suspende.
    Route::get('/usuarios', [UsuariosController::class, 'index'])->middleware('can:usuarios.ver');
    Route::post('/usuarios', [UsuariosController::class, 'store'])->middleware('can:usuarios.crear');
    // Catálogos del formulario de cuentas: lo mínimo para elegir un rol y una persona sin
    // exigir `roles.ver` ni `colaboradores.ver`. Van ANTES de `/usuarios/{usuario}`: si no,
    // el route binding intentaría buscar una cuenta con id "roles-asignables" y daría 404.
    // `roles-asignables` comprueba sus permisos en el controlador (acepta cualquier `usuarios.*`).
    Route::get('/usuarios/roles-asignables', [UsuariosController::class, 'rolesAsignables']);
    Route::get('/usuarios/personas-disponibles', [UsuariosController::class, 'personasDisponibles'])->middleware('can:usuarios.crear');
    Route::get('/usuarios/{usuario}', [UsuariosController::class, 'show'])->middleware('can:usuarios.ver');
    Route::put('/usuarios/{usuario}', [UsuariosController::class, 'update'])->middleware('can:usuarios.editar');
    // Restablecer la contraseña de otra persona es editar su cuenta, no darla de baja.
    Route::patch('/usuarios/{usuario}/password', [UsuariosController::class, 'password'])->middleware('can:usuarios.editar');
    Route::patch('/usuarios/{usuario}/suspender', [UsuariosController::class, 'suspender'])->middleware('can:usuarios.desactivar');
    Route::patch('/usuarios/{usuario}/reactivar', [UsuariosController::class, 'reactivar'])->middleware('can:usuarios.desactivar');
});
