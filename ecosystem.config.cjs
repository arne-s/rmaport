/**
 * PM2 process definitions for production.
 *
 * Setup:
 *   1. Set APP_ROOT to your deploy path below (or override via PM2_APP_ROOT).
 *   2. Configure .env (QUEUE_CONNECTION, BROADCAST_CONNECTION, REVERB_*).
 *   3. pm2 start ecosystem.config.cjs
 *   4. pm2 save && pm2 startup
 *
 * After deploy:
 *   php artisan queue:restart
 *   pm2 reload ecosystem.config.cjs --update-env
 */
const appRoot = process.env.PM2_APP_ROOT || '/var/www/rma-portal';

module.exports = {
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
