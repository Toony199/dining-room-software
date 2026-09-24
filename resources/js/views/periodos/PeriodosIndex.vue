<script setup>
import { computed, onMounted, reactive, ref } from 'vue'

import { usePeriodos } from '@/composables/usePeriodos.js'
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
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { CalendarPlus, LockKeyhole, RotateCcw, SearchX, Settings2, TriangleAlert, Unlock } from '@lucide/vue'

/**
 * Periodos semanales de servicio (§6, §8, §9).
 *
 * Un periodo nace en borrador: ahí se marcan festivos, se ajustan precios y se mueve la ventana.
 * Abierto ya no se configura, porque cambiaría las reglas a quien ya generó su ficha. Cerrarlo es
 * una decisión de una persona; lo que decide si el kiosco acepta fichas es la ventana.
 */
const {
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
} = usePeriodos()

const { vigente: tarifaVigente, fetchVigente } = useTarifas()

const auth = useAuthStore()

// Comodidad, no seguridad: el backend comprueba el permiso en cada operación (§5.6).
const puede = computed(() => ({
    crear: auth.tienePermiso('periodos.crear'),
    editar: auth.tienePermiso('periodos.editar'),
    abrir: auth.tienePermiso('periodos.abrir'),
    cerrar: auth.tienePermiso('periodos.cerrar'),
    reabrir: auth.tienePermiso('periodos.reabrir'),
}))

const ESTADOS = {
    BORRADOR: { texto: 'Borrador', variante: 'secondary', clase: '' },
    ABIERTO: { texto: 'Abierto', variante: 'default', clase: 'bg-green-600' },
    PAGO_CERRADO: { texto: 'Pago cerrado', variante: 'outline', clase: '' },
    CONSOLIDADO: { texto: 'Consolidado', variante: 'outline', clase: '' },
}

