import { dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * PM2 process definitions for production.
 *
 * Setup:
 *   cd ~/public_html/prod
 *   pm2 start
 *   pm2 save && pm2 startup
 *
 * Optional override:
 *   PM2_APP_ROOT=/path/to/app pm2 start
 *
 * After deploy:
 *   php artisan queue:restart
 *   pm2 reload ecosystem.config.js --update-env
 */
const appRoot = process.env.PM2_APP_ROOT || dirname(fileURLToPath(import.meta.url));

export default {
    apps: [
        {
            name: 'rma-portal-queue',
            cwd: appRoot,
            script: 'artisan',
            args: 'queue:work database --sleep=3 --tries=3 --timeout=120 --max-time=3600 --memory=512',
            interpreter: 'php',
            instances: 1,
            autorestart: true,
            watch: false,
            max_memory_restart: '512M',
            restart_delay: 5000,
            env: {
                APP_ENV: 'production',
            },
        },
        {
            name: 'rma-portal-reverb',
            cwd: appRoot,
            script: 'artisan',
            args: 'reverb:start --host=127.0.0.1 --port=8080 --no-interaction',
            interpreter: 'php',
            instances: 1,
            autorestart: true,
            watch: false,
            restart_delay: 5000,
            env: {
                APP_ENV: 'production',
            },
        },
    ],
};
