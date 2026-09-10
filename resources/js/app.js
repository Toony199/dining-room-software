import { createApp } from 'vue'
import { createPinia } from 'pinia'
import './bootstrap'
import App from './App.vue'
import router from './router'
import { registrarInterceptores } from './interceptores'

import '../css/app.css'
import 'vue-sonner/style.css'

const app = createApp(App)
    .use(createPinia())
    .use(router);

// Después de Pinia y el router: el interceptor necesita el store de sesión y navegar.
registrarInterceptores(router);

app.mount('#app');
