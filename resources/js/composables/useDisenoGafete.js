import { ref } from 'vue';
import axios from 'axios';
import { toast } from 'vue-sonner';

// Compartido por toda la aplicación: una hoja de 3 × 3 dibuja nueve gafetes y no debe pedir el
// diseño nueve veces. Se pide una vez y se vuelve a pedir solo cuando alguien lo cambia.
const vigente = ref(null);
let pendiente = null;

/**
 * Diseño del gafete (§4.1): el vigente, con el que se dibujan todos los gafetes, y la
 * administración de diseños (subir como borrador, activar, restablecer), que exige
 * `gafetes.disenar`.
 */
export function useDisenoGafete() {
    const loading = ref(false);
    const historial = ref([]);
    const error = ref(null);

    const manejarError = (err, mensaje) => {
        const status = err.response?.status;

        // 401 y 403 ya los explica el interceptor global (ver interceptores.js).
        if (status === 401 || status === 403) {
            return;
        }

        if (status !== 422) {
            console.error(err);
        }

        toast.error(mensaje, { description: err.response?.data?.message ?? err.message });
    };

    /**
     * El diseño con el que se imprimen los gafetes. Varias tarjetas que lo piden a la vez comparten
     * la misma petición.
     */
    const cargarVigente = async ({ forzar = false } = {}) => {
        if (vigente.value && !forzar) {
            return vigente.value;
        }

        pendiente ??= axios.get('/api/gafetes/diseno')
            .then(({ data }) => {
                vigente.value = data.data;

                return vigente.value;
            })
            .catch((err) => {
                manejarError(err, 'No se pudo cargar el diseño del gafete.');

                return null;
            })
            .finally(() => {
                pendiente = null;
            });

        return pendiente;
    };

    const fetchHistorial = async () => {
        try {
            loading.value = true;
            const { data } = await axios.get('/api/gafetes/disenos', { params: { per_page: 50 } });
            historial.value = data.data;
        } catch (err) {
            manejarError(err, 'No se pudo cargar el historial de diseños.');
        } finally {
            loading.value = false;
        }
    };

    /**
     * Sube un SVG. Queda como borrador: devuelve el diseño para mostrarlo en la vista previa, o null
     * si se rechazó (el motivo queda en `error`, porque dice qué corregir en el editor).
     */
    const subir = async (archivo) => {
        const datos = new FormData();
        datos.append('archivo', archivo);
        error.value = null;

        try {
            loading.value = true;
            const { data } = await axios.post('/api/gafetes/disenos', datos);
            toast.success('Diseño subido como borrador.', {
                description: 'Revísalo en la vista previa y actívalo cuando esté listo.',
            });

            return data.data;
        } catch (err) {
            if (err.response?.status === 422) {
                error.value = err.response.data.errors?.archivo?.[0] ?? err.response.data.message;
            } else {
                manejarError(err, 'No se pudo subir el diseño.');
            }

            return null;
        } finally {
            loading.value = false;
        }
    };

    const activar = async (diseno) => {
        try {
            loading.value = true;
            await axios.patch(`/api/gafetes/disenos/${diseno.id}/activar`);
            await cargarVigente({ forzar: true });
            toast.success('Diseño activado.', { description: 'Todos los gafetes se imprimen ya con este diseño.' });

            return true;
        } catch (err) {
            manejarError(err, 'No se pudo activar el diseño.');

            return false;
        } finally {
            loading.value = false;
        }
    };

    const restablecer = async () => {
        try {
            loading.value = true;
            await axios.patch('/api/gafetes/disenos/restablecer');
            await cargarVigente({ forzar: true });
            toast.success('Se restableció el diseño predeterminado.');

            return true;
        } catch (err) {
            manejarError(err, 'No se pudo restablecer el diseño.');

            return false;
        } finally {
            loading.value = false;
        }
    };

    return {
        vigente,
        loading,
        historial,
        error,
        cargarVigente,
        fetchHistorial,
        subir,
        activar,
        restablecer,
    };
}
