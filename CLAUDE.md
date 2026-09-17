# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this project is

**Comedor** — a system to digitize the planning, request, physical payment, and consumption validation of a company cafeteria service. The full functional specification lives in [software-comedor.md](docs/software-comedor.md) (Spanish, ~1900 lines). **Read the relevant section of that spec before implementing any domain feature** — the business rules there are the source of truth, and only part of them is implemented so far.

Current state: **implemented** — personas (§3) with their photo (§3.1), departamentos (§3.4), roles with granular permisos (§5), optional cuentas de sistema (§3.1), login, a backend permission check on every domain route (§5.6), and gafetes (§4): issuing, reposición, historial and printing one badge at a time. **Not implemented yet** — periodos, fichas, pagos, derechos de consumo, consumo, reportes, auditoría, the kiosk flow, and printing several gafetes on one sheet. The tables for most of these already exist in `database/migrations/` (created ahead of the code); the models and endpoints do not.

## Commands

```bash
composer run dev
```
Runs `php artisan serve`, `queue:listen`, `pail` (log viewer), and `npm run dev` concurrently via `npx concurrently`. This is the normal way to develop.

```bash
composer run test
```
Clears config, then `php artisan test`. Tests run against a separate **MySQL** database, `comedor_testing` (`phpunit.xml`), wiped by `RefreshDatabase` — create it in XAMPP before the first run. It is not SQLite and not the `comedor` dev DB.

Run a single test:
```bash
php artisan test --filter=NombreDelTest
```

Run one suite (`Unit` or `Feature`):
```bash
php artisan test --testsuite=Feature
```

Format PHP (run before finishing a change):
```bash
vendor/bin/pint
```

Initial setup on a fresh clone (installs deps, creates `.env`, keygen, migrate, npm build):
```bash
composer run setup
```

`setup` migrates but does not seed. Seed the permission catalog, the initial roles and the bootstrap admin account afterwards (see *Authentication and permissions*):
```bash
php artisan db:seed
```

## Environment

- Served from XAMPP at `C:\xampp\htdocs\comedor`; PHP 8.3+.
- **The dev database is MySQL** (`DB_CONNECTION=mysql`, database `comedor` on 127.0.0.1:3306). `config/database.php` still carries Laravel's stock `sqlite` default and `database/database.sqlite` is an empty leftover — `.env` is what governs. Start MySQL in XAMPP before running migrations.
- `cache`, `session`, and `queue` all use the **database** driver, so their tables must exist before the app boots correctly.
- `SANCTUM_STATEFUL_DOMAINS` must list the exact `host:port` the SPA is served from (`localhost:8000` for `php artisan serve`). Sanctum's default list does not include it; without a match every `/api/*` request is stateless and login does not persist.
- `ADMIN_EMAIL`, `ADMIN_PASSWORD`, `ADMIN_NUMERO_EMPLEADO` (read through `config/comedor.php`) define the bootstrap admin account created by the seeder.

## Architecture

Laravel serves as an API + SPA shell; all UI is client-rendered by Vue.

- **Catch-all web route** — `routes/web.php` maps `/{any?}` (regex `.*`) to the `app` Blade view. Every non-API URL returns the same shell, so client-side routes need no server counterpart. Any real backend endpoint must be registered in `routes/api.php` (prefixed `/api`) or it will be swallowed by the catch-all.
- **Shell view** — `resources/views/app.blade.php` is minimal: `@vite(['resources/css/app.css', 'resources/js/app.js'])` plus `<div id="app">`.
- **Vue entry** — `resources/js/app.js` → `bootstrap.js` (axios defaults for Sanctum: `withCredentials`, `withXSRFToken`) → Pinia + router → `interceptores.js` (global 401 → back to login, 403 → toast) → `App.vue`. `App.vue` wraps `<router-view />` in the shadcn sidebar layout, except for routes with `meta.layout: 'blank'` (the login). `resources/js/router.js` maps each module to `views/<modulo>/<Modulo>Index.vue`, and its `beforeEach` guard sends visitors without a session to `/login` (convenience only; the backend is what enforces access).
- **Vite** — `vite.config.js` wires `laravel-vite-plugin` (with the Bunny "Instrument Sans" font helper), `@vitejs/plugin-vue`, and `@tailwindcss/vite`. Tailwind v4 has no config file; theme customization goes in `resources/css/app.css` using v4 CSS syntax.

