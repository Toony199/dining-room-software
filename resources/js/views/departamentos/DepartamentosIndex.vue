<script setup>
import { computed, reactive, ref, onMounted, watch } from 'vue'
import { useDebounceFn } from '@vueuse/core'

import { useDepartamentosApi } from '@/composables/useDepartamentosApi.js'
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

import { SquarePen, Plus, SearchX, Search } from '@lucide/vue'

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


const {
    loading,
    departamentos,
    errores,
    paginacion,
    filtros,
    fetchDepartamentos,
    irAPrimeraPagina,
    createDepartamento,
    updateDepartamento,
    desactivarDepartamento,
    activarDepartamento,
    limpiarErrores,
} = useDepartamentosApi();

const auth = useAuthStore();

// Qué acciones ofrece la interfaz según el rol. Es comodidad, no seguridad: el backend comprueba
// el permiso en cada operación (§5.6); esto solo evita ofrecer botones que responderían 403.
const puede = computed(() => ({
    crear: auth.tienePermiso('departamentos.crear'),
    editar: auth.tienePermiso('departamentos.editar'),
    desactivar: auth.tienePermiso('departamentos.desactivar'),
}));

// Sin permiso para editar ni para desactivar, la columna Acciones sobra.
const hayAcciones = computed(() => puede.value.editar || puede.value.desactivar);

// Estado del formulario. editandoId = null → alta; con id → edición.
const editandoId = ref(null);
const form = reactive({
    nombre: '',
    activo: true,
});

// Estado del modal de confirmación de desactivación.
const confirmarDesactivacion = ref(false);
const modaldepartamento = ref(false);
const deptoPendiente = ref(null);

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

// Sincroniza el control de paginación con el paginador del backend.
const paginaActual = computed({
    get: () => paginacion.current_page,
    set: (pagina) => fetchDepartamentos(pagina),
});

const hayFiltros = computed(() => filtros.q !== '' || filtros.activo !== '');

// Los registros cargados antes de que existiera el módulo pueden no tener timestamps:
// sin este guardia, `new Date(null)` los pinta como 31/12/1969.
const fecha = (valor) => (valor ? new Date(valor).toLocaleString() : '—');

const limpiarFiltros = () => {
    filtros.q = '';
    filtros.activo = '';
    irAPrimeraPagina();
};

// Si al desactivar/filtrar la página actual queda vacía pero aún hay registros,
// retrocede a la última página con contenido (p. ej. borrar el único de la pág. 3).
watch(departamentos, (lista) => {
    if (!lista.length && paginacion.current_page > 1) {
        fetchDepartamentos(paginacion.last_page);
    }
});

// --- Acciones ------------------------------------------------------------

// Se dispara cuando el usuario mueve el Switch de una fila.
// nuevoValor === true  → activar directo.
// nuevoValor === false → pedir confirmación antes de desactivar.
const onToggleActivo = async (depto, nuevoValor) => {
    if (nuevoValor) {
        cambiandoId.value = depto.id;
        try {
            await activarDepartamento(depto.id);
        } finally {
            cambiandoId.value = null;
        }
    } else {
        deptoPendiente.value = depto;
        confirmarDesactivacion.value = true;
    }
};

const confirmarDesactivar = async () => {
    const depto = deptoPendiente.value;
    confirmarDesactivacion.value = false;

    if (depto) {
        cambiandoId.value = depto.id;
        try {
            await desactivarDepartamento(depto.id);
        } finally {
            cambiandoId.value = null;
            deptoPendiente.value = null;
        }
    }
};

// Abre el modal en modo ALTA (formulario limpio).
const abrirCrear = () => {
    resetForm();
    modaldepartamento.value = true;
};

const cerrarModal = () => {
    confirmarDesactivacion.value = false;
    deptoPendiente.value = null;
};

const resetForm = () => {
    editandoId.value = null;
    form.nombre = '';
    form.activo = true;
    limpiarErrores();
};

// Abre el modal en modo EDICIÓN, precargando los datos de la fila.
const editar = (depto) => {
    editandoId.value = depto.id;
    form.nombre = depto.nombre;
    form.activo = depto.activo;
    limpiarErrores();
    modaldepartamento.value = true;
};

const guardar = async () => {
    const payload = { nombre: form.nombre, activo: form.activo };
    const ok = editandoId.value
        ? await updateDepartamento(editandoId.value, payload)
        : await createDepartamento(payload);

    // Si la API devolvió errores de validación (422), `ok` es false y
    // dejamos el modal abierto mostrando los mensajes.
    //
    // No se llama a resetForm() aquí: limpiar editandoId mientras el diálogo se está
    // cerrando hace que el título parpadee a "Agregar departamento". Ambos puntos de
    // entrada (abrirCrear / editar) inicializan el formulario, así que basta con cerrar.
    if (ok) {
        modaldepartamento.value = false;
        limpiarErrores();
    }
};

onMounted(() => fetchDepartamentos(1));
</script>

