import { KeyRound, Landmark, Palette, ShieldCheck, Users } from '@lucide/vue';

/**
 * Módulos administrativos y el permiso que abre cada uno (§5.3).
 *
 * Una sola lista para el menú lateral y la página de inicio: si cada vista declarara la suya,
 * tarde o temprano una ofrecería un módulo que la otra ya no.
 *
 * `permiso` solo decide qué se ofrece en la interfaz. Lo que protege de verdad es la
 * comprobación que hace el backend en cada ruta (§5.6).
 */
export const modulos = [
    {
        title: 'Colaboradores',
        url: '/personas',
        icon: Users,
        permiso: 'colaboradores.ver',
        descripcion: 'Alta, edición y baja lógica del personal.',
    },
    {
        title: 'Departamentos',
        url: '/departamentos',
        icon: Landmark,
        permiso: 'departamentos.ver',
        descripcion: 'Catálogo de departamentos al que se adscribe el personal.',
    },
    {
        title: 'Roles',
        url: '/roles',
        icon: ShieldCheck,
        permiso: 'roles.ver',
        descripcion: 'Roles y los permisos que otorga cada uno.',
    },
    {
        title: 'Usuarios',
        url: '/usuarios',
        icon: KeyRound,
        permiso: 'usuarios.ver',
        descripcion: 'Cuentas de acceso a los módulos administrativos.',
    },
    {
        title: 'Diseño del gafete',
        url: '/gafetes/diseno',
        icon: Palette,
        permiso: 'gafetes.disenar',
        descripcion: 'Diseño con el que se ven e imprimen todos los gafetes.',
    },
];