### File layout

Route views live in `resources/js/views/<modulo>/`, one API composable per module in `resources/js/composables/`, reusable components in `resources/js/components/` (shadcn-vue ones under `components/ui/`), Pinia stores in `resources/js/stores/` (`auth.js` holds the session). The module list shared by the sidebar and the home page is `resources/js/modulos.js`. `resources/js/pages/` is an empty leftover. **`resources/views/` is Blade-only** — do not put `.vue` files there.

## Authentication and permissions

- **Session** — Sanctum SPA mode (`$middleware->statefulApi()` in `bootstrap/app.php`): the session travels in an httpOnly cookie with CSRF protection, never in a token stored by the browser. `POST /api/login`, `GET /api/me`, `POST /api/logout` live in `AuthController`.
- **Permissions** — every domain route in `routes/api.php` is inside `auth:sanctum` and carries `can:<clave>`. A `Gate::before` in `AppServiceProvider` resolves any clave against the account's rol through `User::tienePermiso()`, which also denies when the persona is INACTIVO, the account is suspended, or the rol is inactive (`User::motivoBloqueo()`). Permissions are read from the rol on each request, never copied to the account. Claves are seeded by `PermisoSeeder`; `colaboradores.*` is the spec's name for the personas module.
- **Seeders** — `php artisan db:seed` runs `PermisoSeeder`, `RolSeeder`, `AdministradorSeeder`, all idempotent. With `ADMIN_PASSWORD` blank the admin password is generated and printed once. The *Administrador* rol and the bootstrap account are `protegido`: the app refuses to edit or deactivate that rol, and to deactivate the persona, suspend the account or change its rol. Re-running `AdministradorSeeder` is the recovery path: it reactivates that account but never touches its email or password.
- **Tests** — `TestCase::actuandoComo(...$permisos)` authenticates an account holding exactly those permissions. Its rol, persona and departamento add one row to each table (`REGISTROS_DE_SESION`), and their names sort last.

## Domain vocabulary

The spec and the intended data model are in Spanish; keep domain names, enum values, and UI copy in Spanish for consistency with the spec:

- **Persona** — anyone registered; may optionally have a system account (*cuenta de sistema*) with exactly one *rol* and granular *permisos*. Deactivation is logical, never physical deletion. *Número de empleado* is unique and never reassigned.
- **Gafete** — QR badge; issuing a new one deactivates the previous, which is retained for history.
- **Periodo** — service period with per-day pricing (*precio aplicado* frozen historically) and a payment window. States: `BORRADOR → ABIERTO → PAGO CERRADO → CONSOLIDADO`.
- **Ficha** — the day-selection request generated at a kiosk. States: `PENDIENTE → PAGADA` or `→ VENCIDA`. A ficha alone grants nothing.
- **Derecho de consumo** — one independent right per paid day, created only when physical payment is confirmed. Per-day states `UTILIZADO` / `VENCIDO`; never transferable, reschedulable, or refundable.

Two rules that shape most logic: **payment is physical and confirmed by a cashier — the system never processes digital payments**, and **portion projections count only confirmed-paid rights**. Permissions must be validated on the backend (§5.6), and the kiosk flow must stay isolated from administrative sessions (§10.2).

## Conventions

- PHP formatting is Laravel Pint defaults; 4-space indent, LF endings, final newline (`.editorconfig`).
- Migrations must be reversible (`down()`), and every new route/controller/business rule should come with a test in `tests/Feature/` or `tests/Unit/`.
- Use `axios` (already a dependency) for frontend → API calls.
- Controllers return API Resources (the Resource, not the migration, is the public contract). List endpoints paginate with `per_page` capped at 100. Bajas are logical: `PATCH …/desactivar` and `…/activar`, never a `DELETE` route.
- UI copy, validation messages and API error messages are in Spanish.

## Note on GEMINI.md

[GEMINI.md](GEMINI.md) is the Spanish-language equivalent of this file for Gemini CLI and describes the same reality. If you change one, change the other.
