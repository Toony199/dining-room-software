import { createRouter, createWebHistory } from 'vue-router'

import { useAuthStore } from './stores/auth.js'

// import Home from './pages/Home.vue'
import HomeIndex from './views/Home/HomeIndex.vue';
import DepartamentosIndex from './views/departamentos/DepartamentosIndex.vue';
import PersonasIndex from './views/personas/PersonasIndex.vue';
import RolesIndex from './views/roles/RolesIndex.vue';
import UsuariosIndex from './views/usuarios/UsuariosIndex.vue';
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
]

const router = createRouter({
    history: createWebHistory(),
    routes
})

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

    return true;
});

export default router
