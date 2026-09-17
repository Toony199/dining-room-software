<script setup>
import { computed, ref, watch } from 'vue'

import { useAuthStore } from '@/stores/auth.js'
import { useGafetes } from '@/composables/useGafetes.js'
import GafeteTarjeta from '@/components/gafetes/GafeteTarjeta.vue'

import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Label } from '@/components/ui/label'
import {
    Dialog,
    DialogContent,
    DialogDescription,
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
import { Printer, RefreshCw } from '@lucide/vue'

/**
 * Gafete de una persona (§4): vista previa, impresión, emisión o reposición, e historial.
 *
 * Imprime desde la misma ventana, sin abrir otra página. La fotografía no se toma aquí: es un
 * dato de la persona y se captura en su formulario de alta o edición.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    persona: { type: Object, default: null },
})

const emit = defineEmits(['update:open', 'actualizado'])

const auth = useAuthStore()
const { loading, historial, fetchHistorial, emitirGafete, fetchImpresion } = useGafetes()

// Mismas reglas que el backend (§5.6): imprimir lo puede quien emite o quien solo reimprime.
const puede = computed(() => ({
    ver: auth.tienePermiso('gafetes.ver'),
    emitir: auth.tienePermiso('gafetes.emitir'),
    imprimir: auth.tienePermiso('gafetes.emitir') || auth.tienePermiso('gafetes.reimprimir'),
    editarPersona: auth.tienePermiso('colaboradores.editar'),
}))

const gafeteId = ref(null)
// Respuesta de /impresion: trae el token del QR y la foto. Solo la pide quien puede imprimir.
const datos = ref(null)
const confirmarReposicion = ref(false)

const personaActiva = computed(() => props.persona?.estado === 'ACTIVO')

const tarjeta = computed(() => ({
    nombre: datos.value?.persona.nombre_completo ?? props.persona?.nombre_completo ?? '',
    departamento: datos.value?.persona.departamento ?? props.persona?.departamento?.nombre ?? '',
    numeroEmpleado: datos.value?.persona.numero_empleado ?? props.persona?.numero_empleado ?? '',
    fotoUrl: datos.value?.persona.foto_url ?? props.persona?.foto_url ?? null,
    qrToken: datos.value?.qr_token ?? null,
}))

const tieneFoto = computed(() => Boolean(tarjeta.value.fotoUrl))

const cargar = async () => {
    datos.value = null
    historial.value = []

    const tareas = []

    if (gafeteId.value && puede.value.imprimir) {
        tareas.push(fetchImpresion(gafeteId.value).then((respuesta) => { datos.value = respuesta }))
    }

    if (puede.value.ver && props.persona) {
        tareas.push(fetchHistorial(props.persona.id))
    }

    await Promise.all(tareas)
}

watch(() => [props.open, props.persona?.id], ([abierto]) => {
    if (!abierto) {
        return
    }

    gafeteId.value = props.persona?.gafete?.id ?? null
    cargar()
}, { immediate: true })

const emitir = async () => {
    confirmarReposicion.value = false

    const nuevo = await emitirGafete(props.persona.id)

    if (!nuevo) {
        return
    }

    gafeteId.value = nuevo.id
    await cargar()
    emit('actualizado')
}

// Reponer invalida el gafete que la persona trae consigo, así que se pide confirmación. Emitir
// el primero no invalida nada.
const solicitarEmision = () => {
    if (gafeteId.value) {
        confirmarReposicion.value = true
    } else {
        emitir()
    }
}

// Marca <html> solo mientras dura esta impresión, para que la hoja de estilos de abajo no afecte
// a ninguna otra impresión de la aplicación.
const imprimir = () => {
    const raiz = document.documentElement
    raiz.classList.add('imprimiendo-gafete')
    window.addEventListener('afterprint', () => raiz.classList.remove('imprimiendo-gafete'), { once: true })
    window.print()
}

const fecha = (valor) => (valor ? new Date(valor).toLocaleString() : '—')
</script>

<template>
    <Dialog :open="open" @update:open="(valor) => emit('update:open', valor)">
        <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Gafete</DialogTitle>
                <DialogDescription>
                    {{ persona?.nombre_completo }} · No. {{ persona?.numero_empleado }}
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-4 py-2">
                <div class="flex justify-center">
                    <GafeteTarjeta v-bind="tarjeta" />
                </div>

                <div class="grid gap-1 text-center">
                    <div>
                        <Badge v-if="gafeteId" class="bg-green-600">Gafete activo</Badge>
                        <span v-else class="text-sm text-muted-foreground">
                            Sin gafete activo: no puede identificarse en el kiosco ni en el comedor.
                        </span>
                    </div>
                    <p v-if="gafeteId && !puede.imprimir" class="text-xs text-muted-foreground">
                        El código QR solo se muestra a quien puede imprimir gafetes.
                    </p>
                    <p v-if="!tieneFoto" class="text-xs text-muted-foreground">
                        Sin fotografía{{ puede.editarPersona ? ': agrégala en el módulo de colaboradores.' : '.' }}
                    </p>
                    <p v-if="puede.emitir && !personaActiva" class="text-xs text-muted-foreground">
                        La persona está dada de baja: no se le puede emitir un gafete.
                    </p>
                </div>

                <div class="flex flex-wrap justify-center gap-2">
                    <Button
                        v-if="gafeteId && puede.imprimir"
                        :disabled="!datos"
                        @click="imprimir"
                    >
                        <Printer />
                        Imprimir
                    </Button>
                    <Button
                        v-if="puede.emitir"
                        :variant="gafeteId ? 'outline' : 'default'"
                        :disabled="loading || !personaActiva"
                        @click="solicitarEmision"
                    >
                        <RefreshCw v-if="gafeteId" />
                        {{ gafeteId ? 'Reponer' : 'Emitir gafete' }}
                    </Button>
                </div>

                <div v-if="puede.ver" class="grid gap-2">
                    <Label>Historial</Label>
                    <div class="max-h-48 overflow-y-auto rounded-md border divide-y">
                        <div
                            v-for="item in historial"
                            :key="item.id"
                            class="flex items-center justify-between p-2 text-sm"
                        >
                            <span>{{ fecha(item.emitido_en) }}</span>
                            <Badge
                                :variant="item.estado === 'ACTIVO' ? 'default' : 'outline'"
                                :class="item.estado === 'ACTIVO' ? 'bg-green-600' : ''"
                            >
                                {{ item.estado === 'ACTIVO' ? 'Activo' : 'Reemplazado' }}
                            </Badge>
                        </div>
                        <p v-if="!historial.length" class="p-3 text-sm text-muted-foreground">
                            Sin gafetes emitidos.
                        </p>
                    </div>
                </div>
            </div>
        </DialogContent>
    </Dialog>

    <AlertDialog v-model:open="confirmarReposicion">
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>¿Reponer el gafete?</AlertDialogTitle>
                <AlertDialogDescription>
                    El gafete actual de
                    <span class="font-medium">{{ persona?.nombre_completo }}</span>
                    dejará de funcionar de inmediato en el kiosco y en el comedor. Se emitirá uno
                    nuevo con otro código QR, que habrá que imprimir y entregarle.
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel>Cancelar</AlertDialogCancel>
                <AlertDialogAction @click="emitir">Reponer</AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>

    <!-- Copia que se imprime. Vive directamente en <body>, fuera del diálogo, para que la hoja de
         estilos de impresión pueda ocultar todo lo demás sin pelear con el overlay ni con las
         transformaciones del modal. -->
    <Teleport to="body">
        <div v-if="open && datos" class="zona-impresion-gafete">
            <GafeteTarjeta v-bind="tarjeta" />
        </div>
    </Teleport>
</template>

<style>
/*
 * Global a propósito: @page y el ocultado del resto de la página no pueden ser scoped. Todo se
 * condiciona a la clase que imprimir() pone en <html>, para no alterar otras impresiones.
 */
.zona-impresion-gafete {
    display: none;
}

/*
 * La hoja es la del papel de la impresora, con margen: si la hoja midiera lo mismo que el gafete, el
 * borde caería en la orilla, donde la impresora no imprime, y se perdería la guía de corte.
 */
@page gafete {
    margin: 10mm;
}

@media print {
    html.imprimiendo-gafete body > *:not(.zona-impresion-gafete) {
        display: none !important;
    }

    /*
     * Centrado en la hoja: la zona ocupa la página completa y el gafete queda a la mitad, lejos de
     * las orillas que la impresora recorta. La altura es exacta para que no se genere una segunda
     * hoja en blanco.
     */
    html.imprimiendo-gafete .zona-impresion-gafete {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        height: 100vh;
        margin: 0;
        overflow: hidden;
        page: gafete;
    }

    /* El gafete conserva su medida exacta: el centrado no lo encoge. */
    html.imprimiendo-gafete .zona-impresion-gafete > * {
        flex: none;
    }
}
</style>
