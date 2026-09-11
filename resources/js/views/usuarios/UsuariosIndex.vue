<script setup>
import { computed, reactive, ref, onMounted, watch } from 'vue'
import { useDebounceFn } from '@vueuse/core'

import { useUsuarios } from '@/composables/useUsuarios.js'
import { useAuthStore } from '@/stores/auth.js'

import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle
} from '@/components/ui/card'

import {
  Table,
  TableBody,
  TableCaption,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'

import { SquarePen, Plus, SearchX, Search, KeyRound, TriangleAlert } from '@lucide/vue'

import { Button } from '@/components/ui/button'
import { Switch } from '@/components/ui/switch'
import { Badge } from '@/components/ui/badge'
import { Spinner } from '@/components/ui/spinner'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select'
import {
    Pagination,
    PaginationContent,
    PaginationEllipsis,
    PaginationItem,
    PaginationNext,
    PaginationPrevious,
} from '@/components/ui/pagination'
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'


const {
    loading,
    usuarios,
    roles,
    personasDisponibles,
    errores,
    paginacion,
    filtros,
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
} = useUsuarios();

// Estado del formulario. editandoId = null → alta; con id → edición.
const editandoId = ref(null);
const cuentaEditada = ref(null);
const form = reactive({
    persona_id: '',
    email: '',
    password: '',
    password_confirmation: '',
    rol_id: '',
});

// Modales.
const modalcuenta = ref(false);
const modalpassword = ref(false);
const confirmarSuspension = ref(false);
const cuentaPendiente = ref(null);

// Formulario de restablecimiento de contraseña.
const formPassword = reactive({
    password: '',
    password_confirmation: '',
});

// Id de la fila cuyo estado se está cambiando (para mostrar el spinner
// solo en ese registro y no en todos, ya que `loading` es global).
const cambiandoId = ref(null);

// --- Filtros -------------------------------------------------------------

// Se espera a que el usuario deje de teclear para no lanzar una petición por letra.
const buscar = useDebounceFn(irAPrimeraPagina, 350);

// El Select de reka-ui trabaja con strings; '' = sin filtrar.
const filtroActivo = computed({
    get: () => filtros.activo === '' ? 'todos' : filtros.activo,
    set: (valor) => {
        filtros.activo = valor === 'todos' ? '' : valor;
        irAPrimeraPagina();
    },
});

const filtroRol = computed({
    get: () => filtros.rol_id === '' ? 'todos' : String(filtros.rol_id),
    set: (valor) => {
        filtros.rol_id = valor === 'todos' ? '' : valor;
        irAPrimeraPagina();
    },
});

// Sincroniza el control de paginación con el paginador del backend.
const paginaActual = computed({
    get: () => paginacion.current_page,
    set: (pagina) => fetchUsuarios(pagina),
});

const hayFiltros = computed(
    () => filtros.q !== '' || filtros.activo !== '' || filtros.rol_id !== ''
);

const limpiarFiltros = () => {
    filtros.q = '';
    filtros.activo = '';
    filtros.rol_id = '';
    irAPrimeraPagina();
};

const fecha = (valor) => (valor ? new Date(valor).toLocaleString() : '—');

/**
 * Una cuenta activa puede aun así no poder entrar: si su persona fue dada de baja (§3.3) o su
 * rol desactivado. El backend lo calcula en `puede_operar`; aquí se explica el motivo, porque
 * ver "Activa" y que la persona no pueda entrar es exactamente el tipo de contradicción que
 * hace perder una tarde.
 */
const motivoBloqueo = (usuario) => {
    if (usuario.puede_operar || !usuario.activo) {
        return null;
    }

    if (usuario.persona?.estado !== 'ACTIVO') {
        return 'La persona está dada de baja, así que no puede iniciar sesión.';
    }

    if (usuario.rol && !usuario.rol.activo) {
        return 'Su rol está desactivado, así que no tiene permisos efectivos.';
    }

    return 'La cuenta no puede operar.';
};

// El catálogo del formulario solo trae roles activos, pero una cuenta puede tener uno
// desactivado después. Se agrega su rol actual a las opciones para que la edición no lo
// pierda ni quede el select en blanco; el backend lo acepta.
const opcionesRol = computed(() => {
    const propio = cuentaEditada.value?.rol;

    if (!propio || roles.value.some((r) => r.id === propio.id)) {
        return roles.value;
    }

    return [...roles.value, { ...propio, inactivo: true }];
});

// Si al filtrar la página actual queda vacía pero aún hay registros,
// retrocede a la última página con contenido.
watch(usuarios, (lista) => {
    if (!lista.length && paginacion.current_page > 1) {
        fetchUsuarios(paginacion.last_page);
    }
});

// --- Acciones ------------------------------------------------------------

// nuevoValor === true  → reactivar directo.
// nuevoValor === false → pedir confirmación antes de suspender.
const onToggleActivo = async (usuario, nuevoValor) => {
    if (nuevoValor) {
        cambiandoId.value = usuario.id;
        try {
            await reactivarUsuario(usuario.id);
        } finally {
            cambiandoId.value = null;
        }
    } else {
        cuentaPendiente.value = usuario;
        confirmarSuspension.value = true;
    }
};

const confirmarSuspender = async () => {
    const usuario = cuentaPendiente.value;
    confirmarSuspension.value = false;

    if (usuario) {
        cambiandoId.value = usuario.id;
        try {
            await suspenderUsuario(usuario.id);
        } finally {
            cambiandoId.value = null;
            cuentaPendiente.value = null;
        }
    }
};

const cerrarModal = () => {
    confirmarSuspension.value = false;
    cuentaPendiente.value = null;
};

const resetForm = () => {
    editandoId.value = null;
    cuentaEditada.value = null;
    form.persona_id = '';
    form.email = '';
    form.password = '';
    form.password_confirmation = '';
    form.rol_id = '';
    limpiarErrores();
};

// Abre el modal en modo ALTA (formulario limpio).
const abrirCrear = () => {
    resetForm();
    modalcuenta.value = true;
};

// Abre el modal en modo EDICIÓN. La persona no se toca (la cuenta no se transfiere) y la
// contraseña tampoco: tiene su propio modal.
const editar = (usuario) => {
    resetForm();
    editandoId.value = usuario.id;
    cuentaEditada.value = usuario;
    form.persona_id = String(usuario.persona_id);
    form.email = usuario.email;
    form.rol_id = String(usuario.rol_id);
    modalcuenta.value = true;
};

const guardar = async () => {
    const payload = {
        persona_id: form.persona_id,
        email: form.email,
        rol_id: form.rol_id,
    };

    let ok;

    if (editandoId.value) {
        ok = await updateUsuario(editandoId.value, payload);
    } else {
        // Las credenciales solo viajan en el alta.
        payload.password = form.password;
        payload.password_confirmation = form.password_confirmation;
        ok = await createUsuario(payload);
    }

    if (ok) {
        modalcuenta.value = false;
        limpiarErrores();
    }
};

const abrirPassword = (usuario) => {
    cuentaEditada.value = usuario;
    formPassword.password = '';
    formPassword.password_confirmation = '';
    limpiarErrores();
    modalpassword.value = true;
};

const guardarPassword = async () => {
    const ok = await resetearPassword(cuentaEditada.value.id, { ...formPassword });

    if (ok) {
        modalpassword.value = false;
        limpiarErrores();
    }
};

const auth = useAuthStore();

// Qué acciones ofrece la interfaz según el rol. Es comodidad, no seguridad: el backend comprueba
// el permiso en cada operación (§5.6); esto solo evita ofrecer botones que responderían 403.
const puede = computed(() => ({
    crear: auth.tienePermiso('usuarios.crear'),
    editar: auth.tienePermiso('usuarios.editar'),
    desactivar: auth.tienePermiso('usuarios.desactivar'),
}));

// Sin permiso para editar ni para desactivar, la columna Acciones sobra.
const hayAcciones = computed(() => puede.value.editar || puede.value.desactivar);

const esPropia = (usuario) => usuario?.id === auth.usuario?.id;

/**
 * Por qué no se puede suspender esta cuenta, o null si sí se puede. Replica las dos reglas del
 * backend, que rechaza igual: la cuenta administradora del sistema, y la propia.
 */
const motivoSinSuspension = (usuario) => {
    if (usuario.protegido) {
        return 'Cuenta administradora del sistema: no puede suspenderse.';
    }

    if (esPropia(usuario)) {
        return 'Es tu cuenta: no puedes suspenderla tú mismo.';
    }

    return null;
};

// Mismo criterio para el rol: ni la cuenta del sistema ni la propia pueden cambiarlo.
const motivoRolFijo = computed(() => {
    if (cuentaEditada.value?.protegido) {
        return 'El rol de la cuenta administradora del sistema no puede cambiarse.';
    }

    if (editandoId.value && esPropia(cuentaEditada.value)) {
        return 'No puedes cambiar tu propio rol: pídele a otro administrador que lo haga.';
    }

    return null;
});

onMounted(() => {
    fetchUsuarios(1);
    fetchRoles();

    // Solo sirve para el alta, y el backend exige `usuarios.crear`: pedirlo sin ese permiso
    // solo produciría un 403 al abrir la pantalla.
    if (auth.tienePermiso('usuarios.crear')) {
        fetchPersonasDisponibles();
    }
});
</script>

<template>

    <div>
        <h1 class="text-2xl font-bold">Usuarios</h1>
        <h2 class="text-sm text-gray-600">Cuentas de acceso al sistema.</h2>
    </div>

    <Card>

        <CardHeader>
            <div class="flex flex-col sm:flex-row justify-between gap-2">
                <div>
                    <CardTitle>Tabla de cuentas</CardTitle>
                    <CardDescription>
                        La cuenta de sistema es opcional: solo la necesita quien deba entrar a
                        los módulos administrativos. El resto del personal usa su gafete.
                    </CardDescription>
                </div>

                <div class="flex justify-end">
                    <Button v-if="puede.crear" @click="abrirCrear">
                        <Plus/>
                        Agregar
                    </Button>
                </div>
            </div>

            <div class="flex flex-col sm:flex-row gap-2 pt-4">
                <div class="relative flex-1">
                    <Search class="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                    <Input
                        v-model="filtros.q"
                        placeholder="Buscar por correo, nombre o número de empleado…"
                        class="pl-8"
                        autocomplete="off"
                        @update:model-value="buscar"
                    />
                </div>

                <Select v-model="filtroRol">
                    <SelectTrigger class="w-full sm:w-48">
                        <SelectValue placeholder="Rol" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="todos">Todos los roles</SelectItem>
                        <SelectItem v-for="rol in roles" :key="rol.id" :value="String(rol.id)">
                            {{ rol.nombre }}
                        </SelectItem>
                    </SelectContent>
                </Select>

                <Select v-model="filtroActivo">
                    <SelectTrigger class="w-full sm:w-44">
                        <SelectValue placeholder="Estado" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="todos">Todas</SelectItem>
                        <SelectItem value="1">Activas</SelectItem>
                        <SelectItem value="0">Suspendidas</SelectItem>
                    </SelectContent>
                </Select>

                <Button v-if="hayFiltros" variant="ghost" @click="limpiarFiltros">
                    Limpiar
                </Button>
            </div>
        </CardHeader>

        <CardContent>

            <Table>
                <TableCaption v-if="usuarios.length">Lista de las cuentas de sistema.</TableCaption>
                <TableHeader class="bg-stone-50">
                    <TableRow>
                        <TableHead class="text-center font-bold">Correo</TableHead>
                        <TableHead class="text-center font-bold">Persona</TableHead>
                        <TableHead class="text-center font-bold">No. empleado</TableHead>
                        <TableHead class="text-center font-bold">Rol</TableHead>
                        <TableHead class="text-center font-bold">Estado</TableHead>
                        <TableHead class="text-center font-bold">Modificado</TableHead>
                        <TableHead v-if="hayAcciones" class="text-center font-bold">Acciones</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="usuario in usuarios" :key="usuario.id">
                        <TableCell class="text-center">
                            {{ usuario.email }}
                            <Badge v-if="usuario.protegido" variant="outline" class="ml-1">Sistema</Badge>
                            <Badge v-if="esPropia(usuario)" variant="secondary" class="ml-1">Tú</Badge>
                        </TableCell>
                        <TableCell class="text-center">
                            {{ usuario.persona?.nombre_completo ?? '—' }}
                        </TableCell>
                        <TableCell class="text-center font-mono">
                            {{ usuario.persona?.numero_empleado ?? '—' }}
                        </TableCell>
                        <TableCell class="text-center">
                            <Badge :variant="usuario.rol?.activo ? 'secondary' : 'outline'">
                                {{ usuario.rol?.nombre ?? 'Sin rol' }}
                            </Badge>
                        </TableCell>
                        <TableCell class="text-center">
                            <div class="flex items-center justify-center gap-1">
                                <Badge v-if="cambiandoId === usuario.id" variant="outline">
                                    <Spinner class="w-4 h-4" />
                                </Badge>
                                <Badge
                                    v-else
                                    :variant="usuario.activo ? 'default' : 'destructive'"
                                    :class="usuario.activo ? 'bg-green-600' : ''"
                                >
                                    {{ usuario.activo ? 'Activa' : 'Suspendida' }}
                                </Badge>

                                <!-- Activa pero sin poder entrar: la contradicción se explica
                                     aquí en vez de dejar que alguien la descubra en el login. -->
                                <Tooltip v-if="motivoBloqueo(usuario)">
                                    <TooltipTrigger as-child>
                                        <TriangleAlert class="h-4 w-4 text-amber-600" />
                                    </TooltipTrigger>
                                    <TooltipContent>{{ motivoBloqueo(usuario) }}</TooltipContent>
                                </Tooltip>
                            </div>
                        </TableCell>
                        <TableCell class="text-center">{{ fecha(usuario.updated_at) }}</TableCell>
                        <TableCell v-if="hayAcciones" class="flex justify-center gap-4">
                            <Tooltip v-if="puede.editar">
                                <TooltipTrigger as-child>
                                    <SquarePen
                                        @click="editar(usuario)"
                                        class="w-5 text-sky-700 cursor-pointer"
                                    />
                                </TooltipTrigger>
                                <TooltipContent>Editar correo y rol</TooltipContent>
                            </Tooltip>

                            <Tooltip v-if="puede.editar">
                                <TooltipTrigger as-child>
                                    <KeyRound
                                        @click="abrirPassword(usuario)"
                                        class="w-5 text-amber-600 cursor-pointer"
                                    />
                                </TooltipTrigger>
                                <TooltipContent>Restablecer contraseña</TooltipContent>
                            </Tooltip>

                            <!-- Ni la cuenta administradora del sistema ni la propia se
                                 suspenden (ver motivoSinSuspension). El backend lo rechaza igual. -->
                            <Tooltip v-if="puede.desactivar && motivoSinSuspension(usuario)">
                                <TooltipTrigger as-child>
                                    <span class="cursor-not-allowed">
                                        <Switch :model-value="usuario.activo" disabled />
                                    </span>
                                </TooltipTrigger>
                                <TooltipContent>{{ motivoSinSuspension(usuario) }}</TooltipContent>
                            </Tooltip>
                            <Switch
                                v-else-if="puede.desactivar"
                                :model-value="usuario.activo"
                                :disabled="loading"
                                @update:model-value="(val) => onToggleActivo(usuario, val)"
                            />
                        </TableCell>
                    </TableRow>
                    <TableRow v-if="!usuarios.length">
                        <TableCell colspan="7" class="text-center text-gray-400 p-4">
                            <SearchX class="mx-auto h-10 w-10 text-gray-400" />
                            <p class="mt-2">
                                {{ hayFiltros
                                    ? 'Ninguna cuenta coincide con los filtros.'
                                    : 'No hay cuentas de sistema registradas.' }}
                            </p>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </CardContent>

        <CardFooter v-if="paginacion.total" class="flex flex-col sm:flex-row items-center justify-between gap-4">
            <p class="text-sm text-muted-foreground">
                Mostrando {{ usuarios.length }} de {{ paginacion.total }}
                {{ paginacion.total === 1 ? 'cuenta' : 'cuentas' }}
            </p>

            <Pagination
                v-if="paginacion.last_page > 1"
                v-model:page="paginaActual"
                :total="paginacion.total"
                :items-per-page="paginacion.per_page"
                :sibling-count="1"
                show-edges
                class="mx-0 w-auto"
            >
                <PaginationContent v-slot="{ items }">
                    <PaginationPrevious />
                    <template v-for="(item, index) in items">
                        <PaginationItem
                            v-if="item.type === 'page'"
                            :key="index"
                            :value="item.value"
                            :is-active="item.value === paginacion.current_page"
                        >
                            {{ item.value }}
                        </PaginationItem>
                        <PaginationEllipsis v-else :key="`e-${index}`" :index="index" />
                    </template>
                    <PaginationNext />
                </PaginationContent>
            </Pagination>
        </CardFooter>
    </Card>

    <AlertDialog v-model:open="confirmarSuspension">
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>¿Suspender la cuenta?</AlertDialogTitle>
                <AlertDialogDescription>
                    <span class="font-medium">{{ cuentaPendiente?.persona?.nombre_completo }}</span>
                    dejará de poder entrar a los módulos administrativos. Sigue siendo personal
                    activo y puede usar su gafete en el comedor. ¿Deseas continuar?
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel @click="cerrarModal">Cancelar</AlertDialogCancel>
                <AlertDialogAction @click="confirmarSuspender">Suspender</AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>

    <Dialog v-model:open="modalcuenta">
        <DialogContent class="sm:max-w-md">
            <form @submit.prevent="guardar">
                <DialogHeader>
                    <DialogTitle>
                        {{ editandoId ? 'Editar cuenta' : 'Agregar cuenta' }}
                    </DialogTitle>
                    <DialogDescription>
                        {{ editandoId
                            ? 'La persona dueña de la cuenta no cambia, y la contraseña se restablece desde su propia acción.'
                            : 'Da acceso administrativo a una persona ya registrada.' }}
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4 py-4">
                    <div class="grid gap-2">
                        <Label for="persona_id">Persona</Label>

                        <!-- En edición la persona es fija: transferir la cuenta movería el
                             acceso a otra identidad conservando el historial de la primera. -->
                        <Input
                            v-if="editandoId"
                            id="persona_id"
                            :model-value="cuentaEditada?.persona?.nombre_completo"
                            disabled
                        />
                        <Select v-else v-model="form.persona_id">
                            <SelectTrigger id="persona_id" class="w-full">
                                <SelectValue placeholder="Selecciona una persona" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="persona in personasDisponibles"
                                    :key="persona.id"
                                    :value="String(persona.id)"
                                >
                                    {{ persona.nombre_completo }} — {{ persona.numero_empleado }}
                                </SelectItem>
                            </SelectContent>
                        </Select>

                        <p v-if="editandoId" class="text-xs text-muted-foreground">
                            La cuenta no puede transferirse a otra persona.
                        </p>
                        <p
                            v-else-if="!personasDisponibles.length"
                            class="text-xs text-muted-foreground"
                        >
                            No hay personas activas sin cuenta. Registra una en el módulo de
                            personas o crea su cuenta desde ahí mismo.
                        </p>
                        <p v-if="errores.persona_id" class="text-sm text-red-600">
                            {{ errores.persona_id[0] }}
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="email">Correo de acceso</Label>
                        <Input
                            id="email"
                            v-model="form.email"
                            type="email"
                            placeholder="Ej. juan.perez@arod.com"
                            autocomplete="off"
                        />
                        <p v-if="errores.email" class="text-sm text-red-600">
                            {{ errores.email[0] }}
                        </p>
                    </div>

                    <template v-if="!editandoId">
                        <div class="grid gap-2">
                            <Label for="password">Contraseña</Label>
                            <Input
                                id="password"
                                v-model="form.password"
                                type="password"
                                autocomplete="new-password"
                            />
                            <p v-if="errores.password" class="text-sm text-red-600">
                                {{ errores.password[0] }}
                            </p>
                        </div>

                        <div class="grid gap-2">
                            <Label for="password_confirmation">Confirmar contraseña</Label>
                            <Input
                                id="password_confirmation"
                                v-model="form.password_confirmation"
                                type="password"
                                autocomplete="new-password"
                            />
                        </div>
                    </template>

                    <div class="grid gap-2">
                        <Label for="rol_id">Rol</Label>
                        <Select v-model="form.rol_id" :disabled="!!motivoRolFijo">
                            <SelectTrigger id="rol_id" class="w-full">
                                <SelectValue placeholder="Selecciona un rol" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="rol in opcionesRol"
                                    :key="rol.id"
                                    :value="String(rol.id)"
                                >
                                    {{ rol.nombre }}{{ rol.inactivo ? ' (desactivado)' : '' }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <!-- §5.1: exactamente un rol por cuenta. -->
                        <p v-if="motivoRolFijo" class="text-xs text-muted-foreground">
                            {{ motivoRolFijo }}
                        </p>
                        <p v-else class="text-xs text-muted-foreground">
                            Sus permisos serán los que el rol tenga configurados en cada momento.
                        </p>
                        <p v-if="errores.rol_id" class="text-sm text-red-600">
                            {{ errores.rol_id[0] }}
                        </p>
                    </div>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="modalcuenta = false">
                        Cancelar
                    </Button>
                    <Button type="submit" :disabled="loading">
                        {{ editandoId ? 'Actualizar' : 'Guardar' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="modalpassword">
        <DialogContent class="sm:max-w-md">
            <form @submit.prevent="guardarPassword">
                <DialogHeader>
                    <DialogTitle>Restablecer contraseña</DialogTitle>
                    <DialogDescription>
                        Nueva contraseña para
                        <span class="font-medium">{{ cuentaEditada?.persona?.nombre_completo }}</span>
                        ({{ cuentaEditada?.email }}).
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4 py-4">
                    <div class="grid gap-2">
                        <Label for="nueva_password">Contraseña</Label>
                        <Input
                            id="nueva_password"
                            v-model="formPassword.password"
                            type="password"
                            autocomplete="new-password"
                        />
                        <p v-if="errores.password" class="text-sm text-red-600">
                            {{ errores.password[0] }}
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="nueva_password_confirmation">Confirmar contraseña</Label>
                        <Input
                            id="nueva_password_confirmation"
                            v-model="formPassword.password_confirmation"
                            type="password"
                            autocomplete="new-password"
                        />
                    </div>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="modalpassword = false">
                        Cancelar
                    </Button>
                    <Button type="submit" :disabled="loading">Restablecer</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

</template>
