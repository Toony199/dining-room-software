<script setup>
import { computed } from 'vue'

import GafeteTarjeta from '@/components/gafetes/GafeteTarjeta.vue'
import { GAFETES_POR_HOJA, datosDeTarjeta } from '@/components/gafetes/impresion.js'

/**
 * Varios gafetes acomodados en hojas para cortar con tijeras (§4.1).
 *
 * 3 × 3 por hoja, a tamaño real. Las filas van pegadas: se separan con dos cortes rectos de lado a
 * lado de la hoja, que son los fáciles con tijera. Entre columnas quedan 10 mm para partir cada
 * tira con holgura. Es la única manera de que quepan nueve: con espacio también entre filas la
 * tercera ya no entra con un margen que la impresora respete.
 *
 * Con `marco` se dibuja además el contorno de la hoja carta, para la vista previa en pantalla; en
 * la copia que se imprime el contorno es el papel mismo.
 */
const props = defineProps({
    // Respuestas del endpoint de impresión, en el orden en que deben salir.
    gafetes: { type: Array, default: () => [] },
    marco: { type: Boolean, default: false },
})

const hojas = computed(() => {
    const grupos = []

    for (let i = 0; i < props.gafetes.length; i += GAFETES_POR_HOJA) {
        grupos.push(props.gafetes.slice(i, i + GAFETES_POR_HOJA))
    }

    return grupos
})
</script>

<template>
    <div
        v-for="(hoja, indice) in hojas"
        :key="indice"
        class="hoja-gafetes"
        :class="{ 'hoja-gafetes--marco': marco }"
    >
        <div class="cuadricula">
            <GafeteTarjeta
                v-for="gafete in hoja"
                :key="gafete.id"
                v-bind="datosDeTarjeta(gafete)"
            />
        </div>
    </div>
</template>

<style scoped>
.cuadricula {
    display: grid;
    grid-template-columns: repeat(3, 54mm);
    column-gap: 10mm;
    row-gap: 0;
    justify-content: center;
}

/* Un gafete nunca queda partido entre dos hojas. */
.cuadricula > * {
    break-inside: avoid;
}

/* Cada grupo de nueve empieza hoja nueva; la última no deja una hoja en blanco detrás. */
.hoja-gafetes {
    break-after: page;
}

.hoja-gafetes:last-child {
    break-after: auto;
}

/*
 * Al imprimir, cada hoja ocupa la página completa y la cuadrícula queda centrada también a lo alto.
 * Pegada arriba, la primera fila depende de que el margen de 10 mm se respete: si en el cuadro de
 * impresión se eligen márgenes "Ninguno", arranca en el filo del papel y la impresora la recorta.
 * Centrada, quedan ~11 mm arriba y abajo con cualquier margen.
 */
@media print {
    .hoja-gafetes {
        display: flex;
        align-items: center;
        justify-content: center;
        height: 100vh;
        overflow: hidden;
    }
}

/* Vista previa: el contorno de una hoja carta con el margen de impresión (impresion.css). */
.hoja-gafetes--marco {
    box-sizing: border-box;
    width: 215.9mm;
    height: 279.4mm;
    padding: 10mm;
    background: white;
    box-shadow: 0 1px 4px rgb(0 0 0 / 0.25);
}
</style>
