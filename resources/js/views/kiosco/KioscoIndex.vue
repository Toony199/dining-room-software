<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

import { useRouter } from 'vue-router'

import { useKiosco } from '@/composables/useKiosco.js'
import { useAuthStore } from '@/stores/auth.js'
import { modulos } from '@/modulos.js'
import { Button } from '@/components/ui/button'
import { Spinner } from '@/components/ui/spinner'
import { ChevronLeft, CircleAlert, CircleCheckBig, ScanLine } from '@lucide/vue'

/**
 * Kiosco del comedor (§10, §11).
 *
 * Una sola pantalla, sin menú ni manera de llegar a la administración (§10.2): identificar, elegir
 * días, confirmar y volver al inicio. La sesión no existe aquí; lo único que identifica es el QR
 * del gafete.
 *
 * El lector es de los que se conectan por USB y "teclean" el código seguido de un Enter, así que la
 * pantalla mantiene el foco en un campo oculto y escucha ahí. Nunca se muestra el token: se lee, se
 * usa y se olvida.
 *
 * Todo vuelve al inicio solo, por tiempo: nadie debe encontrarse la pantalla con los datos de quien
 * pasó antes.
 */
const { cargando, error, identificar, generarFicha } = useKiosco()

const auth = useAuthStore()
const router = useRouter()

/**
 * La salida solo aparece para quien tiene a dónde ir: administración probando el kiosco, o el
 * personal que lo atiende. Con la cuenta del kiosco no sale, que es lo que pide §10.2: desde la
 * pantalla del pasillo no se llega a ningún módulo.
 */
const puedeSalir = computed(
    () => modulos.some((modulo) => modulo.permiso !== 'kiosco.operar' && auth.tienePermiso(modulo.permiso))
)

const salir = () => {
    limpiarTemporizador()
    router.push('/')
}

const ESPERA_TRAS_TERMINAR = 20
const ESPERA_TRAS_ERROR = 8
const ESPERA_SIN_ACTIVIDAD = 60

// espera | eligiendo | ficha | error
const paso = ref('espera')
const lector = ref(null)
const codigo = ref('')

const persona = ref(null)
const periodo = ref(null)
const ficha = ref(null)
const elegidos = ref([])
const yaTenia = ref(false)
const restante = ref(0)

let cuentaRegresiva = null

