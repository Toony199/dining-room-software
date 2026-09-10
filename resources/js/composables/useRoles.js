import { computed, reactive, ref } from 'vue';
import axios from 'axios';
import { toast } from 'vue-sonner';

export function useRoles() {
    // state
    const loading = ref(false);
    const roles = ref([]);
    const errores = ref({});

    // Catálogo completo de permisos (§5.3). No se pagina: la pantalla de asignación lo
    // necesita entero para pintar las casillas.
    const permisos = ref([]);

    // Metadatos que devuelve el bloque `meta` del paginador de Laravel.
    const paginacion = reactive({
        current_page: 1,
        last_page: 1,
        per_page: 10,
        total: 0,
    });

    // Filtros que viajan como query params a GET /api/roles.
    const filtros = reactive({
        q: '',
        activo: '',
        per_page: 10,
    });

    // Los permisos se muestran agrupados por módulo, que es como los agrupa §5.3.
    const permisosPorModulo = computed(() => {
        const grupos = new Map();

        for (const permiso of permisos.value) {
            if (!grupos.has(permiso.modulo)) {
                grupos.set(permiso.modulo, []);
            }
            grupos.get(permiso.modulo).push(permiso);
        }

        return [...grupos.entries()].map(([modulo, lista]) => ({ modulo, permisos: lista }));
    });

    // helpers
    const limpiarErrores = () => { errores.value = {}; };

    // 422 → errores de validación, se pintan junto a cada campo del formulario.
    // Cualquier otro fallo (500, red caída, 404) no tiene campo donde mostrarse:
    // va a un toast para que el usuario no se quede esperando sin explicación.
    const manejarError = (error, mensaje = 'No se pudo completar la operación.') => {
        const status = error.response?.status;

        if (status === 422) {
            errores.value = error.response.data.errors ?? {};

            return;
        }

        // 401 y 403 los explica el interceptor global (ver interceptores.js). Avisar aquí
        // también apilaría dos toasts sobre el mismo suceso.
        if (status === 401 || status === 403) {
            return;
        }

        console.error(error);
        toast.error(mensaje, {
            description: error.response?.data?.message ?? error.message,
        });
    };

    // API Methods

    const fetchRoles = async (page = paginacion.current_page) => {
        try {
            loading.value = true;
            const { data } = await axios.get('/api/roles', {
                params: {
                    page,
                    per_page: filtros.per_page,
                    // Se omiten los filtros vacíos para no mandar `q=&activo=`.
                    ...(filtros.q ? { q: filtros.q } : {}),
                    ...(filtros.activo !== '' ? { activo: filtros.activo } : {}),
                },
            });

            roles.value = data.data;
            Object.assign(paginacion, data.meta);
        } catch (error) {
            manejarError(error, 'No se pudieron cargar los roles.');
        } finally {
            loading.value = false;
        }
    };

    const fetchPermisos = async () => {
        try {
            const { data } = await axios.get('/api/permisos');

            permisos.value = data.data;
        } catch (error) {
            manejarError(error, 'No se pudo cargar el catálogo de permisos.');
        }
    };

    // El index no trae los permisos de cada rol (solo el conteo): el formulario de edición
    // los pide por rol para precargar las casillas.
    const fetchRol = async (id) => {
        try {
            loading.value = true;
            const { data } = await axios.get(`/api/roles/${id}`);

            return data.data;
        } catch (error) {
            manejarError(error, 'No se pudo cargar el rol.');

            return null;
        } finally {
            loading.value = false;
        }
    };

    // Tras crear o filtrar hay que volver a la página 1: el registro nuevo puede caer
    // en cualquier página por el orden alfabético, y la página actual puede dejar de existir.
    const irAPrimeraPagina = () => fetchRoles(1);

    const createRol = async (payload) => {
        limpiarErrores();
        try {
            loading.value = true;
            await axios.post('/api/roles', payload);
            await irAPrimeraPagina();
            toast.success('Rol creado.');

            return true;
        } catch (error) {
            manejarError(error, 'No se pudo crear el rol.');

            return false;
        } finally {
            loading.value = false;
        }
    };

    const updateRol = async (id, payload) => {
        limpiarErrores();
        try {
            loading.value = true;
            await axios.put(`/api/roles/${id}`, payload);
            await fetchRoles();
            toast.success('Rol actualizado.');

            return true;
        } catch (error) {
            manejarError(error, 'No se pudo actualizar el rol.');

            return false;
        } finally {
            loading.value = false;
        }
    };

    /**
     * Baja lógica del rol (§5.5). El backend responde 422 si el rol tiene usuarios asignados;
     * ese caso no tiene campo de formulario donde pintarse, así que va a un toast con el
     * mensaje del servidor, que ya dice cuántos usuarios hay que reasignar.
     */
    const desactivarRol = async (id) => {
        try {
            loading.value = true;
            await axios.patch(`/api/roles/${id}/desactivar`);
            await fetchRoles();
            toast.success('Rol desactivado.');

            return true;
        } catch (error) {
            if (error.response?.status === 422) {
                toast.error('No se pudo desactivar el rol.', {
                    description: error.response.data.message,
                });

                return false;
            }

            manejarError(error, 'No se pudo desactivar el rol.');

            return false;
        } finally {
            loading.value = false;
        }
    };

    const activarRol = async (id) => {
        try {
            loading.value = true;
            await axios.patch(`/api/roles/${id}/activar`);
            await fetchRoles();
            toast.success('Rol activado.');
        } catch (error) {
            manejarError(error, 'No se pudo activar el rol.');
        } finally {
            loading.value = false;
        }
    };

    return {
        // states
        loading,
        roles,
        permisos,
        permisosPorModulo,
        errores,
        paginacion,
        filtros,

        // métodos
        fetchRoles,
        fetchRol,
        fetchPermisos,
        irAPrimeraPagina,
        createRol,
        updateRol,
        desactivarRol,
        activarRol,
        limpiarErrores,
    };
}
