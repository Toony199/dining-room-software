<script setup>
import { computed, reactive, ref, onMounted, watch } from 'vue'
import { useDebounceFn } from '@vueuse/core'

import { useRoles } from '@/composables/useRoles.js'

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

import { SquarePen, Plus, SearchX, Search, Lock } from '@lucide/vue'

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
    roles,
    permisosPorModulo,
    errores,
    paginacion,
    filtros,
    fetchRoles,
    fetchRol,
    fetchPermisos,
    irAPrimeraPagina,
    createRol,
    updateRol,
    desactivarRol,
    activarRol,
    limpiarErrores,
} = useRoles();

// Estado del formulario. editandoId = null → alta; con id → edición.
const editandoId = ref(null);
const form = reactive({
    nombre: '',
    descripcion: '',
    // Ids de permisos que quedarán asignados. Viaja completo: el backend hace sync().
    permisos: [],
});

// Estado del modal de confirmación de desactivación.
const confirmarDesactivacion = ref(false);
const modalrol = ref(false);
const rolPendiente = ref(null);

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
    set: (pagina) => fetchRoles(pagina),
});

const hayFiltros = computed(() => filtros.q !== '' || filtros.activo !== '');

const limpiarFiltros = () => {
    filtros.q = '';
    filtros.activo = '';
    irAPrimeraPagina();
};

const fecha = (valor) => (valor ? new Date(valor).toLocaleString() : '—');

// Si al desactivar/filtrar la página actual queda vacía pero aún hay registros,
// retrocede a la última página con contenido.
watch(roles, (lista) => {
    if (!lista.length && paginacion.current_page > 1) {
        fetchRoles(paginacion.last_page);
    }
});

// --- Permisos ------------------------------------------------------------

const tienePermiso = (id) => form.permisos.includes(id);

const togglePermiso = (id, marcado) => {
    if (marcado) {
        if (!tienePermiso(id)) form.permisos.push(id);
    } else {
        form.permisos = form.permisos.filter((p) => p !== id);
    }
};

// Estado de la casilla del encabezado de cada módulo: marca o desmarca el grupo completo.
const moduloCompleto = (grupo) => grupo.permisos.every((p) => tienePermiso(p.id));

const toggleModulo = (grupo, marcado) => {
    for (const permiso of grupo.permisos) {
        togglePermiso(permiso.id, marcado);
    }
};

// --- Acciones ------------------------------------------------------------

// Se dispara cuando el usuario mueve el Switch de una fila.
// nuevoValor === true  → activar directo.
// nuevoValor === false → pedir confirmación antes de desactivar.
const onToggleActivo = async (rol, nuevoValor) => {
    if (nuevoValor) {
        cambiandoId.value = rol.id;
        try {
            await activarRol(rol.id);
        } finally {
            cambiandoId.value = null;
        }
    } else {
        rolPendiente.value = rol;
        confirmarDesactivacion.value = true;
    }
};

const confirmarDesactivar = async () => {
    const rol = rolPendiente.value;
    confirmarDesactivacion.value = false;

    if (rol) {
        cambiandoId.value = rol.id;
        try {
            // Si el rol tiene usuarios asignados el backend responde 422 (§5.5) y el
            // composable lo explica en un toast; la fila se recarga sin cambios.
            await desactivarRol(rol.id);
        } finally {
            cambiandoId.value = null;
            rolPendiente.value = null;
        }
    }
};

// Abre el modal en modo ALTA (formulario limpio).
const abrirCrear = () => {
    resetForm();
    modalrol.value = true;
};

const cerrarModal = () => {
    confirmarDesactivacion.value = false;
    rolPendiente.value = null;
};

const resetForm = () => {
    editandoId.value = null;
    form.nombre = '';
    form.descripcion = '';
    form.permisos = [];
    limpiarErrores();
};

// Abre el modal en modo EDICIÓN. El index no trae los permisos de cada rol (solo el conteo),
// así que se piden por rol para precargar las casillas.
const editar = async (rol) => {
    resetForm();
    editandoId.value = rol.id;
    form.nombre = rol.nombre;
    form.descripcion = rol.descripcion ?? '';
    modalrol.value = true;

    const completo = await fetchRol(rol.id);

    if (completo) {
        form.permisos = completo.permisos.map((p) => p.id);
    }
};

const guardar = async () => {
    const payload = {
        nombre: form.nombre,
        descripcion: form.descripcion || null,
        permisos: form.permisos,
    };

    const ok = editandoId.value
        ? await updateRol(editandoId.value, payload)
        : await createRol(payload);

    // Si la API devolvió errores de validación (422), `ok` es false y
    // dejamos el modal abierto mostrando los mensajes.
    if (ok) {
        modalrol.value = false;
        limpiarErrores();
    }
};

