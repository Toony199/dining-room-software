<?php

namespace Database\Seeders;

use App\Models\Permiso;
use Illuminate\Database\Seeder;

/**
 * Catálogo de permisos granulares (§5.3).
 *
 * Es idempotente y aditivo a propósito: la spec pide que los permisos puedan agregarse
 * conforme se incorporen nuevos módulos, así que este seeder se vuelve a correr en cada
 * despliegue que traiga permisos nuevos sin tocar los roles ya configurados.
 */
class PermisoSeeder extends Seeder
{
    /**
     * Claves de §5.3, agrupadas por módulo para la pantalla de asignación.
     *
     * `colaboradores.*` es la nomenclatura literal de §5.3 y corresponde al módulo de
     * personas de este repositorio (§3).
     *
     * `roles.*` no aparece en la lista de §5.3, pero §5.2 exige un módulo de administración
     * de roles y §5.6 exige validar cada operación protegida en backend: sin estas claves ese
     * módulo no tendría con qué protegerse.
     *
     * @var array<string, array<string, string>>
     */
    private const CATALOGO = [
        'usuarios' => [
            'usuarios.ver' => 'Consultar las cuentas de sistema.',
            'usuarios.crear' => 'Crear cuentas de sistema para personas registradas.',
            'usuarios.editar' => 'Editar cuentas de sistema y su rol.',
            'usuarios.desactivar' => 'Suspender cuentas de sistema.',
        ],
        'roles' => [
            'roles.ver' => 'Consultar roles y sus permisos.',
            'roles.crear' => 'Crear roles.',
            'roles.editar' => 'Editar roles y asignarles permisos.',
            'roles.desactivar' => 'Desactivar roles sin usuarios asignados.',
        ],
        'colaboradores' => [
            'colaboradores.ver' => 'Consultar el personal registrado.',
            'colaboradores.crear' => 'Dar de alta personal.',
            'colaboradores.editar' => 'Editar los datos del personal.',
            'colaboradores.desactivar' => 'Dar de baja lógica al personal.',
        ],
        'departamentos' => [
            'departamentos.ver' => 'Consultar el catálogo de departamentos.',
            'departamentos.crear' => 'Crear departamentos.',
            'departamentos.editar' => 'Editar departamentos.',
            'departamentos.desactivar' => 'Desactivar departamentos.',
        ],
        // No aparecen en §5.3; vienen del catálogo completo de docs/analisis-diseno.md §4.
        'gafetes' => [
            'gafetes.ver' => 'Consultar el historial de gafetes de una persona.',
            'gafetes.emitir' => 'Emitir o reponer gafetes; el anterior deja de funcionar.',
            'gafetes.reimprimir' => 'Volver a imprimir el gafete activo de una persona.',
        ],
        'periodos' => [
            'periodos.ver' => 'Consultar los periodos de servicio.',
            'periodos.crear' => 'Crear periodos de servicio.',
            'periodos.editar' => 'Editar periodos en borrador.',
            'periodos.abrir' => 'Abrir un periodo a la generación de fichas.',
            'periodos.cerrar' => 'Cerrar la ventana de pago de un periodo.',
            'periodos.reabrir' => 'Reabrir un periodo cerrado.',
        ],
        'pagos' => [
            'pagos.ver' => 'Consultar los pagos registrados.',
            'pagos.confirmar' => 'Confirmar el pago físico de una ficha.',
            'pagos.cancelar' => 'Cancelar un pago confirmado.',
        ],
        'reportes' => [
            'reportes.ver' => 'Consultar reportes.',
            'reportes.generar' => 'Generar reportes de porciones y consumo.',
        ],
    ];

    public function run(): void
    {
        foreach (self::CATALOGO as $modulo => $permisos) {
            foreach ($permisos as $clave => $descripcion) {
                // updateOrCreate y no create: el seeder se recorre entero en cada despliegue,
                // y una descripción corregida debe propagarse sin duplicar la clave.
                Permiso::updateOrCreate(
                    ['clave' => $clave],
                    ['descripcion' => $descripcion, 'modulo' => $modulo],
                );
            }
        }
    }
}
