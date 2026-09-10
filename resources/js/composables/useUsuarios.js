import { reactive, ref } from 'vue';
import axios from 'axios';
import { toast } from 'vue-sonner';

export function useUsuarios() {
    // state
    const loading = ref(false);
    const usuarios = ref([]);
    const errores = ref({});

    // Catálogos del formulario. Se cargan aparte de la tabla porque no dependen de los
    // filtros ni de la página en la que esté el usuario.
    const roles = ref([]);
    const personasDisponibles = ref([]);

    // Metadatos que devuelve el bloque `meta` del paginador de Laravel.
    const paginacion = reactive({
        current_page: 1,
        last_page: 1,
        per_page: 10,
        total: 0,
    });

    // Filtros que viajan como query params a GET /api/usuarios.
    const filtros = reactive({
        q: '',
        activo: '',
        rol_id: '',
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

    const fetchUsuarios = async (page = paginacion.current_page) => {
        try {
            loading.value = true;
            const { data } = await axios.get('/api/usuarios', {
                params: {
                    page,
                    per_page: filtros.per_page,
                    // Se omiten los filtros vacíos para no mandar `q=&activo=`.
                    ...(filtros.q ? { q: filtros.q } : {}),
                    ...(filtros.activo !== '' ? { activo: filtros.activo } : {}),
                    ...(filtros.rol_id !== '' ? { rol_id: filtros.rol_id } : {}),
                },
            });

            usuarios.value = data.data;
            Object.assign(paginacion, data.meta);
        } catch (error) {
            manejarError(error, 'No se pudieron cargar las cuentas.');
        } finally {
            loading.value = false;
        }
    };

    /**
     * Roles asignables: solo los activos (§5.5), con `id` y `nombre`. También alimenta el
     * filtro por rol del listado.
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

    /**
     * Personas que pueden recibir una cuenta: activas (§3.3, una persona de baja no podría
     * iniciar sesión) y que aún no tengan una (§3.1, máximo una por persona). El filtro lo
     * resuelve el backend para que la lista no dependa de la página que traiga el paginador.
     */
    const fetchPersonasDisponibles = async () => {
        try {
            // Catálogo propio: no exige `colaboradores.ver` ni trae el expediente completo.
            const { data } = await axios.get('/api/usuarios/personas-disponibles', {
                params: { per_page: 100 },
            });

            personasDisponibles.value = data.data;
        } catch (error) {
            manejarError(error, 'No se pudieron cargar las personas.');
        }
    };

    // Tras crear o filtrar hay que volver a la página 1: el registro nuevo puede caer
    // en cualquier página por el orden alfabético, y la página actual puede dejar de existir.
    const irAPrimeraPagina = () => fetchUsuarios(1);

    const createUsuario = async (payload) => {
        limpiarErrores();
        try {
            loading.value = true;
            await axios.post('/api/usuarios', payload);
            await irAPrimeraPagina();
            // La persona ya no está disponible para otra cuenta: hay que recargar el catálogo.
            await fetchPersonasDisponibles();
            toast.success('Cuenta creada.');

            return true;
        } catch (error) {
            manejarError(error, 'No se pudo crear la cuenta.');

            return false;
        } finally {
            loading.value = false;
        }
    };

    const updateUsuario = async (id, payload) => {
        limpiarErrores();
        try {
            loading.value = true;
            await axios.put(`/api/usuarios/${id}`, payload);
            await fetchUsuarios();
            toast.success('Cuenta actualizada.');

            return true;
        } catch (error) {
            manejarError(error, 'No se pudo actualizar la cuenta.');

            return false;
        } finally {
            loading.value = false;
        }
    };

    /**
     * Restablece la contraseña. Va en su propio endpoint: cambiar el correo o el rol de
     * alguien no debe obligar a reescribir su contraseña.
     */
    const resetearPassword = async (id, payload) => {
        limpiarErrores();
        try {
            loading.value = true;
            await axios.patch(`/api/usuarios/${id}/password`, payload);
            toast.success('Contraseña restablecida.');

            return true;
        } catch (error) {
            manejarError(error, 'No se pudo restablecer la contraseña.');

            return false;
        } finally {
            loading.value = false;
        }
    };

    // Suspende el acceso sin dar de baja a la persona: sigue usando el comedor con su gafete.
    const suspenderUsuario = async (id) => {
        try {
            loading.value = true;
            await axios.patch(`/api/usuarios/${id}/suspender`);
            await fetchUsuarios();
            toast.success('Cuenta suspendida.');
        } catch (error) {
            // 422: es la cuenta administradora del sistema. Sin campo donde pintarlo, va a un
            // toast con el motivo del servidor.
            if (error.response?.status === 422) {
                toast.error('No se pudo suspender la cuenta.', {
                    description: error.response.data.message,
                });

                return;
            }

            manejarError(error, 'No se pudo suspender la cuenta.');
        } finally {
            loading.value = false;
        }
    };

    const reactivarUsuario = async (id) => {
        try {
            loading.value = true;
            await axios.patch(`/api/usuarios/${id}/reactivar`);
            await fetchUsuarios();
            toast.success('Cuenta reactivada.');
        } catch (error) {
            manejarError(error, 'No se pudo reactivar la cuenta.');
        } finally {
            loading.value = false;
        }
    };

    return {
        // states
        loading,
        usuarios,
        roles,
        personasDisponibles,
        errores,
        paginacion,
        filtros,

        // métodos
        fetchUsuarios,
        fetchRoles,
        fetchPersonasDisponibles,
        irAPrimeraPagina,
        createUsuario,
        updateUsuario,
        resetearPassword,
        suspenderUsuario,
        reactivarUsuario,
        limpiarErrores,
    };
}
