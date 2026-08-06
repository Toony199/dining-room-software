<script setup>
import { reactive, ref, onMounted } from 'vue'

import { useDepartamentosApi } from '@/composables/useDepartamentosApi.js'

const {
    loading,
    departamentos,
    errores,
    fetchDepartamentos,
    createDepartamento,
    updateDepartamento,
    desactivarDepartamento,
    activarDepartamento,
    limpiarErrores,
} = useDepartamentosApi();

// Estado del formulario. editandoId = null → alta; con id → edición.
const editandoId = ref(null);
const form = reactive({
    nombre: '',
    activo: true,
});

const resetForm = () => {
    editandoId.value = null;
    form.nombre = '';
    form.activo = true;
    limpiarErrores();
};

const editar = (depto) => {
    editandoId.value = depto.id;
    form.nombre = depto.nombre;
    form.activo = depto.activo;
    limpiarErrores();
};

const guardar = async () => {
    const payload = { nombre: form.nombre, activo: form.activo };
    const ok = editandoId.value
        ? await updateDepartamento(editandoId.value, payload)
        : await createDepartamento(payload);

    if (ok) resetForm();
};

onMounted(fetchDepartamentos);
</script>

<template>
    <div class="mx-auto max-w-3xl p-6">
        <h1 class="mb-6 text-2xl font-semibold">Departamentos</h1>

        <!-- Formulario de alta / edición -->
        <form class="mb-8 rounded-lg border border-gray-200 p-4" @submit.prevent="guardar">
            <h2 class="mb-4 text-lg font-medium">
                {{ editandoId ? 'Editar departamento' : 'Nuevo departamento' }}
            </h2>

            <div class="mb-4">
                <label class="mb-1 block text-sm font-medium" for="nombre">Nombre</label>
                <input
                    id="nombre"
                    v-model="form.nombre"
                    type="text"
                    class="w-full rounded border border-gray-300 px-3 py-2"
                    placeholder="Ej. Producción"
                />
                <p v-if="errores.nombre" class="mt-1 text-sm text-red-600">
                    {{ errores.nombre[0] }}
                </p>
            </div>

            <label class="mb-4 flex items-center gap-2 text-sm">
                <input v-model="form.activo" type="checkbox" />
                Activo
            </label>

            <div class="flex gap-2">
                <button
                    type="submit"
                    :disabled="loading"
                    class="rounded bg-blue-600 px-4 py-2 text-white disabled:opacity-50"
                >
                    {{ editandoId ? 'Actualizar' : 'Guardar' }}
                </button>
                <button
                    v-if="editandoId"
                    type="button"
                    class="rounded border border-gray-300 px-4 py-2"
                    @click="resetForm"
                >
                    Cancelar
                </button>
            </div>
        </form>

        <!-- Listado -->
        <table class="w-full border-collapse text-left text-sm">
            <thead>
                <tr class="border-b border-gray-300">
                    <th class="py-2">ID</th>
                    <th class="py-2">Nombre</th>
                    <th class="py-2">Estado</th>
                    <th class="py-2 text-right">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="depto in departamentos" :key="depto.id" class="border-b border-gray-100">
                    <td class="py-2">{{ depto.id }}</td>
                    <td class="py-2">{{ depto.nombre }}</td>
                    <td class="py-2">
                        <span
                            :class="depto.activo ? 'text-green-700' : 'text-gray-400'"
                        >
                            {{ depto.activo ? 'Activo' : 'Inactivo' }}
                        </span>
                    </td>
                    <td class="py-2 text-right">
                        <button class="mr-3 text-blue-600" @click="editar(depto)">Editar</button>
                        <button
                            v-if="depto.activo"
                            class="text-red-600"
                            @click="desactivarDepartamento(depto.id)"
                        >
                            Desactivar
                        </button>
                        <button
                            v-else
                            class="text-green-600"
                            @click="activarDepartamento(depto.id)"
                        >
                            Activar
                        </button>
                    </td>
                </tr>
                <tr v-if="!departamentos.length">
                    <td colspan="4" class="py-4 text-center text-gray-400">
                        No hay departamentos registrados.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
