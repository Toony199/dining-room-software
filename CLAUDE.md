# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this project is

**Comedor** — a system to digitize the planning, request, physical payment, and consumption validation of a company cafeteria service. The full functional specification lives in [software-comedor.md](docs/software-comedor.md) (Spanish, ~1900 lines). **Read the relevant section of that spec before implementing any domain feature** — the business rules there are the source of truth and are not yet reflected in code.

Current state: the repository is a **Laravel 13 + Vue 3 scaffold**. None of the domain (personas, periodos, fichas, gafetes, consumo) is implemented yet — `app/` contains only the default `User` model and base `Controller`, `routes/api.php` is empty, and `database/migrations/` holds only the framework's users/cache/jobs tables.

## Commands

```bash
composer run dev
```
Runs `php artisan serve`, `queue:listen`, `pail` (log viewer), and `npm run dev` concurrently via `npx concurrently`. This is the normal way to develop.

```bash
composer run test
```
Clears config, then `php artisan test`. Tests run against an **in-memory SQLite** DB (`phpunit.xml`), not the MySQL dev DB.

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

## Environment

- Served from XAMPP at `C:\xampp\htdocs\comedor`; PHP 8.3+.
- **The dev database is MySQL** (`DB_CONNECTION=mysql`, database `comedor` on 127.0.0.1:3306). `config/database.php` still carries Laravel's stock `sqlite` default and `database/database.sqlite` is an empty leftover — `.env` is what governs. Start MySQL in XAMPP before running migrations.
- `cache`, `session`, and `queue` all use the **database** driver, so their tables must exist before the app boots correctly.

## Architecture

Laravel serves as an API + SPA shell; all UI is client-rendered by Vue.

- **Catch-all web route** — `routes/web.php` maps `/{any?}` (regex `.*`) to the `app` Blade view. Every non-API URL returns the same shell, so client-side routes need no server counterpart. Any real backend endpoint must be registered in `routes/api.php` (prefixed `/api`) or it will be swallowed by the catch-all.
- **Shell view** — `resources/views/app.blade.php` is minimal: `@vite(['resources/css/app.css', 'resources/js/app.js'])` plus `<div id="app">`.
- **Vue entry** — `resources/js/app.js` → Pinia + router → `App.vue` (only a `<router-view />`). `resources/js/router.js` uses `createWebHistory` and maps `/` → `pages/Home.vue`, `/usuarios` → `pages/Usuarios.vue`.
- **Vite** — `vite.config.js` wires `laravel-vite-plugin` (with the Bunny "Instrument Sans" font helper), `@vitejs/plugin-vue`, and `@tailwindcss/vite`. Tailwind v4 has no config file; theme customization goes in `resources/css/app.css` using v4 CSS syntax.

### File layout

Pages live in `resources/js/pages/`, reusable components in `resources/js/components/`, Pinia stores in `resources/js/stores/`. **`resources/views/` is Blade-only** — do not put `.vue` files there.

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

## Note on GEMINI.md

[GEMINI.md](GEMINI.md) is the Spanish-language equivalent of this file for Gemini CLI and describes the same reality. If you change one, change the other.
