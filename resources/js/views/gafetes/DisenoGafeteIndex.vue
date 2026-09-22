<script setup>
import { onMounted, ref } from 'vue'

import { useDisenoGafete } from '@/composables/useDisenoGafete.js'
import GafeteTarjeta from '@/components/gafetes/GafeteTarjeta.vue'

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
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Spinner } from '@/components/ui/spinner'
import { CircleAlert, Download, Eye, RotateCcw, TriangleAlert, Upload } from '@lucide/vue'

/**
 * Diseño del gafete (§4.1): un solo diseño para todos los gafetes (§4).
 *
 * Se sube un SVG, queda como borrador y se revisa en la vista previa con datos de ejemplo antes de
 * activarlo: uno mal armado dejaría de imprimir bien todos los gafetes al instante. Los anteriores
 * quedan en el historial para volver a ellos. Toda la pantalla exige `gafetes.disenar`, igual que
 * el backend.
 */
const {
    vigente,
    loading,
    historial,
    error,
    cargarVigente,
    fetchHistorial,
    subir,
    activar,
    restablecer,
} = useDisenoGafete()

// Un nombre corto y uno largo: el diseño tiene que funcionar con los dos. El QR es de ejemplo, no
// corresponde a ningún gafete.
const EJEMPLOS = [
    { nombre: 'Ana Solano', departamento: 'Producción', numeroEmpleado: '0001', qrToken: 'EJEMPLO-DE-GAFETE' },
    {
        nombre: 'María Guadalupe Hernández',
        departamento: 'Tecnologías de la Información',
        numeroEmpleado: 'A-10458',
        qrToken: 'EJEMPLO-DE-GAFETE-2',
    },
]

// --- Subir ------------------------------------------------------------------------

const entrada = ref(null)

const elegirArchivo = () => {
    error.value = null
    entrada.value?.click()
}

const alElegirArchivo = async (evento) => {
    const archivo = evento.target.files?.[0]
    // Se limpia para que volver a elegir el mismo archivo (ya corregido) dispare el cambio.
    evento.target.value = ''

    if (!archivo) {
        return
    }

    const borrador = await subir(archivo)

    if (borrador) {
        fetchHistorial()
        abrirVistaPrevia(borrador)
    }
}

// --- Vista previa -------------------------------------------------------------------

const modalVistaPrevia = ref(false)
const enVistaPrevia = ref(null)

const abrirVistaPrevia = (diseno) => {
    enVistaPrevia.value = diseno
    modalVistaPrevia.value = true
}

const estado = (diseno) => {
    if (diseno.activo) {
        return { texto: 'Activo', variante: 'default', clase: 'bg-green-600' }
    }

    // Uno que se activó alguna vez es historial; uno que nunca, sigue siendo borrador.
    return diseno.activado_en
        ? { texto: 'Anterior', variante: 'outline', clase: '' }
        : { texto: 'Borrador', variante: 'secondary', clase: '' }
}

// --- Activar y restablecer --------------------------------------------------------------

const confirmarActivacion = ref(false)
const porActivar = ref(null)

const pedirActivacion = (diseno) => {
    porActivar.value = diseno
    confirmarActivacion.value = true
}

const activarConfirmado = async () => {
    confirmarActivacion.value = false

    if (await activar(porActivar.value)) {
        modalVistaPrevia.value = false
        fetchHistorial()
    }
}

const confirmarRestablecer = ref(false)

const restablecerConfirmado = async () => {
    confirmarRestablecer.value = false

    if (await restablecer()) {
        fetchHistorial()
    }
}

const fecha = (valor) => (valor ? new Date(valor).toLocaleString() : '—')

onMounted(() => {
    // Siempre fresco al entrar: alguien más pudo activar otro diseño mientras tanto.
    cargarVigente({ forzar: true })
    fetchHistorial()
})
</script>

