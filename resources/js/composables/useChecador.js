import { ref } from 'vue';
import axios from 'axios';

/**
 * Validación del consumo en la entrada del comedor (§16).
 *
 * Las respuestas negativas no son errores: "no pagó hoy" es una respuesta con su motivo, y la
 * pantalla la muestra en rojo. Solo se tratan como fallo los problemas de conexión.
 */
export function useChecador() {
    const cargando = ref(false);
    const resumen = ref(null);

    /** Devuelve { resultado, motivo, mensaje, persona, utilizado_en } o null si no hubo conexión. */
    const validar = async (qrToken) => {
        try {
            cargando.value = true;
            const { data } = await axios.post('/api/consumo/validar', { qr_token: qrToken });

            return data.data;
        } catch (error) {
            const status = error.response?.status;

            return {
                resultado: 'RECHAZADO',
                motivo: 'ERROR',
                mensaje: status === 429
                    ? 'Demasiados intentos seguidos. Espera un momento.'
                    : 'No pudimos conectarnos. Avisa al área de sistemas.',
                persona: null,
            };
        } finally {
            cargando.value = false;
        }
    };

    /** Cómo va el servicio de hoy: pagados, servidos y quién acaba de pasar. */
    const fetchResumen = async () => {
        try {
            const { data } = await axios.get('/api/consumo');
            resumen.value = data.data;
        } catch {
            // El contador es información de apoyo: si falla, la pantalla sigue validando.
            resumen.value = null;
        }
    };

    return { cargando, resumen, validar, fetchResumen };
}
