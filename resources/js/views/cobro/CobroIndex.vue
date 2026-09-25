<script setup>
import { computed, onMounted, ref, watch } from 'vue'

import { useCobro } from '@/composables/useCobro.js'
import { useAuthStore } from '@/stores/auth.js'
import TicketPago from '@/components/cobro/TicketPago.vue'
import { imprimirTicket } from '@/components/cobro/impresionTicket.js'

import {
    Card,
    CardContent,
    CardDescription,
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
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Spinner } from '@/components/ui/spinner'
import { Banknote, Printer, Search, SearchX } from '@lucide/vue'

/**
 * Caja del comedor (§12, §13, §14).
 *
 * El cobro gira en torno al folio: la persona llega con él, el cobrador lo escanea o lo teclea, ve
 * lo que debe, puede agregarle o quitarle días, recibe el dinero y confirma. El sistema no cobra
 * (§2.1): valida el monto como un POS y deja registrado lo que el cobrador recibió.
 */
const {
    loading,
    ficha,
    errores,
    pagos,
    corte,
    pendientes,
    buscarFicha,
    buscarPorGafete,
    cambiarDias,
    confirmarPago,
    fetchPagos,
    fetchPendientes,
    limpiarErrores,
} = useCobro()

const auth = useAuthStore()

// Comodidad, no seguridad: el backend comprueba el permiso en cada operación (§5.6).
const puede = computed(() => ({
    editar: auth.tienePermiso('fichas.editar'),
    cobrar: auth.tienePermiso('pagos.confirmar'),
    verPagos: auth.tienePermiso('pagos.ver'),
}))

