<script setup>
import { reactive, ref, onMounted } from 'vue'

import { useDepartamentosApi } from '@/composables/useDepartamentosApi.js'

import {
    Card,
    CardAction,
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
  TableFooter,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'

import { SquarePen, Plus, SearchX } from '@lucide/vue'

import { Button } from '@/components/ui/button'
import { Switch } from '@/components/ui/switch'
import { Badge } from '@/components/ui/badge'
import { Spinner } from '@/components/ui/spinner'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
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
    fetchDepartamentos,
    createDepartamento,
    updateDepartamento,
    desactivarDepartamento,
    activarDepartamento,
    limpiarErrores,
} = useDepartamentosApi();

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
    if (ok) {
        resetForm();
        modaldepartamento.value = false;
    }
};

onMounted(fetchDepartamentos);
</script>

<template>

    <div>
        <h1 class="text-2xl font-bold">Departamentos</h1>
        <h2 class="text-sm text-gray-600">Módulo de gestión de departamentos.</h2>
    </div>

    <Card>

        <CardHeader>
            <div class="flex flex-col sm:flex-row justify-between gap-2">
                <div class="">
                    <CardTitle>Tabla de departamentos</CardTitle>
                    <CardDescription>
                        Aquí puedes ver todos los departamentos existentes y sus estados.
                    </CardDescription>
                </div>
                
                <div class="w-full flex justify-end">
                    <Button @click="abrirCrear">
                        <Plus/>
                        Agregar
                    </Button>
                </div>
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
                        <TableHead class="text-center font-bold">Acciones</TableHead>
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
                            {{ new Date(depto.created_at).toLocaleString() }}
                        </TableCell>
                        <TableCell class="text-center">
                            {{ new Date(depto.updated_at).toLocaleString() }}
                        </TableCell>
                        <TableCell class="flex justify-center gap-4">
                            <SquarePen  
                                @click="editar(depto)"
                                class="w-5 text-sky-700 cursor-pointer"
                                ></SquarePen>
                            <Switch
                                :model-value="depto.activo"
                                :disabled="loading"
                                @update:model-value="(val) => onToggleActivo(depto, val)"
                            />
                        </TableCell>
                    </TableRow>
                    <TableRow v-if="!departamentos.length">
                        <TableCell colspan="6" class="text-center text-gray-400 p-4">
                            <SearchX class="mx-auto h-10 w-10 text-gray-400" />
                            <p class="mt-2">No se encontraron departamentos registrados.</p>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </CardContent>
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

                    <div class="flex items-center gap-2">
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
