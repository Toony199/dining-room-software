import { createRouter, createWebHistory } from 'vue-router'

// import Home from './pages/Home.vue'
import HomeIndex from './views/Home/HomeIndex.vue';
import DepartamentosIndex from './views/departamentos/DepartamentosIndex.vue';

const routes = [
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
        path: '/departamentos',
        name: 'DepartamentosIndex',
        component: DepartamentosIndex,
        meta: {
            title: 'Departamentos',
            requiresAuth: true,
            permission: [],
        }
    },
]

export default createRouter({
    history: createWebHistory(),
    routes
})
