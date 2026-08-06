<?php

namespace App\Http\Controllers;

use App\Http\Requests\DepartamentoRequest;
use App\Models\Departamento;
use Illuminate\Http\JsonResponse;

class DepartamentosController extends Controller
{
    /**
     * Lista todos los departamentos (activos e inactivos, para administración).
     */
    public function index(): JsonResponse
    {
        $departamentos = Departamento::orderBy('nombre')->get();

        return response()->json($departamentos);
    }

    /**
     * Crea un departamento (§3.4).
     */
    public function store(DepartamentoRequest $request): JsonResponse
    {
        $departamento = Departamento::create($request->validated());

        return response()->json($departamento, 201);
    }

    /**
     * Muestra un departamento.
     */
    public function show(Departamento $departamento): JsonResponse
    {
        return response()->json($departamento);
    }

    /**
     * Edita un departamento (§3.4).
     */
    public function update(DepartamentoRequest $request, Departamento $departamento): JsonResponse
    {
        $departamento->update($request->validated());

        return response()->json($departamento);
    }

    /**
     * Baja lógica del departamento (§3.4, RN-07). No se elimina físicamente para conservar el
     * historial de colaboradores y operaciones asociadas.
     */
    public function desactivar(Departamento $departamento): JsonResponse
    {
        $departamento->update(['activo' => false]);

        return response()->json($departamento);
    }

    /**
     * Reactiva un departamento previamente desactivado.
     */
    public function activar(Departamento $departamento): JsonResponse
    {
        $departamento->update(['activo' => true]);

        return response()->json($departamento);
    }
}
