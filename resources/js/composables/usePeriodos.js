import { reactive, ref } from 'vue';
import axios from 'axios';
import { toast } from 'vue-sonner';

/**
 * Periodos semanales de servicio (§6, §8, §9).
 *
 * El periodo nace en borrador, se configura (festivos, precios, ventana) y se abre. Cerrarlo es una
 * acción de una persona; lo que manda para generar fichas es la ventana, no el estado guardado.
 */
export function usePeriodos() {
    const loading = ref(false);
    const periodos = ref([]);
    const errores = ref({});

    const paginacion = reactive({
        current_page: 1,
        last_page: 1,
        per_page: 10,
        total: 0,
    });

    const filtros = reactive({ estado: '' });

    const limpiarErrores = () => { errores.value = {}; };

    // 422 → errores junto al campo; el resto, a un toast. 401 y 403 los explica el interceptor
    // global (ver interceptores.js).
    const manejarError = (error, mensaje = 'No se pudo completar la operación.') => {
        const status = error.response?.status;

        if (status === 422) {
            errores.value = error.response.data.errors ?? {};

            // Las reglas del periodo (estado, precio faltante) no tienen campo en el formulario:
            // se avisan aparte para que no pasen desapercibidas.
            const suelto = errores.value.periodo?.[0] ?? errores.value.estado?.[0] ?? errores.value.dia?.[0];

            if (suelto) {
                toast.error(suelto);
            }

            return;
        }

        if (status === 401 || status === 403) {
            return;
        }

        console.error(error);
        toast.error(mensaje, { description: error.response?.data?.message ?? error.message });
    };

    const fetchPeriodos = async (page = paginacion.current_page) => {
        try {
            loading.value = true;
            const { data } = await axios.get('/api/periodos', {
                params: {
                    page,
                    per_page: paginacion.per_page,
                    ...(filtros.estado ? { estado: filtros.estado } : {}),
                },
            });
            periodos.value = data.data;
            Object.assign(paginacion, data.meta);
        } catch (error) {
            manejarError(error, 'No se pudieron cargar los periodos.');
        } finally {
            loading.value = false;
        }
    };

    /** Un periodo con sus días. */
    const fetchPeriodo = async (id) => {
        try {
            loading.value = true;
            const { data } = await axios.get(`/api/periodos/${id}`);

            return data.data;
        } catch (error) {
            manejarError(error, 'No se pudo cargar el periodo.');

            return null;
        } finally {
            loading.value = false;
        }
    };

    /** Crea el periodo de la semana siguiente. Si ya existía, lo devuelve sin duplicarlo. */
    const generarSiguiente = async () => {
        limpiarErrores();

        try {
            loading.value = true;
            const { status, data } = await axios.post('/api/periodos/generar-siguiente');
            await fetchPeriodos(1);
            toast.success(status === 201 ? 'Periodo creado en borrador.' : 'La semana siguiente ya tenía periodo.');

            return data.data;
        } catch (error) {
            manejarError(error, 'No se pudo generar el periodo.');

            return null;
        } finally {
            loading.value = false;
        }
    };

    const createPeriodo = async (payload) => {
        limpiarErrores();

        try {
            loading.value = true;
            const { data } = await axios.post('/api/periodos', payload);
            await fetchPeriodos(1);
            toast.success('Periodo creado en borrador.');

            return data.data;
        } catch (error) {
            manejarError(error, 'No se pudo crear el periodo.');

            return null;
        } finally {
            loading.value = false;
        }
    };

    const updateVentana = async (id, payload) => {
        limpiarErrores();

        try {
            loading.value = true;
            const { data } = await axios.put(`/api/periodos/${id}`, payload);
            await fetchPeriodos();
            toast.success('Ventana actualizada.');

            return data.data;
        } catch (error) {
            manejarError(error, 'No se pudo actualizar la ventana.');

            return null;
        } finally {
            loading.value = false;
        }
    };

    const updateDia = async (periodoId, diaId, payload) => {
        limpiarErrores();

        try {
            loading.value = true;
            const { data } = await axios.put(`/api/periodos/${periodoId}/dias/${diaId}`, payload);
            await fetchPeriodos();
            toast.success('Día actualizado.');

            return data.data;
        } catch (error) {
            manejarError(error, 'No se pudo actualizar el día.');

            return null;
        } finally {
            loading.value = false;
        }
    };

    /** abrir | cerrar | reabrir (§8). */
    const cambiarEstado = async (id, accion) => {
        limpiarErrores();

        try {
            loading.value = true;
            const { data } = await axios.patch(`/api/periodos/${id}/${accion}`);
            await fetchPeriodos();
            toast.success({
                abrir: 'Periodo abierto.',
                cerrar: 'Periodo cerrado.',
                reabrir: 'Periodo reabierto.',
            }[accion]);

            return data.data;
        } catch (error) {
            manejarError(error, 'No se pudo cambiar el estado del periodo.');

            return null;
        } finally {
            loading.value = false;
        }
    };

    return {
        loading,
        periodos,
        errores,
        paginacion,
        filtros,
        fetchPeriodos,
        fetchPeriodo,
        generarSiguiente,
        createPeriodo,
        updateVentana,
        updateDia,
        cambiarEstado,
        limpiarErrores,
    };
}
