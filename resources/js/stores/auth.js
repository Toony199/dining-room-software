import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import axios from 'axios';

/**
 * Sesión de la cuenta de sistema (§3.1).
 *
 * No guarda credenciales ni token: la sesión vive en una cookie httpOnly que el navegador
 * manda sola. Este store solo recuerda *quién* está dentro, para que el router y la interfaz
 * no tengan que preguntarlo en cada navegación.
 */
export const useAuthStore = defineStore('auth', () => {
    const usuario = ref(null);

    // null = todavía no se ha preguntado al backend. Distinto de "no hay sesión", y por eso el
    // guard del router espera a la primera comprobación antes de decidir a dónde mandar.
    const comprobado = ref(false);
    const cargando = ref(false);

    const autenticado = computed(() => usuario.value !== null);
    const permisos = computed(() => usuario.value?.permisos ?? []);
    const nombre = computed(() => usuario.value?.persona?.nombre_completo ?? usuario.value?.email ?? '');

    /**
     * ¿La sesión otorga este permiso?
     *
     * Sirve para no ofrecer botones que van a fallar. NO es seguridad: ocultar un botón no
     * protege nada, y el backend comprueba el permiso en cada operación (§5.6).
     */
    const tienePermiso = (clave) => permisos.value.includes(clave);

    /**
     * Pregunta al backend si hay sesión. Un 401 es una respuesta normal aquí, no un fallo:
     * significa "no hay nadie dentro".
     */
    const cargarSesion = async () => {
        try {
            cargando.value = true;
            const { data } = await axios.get('/api/me');
            usuario.value = data.data;
        } catch (error) {
            usuario.value = null;

            if (error.response?.status !== 401) {
                console.error(error);
            }
        } finally {
            comprobado.value = true;
            cargando.value = false;
        }
    };

    /**
     * Inicia sesión. Devuelve los errores de validación (422) en vez de lanzarlos, para que la
     * vista los pinte junto al campo; el resto de fallos sí se propagan.
     */
    const login = async (credenciales) => {
        cargando.value = true;

        try {
            // Sanctum entrega la cookie XSRF-TOKEN aquí; sin ella el POST siguiente daría 419.
            await axios.get('/sanctum/csrf-cookie');

            const { data } = await axios.post('/api/login', credenciales);
            usuario.value = data.data;
            comprobado.value = true;

            return { ok: true, errores: {} };
        } catch (error) {
            if (error.response?.status === 422) {
                return { ok: false, errores: error.response.data.errors ?? {} };
            }

            throw error;
        } finally {
            cargando.value = false;
        }
    };

    /**
     * Cierra la sesión. El estado local se limpia pase lo que pase: si la petición falló porque
     * la sesión ya había expirado, dejar al usuario "dentro" en la interfaz sería peor.
     */
    const logout = async () => {
        try {
            cargando.value = true;
            await axios.post('/api/logout');
        } catch (error) {
            console.error(error);
        } finally {
            usuario.value = null;
            comprobado.value = true;
            cargando.value = false;
        }
    };

    return {
        usuario,
        comprobado,
        cargando,
        autenticado,
        permisos,
        nombre,
        tienePermiso,
        cargarSesion,
        login,
        logout,
    };
});
