import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        cors: true,
        // Bind all interfaces: vite runs in a container and the host browser reaches
        // it through the published port, which the default loopback bind refuses.
        host: '0.0.0.0',
        // 5173 belongs to the ai project and 5174 to pwa, so this one uses 5175.
        port: 5175,
        strictPort: true,
        // The browser resolves the dev server and HMR on the host, not inside the
        // container, so advertise the app's hostname instead of 0.0.0.0.
        origin: 'http://ai-app.test:5175',
        hmr: {
            host: 'ai-app.test',
        },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
