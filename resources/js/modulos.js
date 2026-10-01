import { Banknote, CalendarRange, Coins, KeyRound, Landmark, Palette, ScanLine, ShieldCheck, UtensilsCrossed, Users } from '@lucide/vue';

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
        title: 'Kiosco',
        url: '/kiosco',
        icon: ScanLine,
        permiso: 'kiosco.operar',
        descripcion: 'Pantalla donde los colaboradores escanean su gafete y piden sus días.',
    },
    {
        title: 'Checador',
        url: '/checador',
        icon: UtensilsCrossed,
        permiso: 'consumo.validar',
        descripcion: 'Entrada del comedor: valida si el gafete tiene derecho a comer hoy.',
    },
    {
        title: 'Caja',
        url: '/cobro',
        icon: Banknote,
        permiso: 'fichas.ver',
        descripcion: 'Cobro de las fichas, comprobantes y corte del día.',
    },
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
        title: 'Periodos',
        url: '/periodos',
        icon: CalendarRange,
        permiso: 'periodos.ver',
        descripcion: 'Semanas de servicio, sus días y la ventana para generar fichas y pagar.',
    },
    {
        title: 'Precio del comedor',
        url: '/tarifas',
        icon: Coins,
        permiso: 'tarifas.ver',
        descripcion: 'Precio por día con el que se arman los periodos de servicio.',
    },
    {
        title: 'Diseño del gafete',
        url: '/gafetes/diseno',
        icon: Palette,
        permiso: 'gafetes.disenar',
        descripcion: 'Diseño con el que se ven e imprimen todos los gafetes.',
    },
];
