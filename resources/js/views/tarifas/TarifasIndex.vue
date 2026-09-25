<script setup>
import { computed, onMounted, reactive, ref } from 'vue'

import { useTarifas } from '@/composables/useTarifas.js'
import { useAuthStore } from '@/stores/auth.js'

import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog'
import {
    Pagination,
    PaginationContent,
    PaginationEllipsis,
    PaginationItem,
    PaginationNext,
    PaginationPrevious,
} from '@/components/ui/pagination'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
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
import { Plus, SearchX, TriangleAlert, X } from '@lucide/vue'

/**
 * Precio por día del comedor (§6.4, §18).
 *
 * El precio es general para todos: no hay precios por departamento ni por rol. No se edita ni se
 * borra, porque es el respaldo de lo que ya se cobró (§6.5); cambiarlo es registrar otro, que puede
 * entrar hoy o quedar programado, y el anterior se cierra el día previo.
 */
const {
    loading,
    tarifas,
    vigente,
    errores,
    paginacion,
    fetchTarifas,
    fetchVigente,
    createTarifa,
    cancelarTarifa,
    limpiarErrores,
} = useTarifas()

const auth = useAuthStore()

// Comodidad, no seguridad: el backend comprueba el permiso en cada operación (§5.6).
const puedeEditar = computed(() => auth.tienePermiso('tarifas.editar'))

const hoy = new Date().toLocaleDateString('sv-SE') // AAAA-MM-DD en hora local

const modal = ref(false)
const form = reactive({ precio: '', vigente_desde: hoy })

const abrirModal = () => {
    form.precio = ''
    form.vigente_desde = hoy
    limpiarErrores()
    modal.value = true
}

const esProgramada = computed(() => form.vigente_desde > hoy)

// Cambiar el precio el mismo día en que empezó el actual lo reemplaza: es el ajuste de emergencia.
const reemplazaElDeHoy = computed(
    () => form.vigente_desde === hoy && vigente.value?.vigente_desde?.slice(0, 10) === hoy
)

// Si ya hay un precio programado más adelante, el nuevo rige solo hasta que aquel entre.
const siguienteProgramada = computed(() => tarifas.value
    .filter((tarifa) => (tarifa.vigente_desde ?? '') > form.vigente_desde)
    .sort((a, b) => a.vigente_desde.localeCompare(b.vigente_desde))[0] ?? null)

const guardar = async () => {
    const creada = await createTarifa({ precio: form.precio, vigente_desde: form.vigente_desde })

    if (creada) {
        modal.value = false
    }
}

const paginaActual = computed({
    get: () => paginacion.current_page,
    set: (pagina) => fetchTarifas(pagina),
})

const dinero = (valor) => (valor === null || valor === undefined
    ? '—'
    : Number(valor).toLocaleString('es-MX', { style: 'currency', currency: 'MXN' }))

// Las fechas vienen como AAAA-MM-DD; se parten a mano para no restarles un día por zona horaria.
const fecha = (valor) => {
    if (!valor) {
        return '—'
    }

    const [anio, mes, dia] = valor.slice(0, 10).split('-')

    return `${dia}/${mes}/${anio}`
}

// --- Cancelar un precio programado ---------------------------------------------------

const confirmarCancelacion = ref(false)
const porCancelar = ref(null)

const pedirCancelacion = (tarifa) => {
    porCancelar.value = tarifa
    confirmarCancelacion.value = true
}

const cancelarConfirmado = async () => {
    confirmarCancelacion.value = false
    await cancelarTarifa(porCancelar.value)
}

const estado = (tarifa) => {
    if (tarifa.programada) {
        return { texto: 'Programada', variante: 'secondary', clase: '' }
    }

    return tarifa.vigente
        ? { texto: 'Vigente', variante: 'default', clase: 'bg-green-600' }
        : { texto: 'Anterior', variante: 'outline', clase: '' }
}

onMounted(() => {
    fetchVigente()
    fetchTarifas(1)
})
</script>