const DIAS = ['', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo']

const aFecha = (valor) => {
    const [anio, mes, dia] = valor.slice(0, 10).split('-').map(Number)

    return new Date(anio, mes - 1, dia)
}

const fecha = (valor) => (valor ? aFecha(valor).toLocaleDateString('es-MX', { day: '2-digit', month: 'short' }) : '—')
const hora = (valor) => (valor ? new Date(valor).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' }) : '—')
const dinero = (valor) => Number(valor ?? 0).toLocaleString('es-MX', { style: 'currency', currency: 'MXN' })

const ESTADOS = {
    PENDIENTE: { texto: 'Pendiente de pago', variante: 'secondary', clase: '' },
    PAGADA: { texto: 'Pagada', variante: 'default', clase: 'bg-green-600' },
    VENCIDA: { texto: 'Vencida', variante: 'outline', clase: '' },
}

// --- Buscar la ficha ----------------------------------------------------------------------

const folio = ref('')
const campoFolio = ref(null)

// El folio tiene forma de AAAA-SS-NNNN; lo que teclea el lector del gafete no se le parece en
// nada. Así el mismo campo sirve para las dos cosas y el cobrador no elige modo.
const ES_FOLIO = /^\d{4}-\d{1,2}-\d{1,6}$/

// Cuando alguien trae más de una ficha, el cobrador elige cuál cobra. Pasa cuando no pagó una
// semana, esa semana no se cerró y pidió la siguiente.
const opciones = ref([])

const tomarFicha = (elegida) => {
    ficha.value = elegida
    opciones.value = []
    // La selección arranca con lo que la persona pidió en el kiosco.
    elegidos.value = elegida.dias.map((dia) => dia.dia_periodo_id)
    monto.value = ''
    folio.value = elegida.folio
}

const buscar = async () => {
    const texto = folio.value.trim()
    opciones.value = []

    if (!texto) {
        return
    }

    if (ES_FOLIO.test(texto)) {
        const encontrada = await buscarFicha(texto)

        if (encontrada) {
            tomarFicha(encontrada)
        }

        return
    }

    const fichas = await buscarPorGafete(texto)

    if (fichas.length === 1) {
        tomarFicha(fichas[0])
    } else if (fichas.length > 1) {
        ficha.value = null
        opciones.value = fichas
    }
}

const limpiar = () => {
    folio.value = ''
    ficha.value = null
    opciones.value = []
    elegidos.value = []
    monto.value = ''
    limpiarErrores()
    campoFolio.value?.$el?.focus?.() ?? campoFolio.value?.focus?.()
}

// --- Días de la ficha (§13) ------------------------------------------------------------------

const elegidos = ref([])

const diasDelPeriodo = computed(() => ficha.value?.periodo?.dias ?? [])

const editable = computed(() => ficha.value?.estado === 'PENDIENTE' && puede.value.editar)

const alternarDia = (dia) => {
    if (!editable.value || !dia.disponible) {
        return
    }

    elegidos.value = elegidos.value.includes(dia.id)
        ? elegidos.value.filter((id) => id !== dia.id)
        : [...elegidos.value, dia.id]
}

const seleccionCambiada = computed(() => {
    const actuales = (ficha.value?.dias ?? []).map((dia) => dia.dia_periodo_id).sort().join(',')

    return actuales !== [...elegidos.value].sort().join(',')
})

const guardarDias = async () => {
    const actualizada = await cambiarDias(ficha.value.folio, elegidos.value)

    if (actualizada) {
        elegidos.value = actualizada.dias.map((dia) => dia.dia_periodo_id)
    }
}

// --- Cobro (§12.1) ----------------------------------------------------------------------------

const monto = ref('')
const pagoConfirmado = ref(null)
const modalTicket = ref(false)
const zonaTicket = ref(null)

const total = computed(() => Number(ficha.value?.total ?? 0))

const cambio = computed(() => {
    const recibido = Number(monto.value)

    return Number.isFinite(recibido) && recibido >= total.value ? recibido - total.value : null
})

// Los billetes con los que suele pagar la gente, para no teclear.
const sugerencias = computed(() => {
    const exacto = Math.ceil(total.value)

    return [...new Set([exacto, 50, 100, 200, 500])]
        .filter((valor) => valor >= total.value)
        .sort((a, b) => a - b)
        .slice(0, 4)
})

const cobrar = async () => {
    const pago = await confirmarPago(ficha.value.folio, Number(monto.value))

    if (pago) {
        pagoConfirmado.value = pago
        modalTicket.value = true
        fetchPagos()
        fetchPendientes()
    }
}

const imprimir = () => imprimirTicket(zonaTicket.value)

const cerrarTicket = () => {
    modalTicket.value = false
    limpiar()
}

// El campo del folio manda: el lector de la caja escribe ahí.
watch(ficha, (valor) => {
    if (!valor) {
        elegidos.value = []
    }
})

onMounted(() => {
    if (puede.value.verPagos) {
        fetchPagos()
    }

    fetchPendientes()
})
</script>

<template>
    <div>
        <h1 class="text-2xl font-bold">Caja</h1>
        <h2 class="text-sm text-gray-600">
            Cobro de las fichas del comedor. El dinero se recibe en caja; aquí se registra y se
            entrega el comprobante.
        </h2>
    </div>

    <Card>
        <CardHeader>
            <CardTitle>Cobrar una ficha</CardTitle>
            <CardDescription>
                Escanea el gafete de la persona, o el folio de su ficha si lo trae. Puedes agregarle
                o quitarle días antes de cobrar.
            </CardDescription>
        </CardHeader>

        <CardContent class="grid gap-5">
            <form class="flex flex-wrap items-end gap-3" @submit.prevent="buscar">
                <div class="grid gap-2">
                    <Label for="folio">Gafete o folio</Label>
                    <Input
                        id="folio"
                        ref="campoFolio"
                        v-model="folio"
                        class="w-72 font-mono text-lg"
                        placeholder="Escanea el gafete…"
                        autocomplete="off"
                        autofocus
                    />
                </div>
                <Button type="submit" :disabled="loading || !folio.trim()">
                    <Spinner v-if="loading" class="h-4 w-4" />
                    <Search v-else />
                    Buscar
                </Button>
                <Button v-if="ficha" type="button" variant="ghost" @click="limpiar">Limpiar</Button>
            </form>

            <p v-if="errores.folio" class="text-sm text-red-600">{{ errores.folio[0] }}</p>
            <p v-if="errores.qr_token" class="text-sm text-red-600">{{ errores.qr_token[0] }}</p>

            <!-- Más de una ficha: se muestran todas y el cobrador elige. -->
            <div v-if="opciones.length" class="grid gap-2 rounded-md border border-amber-300 bg-amber-50 p-4">
                <p class="text-sm text-amber-900">
                    <span class="font-medium">{{ opciones[0].persona?.nombre_completo }} tiene
                    {{ opciones.length }} fichas.</span>
                    Elige cuál vas a cobrar; la más antigua va primero.
                </p>
                <button
                    v-for="opcion in opciones"
                    :key="opcion.folio"
                    type="button"
                    class="flex flex-wrap items-center justify-between gap-3 rounded-md border bg-white p-3 text-left transition-colors hover:border-blue-400"
                    @click="tomarFicha(opcion)"
                >
                    <span>
                        <span class="font-mono font-medium">{{ opcion.folio }}</span>
                        <span class="block text-sm text-muted-foreground">
                            Semana del {{ fecha(opcion.periodo?.fecha_inicio) }} al
                            {{ fecha(opcion.periodo?.fecha_fin) }} ·
                            {{ opcion.dias?.length ?? 0 }}
                            {{ (opcion.dias?.length ?? 0) === 1 ? 'día' : 'días' }}
                        </span>
                    </span>
                    <span class="flex items-center gap-3">
                        <Badge :variant="ESTADOS[opcion.estado].variante" :class="ESTADOS[opcion.estado].clase">
                            {{ ESTADOS[opcion.estado].texto }}
                        </Badge>
                        <span class="text-lg font-semibold tabular-nums">{{ dinero(opcion.total) }}</span>
                    </span>
                </button>
            </div>

            <template v-if="ficha">
                <div class="grid gap-4 rounded-md border p-4 lg:grid-cols-[1fr_20rem]">
                    <div class="grid content-start gap-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <p class="text-lg font-semibold">{{ ficha.persona?.nombre_completo }}</p>
                                <p class="text-sm text-muted-foreground">
                                    No. {{ ficha.persona?.numero_empleado }} ·
                                    folio <span class="font-mono">{{ ficha.folio }}</span>
                                </p>
                            </div>
                            <Badge :variant="ESTADOS[ficha.estado].variante" :class="ESTADOS[ficha.estado].clase">
                                {{ ESTADOS[ficha.estado].texto }}
                            </Badge>
                        </div>

                        <div class="grid gap-2">
                            <Label>Días</Label>
                            <div class="grid gap-2 sm:grid-cols-2">
                                <button
                                    v-for="dia in diasDelPeriodo"
                                    :key="dia.id"
                                    type="button"
                                    :disabled="!editable || !dia.disponible"
                                    class="flex items-center justify-between rounded-md border p-3 text-left text-sm transition-colors"
                                    :class="[
                                        !dia.disponible
                                            ? 'cursor-not-allowed border-dashed text-muted-foreground'
                                            : elegidos.includes(dia.id)
                                                ? 'border-blue-700 bg-blue-50'
                                                : 'hover:border-blue-300',
                                        !editable && dia.disponible ? 'cursor-default' : '',
                                    ]"
                                    @click="alternarDia(dia)"
                                >
                                    <span>
                                        <span class="font-medium">{{ DIAS[dia.dia_semana] }}</span>
                                        <span class="block text-xs">{{ fecha(dia.fecha) }}</span>
                                        <span v-if="!dia.disponible" class="block text-xs">
                                            {{ dia.motivo_indisponibilidad || 'Sin servicio' }}
                                        </span>
                                    </span>
                                    <span class="tabular-nums">{{ dia.disponible ? dinero(dia.precio_aplicado) : '—' }}</span>
                                </button>
                            </div>

                            <p v-if="errores.dias" class="text-sm text-red-600">{{ errores.dias[0] }}</p>

                            <div v-if="editable" class="flex items-center gap-2">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    :disabled="loading || !seleccionCambiada"
                                    @click="guardarDias"
                                >
                                    Guardar cambios de días
                                </Button>
                                <span v-if="seleccionCambiada" class="text-xs text-muted-foreground">
                                    El total se recalcula al guardar.
                                </span>
                            </div>
                            <p v-else-if="ficha.estado === 'PENDIENTE'" class="text-xs text-muted-foreground">
                                No tienes permiso para cambiar los días de la ficha.
                            </p>
                        </div>
                    </div>

                    <!-- Cobro -->
                    <div class="grid content-start gap-3 rounded-md bg-muted/40 p-4">
                        <p class="flex items-baseline justify-between text-2xl font-bold">
                            <span class="text-base font-normal text-muted-foreground">Total</span>
                            <span class="tabular-nums">{{ dinero(ficha.total) }}</span>
                        </p>

                        <template v-if="ficha.estado === 'PENDIENTE' && puede.cobrar">
                            <div class="grid gap-2">
                                <Label for="monto">Monto recibido</Label>
                                <Input
                                    id="monto"
                                    v-model="monto"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    class="text-lg"
                                    placeholder="0.00"
                                    autocomplete="off"
                                />
                                <div class="flex flex-wrap gap-2">
                                    <Button
                                        v-for="sugerido in sugerencias"
                                        :key="sugerido"
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        @click="monto = String(sugerido)"
                                    >
                                        {{ dinero(sugerido) }}
                                    </Button>
                                </div>
                            </div>

                            <p class="flex items-baseline justify-between text-lg">
                                <span class="text-muted-foreground">Cambio</span>
                                <span class="font-semibold tabular-nums">
                                    {{ cambio === null ? '—' : dinero(cambio) }}
                                </span>
                            </p>

                            <p v-if="errores.monto_recibido" class="text-sm text-red-600">
                                {{ errores.monto_recibido[0] }}
                            </p>

                            <Button :disabled="loading || cambio === null" @click="cobrar">
                                <Banknote />
                                Confirmar pago
                            </Button>
                            <p class="text-xs text-muted-foreground">
                                Confirmar crea el derecho a comer de cada día pagado.
                            </p>
                        </template>

                        <template v-else-if="ficha.estado === 'PAGADA'">
                            <p class="text-sm">
                                Pagada el {{ hora(ficha.pago?.confirmado_en) }} ·
                                recibido {{ dinero(ficha.pago?.monto_recibido) }} ·
                                cambio {{ dinero(ficha.pago?.cambio) }}
                            </p>
                            <Button
                                variant="outline"
                                @click="pagoConfirmado = { ...ficha.pago, ficha, cobrador: ficha.pago?.cobrador }; modalTicket = true"
                            >
                                <Printer />
                                Ver e imprimir ticket
                            </Button>
                        </template>

                        <p v-else class="text-sm text-muted-foreground">
                            Esta ficha venció: la semana cerró sin que se pagara y ya no se puede cobrar.
                        </p>
                    </div>
                </div>
            </template>
        </CardContent>
    </Card>

    <div class="grid gap-4 xl:grid-cols-2">
        <Card>
            <CardHeader>
                <CardTitle>Pendientes de pago</CardTitle>
                <CardDescription>Fichas generadas que todavía no pasan por caja.</CardDescription>
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader class="bg-stone-50">
                        <TableRow>
                            <TableHead class="font-bold">Folio</TableHead>
                            <TableHead class="font-bold">Colaborador</TableHead>
                            <TableHead class="text-center font-bold">Días</TableHead>
                            <TableHead class="text-right font-bold">Total</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="pendiente in pendientes"
                            :key="pendiente.folio"
                            class="cursor-pointer"
                            @click="folio = pendiente.folio; buscar()"
                        >
                            <TableCell class="font-mono">{{ pendiente.folio }}</TableCell>
                            <TableCell>{{ pendiente.persona?.nombre_completo }}</TableCell>
                            <TableCell class="text-center tabular-nums">{{ pendiente.dias?.length ?? 0 }}</TableCell>
                            <TableCell class="text-right tabular-nums">{{ dinero(pendiente.total) }}</TableCell>
                        </TableRow>
                        <TableRow v-if="!pendientes.length">
                            <TableCell colspan="4" class="p-4 text-center text-gray-400">
                                <SearchX class="mx-auto h-8 w-8" />
                                <p class="mt-2">Ninguna ficha pendiente.</p>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>

        <Card v-if="puede.verPagos">
            <CardHeader>
                <CardTitle>Cobrado hoy</CardTitle>
                <CardDescription>
                    Es lo que debe haber en caja: se suma lo cobrado, no lo recibido, porque el
                    cambio se devolvió.
                </CardDescription>
            </CardHeader>
            <CardContent class="grid gap-4">
                <div v-if="corte" class="grid gap-2 rounded-md border p-3">
                    <p class="flex items-baseline justify-between">
                        <span class="text-muted-foreground">
                            {{ corte.fichas }} {{ corte.fichas === 1 ? 'ficha cobrada' : 'fichas cobradas' }}
                        </span>
                        <span class="text-2xl font-bold tabular-nums">{{ dinero(corte.total) }}</span>
                    </p>
                    <div v-for="fila in corte.por_cobrador" :key="fila.cobrador_id" class="flex justify-between text-sm">
                        <span>{{ fila.cobrador }}</span>
                        <span class="tabular-nums">{{ dinero(fila.total) }} · {{ fila.fichas }}</span>
                    </div>
                </div>

                <Table>
                    <TableHeader class="bg-stone-50">
                        <TableRow>
                            <TableHead class="font-bold">Hora</TableHead>
                            <TableHead class="font-bold">Folio</TableHead>
                            <TableHead class="font-bold">Colaborador</TableHead>
                            <TableHead class="text-right font-bold">Cobrado</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="pago in pagos"
                            :key="pago.id"
                            class="cursor-pointer"
                            @click="pagoConfirmado = pago; modalTicket = true"
                        >
                            <TableCell class="tabular-nums">{{ hora(pago.confirmado_en) }}</TableCell>
                            <TableCell class="font-mono">{{ pago.ficha?.folio }}</TableCell>
                            <TableCell>{{ pago.ficha?.persona?.nombre_completo }}</TableCell>
                            <TableCell class="text-right tabular-nums">{{ dinero(pago.total_cobrado) }}</TableCell>
                        </TableRow>
                        <TableRow v-if="!pagos.length">
                            <TableCell colspan="4" class="p-4 text-center text-gray-400">
                                Todavía no se cobra nada hoy.
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    </div>

    <!-- Ticket (§14) -->
    <Dialog v-model:open="modalTicket">
        <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Comprobante de pago</DialogTitle>
                <DialogDescription>
                    Imprímelo solo si la persona lo pide: también puede consultarlo con su gafete en
                    el kiosco.
                </DialogDescription>
            </DialogHeader>

            <div v-if="pagoConfirmado" class="flex justify-center bg-muted/40 p-4">
                <TicketPago :pago="pagoConfirmado" />
            </div>

            <DialogFooter>
                <Button variant="outline" @click="cerrarTicket">Cerrar</Button>
                <Button @click="imprimir">
                    <Printer />
                    Imprimir
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Copia que se imprime, directamente en <body> (ver impresionTicket.css). -->
    <Teleport to="body">
        <div v-if="modalTicket && pagoConfirmado" ref="zonaTicket" class="zona-impresion-ticket">
            <TicketPago :pago="pagoConfirmado" impresion />
        </div>
    </Teleport>
</template>
