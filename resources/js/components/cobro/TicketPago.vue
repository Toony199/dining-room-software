<script setup>
/**
 * Ticket de pago (§14). Es el respaldo de que los derechos se confirmaron con dinero.
 *
 * Formato de caja: papel térmico de 80 mm, ancho fijo, sin color ni gráficos —una térmica solo
 * quema puntos negros— y de alto variable, que lo corta la propia impresora.
 *
 * Lleva lo que pide §14: folio, colaborador, número de empleado, periodo, días pagados, precio por
 * día, total, monto recibido, cambio, fecha de confirmación y quién cobró.
 */
defineProps({
    pago: { type: Object, required: true },
    // La copia que se imprime vive en el <body>; en pantalla se muestra como vista previa.
    impresion: { type: Boolean, default: false },
})

const DIAS = ['', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo']

const aFecha = (valor) => {
    const [anio, mes, dia] = valor.slice(0, 10).split('-').map(Number)

    return new Date(anio, mes - 1, dia)
}

const fecha = (valor) => aFecha(valor).toLocaleDateString('es-MX', { day: '2-digit', month: '2-digit', year: 'numeric' })

const fechaHora = (valor) => new Date(valor).toLocaleString('es-MX', {
    day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
})

const dinero = (valor) => Number(valor ?? 0).toLocaleString('es-MX', { minimumFractionDigits: 2 })
</script>

<template>
    <div class="ticket" :class="{ 'ticket--pantalla': !impresion }">
        <p class="centro fuerte grande">COMEDOR</p>
        <p class="centro">Comprobante de pago</p>

        <p class="linea"></p>

        <p class="centro fuerte grande">{{ pago.ficha.folio }}</p>
        <p class="centro">{{ fechaHora(pago.confirmado_en) }}</p>

        <p class="linea"></p>

        <p>{{ pago.ficha.persona?.nombre_completo }}</p>
        <p>No. empleado: {{ pago.ficha.persona?.numero_empleado }}</p>
        <p v-if="pago.ficha.periodo">
            Semana: {{ fecha(pago.ficha.periodo.fecha_inicio) }} a {{ fecha(pago.ficha.periodo.fecha_fin) }}
        </p>

        <p class="linea"></p>

        <p class="fuerte">DIAS PAGADOS</p>
        <p v-for="dia in pago.ficha.dias" :key="dia.dia_periodo_id" class="renglon">
            <span>{{ DIAS[dia.dia_semana] }} {{ fecha(dia.fecha) }}</span>
            <span>{{ dinero(dia.precio) }}</span>
        </p>

        <p class="linea"></p>

        <p class="renglon fuerte grande">
            <span>TOTAL</span>
            <span>$ {{ dinero(pago.total_cobrado) }}</span>
        </p>
        <p class="renglon">
            <span>Recibido</span>
            <span>$ {{ dinero(pago.monto_recibido) }}</span>
        </p>
        <p class="renglon">
            <span>Cambio</span>
            <span>$ {{ dinero(pago.cambio) }}</span>
        </p>

        <p class="linea"></p>

        <p>Cobró: {{ pago.cobrador ?? '—' }}</p>
        <p class="centro espacio">
            Conserva este comprobante.<br />
            También puedes consultarlo con tu gafete en el kiosco.
        </p>
    </div>
</template>

<style scoped>
.ticket {
    /* 80 mm de papel menos los márgenes que no imprime el cabezal térmico. */
    width: 72mm;
    box-sizing: border-box;
    padding: 2mm 0 6mm;
    /* Monoespaciada: es lo que tienen las térmicas y lo que alinea las cantidades. */
    font-family: "Cascadia Mono", Consolas, "Courier New", monospace;
    font-size: 11px;
    line-height: 1.35;
    color: #000;
    background: #fff;
}

.ticket--pantalla {
    border: 1px dashed #d4d4d4;
    padding-inline: 3mm;
}

.ticket p {
    margin: 0;
}

.centro { text-align: center; }
.fuerte { font-weight: 700; }
.grande { font-size: 13px; }
.espacio { margin-top: 3mm; }

.renglon {
    display: flex;
    justify-content: space-between;
    gap: 4mm;
}

/* Separador de guiones, como los tickets de caja: sobrevive a cualquier térmica. */
.linea {
    border-top: 1px dashed #000;
    margin: 1.5mm 0;
    height: 0;
}
</style>
