<?php

namespace Database\Seeders;

use App\Models\Departamento;
use App\Models\Persona;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Cuenta administradora de arranque.
 *
 * Sin ella, un despliegue nuevo queda en punto muerto: las rutas exigen sesión y permisos
 * (§5.6), pero no habría ninguna cuenta con la cual entrar a crear la primera.
 *
 * La cuenta queda PROTEGIDA: la aplicación no deja dar de baja a su persona, suspenderla ni
 * cambiarle el rol, porque cualquiera de las tres deja la instalación sin quien la administre.
 *
 * Correr este seeder también es la vía de recuperación: si la cuenta quedó inutilizable por
 * cualquier motivo (un cambio directo en la base, por ejemplo), se reactivan su persona, su
 * cuenta y su rol. Lo que NUNCA toca de una cuenta existente es el correo y la contraseña:
 * esos los decide el administrador.
 */
class AdministradorSeeder extends Seeder
{
    public function run(): void
    {
        $rol = Rol::where('nombre', 'Administrador')->first();

        if ($rol === null) {
            $this->command?->warn('AdministradorSeeder: falta el rol Administrador. Corre RolSeeder primero.');

            return;
        }

        $cuenta = $this->cuentaExistente();

        if ($cuenta !== null) {
            $this->reafirmar($cuenta, $rol);

            return;
        }

        $this->crear($rol);
    }

    /**
     * Localiza la cuenta de arranque si ya existe.
     *
     * Por la bandera primero: si el administrador cambió su correo desde la aplicación, buscar
     * por el correo de la configuración no la encontraría y se crearía una segunda cuenta. Los
     * otros dos criterios cubren instalaciones anteriores a la bandera.
     */
    private function cuentaExistente(): ?User
    {
        return User::where('protegido', true)->first()
            ?? User::where('email', config('comedor.admin.email'))->first()
            ?? Persona::where('numero_empleado', config('comedor.admin.numero_empleado'))->first()?->cuenta;
    }

    /**
     * Devuelve la cuenta existente a un estado utilizable, sin tocar sus credenciales.
     */
    private function reafirmar(User $cuenta, Rol $rol): void
    {
        $cuenta->persona()->update(['estado' => 'ACTIVO']);

        $cuenta->forceFill([
            'protegido' => true,
            'activo' => true,
            'rol_id' => $rol->id,
        ])->save();

        $this->command?->info("AdministradorSeeder: la cuenta {$cuenta->email} ya existe; se reafirma como protegida y activa. Su correo y su contraseña no se tocan.");
    }

    private function crear(Rol $rol): void
    {
        $correo = (string) config('comedor.admin.email');

        // La persona es la identidad; la cuenta solo agrega el acceso (§3.1). El departamento
        // se crea si no está: en una instalación nueva el catálogo viene vacío.
        $departamento = Departamento::firstOrCreate(
            ['nombre' => config('comedor.admin.departamento')],
            ['activo' => true],
        );

        $persona = Persona::firstOrCreate(
            ['numero_empleado' => (string) config('comedor.admin.numero_empleado')],
            [
                'nombre' => config('comedor.admin.nombre'),
                'primer_apellido' => config('comedor.admin.apellido'),
                'departamento_id' => $departamento->id,
                'estado' => 'ACTIVO',
            ],
        );

        // Sin contraseña configurada se genera una y se imprime UNA sola vez. Es preferible a
        // dejar una por defecto en el repositorio, que sería la primera que probaría cualquiera.
        $contrasena = config('comedor.admin.password');
        $generada = blank($contrasena);

        if ($generada) {
            $contrasena = Str::password(16);
        }

        $cuenta = $persona->cuenta()->create([
            'email' => $correo,
            'password' => $contrasena,
            'rol_id' => $rol->id,
            'activo' => true,
        ]);

        // `protegido` no es asignable en masa: se fija aparte.
        $cuenta->forceFill(['protegido' => true])->save();

        $this->command?->newLine();
        $this->command?->info('Cuenta administradora creada:');
        $this->command?->line("  correo: {$correo}");

        if ($generada) {
            $this->command?->line("  contraseña: {$contrasena}");
            $this->command?->warn('  Anótala: no vuelve a mostrarse. Cámbiala al entrar.');
        } else {
            $this->command?->line('  contraseña: la de ADMIN_PASSWORD');
        }

        $this->command?->newLine();
    }
}
