import { ref } from 'vue';
import axios from 'axios';

/**
 * Kiosco de generación de fichas (§10, §11).
 *
 * A diferencia del resto de la aplicación, aquí no hay sesión ni toasts: frente a la pantalla hay
 * un colaborador que solo va a pedir su comida, así que cada error se muestra en la propia pantalla,
 * grande y con qué hacer al respecto.
 */
export function useKiosco() {
    const cargando = ref(false);
    const error = ref('');

    const pedir = async (ruta, datos) => {
        error.value = '';

        try {
            cargando.value = true;
            const { data } = await axios.post(ruta, datos);

            return data.data;
        } catch (e) {
            const status = e.response?.status;

            error.value = status === 429
                ? 'Demasiados intentos seguidos. Espera un momento y vuelve a escanear.'
                : (e.response?.data?.message ?? 'No pudimos conectarnos. Avisa al área de personal.');

            return null;
        } finally {
            cargando.value = false;
        }
    };

    /** Paso 1 y 2: quién es y qué puede pedir hoy. */
    const identificar = (qrToken) => pedir('/api/kiosco/identificar', { qr_token: qrToken });

    /** Paso 3 y 4: genera la ficha con los días elegidos. */
    const generarFicha = (qrToken, dias) => pedir('/api/kiosco/fichas', { qr_token: qrToken, dias });

    return { cargando, error, identificar, generarFicha };
}