<template>
    <div>
        <h1 class="text-2xl font-bold">Diseño del gafete</h1>
        <h2 class="text-sm text-gray-600">
            Un solo diseño para todos los gafetes. Se sube como SVG y se activa después de revisarlo.
        </h2>
    </div>

    <Card>
        <CardHeader>
            <CardTitle>Diseño activo</CardTitle>
            <CardDescription>Así se ven e imprimen hoy los gafetes, con datos de ejemplo.</CardDescription>
        </CardHeader>

        <CardContent class="grid gap-6 lg:grid-cols-[auto_1fr]">
            <div class="flex flex-wrap justify-center gap-4 rounded-md bg-muted p-4">
                <template v-if="vigente">
                    <GafeteTarjeta v-for="ejemplo in EJEMPLOS" :key="ejemplo.qrToken" v-bind="ejemplo" :diseno="vigente" />
                </template>
                <Spinner v-else class="m-10 h-6 w-6" />
            </div>

            <div class="grid content-start gap-4">
                <div v-if="vigente" class="grid gap-1 text-sm">
                    <p class="font-medium">
                        {{ vigente.predeterminado ? 'Diseño predeterminado del proyecto' : vigente.nombre_archivo }}
                    </p>
                    <p v-if="!vigente.predeterminado" class="text-muted-foreground">
                        Subido por {{ vigente.subido_por ?? '—' }} · activado el {{ fecha(vigente.activado_en) }}
                    </p>
                    <p v-else class="text-muted-foreground">
                        Se usa mientras no se active un diseño subido.
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <Button @click="elegirArchivo" :disabled="loading">
                        <Spinner v-if="loading" class="h-4 w-4" />
                        <Upload v-else />
                        Subir diseño
                    </Button>
                    <Button as-child variant="outline">
                        <!-- El servidor responde con el archivo como descarga: la página no cambia. -->
                        <a href="/api/gafetes/disenos/plantilla">
                            <Download />
                            Descargar plantilla
                        </a>
                    </Button>
                    <Button
                        v-if="vigente && !vigente.predeterminado"
                        variant="ghost"
                        :disabled="loading"
                        @click="confirmarRestablecer = true"
                    >
                        <RotateCcw />
                        Restablecer predeterminado
                    </Button>
                    <input ref="entrada" type="file" accept=".svg,image/svg+xml" class="hidden" @change="alElegirArchivo" />
                </div>

                <!-- El rechazo dice qué corregir en el editor de diseño: se deja a la vista. -->
                <div
                    v-if="error"
                    class="flex gap-2 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-800"
                >
                    <CircleAlert class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>
                        <span class="font-medium">No se pudo usar el archivo.</span>
                        {{ error }}
                    </span>
                </div>

                <div class="grid gap-1 rounded-md border p-3 text-sm text-muted-foreground">
                    <p class="font-medium text-foreground">Cómo preparar el diseño</p>
                    <ul class="grid list-disc gap-1 pl-5">
                        <li>Parte de la plantilla: mide 54 × 85.6 mm, en vertical.</li>
                        <li>
                            Los rectángulos <code>zona-foto</code>, <code>zona-nombre</code>,
                            <code>zona-departamento</code>, <code>zona-numero</code> y
                            <code>zona-qr</code> marcan dónde va cada dato. Muévelos, pero conserva su
                            id y no los gires. Su relleno es el color de las letras. Si tu editor los
                            guarda como trazos, también sirven.
                        </li>
                        <li>El QR necesita al menos 20 × 20 mm y un fondo claro alrededor.</li>
                        <li>Convierte a curvas los textos fijos e incrusta el logo en el SVG.</li>
                        <li>La línea de corte y las esquinas redondeadas las pone la aplicación.</li>
                    </ul>
                </div>
            </div>
        </CardContent>
    </Card>

    <Card>
        <CardHeader>
            <CardTitle>Historial</CardTitle>
            <CardDescription>
                Los diseños subidos no se borran: cualquiera se puede revisar y volver a activar.
            </CardDescription>
        </CardHeader>
        <CardContent>
            <Table>
                <TableHeader class="bg-stone-50">
                    <TableRow>
                        <TableHead class="font-bold">Archivo</TableHead>
                        <TableHead class="font-bold">Subido por</TableHead>
                        <TableHead class="font-bold">Subido</TableHead>
                        <TableHead class="text-center font-bold">Estado</TableHead>
                        <TableHead class="text-center font-bold">Acciones</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="diseno in historial" :key="diseno.id">
                        <TableCell class="max-w-64 truncate">{{ diseno.nombre_archivo }}</TableCell>
                        <TableCell>{{ diseno.subido_por ?? '—' }}</TableCell>
                        <TableCell>{{ fecha(diseno.subido_en) }}</TableCell>
                        <TableCell class="text-center">
                            <Badge :variant="estado(diseno).variante" :class="estado(diseno).clase">
                                {{ estado(diseno).texto }}
                            </Badge>
                        </TableCell>
                        <TableCell class="flex justify-center gap-2">
                            <Button size="sm" variant="outline" @click="abrirVistaPrevia(diseno)">
                                <Eye />
                                Vista previa
                            </Button>
                            <Button v-if="!diseno.activo" size="sm" :disabled="loading" @click="pedirActivacion(diseno)">
                                Activar
                            </Button>
                        </TableCell>
                    </TableRow>
                    <TableRow v-if="!historial.length">
                        <TableCell colspan="5" class="p-4 text-center text-gray-400">
                            Aún no se ha subido ningún diseño.
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </CardContent>
    </Card>

    <Dialog v-model:open="modalVistaPrevia">
        <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>Vista previa</DialogTitle>
                <DialogDescription v-if="enVistaPrevia">
                    {{ enVistaPrevia.nombre_archivo }} · {{ estado(enVistaPrevia).texto.toLowerCase() }}.
                    Revisa que los datos caigan en su lugar con un nombre corto y con uno largo.
                </DialogDescription>
            </DialogHeader>

            <div v-if="enVistaPrevia" class="grid gap-4">
                <div class="flex flex-wrap justify-center gap-4 rounded-md bg-muted p-4">
                    <GafeteTarjeta
                        v-for="ejemplo in EJEMPLOS"
                        :key="ejemplo.qrToken"
                        v-bind="ejemplo"
                        :diseno="enVistaPrevia"
                    />
                </div>

                <div
                    v-if="enVistaPrevia.advertencias?.length"
                    class="grid gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900"
                >
                    <p class="flex items-center gap-2 font-medium">
                        <TriangleAlert class="h-4 w-4 shrink-0" />
                        Antes de activarlo
                    </p>
                    <ul class="grid list-disc gap-1 pl-6">
                        <li v-for="aviso in enVistaPrevia.advertencias" :key="aviso">{{ aviso }}</li>
                    </ul>
                </div>
            </div>

            <DialogFooter>
                <Button variant="outline" @click="modalVistaPrevia = false">
                    {{ enVistaPrevia?.activo ? 'Cerrar' : 'Dejar como borrador' }}
                </Button>
                <Button v-if="enVistaPrevia && !enVistaPrevia.activo" :disabled="loading" @click="pedirActivacion(enVistaPrevia)">
                    Activar este diseño
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <AlertDialog v-model:open="confirmarActivacion">
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>¿Activar este diseño?</AlertDialogTitle>
                <AlertDialogDescription>
                    Desde ahora todos los gafetes se verán e imprimirán con
                    <span class="font-medium">{{ porActivar?.nombre_archivo }}</span>. Los que ya
                    están impresos siguen funcionando: el QR no depende del diseño. El diseño actual
                    queda en el historial.
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel>Cancelar</AlertDialogCancel>
                <AlertDialogAction @click="activarConfirmado">Activar</AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>

    <AlertDialog v-model:open="confirmarRestablecer">
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>¿Restablecer el diseño predeterminado?</AlertDialogTitle>
                <AlertDialogDescription>
                    Los gafetes volverán a verse e imprimirse con el diseño que trae el proyecto. El
                    diseño actual queda en el historial y se puede volver a activar.
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel>Cancelar</AlertDialogCancel>
                <AlertDialogAction @click="restablecerConfirmado">Restablecer</AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
