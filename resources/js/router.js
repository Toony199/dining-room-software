import { createRouter, createWebHistory } from 'vue-router'

// import Home from './pages/Home.vue'
import HomeIndex from './views/Home/HomeIndex.vue';
import DepartamentosIndex from './views/departamentos/DepartamentosIndex.vue';

const routes = [
    {
        path: '/',
        name: 'home',
        component: HomeIndex
    },
    {
        path: '/departamentos',
        name: 'DepartamentosIndex',
        component: DepartamentosIndex
    },
]

export default createRouter({
    history: createWebHistory(),
    routes
})
