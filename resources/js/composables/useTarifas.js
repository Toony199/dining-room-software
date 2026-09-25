import { reactive, ref } from 'vue';
import axios from 'axios';
import { toast } from 'vue-sonner';

/**
 * Precio por día del comedor (§6.4, §18).
 *
 * Solo consultar y registrar: una tarifa no se edita ni se da de baja, porque es el respaldo de lo
 * que ya se cobró. Cambiar el precio es registrar otro, y el anterior se cierra el día previo.
 */
export function useTarifas() {
    const loading = ref(false);
    const tarifas = ref([]);
    const vigente = ref(null);
    const errores = ref({});

    const paginacion = reactive({
        current_page: 1,
        last_page: 1,
        per_page: 10,
        total: 0,
    });

    const limpiarErrores = () => { errores.value = {}; };

    // 422 → errores de validación, que se pintan junto al campo. El resto va a un toast; 401 y 403
    // los explica el interceptor global (ver interceptores.js).
    const manejarError = (error, mensaje = 'No se pudo completar la operación.') => {
        const status = error.response?.status;

        if (status === 422) {
            errores.value = error.response.data.errors ?? {};

            return;
        }

        if (status === 401 || status === 403) {
            return;
        }

        console.error(error);
        toast.error(mensaje, { description: error.response?.data?.message ?? error.message });
    };

    const fetchTarifas = async (page = paginacion.current_page) => {
        try {
            loading.value = true;
            const { data } = await axios.get('/api/tarifas', { params: { page, per_page: paginacion.per_page } });
            tarifas.value = data.data;
            Object.assign(paginacion, data.meta);
        } catch (error) {
            manejarError(error, 'No se pudo cargar el historial de precios.');
        } finally {
            loading.value = false;
        }
    };

    /** El precio que rige hoy, o null si todavía no se ha registrado ninguno. */
    const fetchVigente = async () => {
        try {
            const { data } = await axios.get('/api/tarifas/vigente');
            vigente.value = data.data;
        } catch (error) {
            manejarError(error, 'No se pudo cargar el precio vigente.');
        }
    };

    /** Registra un precio. Devuelve la tarifa creada, o null si la validación la rechazó. */
    const createTarifa = async (payload) => {
        limpiarErrores();

        try {
            loading.value = true;
            const { data } = await axios.post('/api/tarifas', payload);
            await Promise.all([fetchTarifas(1), fetchVigente()]);
            toast.success(
                data.data.programada ? 'Precio programado.' : 'Precio registrado.',
                { description: data.data.programada ? `Entra en vigor el ${data.data.vigente_desde}.` : 'Rige a partir de hoy.' },
            );

            return data.data;
        } catch (error) {
            manejarError(error, 'No se pudo registrar el precio.');

            return null;
        } finally {
            loading.value = false;
        }
    };

    /** Cancela un precio que todavía no entra en vigor. */
    const cancelarTarifa = async (tarifa) => {
        try {
            loading.value = true;
            await axios.delete(`/api/tarifas/${tarifa.id}`);
            await Promise.all([fetchTarifas(1), fetchVigente()]);
            toast.success('Se canceló el precio programado.');

            return true;
        } catch (error) {
            manejarError(error, 'No se pudo cancelar el precio.');

            return false;
        } finally {
            loading.value = false;
        }
    };

    return {
        loading,
        tarifas,
        vigente,
        errores,
        paginacion,
        fetchTarifas,
        fetchVigente,
        createTarifa,
        cancelarTarifa,
        limpiarErrores,
    };
}