const DIAS = ['', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo']

const aFecha = (valor) => {
    const [anio, mes, dia] = valor.slice(0, 10).split('-').map(Number)

    return new Date(anio, mes - 1, dia)
}

const fechaLarga = (valor) => aFecha(valor).toLocaleDateString('es-MX', { day: 'numeric', month: 'long' })

const fechaHora = (valor) => (valor
    ? new Date(valor).toLocaleString('es-MX', { day: '2-digit', month: 'long', hour: '2-digit', minute: '2-digit' })
    : '')

const dinero = (valor) => Number(valor ?? 0).toLocaleString('es-MX', { style: 'currency', currency: 'MXN' })

const semana = computed(() => (periodo.value
    ? `${fechaLarga(periodo.value.fecha_inicio)} al ${fechaLarga(periodo.value.fecha_fin)}`
    : ''))

const total = computed(() => (periodo.value?.dias ?? [])
    .filter((dia) => elegidos.value.includes(dia.id))
    .reduce((suma, dia) => suma + Number(dia.precio), 0))

// --- Volver al inicio ------------------------------------------------------------------

const limpiarTemporizador = () => {
    clearInterval(cuentaRegresiva)
    cuentaRegresiva = null
}

const volverAlInicio = () => {
    limpiarTemporizador()
    paso.value = 'espera'
    codigo.value = ''
    persona.value = null
    periodo.value = null
    ficha.value = null
    elegidos.value = []
    yaTenia.value = false
    error.value = ''
    enfocarLector()
}

/** Cuenta atrás visible: quien está frente a la pantalla ve cuánto le queda. */
const programarRegreso = (segundos) => {
    limpiarTemporizador()
    restante.value = segundos

    cuentaRegresiva = setInterval(() => {
        restante.value--

        if (restante.value <= 0) {
            volverAlInicio()
        }
    }, 1000)
}

// Elegir días toma su tiempo, pero nadie debe dejar sus datos en pantalla al irse.
const reiniciarInactividad = () => {
    if (paso.value === 'eligiendo') {
        programarRegreso(ESPERA_SIN_ACTIVIDAD)
    }
}

// --- Lectura del gafete -----------------------------------------------------------------

const enfocarLector = () => {
    // El lector escribe donde esté el foco: aquí siempre debe estar el campo oculto.
    requestAnimationFrame(() => lector.value?.focus())
}

const alEscanear = async () => {
    const token = codigo.value.trim()
    codigo.value = ''

    if (!token || cargando.value) {
        return
    }

    const datos = await identificar(token)

    if (!datos) {
        paso.value = 'error'
        programarRegreso(ESPERA_TRAS_ERROR)

        return
    }

    persona.value = datos.persona
    periodo.value = datos.periodo

    if (datos.ficha) {
        // Ya pidió esta semana (§17.1): se le muestra su ficha en vez de dejarlo elegir otra vez.
        ficha.value = datos.ficha
        yaTenia.value = true
        paso.value = 'ficha'
        programarRegreso(ESPERA_TRAS_TERMINAR)

        return
    }

    // El token se guarda solo mientras dura la elección: se necesita para generar la ficha.
    codigo.value = token
    elegidos.value = []
    paso.value = 'eligiendo'
    programarRegreso(ESPERA_SIN_ACTIVIDAD)
}

// --- Elección y generación ----------------------------------------------------------------

const alternarDia = (dia) => {
    if (!dia.disponible) {
        return
    }

    elegidos.value = elegidos.value.includes(dia.id)
        ? elegidos.value.filter((id) => id !== dia.id)
        : [...elegidos.value, dia.id]

    reiniciarInactividad()
}

const confirmar = async () => {
    if (!elegidos.value.length) {
        return
    }

    const generada = await generarFicha(codigo.value, elegidos.value)
    codigo.value = ''

    if (!generada) {
        paso.value = 'error'
        programarRegreso(ESPERA_TRAS_ERROR)

        return
    }

    ficha.value = generada
    yaTenia.value = false
    paso.value = 'ficha'
    programarRegreso(ESPERA_TRAS_TERMINAR)
}

// El campo oculto debe recuperar el foco pase lo que pase: si se pierde, el lector escribe al vacío.
watch(paso, () => enfocarLector())

onMounted(() => {
    enfocarLector()
    document.addEventListener('click', enfocarLector)
})

onBeforeUnmount(() => {
    limpiarTemporizador()
    document.removeEventListener('click', enfocarLector)
})
</script>

<template>
    <div class="flex min-h-screen flex-col bg-stone-100 text-stone-900">
        <!-- Sin menú ni enlaces: desde aquí no se llega a la administración (§10.2). -->
        <header class="flex items-center justify-between gap-4 bg-blue-800 px-8 py-5 text-white">
            <div class="flex items-center gap-3">
                <Button
                    v-if="puedeSalir"
                    variant="ghost"
                    size="icon"
                    class="text-white hover:bg-blue-700 hover:text-white"
                    aria-label="Salir del kiosco"
                    title="Salir del kiosco"
                    @click="salir"
                >
                    <ChevronLeft class="size-6" />
                </Button>
                <p class="text-2xl font-bold tracking-wide">COMEDOR</p>
            </div>
            <p v-if="periodo" class="text-lg">Semana del {{ semana }}</p>
        </header>

        <!-- Aquí escribe el lector USB. Nunca se muestra el código. -->
        <input
            ref="lector"
            v-model="codigo"
            type="password"
            class="sr-only"
            autocomplete="off"
            aria-label="Escanea tu gafete"
            @keyup.enter="alEscanear"
        />

        <main class="flex flex-1 items-center justify-center p-6">
            <div class="w-full max-w-3xl rounded-2xl bg-white p-10 shadow-sm">
                <!-- Espera -->
                <div v-if="paso === 'espera'" class="grid justify-items-center gap-6 py-10 text-center">
                    <ScanLine class="size-24 text-blue-800" />
                    <h1 class="text-4xl font-bold">Escanea tu gafete</h1>
                    <p class="text-xl text-stone-600">
                        Acerca el código de tu gafete al lector para pedir tu comida de la semana.
                    </p>
                    <Spinner v-if="cargando" class="size-8" />
                </div>

                <!-- Elección de días -->
                <div v-else-if="paso === 'eligiendo'" class="grid gap-6">
                    <div>
                        <h1 class="text-3xl font-bold">Hola, {{ persona.nombre }}</h1>
                        <p class="text-lg text-stone-600">Elige los días que vas a comer.</p>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <button
                            v-for="dia in periodo.dias"
                            :key="dia.id"
                            type="button"
                            :disabled="!dia.disponible"
                            class="flex items-center justify-between rounded-xl border-2 p-4 text-left transition-colors"
                            :class="[
                                !dia.disponible
                                    ? 'cursor-not-allowed border-stone-200 bg-stone-50 text-stone-400'
                                    : elegidos.includes(dia.id)
                                        ? 'border-blue-800 bg-blue-50'
                                        : 'border-stone-200 hover:border-blue-300',
                            ]"
                            @click="alternarDia(dia)"
                        >
                            <span>
                                <span class="block text-xl font-semibold">{{ DIAS[dia.dia_semana] }}</span>
                                <span class="block text-sm">{{ fechaLarga(dia.fecha) }}</span>
                                <span v-if="!dia.disponible" class="block text-sm">
                                    {{ dia.motivo_indisponibilidad || 'Sin servicio' }}
                                </span>
                            </span>
                            <span class="text-lg font-medium tabular-nums">
                                {{ dia.disponible ? dinero(dia.precio) : '—' }}
                            </span>
                        </button>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-4 border-t pt-5">
                        <p class="text-2xl">
                            <span class="text-stone-600">Total:</span>
                            <span class="font-bold tabular-nums"> {{ dinero(total) }}</span>
                            <span class="block text-base text-stone-600">
                                {{ elegidos.length }} {{ elegidos.length === 1 ? 'día' : 'días' }} ·
                                vuelve al inicio en {{ restante }} s
                            </span>
                        </p>
                        <div class="flex gap-3">
                            <Button size="lg" variant="outline" class="text-lg" @click="volverAlInicio">
                                Cancelar
                            </Button>
                            <Button
                                size="lg"
                                class="bg-blue-800 text-lg hover:bg-blue-900"
                                :disabled="!elegidos.length || cargando"
                                @click="confirmar"
                            >
                                <Spinner v-if="cargando" class="size-5" />
                                Generar mi ficha
                            </Button>
                        </div>
                    </div>
                </div>

                <!-- Ficha generada, o la que ya tenía -->
                <div v-else-if="paso === 'ficha'" class="grid justify-items-center gap-5 text-center">
                    <CircleCheckBig class="size-20 text-green-600" />
                    <h1 class="text-3xl font-bold">
                        {{ yaTenia ? `Ya tienes tu ficha, ${persona.nombre}` : `Listo, ${persona.nombre}` }}
                    </h1>

                    <div class="w-full rounded-xl bg-stone-50 p-6">
                        <p class="text-stone-600">Folio</p>
                        <p class="text-4xl font-bold tracking-wider tabular-nums">{{ ficha.folio }}</p>

                        <ul class="mt-5 grid gap-1 text-lg">
                            <li v-for="dia in ficha.dias" :key="dia.dia_periodo_id" class="flex justify-between">
                                <span>{{ DIAS[dia.dia_semana] }} {{ fechaLarga(dia.fecha) }}</span>
                                <span class="tabular-nums">{{ dinero(dia.precio) }}</span>
                            </li>
                        </ul>

                        <p class="mt-4 flex justify-between border-t pt-4 text-2xl font-bold">
                            <span>Total</span>
                            <span class="tabular-nums">{{ dinero(ficha.total) }}</span>
                        </p>
                    </div>

                    <!-- Quien ya pagó consulta aquí su comprobante, en vez de guardar el papel (§14). -->
                    <div v-if="ficha.estado === 'PAGADA'" class="w-full rounded-xl border border-green-200 bg-green-50 p-5 text-left">
                        <p class="text-center text-xl font-semibold text-green-800">Ya está pagada</p>
                        <dl class="mt-3 grid gap-1 text-lg">
                            <div class="flex justify-between">
                                <dt class="text-stone-600">Pagaste</dt>
                                <dd class="tabular-nums">{{ dinero(ficha.pago?.total_cobrado) }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-stone-600">Fecha</dt>
                                <dd>{{ fechaHora(ficha.pago?.confirmado_en) }}</dd>
                            </div>
                            <div v-if="ficha.pago?.cobrador" class="flex justify-between">
                                <dt class="text-stone-600">Te cobró</dt>
                                <dd>{{ ficha.pago.cobrador }}</dd>
                            </div>
                        </dl>
                        <p class="mt-3 text-center text-stone-600">
                            Preséntate con tu gafete en el comedor los días que pagaste.
                        </p>
                    </div>

                    <p v-else class="text-xl">Pasa a caja a pagar antes de que cierre la semana.</p>
                    <p class="text-stone-500">Volviendo al inicio en {{ restante }} s</p>
                    <Button size="lg" class="bg-blue-800 text-lg hover:bg-blue-900" @click="volverAlInicio">
                        Terminar
                    </Button>
                </div>

                <!-- Rechazo -->
                <div v-else class="grid justify-items-center gap-5 py-6 text-center">
                    <CircleAlert class="size-20 text-red-600" />
                    <h1 class="text-3xl font-bold">No se pudo continuar</h1>
                    <p class="max-w-xl text-xl text-stone-700">{{ error }}</p>
                    <p class="text-stone-500">Volviendo al inicio en {{ restante }} s</p>
                    <Button size="lg" class="bg-blue-800 text-lg hover:bg-blue-900" @click="volverAlInicio">
                        Entendido
                    </Button>
                </div>
            </div>
        </main>
    </div>
</template>
