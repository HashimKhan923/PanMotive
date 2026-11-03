import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/admin.js'],
            refresh: true,
        }),
    ],
    server: {
        // Tailwind scans compiled views, and PHP writes one each time a page first renders.
        // Watching them reloaded the open page in the middle of whatever you were doing.
        watch: { ignored: ['**/storage/framework/views/**'] },
    },
});