<template>

    <div>
        <h1 class="text-2xl font-bold">Departamentos</h1>
        <h2 class="text-sm text-gray-600">Módulo de gestión de departamentos.</h2>
    </div>

    <Card>

        <CardHeader>
            <div class="flex flex-col sm:flex-row justify-between gap-2">
                <div>
                    <CardTitle>Tabla de departamentos</CardTitle>
                    <CardDescription>
                        Aquí puedes ver todos los departamentos existentes y sus estados.
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
                        placeholder="Buscar por nombre…"
                        class="pl-8"
                        autocomplete="off"
                        @update:model-value="buscar"
                    />
                </div>

                <Select v-model="filtroActivo">
                    <SelectTrigger class="w-full sm:w-44">
                        <SelectValue placeholder="Estado" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="todos">Todos</SelectItem>
                        <SelectItem value="1">Activos</SelectItem>
                        <SelectItem value="0">Inactivos</SelectItem>
                    </SelectContent>
                </Select>

                <Button v-if="hayFiltros" variant="ghost" @click="limpiarFiltros">
                    Limpiar
                </Button>
            </div>
        </CardHeader>

        <CardContent>

            <Table>
                <TableCaption v-if="departamentos.length">Lista de los departamentos existentes.</TableCaption>
                <TableHeader class="bg-stone-50">
                    <TableRow>
                        <TableHead class="text-center font-bold">
                            Identificador
                        </TableHead>
                        <TableHead class="text-center font-bold">Nombre</TableHead>
                        <TableHead class="text-center font-bold">Estado</TableHead>
                        <TableHead class="text-center font-bold">Creado</TableHead>
                        <TableHead class="text-center font-bold">Modificado</TableHead>
                        <TableHead v-if="hayAcciones" class="text-center font-bold">Acciones</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="depto in departamentos" :key="depto.id">
                        <TableCell class="text-center">
                            {{ depto.id }}
                        </TableCell>
                        <TableCell class="text-center">{{ depto.nombre }}</TableCell>
                        <TableCell class="text-center">
                            <Badge v-if="cambiandoId === depto.id" variant="outline">
                                <Spinner class="w-4 h-4" />
                            </Badge>
                            <Badge
                                v-else
                                :variant="depto.activo ? 'default' : 'destructive'"
                                :class="depto.activo ? 'bg-green-600' : ''"
                            >
                                {{ depto.activo ? 'Activo' : 'Inactivo' }}
                            </Badge>
                        </TableCell>
                        <TableCell class="text-center">
                            {{ fecha(depto.created_at) }}
                        </TableCell>
                        <TableCell class="text-center">
                            {{ fecha(depto.updated_at) }}
                        </TableCell>
                        <TableCell v-if="hayAcciones" class="flex justify-center gap-4">
                            <SquarePen
                                v-if="puede.editar"
                                @click="editar(depto)"
                                class="w-5 text-sky-700 cursor-pointer"
                                ></SquarePen>
                            <Switch
                                v-if="puede.desactivar"
                                :model-value="depto.activo"
                                :disabled="loading"
                                @update:model-value="(val) => onToggleActivo(depto, val)"
                            />
                        </TableCell>
                    </TableRow>
                    <TableRow v-if="!departamentos.length">
                        <TableCell colspan="6" class="text-center text-gray-400 p-4">
                            <SearchX class="mx-auto h-10 w-10 text-gray-400" />
                            <p class="mt-2">
                                {{ hayFiltros
                                    ? 'Ningún departamento coincide con los filtros.'
                                    : 'No se encontraron departamentos registrados.' }}
                            </p>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </CardContent>

        <CardFooter v-if="paginacion.total" class="flex flex-col sm:flex-row items-center justify-between gap-4">
            <p class="text-sm text-muted-foreground">
                Mostrando {{ departamentos.length }} de {{ paginacion.total }}
                {{ paginacion.total === 1 ? 'departamento' : 'departamentos' }}
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
                <AlertDialogTitle>¿Desactivar departamento?</AlertDialogTitle>
                <AlertDialogDescription>
                    Estás a punto de desactivar
                    <span class="font-medium">{{ deptoPendiente?.nombre }}</span>.
                    El registro se conservará pero dejará de estar activo. ¿Deseas continuar?
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel @click="cerrarModal">Cancelar</AlertDialogCancel>
                <AlertDialogAction @click="confirmarDesactivar">Desactivar</AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>

    <Dialog v-model:open="modaldepartamento">
        <DialogContent class="sm:max-w-md">
            <form @submit.prevent="guardar">
                <DialogHeader>
                    <DialogTitle>
                        {{ editandoId ? 'Editar departamento' : 'Agregar departamento' }}
                    </DialogTitle>
                    <DialogDescription>
                        {{ editandoId
                            ? 'Modifica los datos del departamento y guarda los cambios.'
                            : 'Registra un nuevo departamento en el sistema.' }}
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4 py-4">
                    <div class="grid gap-2">
                        <Label for="nombre">Nombre</Label>
                        <Input
                            id="nombre"
                            v-model="form.nombre"
                            placeholder="Ej. Producción"
                            autocomplete="off"
                        />
                        <p v-if="errores.nombre" class="text-sm text-red-600">
                            {{ errores.nombre[0] }}
                        </p>
                    </div>

                    <div v-if="puede.desactivar" class="flex items-center gap-2">
                        <Switch id="activo" v-model="form.activo" />
                        <Label for="activo">Activo</Label>
                    </div>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="modaldepartamento = false">
                        Cancelar
                    </Button>
                    <Button type="submit" :disabled="loading">
                        {{ editandoId ? 'Actualizar' : 'Guardar' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

</template>
