import { ref } from 'vue';
import axios from 'axios';
import { toast } from 'vue-sonner';

export function useGafetes() {
    const loading = ref(false);
    const historial = ref([]);

    // Los gafetes no tienen formulario: todo error va a un toast, salvo 401 y 403, que ya los
    // explica el interceptor global (ver interceptores.js).
    const manejarError = (error, mensaje) => {
        const status = error.response?.status;

        if (status === 401 || status === 403) {
            return;
        }

        if (status !== 422) {
            console.error(error);
        }

        toast.error(mensaje, {
            description: error.response?.data?.message ?? error.message,
        });
    };

    /**
     * Historial de gafetes de una persona, del más reciente al más antiguo. No trae el token del
     * QR: ese solo sale por la impresión.
     */
    const fetchHistorial = async (personaId) => {
        try {
            loading.value = true;
            const { data } = await axios.get(`/api/personas/${personaId}/gafetes`);
            historial.value = data.data;
        } catch (error) {
            manejarError(error, 'No se pudo cargar el historial de gafetes.');
        } finally {
            loading.value = false;
        }
    };

    /**
     * Emite o repone el gafete de una persona. Si ya tenía uno, deja de funcionar de inmediato.
     * Devuelve el gafete nuevo, o null si falló.
     */
    const emitirGafete = async (personaId) => {
        try {
            loading.value = true;
            const { data } = await axios.post(`/api/personas/${personaId}/gafetes`);
            toast.success('Gafete emitido.');

            return data.data;
        } catch (error) {
            manejarError(error, 'No se pudo emitir el gafete.');

            return null;
        } finally {
            loading.value = false;
        }
    };

    /**
     * Datos para imprimir el gafete, incluido el token del QR. Devuelve null si el gafete ya no
     * funciona (reemplazado, o persona dada de baja) o si falta el permiso.
     */
    const fetchImpresion = async (gafeteId) => {
        try {
            loading.value = true;
            const { data } = await axios.get(`/api/gafetes/${gafeteId}/impresion`);

            return data.data;
        } catch (error) {
            manejarError(error, 'No se puede imprimir este gafete.');

            return null;
        } finally {
            loading.value = false;
        }
    };

    return {
        loading,
        historial,
        fetchHistorial,
        emitirGafete,
        fetchImpresion,
    };
}
