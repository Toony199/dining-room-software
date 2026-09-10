<script setup>
import { reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { toast } from 'vue-sonner'

import { useAuthStore } from '@/stores/auth.js'

import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle
} from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Command } from '@lucide/vue'

const auth = useAuthStore();
const router = useRouter();
const route = useRoute();

const form = reactive({
    email: '',
    password: '',
    recordarme: false,
});

const errores = ref({});

const entrar = async () => {
    errores.value = {};

    try {
        const { ok, errores: fallos } = await auth.login({ ...form });

        if (!ok) {
            errores.value = fallos;

            return;
        }

        // Vuelve a donde el guard lo interceptó, o al inicio si entró directo al login.
        const destino = typeof route.query.redirect === 'string' ? route.query.redirect : '/';
        await router.replace(destino);
    } catch (error) {
        // 500, red caída: no hay campo donde pintarlo.
        console.error(error);
        toast.error('No se pudo iniciar sesión.', {
            description: error.response?.data?.message ?? error.message,
        });
    }
};
</script>

<template>
    <div class="min-h-screen flex items-center justify-center p-4 bg-muted/40">
        <Card class="w-full max-w-sm">
            <CardHeader class="items-center text-center">
                <div class="mx-auto flex aspect-square size-10 items-center justify-center rounded-lg bg-primary text-primary-foreground">
                    <Command class="size-5" />
                </div>
                <CardTitle class="pt-2">Comedor</CardTitle>
                <CardDescription>
                    Entra con tu cuenta de sistema para administrar el servicio.
                </CardDescription>
            </CardHeader>

            <CardContent>
                <form class="grid gap-4" @submit.prevent="entrar">
                    <div class="grid gap-2">
                        <Label for="email">Correo</Label>
                        <Input
                            id="email"
                            v-model="form.email"
                            type="email"
                            placeholder="tu.correo@arod.com"
                            autocomplete="username"
                            autofocus
                        />
                    </div>

                    <div class="grid gap-2">
                        <Label for="password">Contraseña</Label>
                        <Input
                            id="password"
                            v-model="form.password"
                            type="password"
                            autocomplete="current-password"
                        />
                    </div>

                    <!-- El backend responde con un solo error bajo `email`, tanto para
                         credenciales incorrectas como para cuentas que no pueden operar
                         (§3.3), así que se muestra como un aviso del formulario completo. -->
                    <p v-if="errores.email" class="text-sm text-red-600">
                        {{ errores.email[0] }}
                    </p>
                    <p v-else-if="errores.password" class="text-sm text-red-600">
                        {{ errores.password[0] }}
                    </p>

                    <div class="flex items-center gap-2">
                        <Switch id="recordarme" v-model="form.recordarme" />
                        <Label for="recordarme" class="font-normal">Mantener la sesión abierta</Label>
                    </div>

                    <Button type="submit" class="w-full" :disabled="auth.cargando">
                        {{ auth.cargando ? 'Entrando…' : 'Entrar' }}
                    </Button>
                </form>
            </CardContent>
        </Card>
    </div>
</template>
