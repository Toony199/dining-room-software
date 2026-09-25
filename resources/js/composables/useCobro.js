import { reactive, ref } from 'vue';
import axios from 'axios';
import { toast } from 'vue-sonner';

/**
 * Caja: buscar la ficha, ajustar sus días y confirmar el cobro (§12, §13, §14).
 *
 * El dinero se recibe fuera del sistema; aquí solo se registra y se valida el monto como un POS.
 */
export function useCobro() {
    const loading = ref(false);
    const ficha = ref(null);
    const errores = ref({});

    const pagos = ref([]);
    const corte = ref(null);
    const pendientes = ref([]);

    const filtrosPagos = reactive({ desde: '', hasta: '' });

    const limpiarErrores = () => { errores.value = {}; };

    // 422 → errores junto al campo. Las reglas del cobro (monto que no alcanza, ficha ya pagada)
    // llegan así y además se muestran en pantalla, porque el cobrador tiene a alguien enfrente.
    const manejarError = (error, mensaje = 'No se pudo completar la operación.') => {
        const status = error.response?.status;

        if (status === 422) {
            errores.value = error.response.data.errors ?? {};

            return;
        }

        if (status === 401 || status === 403) {
            return;
        }

        if (status !== 404) {
            console.error(error);
        }

        toast.error(mensaje, { description: error.response?.data?.message ?? error.message });
    };

    /** La ficha que trae la persona. Devuelve null si el folio no existe. */
    const buscarFicha = async (folio) => {
        limpiarErrores();

        try {
            loading.value = true;
            const { data } = await axios.get(`/api/fichas/${encodeURIComponent(folio)}`);
            ficha.value = data.data;

            return data.data;
        } catch (error) {
            ficha.value = null;

            if (error.response?.status === 404) {
                errores.value = { folio: ['No existe ninguna ficha con ese folio. Revisa que esté completo.'] };
            } else {
                manejarError(error, 'No se pudo buscar la ficha.');
            }

            return null;
        } finally {
            loading.value = false;
        }
    };

    /**
     * Las fichas de quien escaneó su gafete en la caja. Es lo habitual, porque el ticket casi nunca
     * se imprime y la persona llega con el gafete, no con el folio.
     *
     * Devuelve una lista: la regla de una sola ficha es por semana (§17.1), así que quien no pagó
     * una semana y pidió la siguiente tiene dos, y el cobrador debe poder ver ambas.
     */
    const buscarPorGafete = async (qrToken) => {
        limpiarErrores();

        try {
            loading.value = true;
            const { data } = await axios.get('/api/fichas/por-gafete', { params: { qr_token: qrToken } });

            return data.data;
        } catch (error) {
            ficha.value = null;
            manejarError(error, 'No se pudo buscar la ficha.');

            return [];
        } finally {
            loading.value = false;
        }
    };

    /** Nueva selección de días antes de cobrar (§13). */
    const cambiarDias = async (folio, dias) => {
        limpiarErrores();

        try {
            loading.value = true;
            const { data } = await axios.put(`/api/fichas/${encodeURIComponent(folio)}/dias`, { dias });
            ficha.value = data.data;
            toast.success('Días actualizados.');

            return data.data;
        } catch (error) {
            manejarError(error, 'No se pudieron cambiar los días.');

            return null;
        } finally {
            loading.value = false;
        }
    };

    /** Confirma el cobro. Devuelve el pago, que es el contenido del ticket. */
    const confirmarPago = async (folio, montoRecibido) => {
        limpiarErrores();

        try {
            loading.value = true;
            const { data } = await axios.post(`/api/fichas/${encodeURIComponent(folio)}/pago`, {
                monto_recibido: montoRecibido,
            });
            ficha.value = data.data.ficha;
            toast.success('Pago confirmado.', {
                description: Number(data.data.cambio) > 0 ? `Entrega $${data.data.cambio} de cambio.` : 'Sin cambio.',
            });

            return data.data;
        } catch (error) {
            manejarError(error, 'No se pudo confirmar el pago.');

            return null;
        } finally {
            loading.value = false;
        }
    };

    /** Pagos del rango (por omisión, hoy) con el corte por cobrador. */
    const fetchPagos = async () => {
        try {
            loading.value = true;
            const { data } = await axios.get('/api/pagos', {
                params: {
                    ...(filtrosPagos.desde ? { desde: filtrosPagos.desde } : {}),
                    ...(filtrosPagos.hasta ? { hasta: filtrosPagos.hasta } : {}),
                    per_page: 50,
                },
            });
            pagos.value = data.data;
            corte.value = data.meta.corte;
        } catch (error) {
            manejarError(error, 'No se pudieron cargar los pagos.');
        } finally {
            loading.value = false;
        }
    };

    /** Fichas que siguen sin pagar: es lo que se revisa antes de que cierre la semana. */
    const fetchPendientes = async () => {
        try {
            const { data } = await axios.get('/api/fichas', { params: { estado: 'PENDIENTE', per_page: 50 } });
            pendientes.value = data.data;
        } catch (error) {
            manejarError(error, 'No se pudieron cargar las fichas pendientes.');
        }
    };

    return {
        loading,
        ficha,
        errores,
        pagos,
        corte,
        pendientes,
        filtrosPagos,
        buscarFicha,
        buscarPorGafete,
        cambiarDias,
        confirmarPago,
        fetchPagos,
        fetchPendientes,
        limpiarErrores,
    };
}
