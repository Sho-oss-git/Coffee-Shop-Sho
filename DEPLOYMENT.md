# Deployment Guide

This project is a Laravel + Inertia + Vue application. It should not be deployed as a plain Vite frontend. The recommended hosting option is Render with PostgreSQL.

## Recommended setup

- Web service: Laravel app
- Worker service: Laravel queue worker
- Database: Render PostgreSQL

## 1. Create the database

In Render:

1. Create a PostgreSQL database.
2. Copy the host, port, database name, username, and password.
3. Add those values to your Render environment variables.

## 2. Configure environment variables

Use the values from `.env.production.example` as a template.

Minimum production values:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-app.onrender.com

DB_CONNECTION=pgsql
DB_HOST=your-db-host
DB_PORT=5432
DB_DATABASE=your_db_name
DB_USERNAME=your_db_user
DB_PASSWORD=your_db_password

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
```

Then generate the app key:

```bash
php artisan key:generate
```

## 3. Render configuration

This repo already includes a `render.yaml` file. Use it as the deployment configuration.

### Web service

```yaml
services:
  - type: web
    name: coffee-shop-pos
    runtime: docker
    plan: free
    buildCommand: |
      composer install --no-dev --optimize-autoloader --no-interaction
      npm install
      npm run build
      php artisan config:cache
      php artisan route:cache
      php artisan view:cache
      php artisan migrate --force
      php artisan storage:link
    startCommand: |
      php artisan serve --host 0.0.0.0 --port $PORT
```

### Worker service

```yaml
  - type: worker
    name: coffee-shop-pos-worker
    runtime: docker
    startCommand: |
      php artisan queue:work --tries=3 --sleep=3 --timeout=120
```

## 4. Deploy on Render

1. Push this repo to GitHub.
2. Open Render.
3. Select "Blueprint" or "New +" and import the repo.
4. If using the blueprint, Render will read `render.yaml`.
5. Add your PostgreSQL connection variables.
6. Deploy.

## 5. Post-deploy checks

After deployment run:

```bash
php artisan migrate --force
php artisan db:seed
php artisan storage:link
```

Then verify that:

- the app loads without errors
- login works
- the queue worker stays running
- database-backed sessions and cache work correctly

## 6. Why not Vercel

This app is a full Laravel project and is not a simple frontend build. Vercel is not suitable for the app in its current form because it requires PHP runtime, Laravel artisan commands, queue workers, and persistent database-backed services.

If you want a Vercel deployment, the recommended architecture is:

- Laravel backend on Render/Railway
- frontend on Vercel, if you split the app into separate frontend and API projects

## 7. Useful commands for production

```bash
php artisan optimize
php artisan migrate --force
php artisan queue:work --tries=3 --sleep=3 --timeout=120
php artisan config:clear
php artisan cache:clear
```
