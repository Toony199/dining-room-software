<script setup>
import { computed } from 'vue'

import { useAuthStore } from '@/stores/auth.js'
import { modulos } from '@/modulos.js'

import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'

const auth = useAuthStore();

// Solo los módulos que el rol puede abrir: la misma regla que el menú lateral. Ofrecer uno que
// respondería 403 solo hace perder el tiempo (§5.6).
const disponibles = computed(() => modulos.filter((modulo) => auth.tienePermiso(modulo.permiso)));
</script>

<template>
    <div>
        <h1 class="text-2xl font-bold">Hola, {{ auth.nombre }}</h1>
        <h2 class="text-sm text-gray-600">
            Entraste con el rol
            <Badge variant="secondary">{{ auth.usuario?.rol?.nombre }}</Badge>
        </h2>
    </div>

    <div v-if="disponibles.length" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <router-link
            v-for="modulo in disponibles"
            :key="modulo.url"
            :to="modulo.url"
            class="block"
        >
            <Card class="h-full transition-colors hover:bg-muted/50">
                <CardHeader>
                    <component :is="modulo.icon" class="h-6 w-6 text-muted-foreground" />
                    <CardTitle class="pt-2">{{ modulo.title }}</CardTitle>
                    <CardDescription>{{ modulo.descripcion }}</CardDescription>
                </CardHeader>
            </Card>
        </router-link>
    </div>

    <Card v-else>
        <CardHeader>
            <CardTitle>Sin módulos disponibles</CardTitle>
            <CardDescription>
                Tu rol todavía no tiene permisos sobre ningún módulo administrativo. Pídele a un
                administrador que lo revise.
            </CardDescription>
        </CardHeader>
    </Card>
</template>
