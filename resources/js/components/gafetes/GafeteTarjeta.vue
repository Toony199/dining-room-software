<script setup>
import { computed, ref, watch } from 'vue'
import QRCode from 'qrcode'

/**
 * Dibujo del gafete (§4.1). Lo usa la vista previa del modal y la copia que se imprime.
 *
 * Versión funcional: tamaño de credencial CR80 en vertical (54 × 85.6 mm). Logo, colores y
 * tipografía definitivos quedan para el diseño corporativo (docs/analisis-diseno.md §10.6).
 */
const props = defineProps({
    nombre: { type: String, default: '' },
    departamento: { type: String, default: '' },
    numeroEmpleado: { type: String, default: '' },
    fotoUrl: { type: String, default: null },
    // Sin token (quien no puede imprimir) se muestra un recuadro en lugar del QR.
    qrToken: { type: String, default: null },
})

const qr = ref('')

watch(() => props.qrToken, async (token) => {
    // El QR codifica solo el token opaco del gafete. El SVG lo genera la librería a partir de ese
    // token, no de texto que escriba un usuario.
    qr.value = token
        ? await QRCode.toString(token, { type: 'svg', margin: 0, errorCorrectionLevel: 'M' })
        : ''
}, { immediate: true })

const fotoFallida = ref(false)
watch(() => props.fotoUrl, () => { fotoFallida.value = false })

const iniciales = computed(() => (
    props.nombre
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((parte) => parte[0])
        .join('')
        .toUpperCase()
))
</script>

<template>
    <div
        class="gafete-tarjeta flex flex-col items-center justify-between overflow-hidden rounded-lg border shadow-lg bg-white text-black m-2"
    >

        <div class="w-full bg-blue-800 py-1 text-center text-[9px] font-bold tracking-widest text-white">
            COMEDOR
        </div>

        <div class="flex h-[20mm] w-[20mm] items-center justify-center overflow-hidden rounded-md border border-neutral-300 bg-neutral-100 text-lg font-semibold text-neutral-500">
            <img
                v-if="fotoUrl && !fotoFallida"
                :src="fotoUrl"
                alt=""
                class="h-full w-full object-cover"
                @error="fotoFallida = true"
            />
            <span v-else>{{ iniciales }}</span>
        </div>

        <div class="px-2 text-center leading-tight">
            <p class="font-mono text-xs">No. {{ numeroEmpleado }}</p>
            <p class="text-xs font-bold">{{ nombre }}</p>
            <p class="text-xs text-neutral-600">{{ departamento }}</p>
        </div>

        <div class="flex h-[22mm] w-[22mm] items-center justify-center pb-2">
            <div v-if="qr" class="qr h-full w-full" v-html="qr" />
            <div
                v-else
                class="flex h-full w-full items-center justify-center rounded border border-dashed border-neutral-300 text-[8px] text-neutral-400"
            >
                QR
            </div>
        </div>
    </div>
</template>

<style scoped>
.gafete-tarjeta {
    width: 54mm;
    height: 85.6mm;
    /* El borde queda dentro de la medida: es la línea de corte. */
    box-sizing: border-box;
    /* Sin esto, el navegador omite al imprimir la franja de color, los fondos y el borde. */
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}

.qr :deep(svg) {
    width: 100%;
    height: 100%;
}
</style>
