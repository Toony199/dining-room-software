import axios from 'axios';
import { toast } from 'vue-sonner';

import { useAuthStore } from '@/stores/auth.js';

/**
 * Respuestas de autorización que ningún formulario sabe pintar.
 *
 * Se registra desde app.js, cuando Pinia y el router ya están instalados: hacerlo en
 * bootstrap.js crearía una importación circular con el store.
 */
export function registrarInterceptores(router) {
    axios.interceptors.response.use(null, (error) => {
        const status = error.response?.status;
        const url = error.config?.url ?? '';

        // 401 en /api/me es la forma normal de preguntar "¿hay sesión?": no es un fallo y no
        // debe sacar a nadie de ningún sitio.
        if (status === 401 && !url.endsWith('/api/me')) {
            const auth = useAuthStore();
            auth.usuario = null;

            if (router.currentRoute.value.name !== 'login') {
                toast.warning('Tu sesión expiró.', {
                    description: 'Vuelve a entrar para continuar.',
                });

                router.replace({
                    name: 'login',
                    query: { redirect: router.currentRoute.value.fullPath },
                });
            }
        }

        // 403 es el backend haciendo su trabajo (§5.6). Llega cuando la interfaz ofreció algo
        // que el rol no permite, o cuando alguien cambió los permisos a media sesión.
        if (status === 403) {
            toast.error('No tienes permiso para esta acción.', {
                description: 'Pídele a un administrador que revise tu rol.',
            });
        }

        return Promise.reject(error);
    });
}
