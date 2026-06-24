import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';

const pathIncludes = (id, segment) =>
    id.includes(segment) || id.includes(segment.replaceAll('/', '\\'));

export default defineConfig({
    plugins: [
        tailwindcss(),
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
            ],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    build: {
        rollupOptions: {
            output: {
                manualChunks(id) {
                    if (
                        pathIncludes(id, '/node_modules/@tiptap/pm/') ||
                        pathIncludes(id, '/node_modules/prosemirror-') ||
                        pathIncludes(id, '/node_modules/orderedmap/') ||
                        pathIncludes(id, '/node_modules/rope-sequence/')
                    ) {
                        return 'prosemirror';
                    }

                    if (
                        pathIncludes(id, '/node_modules/@tiptap/') ||
                        pathIncludes(id, '/node_modules/lowlight/')
                    ) {
                        return 'tiptap';
                    }
                },
            },
        },
    },
});
