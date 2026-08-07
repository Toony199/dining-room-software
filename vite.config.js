import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';
import path from 'node:path';
import fs from 'node:fs';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        vue({
            // Vite 8 usa Rolldown y no provee `fs` al compilador de SFC; sin esto, resolver
            // tipos importados en `defineProps<T>()` (p. ej. PrimitiveProps de reka-ui, usado
            // por los componentes de shadcn-vue) falla con "No fs option provided to compileScript".
            script: {
                fs: {
                    // fileExists debe ser TRUE solo para archivos reales; si devuelve true para
                    // directorios, el compilador intenta leerlos y revienta con EISDIR.
                    fileExists(file) {
                        try {
                            return fs.statSync(file).isFile();
                        } catch {
                            return false;
                        }
                    },
                    readFile(file) {
                        try {
                            return fs.readFileSync(file, 'utf-8');
                        } catch {
                            return undefined;
                        }
                    },
                    realpath(file) {
                        try {
                            return fs.realpathSync(file);
                        } catch {
                            return file;
                        }
                    },
                },
            },
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './resources/js'),
        },
    },
});
