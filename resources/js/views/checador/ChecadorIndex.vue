<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'

import { useChecador } from '@/composables/useChecador.js'
import { useAuthStore } from '@/stores/auth.js'
import { modulos } from '@/modulos.js'
import { Button } from '@/components/ui/button'
import { ChevronLeft, CircleCheckBig, CircleX, ScanLine } from '@lucide/vue'

/**
 * Checador de la entrada del comedor (§16).
 *
 * Una sola pregunta: ¿puede comer hoy quien acaba de escanear? La respuesta se ve de lejos, en
 * verde o en rojo, porque quien la lee está de pie y con fila detrás. Vuelve sola a esperar el
 * siguiente gafete.
 *
 * Igual que el kiosco: pantalla aislada, sin menú (§10.2), y el lector USB "teclea" el código en un
 * campo oculto que siempre recupera el foco. El código nunca se muestra.
 */
const { cargando, resumen, validar, fetchResumen } = useChecador()

const auth = useAuthStore()
const router = useRouter()

// Cuánto se queda la respuesta antes de volver a esperar. Lo suficiente para leerla sin frenar la
// fila; un escaneo nuevo la reemplaza de inmediato.
const ESPERA_PERMITIDO = 4
const ESPERA_RECHAZADO = 7

const lector = ref(null)
const codigo = ref('')
const respuesta = ref(null)
const restante = ref(0)

let cuentaRegresiva = null

const permitido = computed(() => respuesta.value?.resultado === 'PERMITIDO')

// La salida solo aparece para quien tiene a dónde ir; con la cuenta del comedor, no (§10.2).
const puedeSalir = computed(
    () => modulos.some((modulo) => !['consumo.validar', 'consumo.ver'].includes(modulo.permiso) && auth.tienePermiso(modulo.permiso))
)

const hora = (valor) => (valor ? new Date(valor).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' }) : '')

const enfocarLector = () => {
    requestAnimationFrame(() => lector.value?.focus())
}

const limpiarTemporizador = () => {
    clearInterval(cuentaRegresiva)
    cuentaRegresiva = null
}

const volverAEsperar = () => {
    limpiarTemporizador()
    respuesta.value = null
    codigo.value = ''
    enfocarLector()
}

const programarRegreso = (segundos) => {
    limpiarTemporizador()
    restante.value = segundos

    cuentaRegresiva = setInterval(() => {
        restante.value--

        if (restante.value <= 0) {
            volverAEsperar()
        }
    }, 1000)
}

const alEscanear = async () => {
    const token = codigo.value.trim()
    codigo.value = ''

    if (!token || cargando.value) {
        return
    }

    respuesta.value = await validar(token)
    programarRegreso(permitido.value ? ESPERA_PERMITIDO : ESPERA_RECHAZADO)

    if (permitido.value) {
        fetchResumen()
    }
}

const salir = () => {
    limpiarTemporizador()
    router.push('/')
}

// El campo oculto debe recuperar el foco pase lo que pase: si se pierde, el lector escribe al vacío.
watch(respuesta, () => enfocarLector())

onMounted(() => {
    enfocarLector()
    fetchResumen()
    document.addEventListener('click', enfocarLector)
})

onBeforeUnmount(() => {
    limpiarTemporizador()
    document.removeEventListener('click', enfocarLector)
})
</script>

<template>
    <div
        class="flex min-h-screen flex-col transition-colors duration-200"
        :class="respuesta ? (permitido ? 'bg-green-600' : 'bg-red-700') : 'bg-stone-100'"
    >
        <header
            class="flex items-center justify-between gap-4 px-8 py-5"
            :class="respuesta ? 'text-white' : 'bg-blue-800 text-white'"
        >
            <div class="flex items-center gap-3">
                <Button
                    v-if="puedeSalir"
                    variant="ghost"
                    size="icon"
                    class="text-white hover:bg-white/20 hover:text-white"
                    aria-label="Salir del checador"
                    title="Salir del checador"
                    @click="salir"
                >
                    <ChevronLeft class="size-6" />
                </Button>
                <p class="text-2xl font-bold tracking-wide">COMEDOR</p>
            </div>
            <p v-if="resumen" class="text-lg">
                {{ resumen.servidos }} de {{ resumen.pagados }} comidas servidas hoy
            </p>
        </header>

        <!-- Aquí escribe el lector USB. El código nunca se muestra. -->
        <input
            ref="lector"
            v-model="codigo"
            type="password"
            class="sr-only"
            autocomplete="off"
            aria-label="Escanea el gafete"
            @keyup.enter="alEscanear"
        />

        <main class="flex flex-1 items-center justify-center p-6">
            <!-- Esperando -->
            <div v-if="!respuesta" class="grid justify-items-center gap-6 text-center">
                <ScanLine class="size-28 text-blue-800" />
                <h1 class="text-5xl font-bold text-stone-900">Escanea tu gafete</h1>
                <p class="text-2xl text-stone-600">Para registrar tu comida de hoy.</p>
            </div>

            <!-- Respuesta: se lee de lejos y de un vistazo. -->
            <div v-else class="grid justify-items-center gap-6 text-center text-white">
                <component :is="permitido ? CircleCheckBig : CircleX" class="size-32" />

                <h1 class="text-6xl font-bold">{{ permitido ? 'PASA' : 'NO PASA' }}</h1>

                <p v-if="respuesta.persona" class="text-4xl">
                    {{ respuesta.persona.nombre }}
                </p>
                <p v-if="respuesta.persona" class="text-xl opacity-80">
                    No. {{ respuesta.persona.numero_empleado }}
                </p>

                <p class="max-w-2xl text-3xl">{{ respuesta.mensaje }}</p>

                <p v-if="permitido && respuesta.utilizado_en" class="text-xl opacity-80">
                    Registrado a las {{ hora(respuesta.utilizado_en) }}
                </p>

                <p class="text-lg opacity-70">Siguiente en {{ restante }} s</p>
                <Button
                    size="lg"
                    variant="outline"
                    class="border-white bg-transparent text-lg text-white hover:bg-white/20 hover:text-white"
                    @click="volverAEsperar"
                >
                    Siguiente
                </Button>
            </div>
        </main>
    </div>
</template>
