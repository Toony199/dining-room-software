<script setup>
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { CircleHelp } from '@lucide/vue'

/**
 * Signo de interrogación junto a la etiqueta de un campo: al pasar el cursor —o al llegar con el
 * teclado— explica qué se espera ahí.
 *
 * Es un complemento de la etiqueta, no su sustituto: si el nombre del campo solo se entiende con
 * la ayuda, lo que hay que cambiar es el nombre.
 */
defineProps({
    texto: { type: String, required: true },
    // Para quien navega con lector de pantalla, que no ve junto a qué etiqueta está el icono.
    campo: { type: String, default: '' },
})
</script>

<template>
    <Tooltip>
        <TooltipTrigger as-child>
            <button
                type="button"
                class="inline-flex size-4 items-center justify-center rounded-full text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                :aria-label="campo ? `Qué es ${campo}` : 'Más información'"
            >
                <CircleHelp class="size-4" />
            </button>
        </TooltipTrigger>
        <TooltipContent class="max-w-72 text-pretty">
            {{ texto }}
        </TooltipContent>
    </Tooltip>
</template>
