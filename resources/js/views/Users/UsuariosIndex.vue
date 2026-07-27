<script setup>
</script>

<template>
  <div class="min-h-screen bg-neutral-50 flex flex-col items-center justify-center p-6 text-neutral-800">
    <div class="max-w-lg w-full bg-white rounded-2xl shadow-sm border border-neutral-100 p-8 space-y-6">
      
      <div class="flex items-center justify-between border-b border-neutral-100 pb-4">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 bg-indigo-50 text-indigo-600 rounded-xl flex items-center justify-center text-xl font-bold">
            👤
          </div>
          <div>
            <h1 class="text-xl font-bold text-neutral-900">Usuarios</h1>
            <p class="text-xs text-neutral-500">Listado de usuarios registrados en el sistema</p>
          </div>
        </div>
        <router-link 
          to="/" 
          class="text-sm font-semibold text-neutral-600 hover:text-neutral-900 bg-neutral-100 hover:bg-neutral-200 px-3 py-1.5 rounded-lg transition-colors duration-200"
        >
          Volver al Inicio
        </router-link>
      </div>

      <!-- Placeholder de lista de usuarios -->
      <div class="space-y-3">
        <div class="flex items-center justify-between p-4 bg-neutral-50 rounded-xl border border-neutral-100">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-neutral-200 flex items-center justify-center text-neutral-600 font-semibold">
              JD
            </div>
            <div>
              <p class="font-semibold text-sm text-neutral-800">John Doe</p>
              <p class="text-xs text-neutral-500">john@example.com</p>
            </div>
          </div>
          <span class="px-2 py-1 text-xs font-semibold bg-green-50 text-green-700 rounded-md border border-green-100">Activo</span>
        </div>

        <div class="flex items-center justify-between p-4 bg-neutral-50 rounded-xl border border-neutral-100">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-neutral-200 flex items-center justify-center text-neutral-600 font-semibold">
              JS
            </div>
            <div>
              <p class="font-semibold text-sm text-neutral-800">Jane Smith</p>
              <p class="text-xs text-neutral-500">jane@example.com</p>
            </div>
          </div>
          <span class="px-2 py-1 text-xs font-semibold bg-green-50 text-green-700 rounded-md border border-green-100">Activo</span>
        </div>
      </div>

    </div>
  </div>
</template>

<style> </style>