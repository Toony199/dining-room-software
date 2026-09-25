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
 * ¿La sesión solo sirve para operar el kiosco? Es el caso del equipo del pasillo: su rol tiene
 * `kiosco.operar` y ningún permiso de módulo administrativo.
 */
function soloOperaElKiosco() {
    const auth = useAuthStore();

    return auth.tienePermiso('kiosco.operar')
        && !modulos.some((modulo) => modulo.permiso !== 'kiosco.operar' && auth.tienePermiso(modulo.permiso));
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

    // Para la cuenta del kiosco, el kiosco *es* la pantalla de inicio: el panel de módulos le
    // saldría vacío, porque su rol no abre ninguno.
    if (to.path === '/' && auth.autenticado && soloOperaElKiosco()) {
        return { path: '/kiosco' };
    }

    return true;
});

export default router
