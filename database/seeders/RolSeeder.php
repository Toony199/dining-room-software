<?php

namespace Database\Seeders;

use App\Models\Permiso;
use App\Models\Rol;
use Illuminate\Database\Seeder;

/**
 * Roles iniciales de §5.2.
 *
 * La spec los llama "ejemplos iniciales" y exige que los roles NO estén codificados de manera
 * rígida: esto es solo un punto de partida usable, y la empresa puede crear, editar y
 * reconfigurar roles desde el módulo de administración.
 *
 * Por eso el seeder solo asigna permisos a los roles que él mismo acaba de crear: si un
 * administrador ya ajustó los permisos de "Cobrador", volver a correr el seeder no debe
 * pisarle la configuración (§5.4).
 */
class RolSeeder extends Seeder
{
    /**
     * @var array<string, array{descripcion: string, permisos: array<int, string>}>
     */
    private const ROLES = [
        'Administrador' => [
            'descripcion' => 'Acceso total al sistema.',
            'permisos' => ['*'],
        ],
        'Cobrador' => [
            'descripcion' => 'Confirma el pago físico de las fichas en caja.',
            'permisos' => [
                'colaboradores.ver',
                // Leer el catálogo de departamentos es parte de poder consultar al personal:
                // la tabla muestra el departamento de cada persona y el filtro lo necesita.
                'departamentos.ver',
                'periodos.ver',
                'fichas.ver',
                'fichas.editar',
                'pagos.ver',
                'pagos.confirmar',
            ],
        ],
        'Kiosco' => [
            'descripcion' => 'Cuenta del equipo del kiosco. Solo genera fichas; no abre ningún módulo administrativo.',
            'permisos' => ['kiosco.operar'],
        ],
        'Gestor de periodos' => [
            'descripcion' => 'Administra los periodos de servicio y su ventana de pago.',
            'permisos' => [
                'periodos.ver',
                'periodos.crear',
                'periodos.editar',
                'periodos.abrir',
                'periodos.cerrar',
                'periodos.reabrir',
                'tarifas.ver',
                'tarifas.editar',
                'colaboradores.ver',
                'departamentos.ver',
            ],
        ],
        'Supervisor' => [
            'descripcion' => 'Consulta la operación y genera reportes.',
            'permisos' => [
                'colaboradores.ver',
                'departamentos.ver',
                'gafetes.ver',
                'periodos.ver',
                'pagos.ver',
                'reportes.ver',
                'reportes.generar',
            ],
        ],
        'Consulta' => [
            'descripcion' => 'Solo lectura.',
            'permisos' => [
                'colaboradores.ver',
                'departamentos.ver',
                'gafetes.ver',
                'periodos.ver',
                'pagos.ver',
                'reportes.ver',
            ],
        ],
    ];

    public function run(): void
    {
        $permisos = Permiso::pluck('id', 'clave');

        foreach (self::ROLES as $nombre => $config) {
            $rol = Rol::firstOrNew(['nombre' => $nombre]);
            $accesoTotal = $config['permisos'] === ['*'];

            // Ya existía: se respeta lo que haya configurado el administrador (§5.4).
            //
            // La excepción es el rol de acceso total: se vuelve a sincronizar en cada corrida
            // para que herede los permisos de los módulos nuevos. Si no, al agregar un módulo
            // nadie podría administrarlo hasta asignarle el permiso a mano.
            if ($rol->exists && ! $accesoTotal) {
                continue;
            }

            if (! $rol->exists) {
                $rol->fill(['descripcion' => $config['descripcion'], 'activo' => true])->save();
            }

            // El rol de acceso total queda protegido: la interfaz no debe permitir editarlo ni
            // desactivarlo, porque hacerlo dejaría la instalación sin quien pueda administrarla
            // y sin forma de arreglarlo desde la propia aplicación (§5.2).
            //
            // Se reafirma en cada corrida, no solo al crearlo: si alguien lo desprotegió a mano
            // en la base de datos, el siguiente despliegue lo devuelve a su sitio.
            if ($accesoTotal) {
                $rol->forceFill(['protegido' => true, 'activo' => true])->save();
            }

            $claves = $accesoTotal ? $permisos->keys()->all() : $config['permisos'];

            $rol->permisos()->sync($permisos->only($claves)->values()->all());
        }
    }
}
