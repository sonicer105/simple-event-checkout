# Simple Event Checkout

Slim + Twig + Doctrine DBAL + MySQL app for selling tickets for small, one-off events.

## Docker Dev Setup

1. Start app + DB:
   - `docker compose up --build`
2. Install PHP dependencies:
   - `docker compose exec app composer install`
3. Open the app:
   - `http://localhost:7879`
4. Optional DB UI:
   - `docker compose --profile tools up -d phpmyadmin`
   - `http://localhost:8082`

Notes:

- Containers:
  - App: `simple-event-checkout-app`
  - DB: `simple-event-checkout-db` (MySQL exposed on host port `3308`)
- Config overrides (gitignored): `public_html/config/local.php` (template: `public_html/config/local.php.example`).
- MySQL initializes the schema from `public_html/database/migrations/001_init.sql` on first boot (fresh volume only).
- To reset DB: `docker compose down -v` then `docker compose up --build`.

## Config

- `public_html/config/config.php` is safe to commit.
- `public_html/config/local.php` overrides `config.php` (via `array_replace_recursive()`) and is the right place for secrets.
- If TLS terminates upstream (e.g. cloudflared), set:
  - `security.force_https = true` (so cookies are marked `Secure` even when PHP doesn’t see `HTTPS=on`).

## Product Images

- Place product/event images in `public_html/assets/img/products/` (served as `/assets/img/products/...`).
- Select the image from the Image dropdown in the admin event editor.
- If no image is selected, the UI falls back to `/assets/img/placeholder.png`.

## Migrations

Run migrations with:

- `docker exec -i simple-event-checkout-app php public_html/scripts/migrate.php`

Useful flags:

- `--dry-run` prints pending migrations
- `--baseline` marks pending migrations as applied without executing SQL (for DBs that were initialized by docker init scripts)

## Node / NPM (Vendor Sync)

This project uses Node only to sync browser assets (Web Awesome, Font Awesome kit icons, ZXing) into `public_html/assets/vendor/`.

Requirements:

- Node.js 24.x (latest)
- A Pro auth token for Web Awesome Pro + Font Awesome packages

Setup:

- Copy `.npmrc.example` to `.npmrc` and replace `your-token-here` with your tokens

Install/update JS deps (runs vendor sync via postinstall):

- `npm install`

Manual re-sync:

- `npm run sync:vendor`

## Seed Admin (Dev)

Create an admin user:

- `docker exec -i simple-event-checkout-app bash -lc 'ADMIN_USERNAME=admin ADMIN_EMAIL=admin@example.com ADMIN_PASSWORD=ChangeMe123! php public_html/scripts/seed-admin.php'`

## Local (Non-Docker) Setup

This project is developed with Docker. If you run it on bare metal:

1. Create the database and user.
2. Apply migrations with `public_html/scripts/migrate.php` (requires PHP + composer deps installed).
3. Configure `public_html/config/local.php` for DB + SMTP + Square.
4. Serve `public_html/` with Apache (recommended, uses `public_html/.htaccess`).

## Runtime Requirements

- PHP 8.3+
- Extensions:
  - `pdo` + `pdo_mysql`
  - `openssl`
- MySQL 8+
- Apache with `mod_rewrite`

## Note

No automated test suite is included.

## License

See `LICENSE.md`.
