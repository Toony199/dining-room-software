<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'

import { Button } from '@/components/ui/button'
import { Camera, ImageUp, RefreshCw } from '@lucide/vue'

/**
 * Toma la fotografía de una persona (§3.1) con la cámara del dispositivo, o desde un archivo si
 * no hay cámara disponible.
 *
 * El recorte, la reducción y la conversión a WebP ocurren aquí, en el navegador: así el
 * servidor no necesita librerías de imagen y lo que se sube ya pesa poco.
 *
 * Emite `capturada` con un Blob listo para subir, o con null cuando se descarta para repetir.
 */
const emit = defineEmits(['capturada'])

// Vertical 3:4, como el recuadro de la foto en el gafete. Sobra para imprimir a ~2 cm de ancho.
const ANCHO = 600
const ALTO = 800
const CALIDAD = 0.85

const video = ref(null)
const selectorArchivo = ref(null)
const previa = ref(null)
const estado = ref('iniciando') // iniciando | camara | sin-camara | capturada
const motivoSinCamara = ref('')
let stream = null

const detenerCamara = () => {
    stream?.getTracks().forEach((pista) => pista.stop())
    stream = null
}

const soltarPrevia = () => {
    if (previa.value) {
        URL.revokeObjectURL(previa.value)
    }
    previa.value = null
}

const iniciarCamara = async () => {
    estado.value = 'iniciando'

    // Los navegadores solo prestan la cámara en HTTPS o en localhost. Servida por HTTP en la red
    // interna no estará disponible, y queda el selector de archivo, que en celulares abre la
    // cámara del sistema.
    if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
        motivoSinCamara.value = 'La cámara no está disponible en esta conexión (requiere HTTPS). Usa el botón para tomar o elegir la foto.'
        estado.value = 'sin-camara'

        return
    }

    try {
        stream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 960 } },
            audio: false,
        })
        estado.value = 'camara'
        await nextTick()
        video.value.srcObject = stream
        await video.value.play()
    } catch (error) {
        detenerCamara()
        motivoSinCamara.value = error?.name === 'NotAllowedError'
            ? 'No se dio permiso para usar la cámara. Usa el botón para tomar o elegir la foto.'
            : 'No se encontró una cámara disponible. Usa el botón para tomar o elegir la foto.'
        estado.value = 'sin-camara'
    }
}

// Recorta al centro con proporción 3:4 y lo reduce a ANCHO × ALTO.
const recortar = (fuente, anchoFuente, altoFuente) => {
    const canvas = document.createElement('canvas')
    canvas.width = ANCHO
    canvas.height = ALTO

    const proporcion = ANCHO / ALTO
    let ancho = anchoFuente
    let alto = altoFuente

    if (ancho / alto > proporcion) {
        ancho = alto * proporcion
    } else {
        alto = ancho / proporcion
    }

    canvas.getContext('2d').drawImage(
        fuente,
        (anchoFuente - ancho) / 2,
        (altoFuente - alto) / 2,
        ancho,
        alto,
        0,
        0,
        ANCHO,
        ALTO,
    )

    return canvas
}

// WebP de preferencia. Los navegadores que no saben codificarlo (Safari) devuelven PNG en su
// lugar; en ese caso se usa JPG, que pesa bastante menos.
const aBlob = (canvas) => new Promise((resolve) => {
    canvas.toBlob((blob) => {
        if (blob?.type === 'image/webp') {
            resolve(blob)

            return
        }

        canvas.toBlob(resolve, 'image/jpeg', CALIDAD)
    }, 'image/webp', CALIDAD)
})

const entregar = async (canvas) => {
    const blob = await aBlob(canvas)

    soltarPrevia()
    previa.value = URL.createObjectURL(blob)
    estado.value = 'capturada'
    detenerCamara()
    emit('capturada', blob)
}

const capturar = () => entregar(recortar(video.value, video.value.videoWidth, video.value.videoHeight))

const desdeArchivo = async (evento) => {
    const archivo = evento.target.files?.[0]
    evento.target.value = ''

    if (!archivo) {
        return
    }

    // createImageBitmap respeta la orientación EXIF de las fotos tomadas con el celular.
    const imagen = await createImageBitmap(archivo)
    await entregar(recortar(imagen, imagen.width, imagen.height))
}

const repetir = () => {
    soltarPrevia()
    emit('capturada', null)
    iniciarCamara()
}

onMounted(iniciarCamara)

onBeforeUnmount(() => {
    detenerCamara()
    soltarPrevia()
})
</script>

<template>
    <div class="grid gap-2">
        <div class="relative mx-auto aspect-[3/4] w-36 overflow-hidden rounded-md border bg-muted">
            <!-- Se ve en espejo, como un espejo; la foto se guarda sin invertir. -->
            <video
                v-show="estado === 'camara'"
                ref="video"
                class="h-full w-full -scale-x-100 object-cover"
                playsinline
                muted
            />
            <img
                v-if="estado === 'capturada' && previa"
                :src="previa"
                alt="Fotografía tomada"
                class="h-full w-full object-cover"
            />
            <div
                v-if="estado === 'iniciando' || estado === 'sin-camara'"
                class="absolute inset-0 flex items-center justify-center p-2 text-center text-xs text-muted-foreground"
            >
                <span v-if="estado === 'iniciando'">Abriendo la cámara…</span>
                <Camera v-else class="h-8 w-8" />
            </div>
        </div>

        <p v-if="estado === 'sin-camara'" class="text-center text-xs text-muted-foreground">
            {{ motivoSinCamara }}
        </p>

        <div class="flex flex-wrap justify-center gap-2">
            <Button v-if="estado === 'camara'" type="button" size="sm" @click="capturar">
                <Camera />
                Capturar
            </Button>
            <Button v-if="estado === 'capturada'" type="button" size="sm" variant="outline" @click="repetir">
                <RefreshCw />
                Repetir
            </Button>
            <Button
                v-if="estado !== 'capturada'"
                type="button"
                size="sm"
                variant="outline"
                @click="selectorArchivo.click()"
            >
                <ImageUp />
                {{ estado === 'camara' ? 'Elegir archivo' : 'Tomar o elegir foto' }}
            </Button>
        </div>

        <!-- `capture` hace que en celulares abra directamente la cámara del sistema. -->
        <input
            ref="selectorArchivo"
            type="file"
            accept="image/*"
            capture="user"
            class="hidden"
            @change="desdeArchivo"
        />
    </div>
</template>
