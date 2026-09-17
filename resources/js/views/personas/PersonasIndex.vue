<script setup>
import { computed, reactive, ref, onMounted, watch } from 'vue'
import { useDebounceFn } from '@vueuse/core'

import { usePersonas } from '@/composables/usePersonas.js'
import { useAuthStore } from '@/stores/auth.js'
import GafeteModal from '@/components/gafetes/GafeteModal.vue'
import CapturaFoto from '@/components/gafetes/CapturaFoto.vue'

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

import { SquarePen, Plus, SearchX, Search, IdCard } from '@lucide/vue'

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
    personas,
    departamentos,
    roles,
    errores,
    paginacion,
    filtros,
    fetchPersonas,
    fetchDepartamentos,
    fetchRoles,
    irAPrimeraPagina,
    createPersona,
    updatePersona,
    desactivarPersona,
    activarPersona,
    subirFoto,
    limpiarErrores,
} = usePersonas();

// Estado del formulario. editandoId = null → alta; con id → edición.
const editandoId = ref(null);
const form = reactive({
    numero_empleado: '',
    nombre: '',
    primer_apellido: '',
    segundo_apellido: '',
    departamento_id: '',
});

// Fotografía (§3.1). Es un dato de la persona: se toma aquí, en el alta o en la edición, y es la
// que muestra su gafete. Se sube justo después de guardar, porque en el alta la persona todavía
// no existe. Subirla exige `colaboradores.editar`, así que sin ese permiso no se ofrece.
const tomandoFoto = ref(false);
const fotoNueva = ref(null);

const alCapturarFoto = (blob) => {
    fotoNueva.value = blob;
};

const cancelarFoto = () => {
    tomandoFoto.value = false;
    fotoNueva.value = null;
};

const auth = useAuthStore();

// Qué acciones ofrece la interfaz según el rol. Es comodidad, no seguridad: el backend comprueba
// el permiso en cada operación (§5.6); esto solo evita ofrecer botones que responderían 403.
const puede = computed(() => ({
    crear: auth.tienePermiso('colaboradores.crear'),
    editar: auth.tienePermiso('colaboradores.editar'),
    desactivar: auth.tienePermiso('colaboradores.desactivar'),
    verGafetes: auth.tienePermiso('gafetes.ver'),
    emitirGafete: auth.tienePermiso('gafetes.emitir'),
    // Imprimir lo puede quien emite o quien solo reimprime, igual que en el backend.
    imprimirGafete: auth.tienePermiso('gafetes.emitir') || auth.tienePermiso('gafetes.reimprimir'),
}));

const puedeConGafetes = computed(
    () => puede.value.verGafetes || puede.value.emitirGafete || puede.value.imprimirGafete
);

// Sin ninguna acción disponible sobre las filas, la columna Acciones sobra.
const hayAcciones = computed(
    () => puede.value.editar || puede.value.desactivar || puedeConGafetes.value
);

// --- Gafetes (§4) ----------------------------------------------------------

const modalgafete = ref(false);
const personaGafete = ref(null);

const abrirGafete = (persona) => {
    personaGafete.value = persona;
    modalgafete.value = true;
};

/**
 * Crear la cuenta junto con la persona es crear una cuenta, y el backend exige `usuarios.crear`
 * además de `colaboradores.crear` (§5.6). Sin ese permiso no se ofrece el bloque ni se pide el
 * catálogo de roles: hacerlo solo produciría un 403 al guardar y otro al cargar la pantalla.
 */
const puedeCrearCuentas = computed(() => auth.tienePermiso('usuarios.crear'));

/**
 * Por qué no se puede dar de baja a esta persona, o null si sí se puede. Replica las dos reglas
 * del backend, que rechaza igual: la cuenta administradora del sistema, y la propia (nadie se da
 * de baja a sí mismo).
 */
const motivoSinBaja = (persona) => {
    if (persona.cuenta?.protegido) {
        return 'Tiene la cuenta administradora del sistema: no puede darse de baja.';
    }

    if (persona.cuenta && persona.cuenta.id === auth.usuario?.id) {
        return 'Eres tú: no puedes darte de baja a ti mismo.';
    }

    return null;
};

