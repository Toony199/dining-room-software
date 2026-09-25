<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DepartamentosController;
use App\Http\Controllers\DisenosGafeteController;
use App\Http\Controllers\FotosPersonaController;
use App\Http\Controllers\GafetesController;
use App\Http\Controllers\KioscoController;
use App\Http\Controllers\PeriodosController;
use App\Http\Controllers\PermisosController;
use App\Http\Controllers\PersonasController;
use App\Http\Controllers\RolesController;
use App\Http\Controllers\TarifasController;
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
    // Catálogo de departamentos del formulario de personas: lo mínimo para elegir uno sin
    // exigir `departamentos.ver`. Va ANTES de `/personas/{persona}` por el route binding, y
    // comprueba sus permisos en el controlador (acepta cualquier `colaboradores.*`).
    Route::get('/personas/departamentos-asignables', [PersonasController::class, 'departamentosAsignables']);
    Route::get('/personas/{persona}', [PersonasController::class, 'show'])->middleware('can:colaboradores.ver');
    Route::put('/personas/{persona}', [PersonasController::class, 'update'])->middleware('can:colaboradores.editar');
    Route::patch('/personas/{persona}/desactivar', [PersonasController::class, 'desactivar'])->middleware('can:colaboradores.desactivar');
    Route::patch('/personas/{persona}/activar', [PersonasController::class, 'activar'])->middleware('can:colaboradores.desactivar');

    // Kiosco (§10). Quien está frente a la pantalla es un colaborador y se identifica con el QR
    // de su gafete, pero el equipo entra con su propia cuenta: la del rol Kiosco, que solo tiene
    // este permiso y por eso no abre ningún módulo administrativo (§10.1). El límite por
    // frecuencia se queda: un lector averiado o un token probado a mano no deben poder martillear.
    Route::middleware('throttle:kiosco')->group(function () {
        Route::post('/kiosco/identificar', [KioscoController::class, 'identificar'])
            ->middleware('can:kiosco.operar')->name('kiosco.identificar');
        Route::post('/kiosco/fichas', [KioscoController::class, 'generarFicha'])
            ->middleware('can:kiosco.operar')->name('kiosco.fichas');
    });

    // Gafetes (§4). Sin DELETE ni edición: un gafete solo se reemplaza y el historial se
    // conserva (§4.3). El token del QR solo sale por la impresión, que comprueba sus permisos
    // en el controlador (acepta `gafetes.emitir` o `gafetes.reimprimir`).
    Route::get('/personas/{persona}/gafetes', [GafetesController::class, 'index'])->middleware('can:gafetes.ver');
    Route::post('/personas/{persona}/gafetes', [GafetesController::class, 'store'])->middleware('can:gafetes.emitir');
    // Varios gafetes en una hoja. Va antes del de uno solo para dejar claro que `impresion` aquí
    // es una ruta fija y no el id de un gafete.
    Route::get('/gafetes/impresion', [GafetesController::class, 'impresionEnLote']);
    Route::get('/gafetes/{gafete}/impresion', [GafetesController::class, 'impresion']);

    // Diseño del gafete (§4.1). Un solo formato para todos: se sube como borrador y se activa
    // aparte. Ver el vigente y su fondo lo puede quien trabaja con gafetes (cualquier gafetes.*,
    // se comprueba en el controlador); el resto es de quien diseña.
    Route::get('/gafetes/diseno', [DisenosGafeteController::class, 'vigente']);
    Route::get('/gafetes/disenos', [DisenosGafeteController::class, 'index'])->middleware('can:gafetes.disenar');
    Route::post('/gafetes/disenos', [DisenosGafeteController::class, 'store'])->middleware('can:gafetes.disenar');
    Route::get('/gafetes/disenos/plantilla', [DisenosGafeteController::class, 'plantilla'])->middleware('can:gafetes.disenar');
    Route::get('/gafetes/disenos/predeterminado/fondo', [DisenosGafeteController::class, 'fondoPredeterminado']);
    Route::patch('/gafetes/disenos/restablecer', [DisenosGafeteController::class, 'restablecer'])->middleware('can:gafetes.disenar');
    Route::get('/gafetes/disenos/{diseno}/fondo', [DisenosGafeteController::class, 'fondo'])->whereNumber('diseno');
    Route::patch('/gafetes/disenos/{diseno}/activar', [DisenosGafeteController::class, 'activar'])->whereNumber('diseno')->middleware('can:gafetes.disenar');

    // Fotografía de la persona (§3.1). Privada: el archivo vive fuera de public/ y solo sale por
    // aquí. Verla comprueba sus permisos en el controlador (colaboradores.ver o gafetes.*);
    // tomarla o cambiarla es editar a la persona.
    Route::get('/personas/{persona}/foto', [FotosPersonaController::class, 'show']);
    Route::post('/personas/{persona}/foto', [FotosPersonaController::class, 'store'])->middleware('can:colaboradores.editar');

    // Tarifas (§6.4, §18). Solo alta y consulta: el precio es histórico y no se edita ni se
    // borra; cambiarlo es registrar otro, y el anterior queda cerrado el día previo (§6.5).
    Route::get('/tarifas', [TarifasController::class, 'index'])->middleware('can:tarifas.ver');
    // Va ANTES de cualquier /tarifas/{tarifa} por el route binding.
    Route::get('/tarifas/vigente', [TarifasController::class, 'vigente'])->middleware('can:tarifas.ver');
    Route::post('/tarifas', [TarifasController::class, 'store'])->middleware('can:tarifas.editar');

    // Periodos de servicio (§6, §8, §9). Sin DELETE: el periodo guarda el precio con el que se
    // cobró cada día. Cada transición de estado tiene su permiso.
    Route::get('/periodos', [PeriodosController::class, 'index'])->middleware('can:periodos.ver');
    Route::post('/periodos', [PeriodosController::class, 'store'])->middleware('can:periodos.crear');
    // Va ANTES de /periodos/{periodo} por el route binding: si no, buscaría el periodo
    // "generar-siguiente" y respondería 404.
    Route::post('/periodos/generar-siguiente', [PeriodosController::class, 'generarSiguiente'])->middleware('can:periodos.crear');
    Route::get('/periodos/{periodo}', [PeriodosController::class, 'show'])->middleware('can:periodos.ver');
    Route::put('/periodos/{periodo}', [PeriodosController::class, 'update'])->middleware('can:periodos.editar');
    Route::put('/periodos/{periodo}/dias/{dia}', [PeriodosController::class, 'actualizarDia'])->middleware('can:periodos.editar');
    Route::patch('/periodos/{periodo}/abrir', [PeriodosController::class, 'abrir'])->middleware('can:periodos.abrir');
    Route::patch('/periodos/{periodo}/cerrar', [PeriodosController::class, 'cerrar'])->middleware('can:periodos.cerrar');
    Route::patch('/periodos/{periodo}/reabrir', [PeriodosController::class, 'reabrir'])->middleware('can:periodos.reabrir');

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
