# Production deploy checklist

`docker-compose.yml` is the local development stack (Vite dev server, bind mount, phpMyAdmin). Don't deploy it as is.

## 1. File ownership

Laravel writes to `storage/` and `bootstrap/cache/` at runtime. If a command runs as **root**, the files it creates are
owned by root and the web server user can't write to them any more; one root `php artisan ...` can make every `Log::` call
fail for the rest of the day (with daily logs). Run **every** artisan / composer command, the cron entry and the queue
workers as the user PHP-FPM runs as:

```bash
sudo -u www-data php artisan migrate --force
sudo chown -R www-data:www-data storage bootstrap/cache
```

## 2. Processes

- **Scheduler:** one cron entry as the web user, on one server only: `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1`
- **Queue worker:** Supervisor with `command=php /path/to/app/artisan queue:work --sleep=3 --tries=1 --max-time=3600` and `user=www-data`.
- `queue:work` keeps the code and config it booted with: run `php artisan queue:restart` after **every** deploy.

## 3. Every deploy

```bash
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data npm ci && sudo -u www-data npm run build
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache
sudo -u www-data php artisan queue:restart
```

`config:cache` means `env()` is only read while caching. Run it again after changing `.env`. Make sure `public/hot` doesn't
exist on the server: it makes Laravel load the assets from a Vite dev server.

## 4. Once per server

- `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` = the public https URL, `LOG_LEVEL=info`,
  `TRUSTED_PROXIES` = the load balancer's IPs (empty when nothing sits in front of the app; never `*` when the server is
  reachable directly).
- **`JWT_SECRET`**: at least 32 random bytes (`php -r 'echo bin2hex(random_bytes(32));'`), only in this file. Changing it signs
  everyone's access token out (the refresh cookie gets them a new one).
- **`JWT_REFRESH_COOKIE_SECURE=true`** (or leave it out): the app must be on https.
- `APP_KEY`: keep it safe and unchanged.
- The app is served from one origin (SPA + `/api`): the refresh cookie is `SameSite=Strict`. A frontend on another site can't
  use it.
- `php artisan storage:link`. PHP extensions: `pdo_mysql`, `mbstring`, `intl`, `bcmath`, `zip`, `pcntl`.
