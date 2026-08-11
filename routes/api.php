<?php

use App\Http\Controllers\DepartamentosController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Endpoint para el CRUD de departamentos
Route::get('/departamentos', [DepartamentosController::class, 'index']);
Route::post('/departamentos', [DepartamentosController::class, 'store']);
Route::get('/departamentos/{departamento}', [DepartamentosController::class, 'show']);
Route::put('/departamentos/{departamento}', [DepartamentosController::class, 'update']);
Route::patch('/departamentos/{departamento}/desactivar', [DepartamentosController::class, 'desactivar']);
Route::patch('/departamentos/{departamento}/activar', [DepartamentosController::class, 'activar']);