// §3.1: "durante el alta se deberá determinar si la persona tendrá acceso administrativo".
// Apagado, la persona queda solo como consumidora del comedor con su gafete.
const requiereCuenta = ref(false);
const cuenta = reactive({
    email: '',
    password: '',
    password_confirmation: '',
    rol_id: '',
});

// Estado del modal de confirmación de desactivación.
const confirmarDesactivacion = ref(false);
const modalpersona = ref(false);
const personaPendiente = ref(null);

// Id de la fila cuyo estado se está cambiando (para mostrar el spinner
// solo en ese registro y no en todos, ya que `loading` es global).
const cambiandoId = ref(null);

// --- Filtros -------------------------------------------------------------

// Se espera a que el usuario deje de teclear para no lanzar una petición por letra.
const buscar = useDebounceFn(irAPrimeraPagina, 350);

// El Select de reka-ui trabaja con strings; '' = sin filtrar.
const filtroEstado = computed({
    get: () => filtros.estado === '' ? 'todos' : filtros.estado,
    set: (valor) => {
        filtros.estado = valor === 'todos' ? '' : valor;
        irAPrimeraPagina();
    },
});

const filtroDepartamento = computed({
    get: () => filtros.departamento_id === '' ? 'todos' : String(filtros.departamento_id),
    set: (valor) => {
        filtros.departamento_id = valor === 'todos' ? '' : valor;
        irAPrimeraPagina();
    },
});

// Sincroniza el control de paginación con el paginador del backend.
const paginaActual = computed({
    get: () => paginacion.current_page,
    set: (pagina) => fetchPersonas(pagina),
});

const hayFiltros = computed(
    () => filtros.q !== '' || filtros.estado !== '' || filtros.departamento_id !== ''
);

const limpiarFiltros = () => {
    filtros.q = '';
    filtros.estado = '';
    filtros.departamento_id = '';
    irAPrimeraPagina();
};

// Los registros cargados antes de que existiera el modulo pueden no tener timestamps:
// sin este guardia, `new Date(null)` los pinta como 31/12/1969.
const fecha = (valor) => (valor ? new Date(valor).toLocaleString() : '—');

const personaEditada = ref(null);

// El catálogo del formulario solo trae departamentos activos (§3.4), pero una persona puede
// estar adscrita a uno desactivado después. Se agrega su departamento actual a las opciones
// para que la edición no lo pierda ni quede el select en blanco; el backend lo acepta.
const opcionesDepartamento = computed(() => {
    const propio = personaEditada.value?.departamento;

    // Mientras el catálogo no llega no se puede saber si el departamento propio sigue activo: se
    // ofrece tal cual. Si se comparara contra la lista vacía, se marcaría como "(inactivo)" a
    // cualquiera que abra el formulario en cuanto carga la página.
    if (!departamentos.value.length) {
        return propio ? [propio] : [];
    }

    if (!propio || departamentos.value.some((d) => d.id === propio.id)) {
        return departamentos.value;
    }

    return [...departamentos.value, { ...propio, inactivo: true }];
});

// Si al desactivar/filtrar la página actual queda vacía pero aún hay registros,
// retrocede a la última página con contenido (p. ej. filtrar el único de la pág. 3).
watch(personas, (lista) => {
    if (!lista.length && paginacion.current_page > 1) {
        fetchPersonas(paginacion.last_page);
    }
});

// --- Acciones ------------------------------------------------------------

// Se dispara cuando el usuario mueve el Switch de una fila.
// nuevoValor === true  → activar directo.
// nuevoValor === false → pedir confirmación antes de desactivar.
const onToggleEstado = async (persona, nuevoValor) => {
    if (nuevoValor) {
        cambiandoId.value = persona.id;
        try {
            await activarPersona(persona.id);
        } finally {
            cambiandoId.value = null;
        }
    } else {
        personaPendiente.value = persona;
        confirmarDesactivacion.value = true;
    }
};

const confirmarDesactivar = async () => {
    const persona = personaPendiente.value;
    confirmarDesactivacion.value = false;

    if (persona) {
        cambiandoId.value = persona.id;
        try {
            await desactivarPersona(persona.id);
        } finally {
            cambiandoId.value = null;
            personaPendiente.value = null;
        }
    }
};

