<script setup lang="ts">
import type { SidebarProps } from '@/components/ui/sidebar'

const appName = import.meta.env.VITE_APP_NAME;


import {
  Landmark,
  BookOpen,
  Bot,
  Command,
  Frame,
  LifeBuoy,
  Map,
  PieChart,
  Send,
  Settings2,
  SquareTerminal,
  Home,
} from "@lucide/vue"

import NavMain from '@/components/NavMain.vue'
import NavProjects from '@/components/NavProjects.vue'
import NavSecondary from '@/components/NavSecondary.vue'
import NavUser from '@/components/NavUser.vue'
import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
} from '@/components/ui/sidebar'

const props = withDefaults(defineProps<SidebarProps>(), {
  variant: "inset",
})

const data = {
  user: {
    name: "Desarrollador",
    email: "correo@correo.com",
    avatar: "/avatars/shadcn.jpg",
  },
  navMain: [
    {
      title: 'Home',
      url: '/',
      icon: Home,
      isActive: false,
      // items: [
      //   {
      //     title: '',
      //     url: '',
      //   }
      // ]
    },
    {
      title: 'Departamentos',
      url: '/departamentos',
      icon: Landmark,
      isActive: false,
    },
  ],
  navSecondary: [
    {
      title: "Support",
      url: "#",
      icon: Landmark,
    },
  ],
  projects: []
}

</script>

<template>
  <Sidebar v-bind="props">
    <SidebarHeader>
      <SidebarMenu>
        <SidebarMenuItem>
          <SidebarMenuButton size="lg" as-child>
            <router-link to="/">
              <div class="flex aspect-square size-8 items-center justify-center rounded-lg bg-sidebar-primary text-sidebar-primary-foreground">
                <Command class="size-4" />
              </div>
              <div class="grid flex-1 text-left text-sm leading-tight">
                <!-- USAR EL NOMBRE DE LA APLICACIÓN ESTRAIDA DE EL ARCHIVO .ENV -->
                <span class="truncate font-medium">{{ appName }}</span>
                <span class="truncate text-xs">AROD S.A DE C.V</span>
              </div>
            </router-link>
          </SidebarMenuButton>
        </SidebarMenuItem>
      </SidebarMenu>
    </SidebarHeader>
    <SidebarContent>
      <NavMain :items="data.navMain" />
      <NavProjects :projects="data.projects" />
      <NavSecondary :items="data.navSecondary" class="mt-auto" />
    </SidebarContent>
    <SidebarFooter>
      <NavUser :user="data.user" />
    </SidebarFooter>
  </Sidebar>
</template>
