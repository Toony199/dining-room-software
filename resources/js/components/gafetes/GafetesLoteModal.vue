<script setup>
import { computed, nextTick, ref, watch } from 'vue'

import { useGafetes } from '@/composables/useGafetes.js'
import HojasGafetes from '@/components/gafetes/HojasGafetes.vue'
import { GAFETES_POR_HOJA, esperarGafetes, imprimirZona } from '@/components/gafetes/impresion.js'

import { Button } from '@/components/ui/button'
import { Spinner } from '@/components/ui/spinner'
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog'
import { Printer, TriangleAlert } from '@lucide/vue'

/**
 * Vista previa e impresión de varios gafetes en hojas de 3 × 3 (§4.1).
 *
 * Muestra las hojas tal como van a salir y advierte a quién no se va a imprimir antes de gastar
 * papel. Quién entra lo decide el servidor, no la selección: entre que se cargó el listado y se
 * abrió esto, alguien pudo reponer un gafete o dar de baja a una persona.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    // Ids de las personas seleccionadas en el listado.
    personas: { type: Array, default: () => [] },
})

const emit = defineEmits(['update:open'])

const { fetchImpresionLote } = useGafetes()

const resultado = ref(null)
const listo = ref(false)
const zona = ref(null)

// Si el modal se cierra o se vuelve a abrir mientras carga, la respuesta vieja se descarta.
let carga = 0

const gafetes = computed(() => resultado.value?.gafetes ?? [])
const omitidos = computed(() => resultado.value?.omitidos ?? [])
const totalHojas = computed(() => Math.ceil(gafetes.value.length / GAFETES_POR_HOJA))

const resumen = computed(() => {
    const n = gafetes.value.length
    const h = totalHojas.value

    return `${n} ${n === 1 ? 'gafete' : 'gafetes'} en ${h} ${h === 1 ? 'hoja' : 'hojas'}.`
})

const cargar = async () => {
    const esta = ++carga
    resultado.value = null
    listo.value = false

    const respuesta = await fetchImpresionLote(props.personas)

    if (esta !== carga || !respuesta) {
        return
    }

    resultado.value = respuesta

    if (!respuesta.gafetes.length) {
        return
    }

    // El botón de imprimir se habilita hasta que la copia tiene todos sus QR y sus fotos: si no,
    // con varios gafetes salen huecos en blanco.
    await nextTick()

    if (esta !== carga || !zona.value) {
        return
    }

    await esperarGafetes(zona.value, respuesta.gafetes.length)

    if (esta === carga) {
        listo.value = true
    }
}

watch(() => props.open, (abierto) => {
    if (abierto) {
        cargar()
    } else {
        carga++
    }
}, { immediate: true })

const imprimir = () => imprimirZona(zona.value)
</script>

<template>
    <Dialog :open="open" @update:open="(valor) => emit('update:open', valor)">
        <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-3xl">
            <DialogHeader>
                <DialogTitle>Imprimir gafetes</DialogTitle>
                <DialogDescription>
                    <template v-if="!resultado">Preparando los gafetes…</template>
                    <template v-else-if="gafetes.length">
                        {{ resumen }} Se cortan con tijeras: primero las filas de lado a lado, luego
                        cada tira por los espacios.
                    </template>
                    <template v-else>No hay gafetes que imprimir.</template>
                </DialogDescription>
            </DialogHeader>

            <div v-if="!resultado" class="flex justify-center py-10">
                <Spinner class="h-6 w-6" />
            </div>

            <template v-else>
                <div
                    v-if="omitidos.length"
                    class="grid gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900"
                >
                    <p class="flex items-center gap-2 font-medium">
                        <TriangleAlert class="h-4 w-4 shrink-0" />
                        {{ omitidos.length === 1
                            ? 'Una persona no se va a imprimir:'
                            : `${omitidos.length} personas no se van a imprimir:` }}
                    </p>
                    <ul class="grid gap-1 pl-6">
                        <li v-for="omitido in omitidos" :key="omitido.id">
                            <span class="font-medium">{{ omitido.nombre_completo ?? `Persona ${omitido.id}` }}</span>
                            — {{ omitido.motivo }}
                        </li>
                    </ul>
                </div>

                <!-- Vista previa a escala: cada recuadro es una hoja carta con su margen. -->
                <div v-if="gafetes.length" class="overflow-x-auto rounded-md bg-muted p-4">
                    <div class="vista-hojas flex flex-wrap justify-center gap-[10mm]">
                        <HojasGafetes :gafetes="gafetes" marco />
                    </div>
                </div>

                <p v-if="gafetes.length" class="text-xs text-muted-foreground">
                    Imprime con la escala al 100 %: si el navegador la ajusta a la página, los
                    gafetes dejan de medir 54 × 85.6 mm.
                </p>
            </template>

            <DialogFooter>
                <Button variant="outline" @click="emit('update:open', false)">Cerrar</Button>
                <Button v-if="gafetes.length" :disabled="!listo" @click="imprimir">
                    <Spinner v-if="!listo" class="h-4 w-4" />
                    <Printer v-else />
                    {{ listo ? `Imprimir ${totalHojas === 1 ? '1 hoja' : `${totalHojas} hojas`}` : 'Preparando…' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Copia que se imprime, directamente en <body> (ver impresion.css). -->
    <Teleport to="body">
        <div v-if="open && gafetes.length" ref="zona" class="zona-impresion-gafete">
            <HojasGafetes :gafetes="gafetes" />
        </div>
    </Teleport>
</template>

<style scoped>
/* La hoja carta mide 816 px de ancho en pantalla; a esta escala caben dos lado a lado. */
.vista-hojas {
    zoom: 0.4;
}
</style>