onMounted(() => {
    fetchRoles(1);
    fetchPermisos();
});
</script>

<template>

    <div>
        <h1 class="text-2xl font-bold">Roles</h1>
        <h2 class="text-sm text-gray-600">Módulo de roles y permisos del sistema.</h2>
    </div>

    <Card>

        <CardHeader>
            <div class="flex flex-col sm:flex-row justify-between gap-2">
                <div>
                    <CardTitle>Tabla de roles</CardTitle>
                    <CardDescription>
                        Cada cuenta de sistema tiene exactamente un rol, y sus permisos son los
                        que el rol tenga configurados en este momento.
                    </CardDescription>
                </div>

                <div class="flex justify-end">
                    <Button @click="abrirCrear">
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
                <TableCaption v-if="roles.length">Lista de los roles configurados.</TableCaption>
                <TableHeader class="bg-stone-50">
                    <TableRow>
                        <TableHead class="text-center font-bold">Nombre</TableHead>
                        <TableHead class="text-center font-bold">Descripción</TableHead>
                        <TableHead class="text-center font-bold">Permisos</TableHead>
                        <TableHead class="text-center font-bold">Usuarios</TableHead>
                        <TableHead class="text-center font-bold">Estado</TableHead>
                        <TableHead class="text-center font-bold">Modificado</TableHead>
                        <TableHead class="text-center font-bold">Acciones</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="rol in roles" :key="rol.id">
                        <TableCell class="text-center font-medium">
                            {{ rol.nombre }}
                            <Badge v-if="rol.protegido" variant="outline" class="ml-1">Sistema</Badge>
                        </TableCell>
                        <TableCell class="text-center text-muted-foreground">
                            {{ rol.descripcion ?? '—' }}
                        </TableCell>
                        <TableCell class="text-center">
                            <Badge variant="outline">{{ rol.permisos_count }}</Badge>
                        </TableCell>
                        <TableCell class="text-center">
                            <Badge variant="outline">{{ rol.usuarios_count }}</Badge>
                        </TableCell>
                        <TableCell class="text-center">
                            <Badge v-if="cambiandoId === rol.id" variant="outline">
                                <Spinner class="w-4 h-4" />
                            </Badge>
                            <Badge
                                v-else
                                :variant="rol.activo ? 'default' : 'destructive'"
                                :class="rol.activo ? 'bg-green-600' : ''"
                            >
                                {{ rol.activo ? 'Activo' : 'Inactivo' }}
                            </Badge>
                        </TableCell>
                        <TableCell class="text-center">{{ fecha(rol.updated_at) }}</TableCell>
                        <TableCell class="flex justify-center gap-4">
                            <!-- El rol de acceso total no se edita ni se desactiva (§5.2):
                                 hacerlo dejaría la instalación sin quien pueda administrarla.
                                 El backend lo rechaza igual; esto solo evita el intento. -->
                            <Tooltip v-if="rol.protegido">
                                <TooltipTrigger as-child>
                                    <Lock class="w-5 text-muted-foreground cursor-not-allowed" />
                                </TooltipTrigger>
                                <TooltipContent>
                                    Rol protegido: el sistema lo necesita para conservar el
                                    acceso administrativo.
                                </TooltipContent>
                            </Tooltip>
                            <SquarePen
                                v-else
                                @click="editar(rol)"
                                class="w-5 text-sky-700 cursor-pointer"
                                ></SquarePen>

                            <Tooltip v-if="rol.protegido">
                                <TooltipTrigger as-child>
                                    <span class="cursor-not-allowed">
                                        <Switch :model-value="true" disabled />
                                    </span>
                                </TooltipTrigger>
                                <TooltipContent>Un rol protegido no puede desactivarse.</TooltipContent>
                            </Tooltip>

                            <!-- Un rol con usuarios asignados no se puede desactivar (§5.5):
                                 el switch se bloquea y el tooltip explica por qué, en vez de
                                 dejar que el usuario descubra el 422 al intentarlo. -->
                            <Tooltip v-else-if="rol.activo && rol.usuarios_count > 0">
                                <TooltipTrigger as-child>
                                    <span class="cursor-not-allowed">
                                        <Switch :model-value="true" disabled />
                                    </span>
                                </TooltipTrigger>
                                <TooltipContent>
                                    Reasigna primero a
                                    {{ rol.usuarios_count === 1 ? 'su usuario' : `sus ${rol.usuarios_count} usuarios` }}.
                                </TooltipContent>
                            </Tooltip>
                            <Switch
                                v-else
                                :model-value="rol.activo"
                                :disabled="loading"
                                @update:model-value="(val) => onToggleActivo(rol, val)"
                            />
                        </TableCell>
                    </TableRow>
                    <TableRow v-if="!roles.length">
                        <TableCell colspan="7" class="text-center text-gray-400 p-4">
                            <SearchX class="mx-auto h-10 w-10 text-gray-400" />
                            <p class="mt-2">
                                {{ hayFiltros
                                    ? 'Ningún rol coincide con los filtros.'
                                    : 'No se encontraron roles configurados.' }}
                            </p>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </CardContent>

        <CardFooter v-if="paginacion.total" class="flex flex-col sm:flex-row items-center justify-between gap-4">
            <p class="text-sm text-muted-foreground">
                Mostrando {{ roles.length }} de {{ paginacion.total }}
                {{ paginacion.total === 1 ? 'rol' : 'roles' }}
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
                <AlertDialogTitle>¿Desactivar rol?</AlertDialogTitle>
                <AlertDialogDescription>
                    Estás a punto de desactivar
                    <span class="font-medium">{{ rolPendiente?.nombre }}</span>.
                    El registro se conservará, pero el rol dejará de poder asignarse a cuentas
                    nuevas. ¿Deseas continuar?
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel @click="cerrarModal">Cancelar</AlertDialogCancel>
                <AlertDialogAction @click="confirmarDesactivar">Desactivar</AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>

    <Dialog v-model:open="modalrol">
        <DialogContent class="sm:max-w-2xl">
            <form @submit.prevent="guardar">
                <DialogHeader>
                    <DialogTitle>
                        {{ editandoId ? 'Editar rol' : 'Agregar rol' }}
                    </DialogTitle>
                    <DialogDescription>
                        {{ editandoId
                            ? 'Los cambios de permisos alcanzan de inmediato a todos los usuarios que tengan este rol.'
                            : 'Define el rol y marca los permisos que otorgará.' }}
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4 py-4">
                    <div class="grid gap-2">
                        <Label for="nombre">Nombre</Label>
                        <Input
                            id="nombre"
                            v-model="form.nombre"
                            placeholder="Ej. Cobrador"
                            autocomplete="off"
                        />
                        <p v-if="errores.nombre" class="text-sm text-red-600">
                            {{ errores.nombre[0] }}
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="descripcion">Descripción (opcional)</Label>
                        <Input
                            id="descripcion"
                            v-model="form.descripcion"
                            placeholder="Ej. Confirma el pago físico de las fichas en caja."
                            autocomplete="off"
                        />
                        <p v-if="errores.descripcion" class="text-sm text-red-600">
                            {{ errores.descripcion[0] }}
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <div class="flex items-center gap-2">
                            <Lock class="h-4 w-4 text-muted-foreground" />
                            <Label>Permisos</Label>
                            <Badge variant="outline">{{ form.permisos.length }}</Badge>
                        </div>

                        <div class="max-h-72 overflow-y-auto rounded-md border divide-y">
                            <div v-for="grupo in permisosPorModulo" :key="grupo.modulo" class="p-3">
                                <div class="flex items-center gap-2 pb-2">
                                    <Switch
                                        :model-value="moduloCompleto(grupo)"
                                        @update:model-value="(val) => toggleModulo(grupo, val)"
                                    />
                                    <span class="text-sm font-semibold capitalize">{{ grupo.modulo }}</span>
                                </div>

                                <div class="grid sm:grid-cols-2 gap-2 pl-2">
                                    <label
                                        v-for="permiso in grupo.permisos"
                                        :key="permiso.id"
                                        class="flex items-start gap-2 cursor-pointer"
                                    >
                                        <input
                                            type="checkbox"
                                            class="mt-1"
                                            :checked="tienePermiso(permiso.id)"
                                            @change="togglePermiso(permiso.id, $event.target.checked)"
                                        />
                                        <span class="text-sm">
                                            <span class="font-mono text-xs">{{ permiso.clave }}</span>
                                            <span class="block text-xs text-muted-foreground">
                                                {{ permiso.descripcion }}
                                            </span>
                                        </span>
                                    </label>
                                </div>
                            </div>

                            <p v-if="!permisosPorModulo.length" class="p-4 text-sm text-muted-foreground">
                                No hay permisos en el catálogo. Corre <code>php artisan db:seed</code>.
                            </p>
                        </div>

                        <p v-if="errores.permisos" class="text-sm text-red-600">
                            {{ errores.permisos[0] }}
                        </p>
                    </div>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="modalrol = false">
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
