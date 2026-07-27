import { createRouter, createWebHistory } from 'vue-router'

// import Home from './pages/Home.vue'
import HomeIndex from './views/Home/HomeIndex.vue'

const routes = [
    {
        path: '/',
        name: 'home',
        component: HomeIndex
    },
]

export default createRouter({
    history: createWebHistory(),
    routes
})