// Abre el modal en modo ALTA (formulario limpio).
const abrirCrear = () => {
    resetForm();
    modalpersona.value = true;
};

const cerrarModal = () => {
    confirmarDesactivacion.value = false;
    personaPendiente.value = null;
};

const resetForm = () => {
    editandoId.value = null;
    personaEditada.value = null;
    form.numero_empleado = '';
    form.nombre = '';
    form.primer_apellido = '';
    form.segundo_apellido = '';
    form.departamento_id = '';
    requiereCuenta.value = false;
    cuenta.email = '';
    cuenta.password = '';
    cuenta.password_confirmation = '';
    cuenta.rol_id = '';
    cancelarFoto();
    limpiarErrores();
};

// Abre el modal en modo EDICIÓN, precargando los datos de la fila.
const editar = (persona) => {
    editandoId.value = persona.id;
    personaEditada.value = persona;
    form.numero_empleado = persona.numero_empleado;
    form.nombre = persona.nombre;
    form.primer_apellido = persona.primer_apellido;
    form.segundo_apellido = persona.segundo_apellido ?? '';
    form.departamento_id = String(persona.departamento_id);
    cancelarFoto();
    limpiarErrores();
    modalpersona.value = true;
};

const guardar = async () => {
    const payload = {
        numero_empleado: form.numero_empleado,
        nombre: form.nombre,
        primer_apellido: form.primer_apellido,
        // La columna es nullable: un campo vacío es "sin segundo apellido", no una cadena vacía.
        segundo_apellido: form.segundo_apellido || null,
        departamento_id: form.departamento_id,
    };

    // El bloque `cuenta` solo viaja en el alta y solo si el switch está encendido: el backend
    // ignora este bloque al editar, porque las credenciales se administran en /usuarios.
    if (!editandoId.value && requiereCuenta.value && puedeCrearCuentas.value) {
        payload.cuenta = { ...cuenta };
    }

    const ok = editandoId.value
        ? await updatePersona(editandoId.value, payload)
        : await createPersona(payload);

    // La foto se sube aparte y después: en el alta la persona apenas existe. Si esto falla, la
    // persona queda guardada igual (la foto es opcional), el aviso lo explica y se puede volver a
    // tomar editándola.
    if (ok && fotoNueva.value) {
        await subirFoto(editandoId.value ?? ok.id, fotoNueva.value);
    }

    // Si la API devolvió errores de validación (422), `ok` es false y
    // dejamos el modal abierto mostrando los mensajes.
    //
    // No se llama a resetForm() aquí: limpiar editandoId mientras el diálogo se está
    // cerrando hace que el título parpadee a "Agregar persona". Ambos puntos de
    // entrada (abrirCrear / editar) inicializan el formulario, así que basta con cerrar.
    if (ok) {
        modalpersona.value = false;
        limpiarErrores();
    }
};

onMounted(() => {
    fetchPersonas(1);
    fetchDepartamentos();

    if (puedeCrearCuentas.value) {
        fetchRoles();
    }
});
</script>

