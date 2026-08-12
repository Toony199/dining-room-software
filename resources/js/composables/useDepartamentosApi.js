import { reactive, ref } from 'vue';
import axios from 'axios';
import { toast } from 'vue-sonner';

export function useDepartamentosApi() {
    // state
    const loading = ref(false);
    const departamentos = ref([]);
    const errores = ref({});

    // Metadatos que devuelve el bloque `meta` del paginador de Laravel.
    const paginacion = reactive({
        current_page: 1,
        last_page: 1,
        per_page: 10,
        total: 0,
    });

    // Filtros que viajan como query params a GET /api/departamentos.
    // `activo` en '' significa "sin filtrar" (administración ve activos e inactivos).
    const filtros = reactive({
        q: '',
        activo: '',
        per_page: 10,
    });

    // helpers
    const limpiarErrores = () => { errores.value = {}; };

    // 422 → errores de validación, se pintan junto a cada campo del formulario.
    // Cualquier otro fallo (500, red caída, 404) no tiene campo donde mostrarse:
    // va a un toast para que el usuario no se quede esperando sin explicación.
    const manejarError = (error, mensaje = 'No se pudo completar la operación.') => {
        if (error.response?.status === 422) {
            errores.value = error.response.data.errors ?? {};

            return;
        }

        console.error(error);
        toast.error(mensaje, {
            description: error.response?.data?.message ?? error.message,
        });
    };

    // API Methods

    const fetchDepartamentos = async (page = paginacion.current_page) => {
        try {
            loading.value = true;
            const { data } = await axios.get('/api/departamentos', {
                params: {
                    page,
                    per_page: filtros.per_page,
                    // Se omiten los filtros vacíos para no mandar `q=&activo=`.
                    ...(filtros.q ? { q: filtros.q } : {}),
                    ...(filtros.activo !== '' ? { activo: filtros.activo } : {}),
                },
            });

            departamentos.value = data.data;
            Object.assign(paginacion, data.meta);
        } catch (error) {
            manejarError(error, 'No se pudieron cargar los departamentos.');
        } finally {
            loading.value = false;
        }
    };

    // Tras crear o filtrar hay que volver a la página 1: el registro nuevo puede caer
    // en cualquier página por el orden alfabético, y la página actual puede dejar de existir.
    const irAPrimeraPagina = () => fetchDepartamentos(1);

    const createDepartamento = async (payload) => {
        limpiarErrores();
        try {
            loading.value = true;
            await axios.post('/api/departamentos', payload);
            await irAPrimeraPagina();
            toast.success('Departamento creado.');

            return true;
        } catch (error) {
            manejarError(error, 'No se pudo crear el departamento.');

            return false;
        } finally {
            loading.value = false;
        }
    };

    const updateDepartamento = async (id, payload) => {
        limpiarErrores();
        try {
            loading.value = true;
            await axios.put(`/api/departamentos/${id}`, payload);
            await fetchDepartamentos();
            toast.success('Departamento actualizado.');

            return true;
        } catch (error) {
            manejarError(error, 'No se pudo actualizar el departamento.');

            return false;
        } finally {
            loading.value = false;
        }
    };

    const desactivarDepartamento = async (id) => {
        try {
            loading.value = true;
            await axios.patch(`/api/departamentos/${id}/desactivar`);
            await fetchDepartamentos();
            toast.success('Departamento desactivado.');
        } catch (error) {
            manejarError(error, 'No se pudo desactivar el departamento.');
        } finally {
            loading.value = false;
        }
    };

    const activarDepartamento = async (id) => {
        try {
            loading.value = true;
            await axios.patch(`/api/departamentos/${id}/activar`);
            await fetchDepartamentos();
            toast.success('Departamento activado.');
        } catch (error) {
            manejarError(error, 'No se pudo activar el departamento.');
        } finally {
            loading.value = false;
        }
    };

    return {
        // states
        loading,
        departamentos,
        errores,
        paginacion,
        filtros,

        // métodos
        fetchDepartamentos,
        irAPrimeraPagina,
        createDepartamento,
        updateDepartamento,
        desactivarDepartamento,
        activarDepartamento,
        limpiarErrores,
    };
}
