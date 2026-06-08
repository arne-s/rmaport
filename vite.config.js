import { defineConfig } from 'vite';
import laravel, { refreshPaths } from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/scss/overrides/filament/admin.scss',
                'resources/scss/overrides/filament/adminMenu.scss',
                'resources/js/app.js',
                'resources/js/filament.js',
                'resources/js/filament_scripts.js',
                'resources/js/appointment-calendar-picker-entry.js',

            ],
            refresh: [
                ...refreshPaths,
                'app/Http/Livewire/**',
                'app/Forms/Components/**',
            ],
        }),
        tailwindcss(),
    ],
})
