import { ref } from 'vue';
import axios from 'axios';

export function useDepartamentosApi() {
    // state
    const loading = ref(false);
    const departamentos = ref([]);
    const errores = ref({});

    // helpers
    const limpiarErrores = () => { errores.value = {}; };

    const manejarError = (error) => {
        if (error.response && error.response.status === 422) {
            errores.value = error.response.data.errors ?? {};
        } else {
            console.error(error);
        }
    };

    // API Methods

    const fetchDepartamentos = async () => {
        try {
            loading.value = true;
            const response = await axios.get('/api/departamentos');
            departamentos.value = response.data;
        } catch (error) {
            manejarError(error);
        } finally {
            loading.value = false;
        }
    };

    const createDepartamento = async (payload) => {
        limpiarErrores();
        try {
            loading.value = true;
            await axios.post('/api/departamentos', payload);
            await fetchDepartamentos();
            return true;
        } catch (error) {
            manejarError(error);
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
            return true;
        } catch (error) {
            manejarError(error);
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
        } catch (error) {
            manejarError(error);
        } finally {
            loading.value = false;
        }
    };

    const activarDepartamento = async (id) => {
        try {
            loading.value = true;
            await axios.patch(`/api/departamentos/${id}/activar`);
            await fetchDepartamentos();
        } catch (error) {
            manejarError(error);
        } finally {
            loading.value = false;
        }
    };

    return {
        // states
        loading,
        departamentos,
        errores,

        // métodos
        fetchDepartamentos,
        createDepartamento,
        updateDepartamento,
        desactivarDepartamento,
        activarDepartamento,
        limpiarErrores,
    };
}