<template>
    <div>
        <h1 class="text-2xl font-bold">Precio del comedor</h1>
        <h2 class="text-sm text-gray-600">
            Precio por día, igual para todo el personal. Se usa al armar cada periodo de servicio.
        </h2>
    </div>

    <Card>
        <CardHeader>
            <div class="flex flex-col justify-between gap-2 sm:flex-row">
                <div>
                    <CardTitle>Precio vigente</CardTitle>
                    <CardDescription>
                        Es el que se copia a cada día de los periodos que se creen a partir de ahora.
                    </CardDescription>
                </div>
                <div class="flex justify-end">
                    <Button v-if="puedeEditar" @click="abrirModal">
                        <Plus />
                        {{ vigente ? 'Cambiar precio' : 'Registrar precio' }}
                    </Button>
                </div>
            </div>
        </CardHeader>

        <CardContent>
            <div v-if="vigente" class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <span class="text-4xl font-bold tabular-nums">{{ dinero(vigente.precio) }}</span>
                <span class="text-sm text-muted-foreground">
                    por día, desde el {{ fecha(vigente.vigente_desde) }}
                </span>
            </div>

            <div
                v-else
                class="flex gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900"
            >
                <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" />
                <span>
                    <span class="font-medium">Todavía no hay un precio registrado.</span>
                    Sin él no se pueden crear periodos de servicio, porque cada día del periodo
                    guarda el precio con el que se cobró.
                </span>
            </div>
        </CardContent>
    </Card>

    <Card>
        <CardHeader>
            <CardTitle>Historial</CardTitle>
            <CardDescription>
                Los precios anteriores no se modifican ni se borran: son el respaldo de lo que ya se
                cobró.
            </CardDescription>
        </CardHeader>

        <CardContent>
            <Table>
                <TableCaption v-if="tarifas.length">Precios registrados.</TableCaption>
                <TableHeader class="bg-stone-50">
                    <TableRow>
                        <TableHead class="text-center font-bold">Precio</TableHead>
                        <TableHead class="text-center font-bold">Desde</TableHead>
                        <TableHead class="text-center font-bold">Hasta</TableHead>
                        <TableHead class="text-center font-bold">Estado</TableHead>
                        <TableHead class="text-center font-bold">Registrado por</TableHead>
                        <TableHead v-if="puedeEditar" class="text-center font-bold">Acciones</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="tarifa in tarifas" :key="tarifa.id">
                        <TableCell class="text-center font-medium tabular-nums">
                            {{ dinero(tarifa.precio) }}
                        </TableCell>
                        <TableCell class="text-center tabular-nums">{{ fecha(tarifa.vigente_desde) }}</TableCell>
                        <TableCell class="text-center tabular-nums">
                            <!-- La última no tiene fin: rige hasta que se registre otra. -->
                            {{ tarifa.vigente_hasta ? fecha(tarifa.vigente_hasta) : 'Sin fin' }}
                        </TableCell>
                        <TableCell class="text-center">
                            <Badge :variant="estado(tarifa).variante" :class="estado(tarifa).clase">
                                {{ estado(tarifa).texto }}
                            </Badge>
                        </TableCell>
                        <TableCell class="text-center">{{ tarifa.creado_por ?? '—' }}</TableCell>
                        <TableCell v-if="puedeEditar" class="text-center">
                            <!-- Solo los programados: los que ya rigieron respaldan lo cobrado. -->
                            <Button
                                v-if="tarifa.programada"
                                size="sm"
                                variant="ghost"
                                :disabled="loading"
                                @click="pedirCancelacion(tarifa)"
                            >
                                <X />
                                Cancelar
                            </Button>
                        </TableCell>
                    </TableRow>
                    <TableRow v-if="!tarifas.length">
                        <TableCell colspan="6" class="p-4 text-center text-gray-400">
                            <SearchX class="mx-auto h-10 w-10 text-gray-400" />
                            <p class="mt-2">Aún no se ha registrado ningún precio.</p>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </CardContent>

        <CardFooter v-if="paginacion.total" class="flex flex-col items-center justify-between gap-4 sm:flex-row">
            <p class="text-sm text-muted-foreground">
                Mostrando {{ tarifas.length }} de {{ paginacion.total }}
                {{ paginacion.total === 1 ? 'precio' : 'precios' }}
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

    <AlertDialog v-model:open="confirmarCancelacion">
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>¿Cancelar el precio programado?</AlertDialogTitle>
                <AlertDialogDescription>
                    El precio de
                    <span class="font-medium">{{ dinero(porCancelar?.precio) }}</span>
                    que iba a entrar el {{ fecha(porCancelar?.vigente_desde) }} se elimina, y el
                    precio anterior sigue rigiendo. Los periodos que ya se hayan armado conservan el
                    precio con el que se crearon: si alguno tomó este, hay que ajustarlo día por día
                    en el periodo, mientras esté en borrador.
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel>Conservarlo</AlertDialogCancel>
                <AlertDialogAction @click="cancelarConfirmado">Cancelar el precio</AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>

    <Dialog v-model:open="modal">
        <DialogContent class="sm:max-w-md">
            <form @submit.prevent="guardar">
                <DialogHeader>
                    <DialogTitle>Registrar precio</DialogTitle>
                    <DialogDescription>
                        El precio anterior se cierra el día antes de que empiece este, o queda
                        reemplazado si ambos empiezan el mismo día. Los periodos ya creados
                        conservan el precio con el que se armaron.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4 py-4">
                    <div class="grid gap-2">
                        <Label for="precio">Precio por día</Label>
                        <Input
                            id="precio"
                            v-model="form.precio"
                            type="number"
                            step="0.01"
                            min="0.01"
                            placeholder="Ej. 55.00"
                            autocomplete="off"
                        />
                        <p v-if="errores.precio" class="text-sm text-red-600">{{ errores.precio[0] }}</p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="vigente_desde">Rige desde</Label>
                        <Input id="vigente_desde" v-model="form.vigente_desde" type="date" :min="hoy" />
                        <p v-if="esProgramada" class="text-xs text-muted-foreground">
                            Queda programado: hasta ese día sigue rigiendo el precio actual.
                        </p>
                        <p v-else-if="reemplazaElDeHoy" class="text-xs text-amber-700">
                            Reemplaza el precio de hoy ({{ dinero(vigente.precio) }}): rige a partir
                            de ahora, y lo ya armado conserva el suyo.
                        </p>
                        <p v-else class="text-xs text-muted-foreground">
                            Rige desde hoy; el precio anterior queda cerrado ayer.
                        </p>
                        <p v-if="siguienteProgramada" class="text-xs text-muted-foreground">
                            Regirá hasta el {{ fecha(siguienteProgramada.vigente_desde) }}, cuando
                            entre el precio ya programado de {{ dinero(siguienteProgramada.precio) }}.
                        </p>
                        <p v-if="errores.vigente_desde" class="text-sm text-red-600">
                            {{ errores.vigente_desde[0] }}
                        </p>
                    </div>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="modal = false">Cancelar</Button>
                    <Button type="submit" :disabled="loading">Registrar</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
