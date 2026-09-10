<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { ChevronsUpDown, LogOut, ShieldCheck } from "@lucide/vue"

import { useAuthStore } from '@/stores/auth.js'

import {
  Avatar,
  AvatarFallback,
} from '@/components/ui/avatar'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  useSidebar,
} from '@/components/ui/sidebar'

const { isMobile } = useSidebar()
const auth = useAuthStore()
const router = useRouter()

// La identidad viene de la sesión: `persona.nombre_completo` y el correo de la cuenta.
const nombre = computed(() => auth.nombre || 'Sin sesión')
const correo = computed(() => auth.usuario?.email ?? '')
const rol = computed(() => auth.usuario?.rol?.nombre ?? '')

// Iniciales para el avatar, ya que no hay fotografía todavía (§3.1, pendiente).
const iniciales = computed(() => (
  nombre.value
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((parte) => parte[0])
    .join('')
    .toUpperCase() || '?'
))

const salir = async () => {
  await auth.logout()
  await router.replace({ name: 'login' })
}
</script>

<template>
  <SidebarMenu>
    <SidebarMenuItem>
      <DropdownMenu>
        <DropdownMenuTrigger as-child>
          <SidebarMenuButton
            size="lg"
            class="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
          >
            <Avatar class="h-8 w-8 rounded-lg">
              <AvatarFallback class="rounded-lg">
                {{ iniciales }}
              </AvatarFallback>
            </Avatar>
            <div class="grid flex-1 text-left text-sm leading-tight">
              <span class="truncate font-medium">{{ nombre }}</span>
              <span class="truncate text-xs">{{ correo }}</span>
            </div>
            <ChevronsUpDown class="ml-auto size-4" />
          </SidebarMenuButton>
        </DropdownMenuTrigger>
        <DropdownMenuContent
          class="w-(--reka-dropdown-menu-trigger-width) min-w-56 rounded-lg"
          :side="isMobile ? 'bottom' : 'right'"
          align="end"
          :side-offset="4"
        >
          <DropdownMenuLabel class="p-0 font-normal">
            <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
              <Avatar class="h-8 w-8 rounded-lg">
                <AvatarFallback class="rounded-lg">
                  {{ iniciales }}
                </AvatarFallback>
              </Avatar>
              <div class="grid flex-1 text-left text-sm leading-tight">
                <span class="truncate font-semibold">{{ nombre }}</span>
                <span class="truncate text-xs">{{ correo }}</span>
              </div>
            </div>
          </DropdownMenuLabel>

          <DropdownMenuSeparator />

          <!-- §5.1: exactamente un rol, y de él salen todos los permisos. -->
          <DropdownMenuItem v-if="rol" disabled>
            <ShieldCheck />
            {{ rol }}
          </DropdownMenuItem>

          <DropdownMenuSeparator v-if="rol" />

          <DropdownMenuItem @select="salir">
            <LogOut />
            Cerrar sesión
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </SidebarMenuItem>
  </SidebarMenu>
</template>
