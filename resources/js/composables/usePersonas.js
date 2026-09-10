import { reactive, ref } from 'vue';
import axios from 'axios';
import { toast } from 'vue-sonner';

export function usePersonas() {
    // state
    const loading = ref(false);
    const personas = ref([]);
    const errores = ref({});

    // Catálogos para los <select> del formulario. Se cargan aparte de la tabla porque
    // no dependen de los filtros ni de la página en la que esté el usuario.
    const departamentos = ref([]);

    // Roles activos, para el bloque de cuenta de sistema del alta (§3.1, §5.1).
    const roles = ref([]);

    // Metadatos que devuelve el bloque `meta` del paginador de Laravel.
    const paginacion = reactive({
        current_page: 1,
        last_page: 1,
        per_page: 10,
        total: 0,
    });

    // Filtros que viajan como query params a GET /api/personas.
    // `estado` y `departamento_id` en '' significan "sin filtrar" (administración ve todo).
    const filtros = reactive({
        q: '',
        estado: '',
        departamento_id: '',
        per_page: 10,
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

    const fetchPersonas = async (page = paginacion.current_page) => {
        try {
            loading.value = true;
            const { data } = await axios.get('/api/personas', {
                params: {
                    page,
                    per_page: filtros.per_page,
                    // Se omiten los filtros vacíos para no mandar `q=&estado=`.
                    ...(filtros.q ? { q: filtros.q } : {}),
                    ...(filtros.estado !== '' ? { estado: filtros.estado } : {}),
                    ...(filtros.departamento_id !== '' ? { departamento_id: filtros.departamento_id } : {}),
                },
            });

            personas.value = data.data;
            Object.assign(paginacion, data.meta);
        } catch (error) {
            manejarError(error, 'No se pudieron cargar las personas.');
        } finally {
            loading.value = false;
        }
    };

    /**
     * Departamentos que pueden ofrecerse para adscribir personal: solo los activos (§3.4).
     * `per_page=100` es el tope que admite la API; el catálogo es corto y cabe de una vez,
     * así el <select> no necesita paginar.
     */
    const fetchDepartamentos = async () => {
        try {
            const { data } = await axios.get('/api/departamentos', {
                params: { activo: 1, per_page: 100 },
            });

            departamentos.value = data.data;
        } catch (error) {
            manejarError(error, 'No se pudieron cargar los departamentos.');
        }
    };

    /**
     * Roles que pueden asignarse a una cuenta nueva: solo los activos (§5.5). Los da el
     * catálogo del formulario de cuentas, que no exige `roles.ver`.
     */
    const fetchRoles = async () => {
        try {
            // Catálogo propio del formulario de cuentas: no exige `roles.ver`, que es para ver
            // cómo está configurado cada rol, no para elegir uno.
            const { data } = await axios.get('/api/usuarios/roles-asignables');

            roles.value = data.data;
        } catch (error) {
            manejarError(error, 'No se pudieron cargar los roles.');
        }
    };

    // Tras crear o filtrar hay que volver a la página 1: el registro nuevo puede caer
    // en cualquier página por el orden alfabético, y la página actual puede dejar de existir.
    const irAPrimeraPagina = () => fetchPersonas(1);

    const createPersona = async (payload) => {
        limpiarErrores();
        try {
            loading.value = true;
            await axios.post('/api/personas', payload);
            await irAPrimeraPagina();
            toast.success('Persona registrada.');

            return true;
        } catch (error) {
            manejarError(error, 'No se pudo registrar la persona.');

            return false;
        } finally {
            loading.value = false;
        }
    };

    const updatePersona = async (id, payload) => {
        limpiarErrores();
        try {
            loading.value = true;
            await axios.put(`/api/personas/${id}`, payload);
            await fetchPersonas();
            toast.success('Persona actualizada.');

            return true;
        } catch (error) {
            manejarError(error, 'No se pudo actualizar la persona.');

            return false;
        } finally {
            loading.value = false;
        }
    };

    // Baja lógica (§3.3): la persona conserva su historial y su número de empleado.
    const desactivarPersona = async (id) => {
        try {
            loading.value = true;
            await axios.patch(`/api/personas/${id}/desactivar`);
            await fetchPersonas();
            toast.success('Persona desactivada.');
        } catch (error) {
            // 422: la persona tiene la cuenta administradora del sistema. No hay campo de
            // formulario donde pintarlo, así que va a un toast con el motivo del servidor.
            if (error.response?.status === 422) {
                toast.error('No se pudo desactivar la persona.', {
                    description: error.response.data.message,
                });

                return;
            }

            manejarError(error, 'No se pudo desactivar la persona.');
        } finally {
            loading.value = false;
        }
    };

    const activarPersona = async (id) => {
        try {
            loading.value = true;
            await axios.patch(`/api/personas/${id}/activar`);
            await fetchPersonas();
            toast.success('Persona activada.');
        } catch (error) {
            manejarError(error, 'No se pudo activar la persona.');
        } finally {
            loading.value = false;
        }
    };

    return {
        // states
        loading,
        personas,
        departamentos,
        roles,
        errores,
        paginacion,
        filtros,

        // métodos
        fetchPersonas,
        fetchDepartamentos,
        fetchRoles,
        irAPrimeraPagina,
        createPersona,
        updatePersona,
        desactivarPersona,
        activarPersona,
        limpiarErrores,
    };
}
