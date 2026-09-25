<?php

namespace Tests;

use App\Models\Departamento;
use App\Models\Permiso;
use App\Models\Persona;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Nombre del rol y del departamento que crea `actuandoComo`. La Z inicial los manda al
     * final de cualquier orden alfabético.
     */
    protected const NOMBRE_DE_SESION = 'ZZ Sesión de pruebas';

    /**
     * Apellido de la persona de la sesión. Va aparte porque `personas.primer_apellido` es
     * varchar(15) y no cabe el nombre largo.
     */
    protected const APELLIDO_DE_SESION = 'ZZSesion';

    /**
     * Correo de la cuenta de la sesión. Fijo y ordenando al final, por lo mismo que el rol y el
     * departamento: el de la factory es aleatorio y de vez en cuando ordenaba antes que los correos
     * que las pruebas dan por primeros. Con dominio propio, además, para que no lo encuentren las
     * pruebas que buscan por texto (varias buscan "arod").
     */
    protected const CORREO_DE_SESION = 'zzsesion@zzsesion.test';

    /**
     * Filas que `actuandoComo` añade a cada tabla. Las pruebas que cuentan registros las
     * descuentan en vez de fingir que las tablas empiezan vacías.
     */
    protected const REGISTROS_DE_SESION = 1;

    /**
     * Autentica una cuenta que tiene exactamente los permisos indicados (§5.6).
     *
     * Las rutas exigen sesión y permiso, así que casi toda prueba de endpoint necesita una
     * cuenta detrás. Dar solo los permisos que la prueba usa no es una formalidad: si el
     * helper concediera todo, un endpoint protegido con la clave equivocada pasaría igual y
     * la suite no notaría nada.
     */
    protected function actuandoComo(string ...$permisos): User
    {
        // Nombres fijos y que ordenan al final: la sesión deja su propio rol, departamento y
        // persona en las tablas, y las pruebas que comprueban orden alfabético no deberían
        // depender de qué palabras aleatorias le tocaron a la factory.
        $rol = Rol::factory()->create(['nombre' => self::NOMBRE_DE_SESION]);

        $rol->permisos()->sync(
            collect($permisos)
                ->map(fn (string $clave) => Permiso::firstOrCreate(
                    ['clave' => $clave],
                    ['modulo' => str_contains($clave, '.') ? strtok($clave, '.') : $clave],
                )->id)
                ->all()
        );

        $cuenta = User::factory()->create([
            'persona_id' => Persona::factory()->create([
                'nombre' => 'Sesión',
                'primer_apellido' => self::APELLIDO_DE_SESION,
                // Sin segundo apellido y con número fijo: los de la factory son aleatorios y
                // pueden contener el término que busca una prueba, haciéndola intermitente.
                'segundo_apellido' => null,
                'numero_empleado' => 'ZZ-SESION',
                'departamento_id' => Departamento::factory()->create(['nombre' => self::NOMBRE_DE_SESION])->id,
            ])->id,
            'rol_id' => $rol->id,
            'email' => self::CORREO_DE_SESION,
        ]);

        $this->actingAs($cuenta);

        return $cuenta;
    }
}
