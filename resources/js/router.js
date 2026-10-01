import { createRouter, createWebHistory } from 'vue-router'

import { useAuthStore } from './stores/auth.js'
import { modulos } from './modulos.js'

// import Home from './pages/Home.vue'
import HomeIndex from './views/Home/HomeIndex.vue';
import DepartamentosIndex from './views/departamentos/DepartamentosIndex.vue';
import PersonasIndex from './views/personas/PersonasIndex.vue';
import RolesIndex from './views/roles/RolesIndex.vue';
import UsuariosIndex from './views/usuarios/UsuariosIndex.vue';
import DisenoGafeteIndex from './views/gafetes/DisenoGafeteIndex.vue';
import TarifasIndex from './views/tarifas/TarifasIndex.vue';
import PeriodosIndex from './views/periodos/PeriodosIndex.vue';
import KioscoIndex from './views/kiosco/KioscoIndex.vue';
import CobroIndex from './views/cobro/CobroIndex.vue';
import ChecadorIndex from './views/checador/ChecadorIndex.vue';
import LoginIndex from './views/auth/LoginIndex.vue';

const routes = [
    {
        path: '/login',
        name: 'login',
        component: LoginIndex,
        meta: {
            title: 'Entrar',
            requiresAuth: false,
            // Sin sidebar ni breadcrumb (ver App.vue).
            layout: 'blank',
            permission: [],
        }
    },
    {
        path: '/',
        name: 'home',
        component: HomeIndex,
        meta: {
            title: '',
            requiresAuth: true,
            permission: [],
        }
    },
    {
        path: '/personas',
        name: 'PersonasIndex',
        component: PersonasIndex,
        meta: {
            title: 'Personas',
            requiresAuth: true,
            permission: [],
        }
    },
    {
        path: '/departamentos',
        name: 'DepartamentosIndex',
        component: DepartamentosIndex,
        meta: {
            title: 'Departamentos',
            requiresAuth: true,
            permission: [],
        }
    },
    {
        path: '/roles',
        name: 'RolesIndex',
        component: RolesIndex,
        meta: {
            title: 'Roles',
            requiresAuth: true,
            permission: [],
        }
    },
    {
        path: '/usuarios',
        name: 'UsuariosIndex',
        component: UsuariosIndex,
        meta: {
            title: 'Usuarios',
            requiresAuth: true,
            permission: [],
        }
    },
    {
        // Kiosco (§10). Sin sesión y sin menú: es una experiencia aislada, y desde aquí no se
        // llega a la administración (§10.2). Lo identifica el QR del gafete, no una cuenta.
        path: '/kiosco',
        name: 'KioscoIndex',
        component: KioscoIndex,
        meta: {
            title: 'Kiosco',
            // El equipo del kiosco entra con su propia cuenta (rol Kiosco). Lo que no exige es una
            // cuenta administrativa (§10.1): ese rol no abre ningún módulo.
            requiresAuth: true,
            // Sin barra lateral: desde el kiosco no se llega a la administración (§10.2).
            layout: 'blank',
            permission: [],
        }
    },
    {
        // Checador (§16). Como el kiosco: cuenta propia con permisos mínimos y pantalla aislada,
        // sin menú ni manera de llegar a la administración (§10.2).
        path: '/checador',
        name: 'ChecadorIndex',
        component: ChecadorIndex,
        meta: {
            title: 'Checador',
            requiresAuth: true,
            layout: 'blank',
            permission: [],
        }
    },
    {
        path: '/cobro',
        name: 'CobroIndex',
        component: CobroIndex,
        meta: {
            title: 'Caja',
            requiresAuth: true,
            permission: [],
        }
    },
    {
        path: '/periodos',
        name: 'PeriodosIndex',
        component: PeriodosIndex,
        meta: {
            title: 'Periodos',
            requiresAuth: true,
            permission: [],
        }
    },
    {
        path: '/tarifas',
        name: 'TarifasIndex',
        component: TarifasIndex,
        meta: {
            title: 'Precio del comedor',
            requiresAuth: true,
            permission: [],
        }
    },
    {
        path: '/gafetes/diseno',
        name: 'DisenoGafeteIndex',
        component: DisenoGafeteIndex,
        meta: {
            title: 'Diseño del gafete',
            requiresAuth: true,
            permission: [],
        }
    },
]

const router = createRouter({
    history: createWebHistory(),
    routes
})

/**
 * Para las cuentas de una sola pantalla —el kiosco del pasillo, el checador de la entrada— esa
 * pantalla es su inicio: el panel de módulos les saldría vacío, porque su rol no abre ninguno.
 *
 * Devuelve la ruta a la que mandarlas, o null si la sesión abre algún módulo administrativo.
 */
function pantallaUnica() {
    const auth = useAuthStore();

    const abreOtroModulo = (propias) => modulos.some(
        (modulo) => !propias.includes(modulo.permiso) && auth.tienePermiso(modulo.permiso)
    );

    if (auth.tienePermiso('kiosco.operar') && !abreOtroModulo(['kiosco.operar'])) {
        return '/kiosco';
    }

    if (auth.tienePermiso('consumo.validar') && !abreOtroModulo(['consumo.validar'])) {
        return '/checador';
    }

    return null;
}

/**
 * Guard de sesión.
 *
 * Esto es comodidad, no seguridad: cualquiera puede saltárselo con las herramientas del
 * navegador. Lo que protege de verdad son los permisos que el backend comprueba en cada
 * operación (§5.6).
 */
router.beforeEach(async (to) => {
    const auth = useAuthStore();

    // Primera navegación de la sesión: hay que preguntarle al backend si la cookie sigue
    // valiendo antes de decidir a dónde mandar. Solo ocurre una vez por carga de la página.
    if (!auth.comprobado) {
        await auth.cargarSesion();
    }

    if (to.meta.requiresAuth && !auth.autenticado) {
        // Se recuerda a dónde iba para devolverlo ahí después de entrar.
        return { name: 'login', query: to.fullPath === '/' ? {} : { redirect: to.fullPath } };
    }

    // Quien ya tiene sesión no necesita volver a ver el formulario.
    if (to.name === 'login' && auth.autenticado) {
        return { path: '/' };
    }

    // Las cuentas de una sola pantalla entran directo a la suya.
    if (to.path === '/' && auth.autenticado) {
        const propia = pantallaUnica();

        if (propia) {
            return { path: propia };
        }
    }

    return true;
});

export default router