const DIAS = ['', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo']

// Las fechas llegan como AAAA-MM-DD; se parten a mano para no restarles un día por zona horaria.
const aFecha = (valor) => {
    const [anio, mes, dia] = valor.slice(0, 10).split('-').map(Number)

    return new Date(anio, mes - 1, dia)
}

const fecha = (valor) => (valor ? aFecha(valor).toLocaleDateString('es-MX', { day: '2-digit', month: 'short' }) : '—')

const rango = (desde, hasta) => (desde && hasta
    ? `${fecha(desde)} – ${fecha(hasta)} ${aFecha(hasta).getFullYear()}`
    : '—')

const dinero = (valor) => Number(valor ?? 0).toLocaleString('es-MX', { style: 'currency', currency: 'MXN' })

const filtroEstado = computed({
    get: () => filtros.estado || 'todos',
    set: (valor) => {
        filtros.estado = valor === 'todos' ? '' : valor
        fetchPeriodos(1)
    },
})

const paginaActual = computed({
    get: () => paginacion.current_page,
    set: (pagina) => fetchPeriodos(pagina),
})

// --- Semana siguiente ---------------------------------------------------------------

const lunesSiguiente = computed(() => {
    const hoy = new Date()
    const lunes = new Date(hoy.getFullYear(), hoy.getMonth(), hoy.getDate())
    // getDay(): 0 = domingo. Se avanza al lunes de la semana que viene.
    lunes.setDate(lunes.getDate() + ((8 - (lunes.getDay() || 7)) % 7 || 7))

    return lunes.toLocaleDateString('sv-SE')
})

const faltaLaSemanaSiguiente = computed(
    () => !periodos.value.some((periodo) => periodo.fecha_inicio === lunesSiguiente.value)
)

// --- Detalle y configuración ---------------------------------------------------------

const modalDetalle = ref(false)
const periodo = ref(null)
const ventana = reactive({ ventana_inicio: '', ventana_fin: '' })
const dias = ref([])

const esBorrador = computed(() => periodo.value?.estado === 'BORRADOR')

const cargarDetalle = (datos) => {
    periodo.value = datos
    ventana.ventana_inicio = datos.ventana_inicio
    ventana.ventana_fin = datos.ventana_fin
    // Copia local: se edita aquí y se guarda día por día.
    dias.value = datos.dias.map((dia) => ({ ...dia, original: { ...dia } }))
}

const abrirDetalle = async (fila) => {
    limpiarErrores()
    const datos = await fetchPeriodo(fila.id)

    if (datos) {
        cargarDetalle(datos)
        modalDetalle.value = true
    }
}

const cambiado = (dia) => ['disponible', 'es_festivo', 'motivo_indisponibilidad', 'precio_aplicado']
    .some((campo) => String(dia[campo] ?? '') !== String(dia.original[campo] ?? ''))

const guardarVentana = async () => {
    const datos = await updateVentana(periodo.value.id, { ...ventana })

    if (datos) {
        cargarDetalle(datos)
    }
}

const guardarDia = async (dia) => {
    const datos = await updateDia(periodo.value.id, dia.id, {
        disponible: dia.disponible,
        es_festivo: dia.es_festivo,
        // Un día disponible no lleva motivo: el backend lo rechaza.
        motivo_indisponibilidad: dia.disponible ? null : (dia.motivo_indisponibilidad || null),
        precio_aplicado: dia.precio_aplicado,
    })

    if (datos) {
        cargarDetalle(datos)
    }
}

// Quitar el servicio de un día suele ser por festivo; marcarlo de vuelta devuelve el servicio.
const alCambiarDisponible = (dia, disponible) => {
    dia.disponible = disponible

    if (disponible) {
        dia.motivo_indisponibilidad = ''
        dia.es_festivo = false
    }
}

// --- Cambios de estado ----------------------------------------------------------------

const confirmacion = reactive({ abierta: false, accion: null, periodo: null })

const pedirCambio = (fila, accion) => {
    Object.assign(confirmacion, { abierta: true, accion, periodo: fila })
}

const confirmarCambio = async () => {
    const { periodo: fila, accion } = confirmacion
    confirmacion.abierta = false

    const datos = await cambiarEstado(fila.id, accion)

    if (datos && periodo.value?.id === datos.id) {
        cargarDetalle(datos)
    }
}

const TEXTOS_CONFIRMACION = {
    abrir: {
        titulo: '¿Abrir el periodo?',
        cuerpo: 'Los colaboradores podrán generar sus fichas en el kiosco durante la ventana, y la caja podrá cobrarlas. Ya no se podrán cambiar los días ni los precios.',
        boton: 'Abrir',
    },
    cerrar: {
        titulo: '¿Cerrar el pago del periodo?',
        cuerpo: 'Dejan de generarse fichas y de cobrarse. Las fichas que quedaron pendientes vencen y no cuentan para la proyección de porciones. Después de cerrar se genera el reporte.',
        boton: 'Cerrar',
    },
    reabrir: {
        titulo: '¿Reabrir el periodo?',
        cuerpo: 'Vuelve a admitir fichas y cobros mientras la ventana siga corriendo. Las fichas que ya vencieron no reviven.',
        boton: 'Reabrir',
    },
}

// --- Alta manual ------------------------------------------------------------------------

const modalAlta = ref(false)
const form = reactive({ fecha_inicio: '', fecha_fin: '', ventana_inicio: '', ventana_fin: '' })

const abrirAlta = () => {
    limpiarErrores()
    const lunes = aFecha(lunesSiguiente.value)
    const dia = (desplazamiento) => {
        const fecha = new Date(lunes)
        fecha.setDate(fecha.getDate() + desplazamiento)

        return fecha.toLocaleDateString('sv-SE')
    }

    // Los valores de siempre: servicio de lunes a viernes, ventana del miércoles al viernes previos.
    Object.assign(form, {
        fecha_inicio: dia(0),
        fecha_fin: dia(4),
        ventana_inicio: dia(-5),
        ventana_fin: dia(-3),
    })
    modalAlta.value = true
}

const guardarAlta = async () => {
    if (await createPeriodo({ ...form })) {
        modalAlta.value = false
    }
}

onMounted(() => {
    fetchVigente()
    fetchPeriodos(1)
})
</script>

<template>
    <div>
        <h1 class="text-2xl font-bold">Periodos de servicio</h1>
        <h2 class="text-sm text-gray-600">
            Cada periodo es una semana de comedor, con sus días y la ventana para generar fichas y pagar.
        </h2>
    </div>

    <Card>
        <CardHeader>
            <div class="flex flex-col justify-between gap-2 sm:flex-row">
                <div>
                    <CardTitle>Periodos</CardTitle>
                    <CardDescription>
                        El periodo de la semana siguiente se crea en borrador los martes. Revísalo,
                        marca los festivos y ábrelo.
                    </CardDescription>
                </div>

                <div class="flex flex-wrap justify-end gap-2">
                    <Button v-if="puede.crear && faltaLaSemanaSiguiente" :disabled="loading" @click="generarSiguiente">
                        <CalendarPlus />
                        Generar semana siguiente
                    </Button>
                    <Button v-if="puede.crear" variant="outline" @click="abrirAlta">
                        Nuevo periodo
                    </Button>
                </div>
            </div>

            <div
                v-if="!tarifaVigente"
                class="mt-2 flex gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900"
            >
                <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" />
                <span>
                    <span class="font-medium">No hay un precio registrado.</span>
                    Sin él no se pueden crear periodos: cada día guarda el precio con el que se cobró.
                    Regístralo en <RouterLink class="underline" to="/tarifas">Precio del comedor</RouterLink>.
                </span>
            </div>

            <div class="flex pt-4">
                <Select v-model="filtroEstado">
                    <SelectTrigger class="w-full sm:w-56">
                        <SelectValue placeholder="Estado" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="todos">Todos los estados</SelectItem>
                        <SelectItem value="BORRADOR">Borrador</SelectItem>
                        <SelectItem value="ABIERTO">Abierto</SelectItem>
                        <SelectItem value="PAGO_CERRADO">Pago cerrado</SelectItem>
                        <SelectItem value="CONSOLIDADO">Consolidado</SelectItem>
                    </SelectContent>
                </Select>
            </div>
        </CardHeader>

        <CardContent>
            <Table>
                <TableHeader class="bg-stone-50">
                    <TableRow>
                        <TableHead class="text-center font-bold">Servicio</TableHead>
                        <TableHead class="text-center font-bold">Ventana</TableHead>
                        <TableHead class="text-center font-bold">Días con servicio</TableHead>
                        <TableHead class="text-center font-bold">Estado</TableHead>
                        <TableHead class="text-center font-bold">Acciones</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="fila in periodos" :key="fila.id">
                        <TableCell class="text-center font-medium">
                            {{ rango(fila.fecha_inicio, fila.fecha_fin) }}
                        </TableCell>
                        <TableCell class="text-center">
                            <div>{{ rango(fila.ventana_inicio, fila.ventana_fin) }}</div>
                            <span v-if="fila.admite_fichas" class="text-xs text-green-700">
                                Recibiendo fichas hoy
                            </span>
                            <span v-else-if="fila.ventana_vencida" class="text-xs text-muted-foreground">
                                Ventana terminada
                            </span>
                        </TableCell>
                        <TableCell class="text-center tabular-nums">{{ fila.dias_disponibles ?? '—' }}</TableCell>
                        <TableCell class="text-center">
                            <Badge :variant="ESTADOS[fila.estado].variante" :class="ESTADOS[fila.estado].clase">
                                {{ ESTADOS[fila.estado].texto }}
                            </Badge>
                        </TableCell>
                        <TableCell class="flex flex-wrap justify-center gap-2">
                            <Button size="sm" variant="outline" @click="abrirDetalle(fila)">
                                <Settings2 />
                                {{ fila.estado === 'BORRADOR' && puede.editar ? 'Configurar' : 'Ver' }}
                            </Button>
                            <Button
                                v-if="fila.estado === 'BORRADOR' && puede.abrir"
                                size="sm"
                                :disabled="loading"
                                @click="pedirCambio(fila, 'abrir')"
                            >
                                <Unlock />
                                Abrir
                            </Button>
                            <Button
                                v-if="fila.estado === 'ABIERTO' && puede.cerrar"
                                size="sm"
                                variant="outline"
                                :disabled="loading"
                                @click="pedirCambio(fila, 'cerrar')"
                            >
                                <LockKeyhole />
                                Cerrar
                            </Button>
                            <Button
                                v-if="fila.estado === 'PAGO_CERRADO' && puede.reabrir"
                                size="sm"
                                variant="ghost"
                                :disabled="loading"
                                @click="pedirCambio(fila, 'reabrir')"
                            >
                                <RotateCcw />
                                Reabrir
                            </Button>
                        </TableCell>
                    </TableRow>
                    <TableRow v-if="!periodos.length">
                        <TableCell colspan="5" class="p-4 text-center text-gray-400">
                            <SearchX class="mx-auto h-10 w-10 text-gray-400" />
                            <p class="mt-2">
                                {{ filtros.estado ? 'Ningún periodo con ese estado.' : 'Todavía no hay periodos.' }}
                            </p>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </CardContent>

        <CardFooter v-if="paginacion.total" class="flex flex-col items-center justify-between gap-4 sm:flex-row">
            <p class="text-sm text-muted-foreground">
                Mostrando {{ periodos.length }} de {{ paginacion.total }}
                {{ paginacion.total === 1 ? 'periodo' : 'periodos' }}
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

    <!-- Detalle: ventana y días -->
    <Dialog v-model:open="modalDetalle">
        <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-3xl">
            <DialogHeader>
                <DialogTitle>
                    Periodo {{ periodo ? rango(periodo.fecha_inicio, periodo.fecha_fin) : '' }}
                </DialogTitle>
                <DialogDescription>
                    <template v-if="esBorrador && puede.editar">
                        En borrador se ajustan los días, sus precios y la ventana. Al abrirlo quedan fijos.
                    </template>
                    <template v-else>
                        El periodo ya no está en borrador: sus días y su ventana ya no se modifican.
                    </template>
                </DialogDescription>
            </DialogHeader>

            <div v-if="periodo" class="grid gap-5">
                <div class="grid gap-3 rounded-md border p-3">
                    <Label>Ventana para generar fichas y pagar</Label>
                    <div class="flex flex-wrap items-end gap-3">
                        <div class="grid gap-1">
                            <span class="text-xs text-muted-foreground">Desde</span>
                            <Input
                                v-model="ventana.ventana_inicio"
                                type="date"
                                :disabled="!esBorrador || !puede.editar"
                            />
                        </div>
                        <div class="grid gap-1">
                            <span class="text-xs text-muted-foreground">Hasta</span>
                            <Input
                                v-model="ventana.ventana_fin"
                                type="date"
                                :disabled="!esBorrador || !puede.editar"
                            />
                        </div>
                        <Button
                            v-if="esBorrador && puede.editar"
                            size="sm"
                            :disabled="loading"
                            @click="guardarVentana"
                        >
                            Guardar ventana
                        </Button>
                    </div>
                    <p v-if="errores.ventana_inicio" class="text-sm text-red-600">{{ errores.ventana_inicio[0] }}</p>
                    <p v-if="errores.ventana_fin" class="text-sm text-red-600">{{ errores.ventana_fin[0] }}</p>
                    <p class="text-xs text-muted-foreground">
                        Puede ir antes del periodo, que es lo normal aquí, o dentro de él.
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label>Días</Label>
                    <div class="grid gap-2">
                        <div
                            v-for="dia in dias"
                            :key="dia.id"
                            class="grid items-center gap-3 rounded-md border p-3 sm:grid-cols-[9rem_auto_auto_1fr_auto]"
                        >
                            <div>
                                <p class="font-medium">{{ DIAS[dia.dia_semana] }}</p>
                                <p class="text-xs text-muted-foreground">{{ fecha(dia.fecha) }}</p>
                            </div>

                            <label class="flex items-center gap-2 text-sm">
                                <Switch
                                    :model-value="dia.disponible"
                                    :disabled="!esBorrador || !puede.editar"
                                    @update:model-value="(valor) => alCambiarDisponible(dia, valor)"
                                />
                                Servicio
                            </label>

                            <label class="flex items-center gap-2 text-sm">
                                <Switch
                                    v-model="dia.es_festivo"
                                    :disabled="!esBorrador || !puede.editar || dia.disponible"
                                />
                                Festivo
                            </label>

                            <Input
                                v-if="!dia.disponible"
                                v-model="dia.motivo_indisponibilidad"
                                placeholder="Motivo (ej. festivo, mantenimiento)"
                                :disabled="!esBorrador || !puede.editar"
                                class="sm:max-w-64"
                            />
                            <span v-else class="text-sm text-muted-foreground">
                                {{ dinero(dia.precio_aplicado) }} por comida
                            </span>

                            <div class="flex items-center gap-2">
                                <Input
                                    v-if="esBorrador && puede.editar"
                                    v-model="dia.precio_aplicado"
                                    type="number"
                                    step="0.01"
                                    min="0.01"
                                    class="w-28"
                                />
                                <Button
                                    v-if="esBorrador && puede.editar"
                                    size="sm"
                                    variant="outline"
                                    :disabled="loading || !cambiado(dia)"
                                    @click="guardarDia(dia)"
                                >
                                    Guardar
                                </Button>
                            </div>
                        </div>
                    </div>
                    <p v-if="errores.precio_aplicado" class="text-sm text-red-600">{{ errores.precio_aplicado[0] }}</p>
                    <p v-if="errores.motivo_indisponibilidad" class="text-sm text-red-600">
                        {{ errores.motivo_indisponibilidad[0] }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Un día sin servicio no se puede seleccionar, no se cobra y no cuenta para las porciones.
                    </p>
                </div>
            </div>

            <DialogFooter>
                <Button variant="outline" @click="modalDetalle = false">Cerrar</Button>
                <Button
                    v-if="periodo?.estado === 'BORRADOR' && puede.abrir"
                    :disabled="loading"
                    @click="pedirCambio(periodo, 'abrir')"
                >
                    <Unlock />
                    Abrir periodo
                </Button>
                <Button
                    v-if="periodo?.estado === 'ABIERTO' && puede.cerrar"
                    :disabled="loading"
                    @click="pedirCambio(periodo, 'cerrar')"
                >
                    <LockKeyhole />
                    Cerrar pago
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Alta manual -->
    <Dialog v-model:open="modalAlta">
        <DialogContent class="sm:max-w-md">
            <form @submit.prevent="guardarAlta">
                <DialogHeader>
                    <DialogTitle>Nuevo periodo</DialogTitle>
                    <DialogDescription>
                        Viene propuesta la semana siguiente con la ventana de siempre. Cámbialo si esta
                        semana es distinta.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4 py-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="fecha_inicio">Primer día</Label>
                        <Input id="fecha_inicio" v-model="form.fecha_inicio" type="date" />
                        <p v-if="errores.fecha_inicio" class="text-sm text-red-600">{{ errores.fecha_inicio[0] }}</p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="fecha_fin">Último día</Label>
                        <Input id="fecha_fin" v-model="form.fecha_fin" type="date" />
                        <p v-if="errores.fecha_fin" class="text-sm text-red-600">{{ errores.fecha_fin[0] }}</p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="ventana_inicio">Ventana desde</Label>
                        <Input id="ventana_inicio" v-model="form.ventana_inicio" type="date" />
                        <p v-if="errores.ventana_inicio" class="text-sm text-red-600">{{ errores.ventana_inicio[0] }}</p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="ventana_fin">Ventana hasta</Label>
                        <Input id="ventana_fin" v-model="form.ventana_fin" type="date" />
                        <p v-if="errores.ventana_fin" class="text-sm text-red-600">{{ errores.ventana_fin[0] }}</p>
                    </div>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="modalAlta = false">Cancelar</Button>
                    <Button type="submit" :disabled="loading">Crear en borrador</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <AlertDialog v-model:open="confirmacion.abierta">
        <AlertDialogContent v-if="confirmacion.accion">
            <AlertDialogHeader>
                <AlertDialogTitle>{{ TEXTOS_CONFIRMACION[confirmacion.accion].titulo }}</AlertDialogTitle>
                <AlertDialogDescription>
                    {{ TEXTOS_CONFIRMACION[confirmacion.accion].cuerpo }}
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel>Cancelar</AlertDialogCancel>
                <AlertDialogAction @click="confirmarCambio">
                    {{ TEXTOS_CONFIRMACION[confirmacion.accion].boton }}
                </AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