<template>

    <div>
        <h1 class="text-2xl font-bold">Colaboradores</h1>
        <h2 class="text-sm text-gray-600">Módulo de gestión de personal.</h2>
    </div>

    <Card>

        <CardHeader>
            <div class="flex flex-col sm:flex-row justify-between gap-2">
                <div>
                    <CardTitle>Tabla de personas</CardTitle>
                    <CardDescription>
                        Aquí puedes ver a todo el personal registrado, su departamento y su estado.
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
                        placeholder="Buscar por nombre, apellidos o número de empleado…"
                        class="pl-8"
                        autocomplete="off"
                        @update:model-value="buscar"
                    />
                </div>

                <Select v-model="filtroDepartamento">
                    <SelectTrigger class="w-full sm:w-52">
                        <SelectValue placeholder="Departamento" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="todos">Todos los departamentos</SelectItem>
                        <SelectItem
                            v-for="depto in departamentos"
                            :key="depto.id"
                            :value="String(depto.id)"
                        >
                            {{ depto.nombre }}
                        </SelectItem>
                    </SelectContent>
                </Select>

                <Select v-model="filtroEstado">
                    <SelectTrigger class="w-full sm:w-44">
                        <SelectValue placeholder="Estado" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="todos">Todos</SelectItem>
                        <SelectItem value="ACTIVO">Activos</SelectItem>
                        <SelectItem value="INACTIVO">Inactivos</SelectItem>
                    </SelectContent>
                </Select>

                <Button v-if="hayFiltros" variant="ghost" @click="limpiarFiltros">
                    Limpiar
                </Button>
            </div>
        </CardHeader>

        <CardContent>

            <Table>
                <TableCaption v-if="personas.length">Lista del personal registrado.</TableCaption>
                <TableHeader class="bg-stone-50">
                    <TableRow>
                        <TableHead class="text-center font-bold">
                            No. empleado
                        </TableHead>
                        <TableHead class="text-center font-bold">Nombre</TableHead>
                        <TableHead class="text-center font-bold">Departamento</TableHead>
                        <TableHead class="text-center font-bold">Rol</TableHead>
                        <TableHead class="text-center font-bold">Estado</TableHead>
                        <TableHead class="text-center font-bold">Creado</TableHead>
                        <TableHead class="text-center font-bold">Modificado</TableHead>
                        <TableHead v-if="hayAcciones" class="text-center font-bold">Acciones</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="persona in personas" :key="persona.id">
                        <TableCell class="text-center font-mono">
                            {{ persona.numero_empleado }}
                        </TableCell>
                        <TableCell class="text-center">{{ persona.nombre_completo }}</TableCell>
                        <TableCell class="text-center">
                            {{ persona.departamento?.nombre ?? '—' }}
                        </TableCell>
                        <TableCell class="text-center">
                            <!-- §3.1: no tener cuenta es lo normal, no una carencia: esa
                                 persona usa el comedor con su gafete. -->
                            <span v-if="!persona.cuenta" class="text-gray-400">—</span>
                            <Badge v-else :variant="persona.cuenta.activo ? 'secondary' : 'outline'">
                                {{ persona.cuenta.rol?.nombre ?? 'Sin rol' }}
                                <span v-if="!persona.cuenta.activo"> (suspendida)</span>
                            </Badge>
                        </TableCell>
                        <TableCell class="text-center">
                            <Badge v-if="cambiandoId === persona.id" variant="outline">
                                <Spinner class="w-4 h-4" />
                            </Badge>
                            <Badge
                                v-else
                                :variant="persona.estado === 'ACTIVO' ? 'default' : 'destructive'"
                                :class="persona.estado === 'ACTIVO' ? 'bg-green-600' : ''"
                            >
                                {{ persona.estado === 'ACTIVO' ? 'Activo' : 'Inactivo' }}
                            </Badge>
                        </TableCell>
                        <TableCell class="text-center">
                            {{ fecha(persona.created_at) }}
                        </TableCell>
                        <TableCell class="text-center">
                            {{ fecha(persona.updated_at) }}
                        </TableCell>
                        <TableCell v-if="hayAcciones" class="flex justify-center gap-4">
                            <IdCard
                                v-if="puedeConGafetes"
                                @click="abrirGafete(persona)"
                                class="w-5 text-emerald-700 cursor-pointer"
                            />
                            <SquarePen
                                v-if="puede.editar"
                                @click="editar(persona)"
                                class="w-5 text-sky-700 cursor-pointer"
                                ></SquarePen>
                            <!-- Ni la persona de la cuenta administradora ni uno mismo se dan de
                                 baja (ver motivoSinBaja). El backend lo rechaza igual; esto
                                 evita el intento. -->
                            <Tooltip v-if="puede.desactivar && motivoSinBaja(persona)">
                                <TooltipTrigger as-child>
                                    <span class="cursor-not-allowed">
                                        <Switch :model-value="persona.estado === 'ACTIVO'" disabled />
                                    </span>
                                </TooltipTrigger>
                                <TooltipContent>{{ motivoSinBaja(persona) }}</TooltipContent>
                            </Tooltip>
                            <Switch
                                v-else-if="puede.desactivar"
                                :model-value="persona.estado === 'ACTIVO'"
                                :disabled="loading"
                                @update:model-value="(val) => onToggleEstado(persona, val)"
                            />
                        </TableCell>
                    </TableRow>
                    <TableRow v-if="!personas.length">
                        <TableCell colspan="8" class="text-center text-gray-400 p-4">
                            <SearchX class="mx-auto h-10 w-10 text-gray-400" />
                            <p class="mt-2">
                                {{ hayFiltros
                                    ? 'Ninguna persona coincide con los filtros.'
                                    : 'No se encontraron personas registradas.' }}
                            </p>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </CardContent>

        <CardFooter v-if="paginacion.total" class="flex flex-col sm:flex-row items-center justify-between gap-4">
            <p class="text-sm text-muted-foreground">
                Mostrando {{ personas.length }} de {{ paginacion.total }}
                {{ paginacion.total === 1 ? 'persona' : 'personas' }}
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

    <AlertDialog v-model:open="confirmarDesactivacion">
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>¿Desactivar persona?</AlertDialogTitle>
                <AlertDialogDescription>
                    Estás a punto de dar de baja a
                    <span class="font-medium">{{ personaPendiente?.nombre_completo }}</span>.
                    Conservará su historial y su número de empleado, pero no podrá usar su gafete,
                    generar fichas ni validar consumos. ¿Deseas continuar?
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel @click="cerrarModal">Cancelar</AlertDialogCancel>
                <AlertDialogAction @click="confirmarDesactivar">Desactivar</AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>

    <Dialog v-model:open="modalpersona">
        <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-md">
            <form @submit.prevent="guardar">
                <DialogHeader>
                    <DialogTitle>
                        {{ editandoId ? 'Editar persona' : 'Agregar persona' }}
                    </DialogTitle>
                    <DialogDescription>
                        {{ editandoId
                            ? 'Modifica los datos de la persona y guarda los cambios.'
                            : 'Registra a una nueva persona en el sistema.' }}
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4 py-4">
                    <div class="grid gap-2">
                        <Label for="numero_empleado">Número de empleado</Label>
                        <Input
                            id="numero_empleado"
                            v-model="form.numero_empleado"
                            placeholder="Ej. 1234"
                            autocomplete="off"
                            :disabled="!!editandoId"
                        />
                        <!-- El número de empleado es un identificador permanente: no se puede
                             cambiar ni reasignar (§3.2). En edición se muestra solo de lectura. -->
                        <p v-if="editandoId" class="text-xs text-muted-foreground">
                            El número de empleado no puede modificarse.
                        </p>
                        <p v-if="errores.numero_empleado" class="text-sm text-red-600">
                            {{ errores.numero_empleado[0] }}
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="nombre">Nombre</Label>
                        <Input
                            id="nombre"
                            v-model="form.nombre"
                            placeholder="Ej. Juan"
                            autocomplete="off"
                        />
                        <p v-if="errores.nombre" class="text-sm text-red-600">
                            {{ errores.nombre[0] }}
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="primer_apellido">Primer apellido</Label>
                        <Input
                            id="primer_apellido"
                            v-model="form.primer_apellido"
                            placeholder="Ej. Pérez"
                            autocomplete="off"
                        />
                        <p v-if="errores.primer_apellido" class="text-sm text-red-600">
                            {{ errores.primer_apellido[0] }}
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="segundo_apellido">Segundo apellido (opcional)</Label>
                        <Input
                            id="segundo_apellido"
                            v-model="form.segundo_apellido"
                            placeholder="Ej. Ruiz"
                            autocomplete="off"
                        />
                        <p v-if="errores.segundo_apellido" class="text-sm text-red-600">
                            {{ errores.segundo_apellido[0] }}
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="departamento_id">Departamento</Label>
                        <Select v-model="form.departamento_id">
                            <SelectTrigger id="departamento_id" class="w-full">
                                <SelectValue placeholder="Selecciona un departamento" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="depto in opcionesDepartamento"
                                    :key="depto.id"
                                    :value="String(depto.id)"
                                >
                                    {{ depto.nombre }}{{ depto.inactivo ? ' (inactivo)' : '' }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p v-if="errores.departamento_id" class="text-sm text-red-600">
                            {{ errores.departamento_id[0] }}
                        </p>
                    </div>

                    <!-- Cuenta de sistema (§3.1). Solo en el alta: cambiar el correo, el rol o
                         la contraseña de una cuenta existente va por el módulo de usuarios, para
                         no convertir este formulario en una segunda puerta a las credenciales. -->
                    <!-- Fotografía (§3.1): la misma que se imprime en el gafete. -->
                    <div v-if="puede.editar" class="rounded-md border p-3 grid gap-3">
                        <div class="flex items-center justify-between gap-2">
                            <div class="grid gap-0.5">
                                <Label>Fotografía (opcional)</Label>
                                <span class="text-xs text-muted-foreground">
                                    Es la que aparece en el gafete.
                                </span>
                            </div>
                            <Button
                                v-if="!tomandoFoto"
                                type="button"
                                size="sm"
                                variant="outline"
                                @click="tomandoFoto = true"
                            >
                                {{ personaEditada?.foto_url ? 'Cambiar foto' : 'Tomar foto' }}
                            </Button>
                            <Button v-else type="button" size="sm" variant="ghost" @click="cancelarFoto">
                                Cancelar
                            </Button>
                        </div>

                        <CapturaFoto v-if="tomandoFoto" @capturada="alCapturarFoto" />
                        <img
                            v-else-if="personaEditada?.foto_url"
                            :src="personaEditada.foto_url"
                            alt="Fotografía actual"
                            class="mx-auto aspect-3/4 w-24 rounded-md border object-cover"
                        />
                        <p v-if="fotoNueva" class="text-center text-xs text-muted-foreground">
                            La foto se guardará al presionar {{ editandoId ? 'Actualizar' : 'Guardar' }}.
                        </p>
                    </div>

                    <div v-if="!editandoId && puedeCrearCuentas" class="rounded-md border p-3 grid gap-3">
                        <div class="flex items-start gap-2">
                            <Switch id="requiere_cuenta" v-model="requiereCuenta" />
                            <div class="grid gap-0.5">
                                <Label for="requiere_cuenta">Requiere cuenta de sistema</Label>
                                <span class="text-xs text-muted-foreground">
                                    Sin cuenta, la persona usa el comedor con su gafete pero no
                                    entra a los módulos administrativos.
                                </span>
                            </div>
                        </div>

                        <template v-if="requiereCuenta">
                            <div class="grid gap-2">
                                <Label for="cuenta_email">Correo de acceso</Label>
                                <Input
                                    id="cuenta_email"
                                    v-model="cuenta.email"
                                    type="email"
                                    placeholder="Ej. juan.perez@arod.com"
                                    autocomplete="off"
                                />
                                <p v-if="errores['cuenta.email']" class="text-sm text-red-600">
                                    {{ errores['cuenta.email'][0] }}
                                </p>
                            </div>

                            <div class="grid gap-2">
                                <Label for="cuenta_password">Contraseña</Label>
                                <Input
                                    id="cuenta_password"
                                    v-model="cuenta.password"
                                    type="password"
                                    autocomplete="new-password"
                                />
                                <p v-if="errores['cuenta.password']" class="text-sm text-red-600">
                                    {{ errores['cuenta.password'][0] }}
                                </p>
                            </div>

                            <div class="grid gap-2">
                                <Label for="cuenta_password_confirmation">Confirmar contraseña</Label>
                                <Input
                                    id="cuenta_password_confirmation"
                                    v-model="cuenta.password_confirmation"
                                    type="password"
                                    autocomplete="new-password"
                                />
                            </div>

                            <div class="grid gap-2">
                                <Label for="cuenta_rol">Rol</Label>
                                <Select v-model="cuenta.rol_id">
                                    <SelectTrigger id="cuenta_rol" class="w-full">
                                        <SelectValue placeholder="Selecciona un rol" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="rol in roles"
                                            :key="rol.id"
                                            :value="String(rol.id)"
                                        >
                                            {{ rol.nombre }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <!-- §5.1: exactamente un rol por cuenta. -->
                                <p v-if="errores['cuenta.rol_id']" class="text-sm text-red-600">
                                    {{ errores['cuenta.rol_id'][0] }}
                                </p>
                            </div>
                        </template>
                    </div>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="modalpersona = false">
                        Cancelar
                    </Button>
                    <Button type="submit" :disabled="loading">
                        {{ editandoId ? 'Actualizar' : 'Guardar' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <GafeteModal
        v-model:open="modalgafete"
        :persona="personaGafete"
        @actualizado="fetchPersonas()"
    />

</template>
