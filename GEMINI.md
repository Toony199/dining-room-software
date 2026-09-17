# Guía del Proyecto: Comedor

Este archivo sirve como contexto de instrucción y referencia rápida para el desarrollo del proyecto **Comedor**, diseñado tanto para desarrolladores como para agentes de IA que colaboren en el proyecto.

> Este documento y `CLAUDE.md` describen la misma realidad del repositorio. Si corriges uno, corrige el otro.

---

## 📋 Resumen del Proyecto

**Comedor** es una aplicación web construida como una **Single Page Application (SPA)**. Utiliza **Laravel** en el backend como API y despachador del frontend, y **Vue.js** en el frontend, estilizado con **TailwindCSS**.

El objetivo del sistema es digitalizar la planeación, solicitud, pago físico y validación del derecho de consumo del servicio de comedor de una empresa. **La especificación funcional completa está en [`software-comedor.md`](docs/software-comedor.md)** y es la fuente de verdad del negocio: consúltala antes de implementar cualquier funcionalidad de dominio.

**Estado actual:** **implementado** — personas (§3) con su fotografía (§3.1), departamentos (§3.4), roles con permisos granulares (§5), cuentas de sistema opcionales (§3.1), inicio de sesión, validación de permisos en backend en cada ruta de dominio (§5.6) y gafetes (§4): emisión, reposición, historial e impresión de uno a la vez. **Pendiente** — periodos, fichas, pagos, derechos de consumo, consumo, reportes, auditoría, el flujo de kiosco y la impresión de varios gafetes en una hoja. Las tablas de casi todo lo pendiente ya existen en `database/migrations/` (se crearon por adelantado); los modelos y endpoints no.

### Tecnologías Principales
- **Backend:** PHP 8.3+ / Laravel 13.x
- **Frontend:** Vue.js 3.x
- **Enrutamiento Frontend:** Vue Router 5.x
- **Gestión de Estado:** Pinia 4.x (registrado en `resources/js/app.js`)
- **Estilos:** TailwindCSS v4.x (integrado mediante `@tailwindcss/vite`)
- **Compilación de Activos:** Vite 8.x con `laravel-vite-plugin`
- **Base de Datos:** MySQL (XAMPP) — base `comedor` en `127.0.0.1:3306`, configurada en `.env`
- **Pruebas:** PHPUnit 12.x (corren sobre una base MySQL aparte, `comedor_testing`, ver `phpunit.xml`; no es SQLite)
- **Autenticación:** Laravel Sanctum en modo SPA (sesión en cookie httpOnly + CSRF)
- **Formateador de Código (Linter):** Laravel Pint 1.x

---

## 🏗️ Arquitectura y Estructura

El proyecto sigue una estructura de SPA integrada dentro del ecosistema de Laravel:

### Backend (Laravel)
- **Punto de Entrada Web:** `routes/web.php` redirige todas las peticiones no-API hacia la vista unificada de Blade con:
  ```php
  Route::view('/{any?}', 'app')->where('any', '.*');
  ```
  Por este comodín, **todo endpoint real debe registrarse en `routes/api.php`** (prefijo `/api`); de lo contrario la ruta será absorbida por la SPA.
- **Vista Principal:** `resources/views/app.blade.php` monta el div contenedor `<div id="app"></div>` e inyecta los activos de CSS y JS utilizando la directiva `@vite`.
- **API:** `routes/api.php` agrupa todos los endpoints de dominio bajo `auth:sanctum`, cada uno con su permiso `can:<clave>` (ver *Autenticación y permisos*). Los controladores responden con API Resources en JSON.
- **Mapeo de Clases:** Sigue el estándar PSR-4 bajo el namespace `App\` (`app/`).

### Frontend (Vue.js)
- **Punto de Entrada:** `resources/js/app.js` carga `bootstrap.js` (axios configurado para Sanctum), registra Pinia y el enrutador, instala `interceptores.js` (401 → vuelve al login, 403 → aviso) y monta la aplicación en `#app`.
- **Layout General:** `resources/js/App.vue` envuelve `<router-view />` en el layout con sidebar de shadcn, salvo en rutas con `meta.layout: 'blank'` (el login).
- **Enrutador:** `resources/js/router.js` asocia cada módulo a `views/<modulo>/<Modulo>Index.vue` (`/login`, `/`, `/personas`, `/departamentos`, `/roles`, `/usuarios`). Su guard `beforeEach` manda al login a quien no tiene sesión; es comodidad, la protección real está en el backend.
- **Ubicación de archivos:** vistas en `resources/js/views/<modulo>/`, un composable de API por módulo en `resources/js/composables/`, componentes reutilizables en `resources/js/components/` (los de shadcn-vue en `components/ui/`) y stores de Pinia en `resources/js/stores/` (`auth.js` guarda la sesión). La lista de módulos que comparten el menú lateral y la página de inicio está en `resources/js/modulos.js`. `resources/js/pages/` es un resto vacío. `resources/views/` queda reservado exclusivamente para plantillas Blade.
- **Estilos:** `@tailwindcss/vite` compila los estilos de Tailwind v4 declarados en `resources/css/app.css` (sin archivo `tailwind.config.js`; el tema se declara con `@theme` en ese CSS).

---

## 🚀 Comandos de Construcción y Ejecución

### 1. Configuración Inicial (Setup)
```bash
composer run setup
```
*Este comando realiza internamente:*
- `composer install`
- Copia `.env.example` a `.env` (si no existe)
- `php artisan key:generate`
- `php artisan migrate --force`
- `npm install --ignore-scripts`
- `npm run build`

> Requiere que MySQL esté levantado en XAMPP antes de migrar. Los drivers de `cache`, `session` y `queue` son `database`, así que sus tablas deben existir para que la aplicación arranque correctamente.

`setup` migra pero no siembra. Después, siembra el catálogo de permisos, los roles iniciales y la cuenta administradora de arranque:
```bash
php artisan db:seed
```

Variables de `.env` que hay que revisar:
- `SANCTUM_STATEFUL_DOMAINS` debe incluir el `host:puerto` exacto desde donde se sirve la SPA (`localhost:8000` con `php artisan serve`). La lista por defecto de Sanctum no lo trae; sin coincidencia, `/api/*` queda sin sesión y el login no persiste.
- `ADMIN_EMAIL`, `ADMIN_PASSWORD` y `ADMIN_NUMERO_EMPLEADO` (vía `config/comedor.php`) definen la cuenta administradora que crea el seeder.

### 2. Entorno de Desarrollo Local
```bash
composer run dev
```
*Utiliza `npx concurrently` para correr en paralelo:*
- Servidor Laravel (`php artisan serve`)
- Escucha de Colas (`php artisan queue:listen --tries=1 --timeout=0`)
- Logs interactivos (`php artisan pail --timeout=0`)
- Servidor Vite (`npm run dev`)

### 3. Ejecución de Pruebas
```bash
composer run test
```

Una sola prueba:
```bash
php artisan test --filter=NombreDelTest
```

Una sola suite (`Unit` o `Feature`):
```bash
php artisan test --testsuite=Feature
```

### 4. Formatear y Corregir Estilo de Código
```bash
vendor/bin/pint
```

---

## 🔐 Autenticación y permisos

- **Sesión:** Sanctum en modo SPA (`$middleware->statefulApi()` en `bootstrap/app.php`). La sesión viaja en una cookie httpOnly con protección CSRF, nunca en un token guardado por el navegador. `POST /api/login`, `GET /api/me` y `POST /api/logout` viven en `AuthController`.
- **Permisos:** cada ruta de dominio en `routes/api.php` está dentro de `auth:sanctum` y lleva `can:<clave>`. Un `Gate::before` en `AppServiceProvider` resuelve cualquier clave contra el rol de la cuenta con `User::tienePermiso()`, que además niega si la persona está INACTIVA, la cuenta suspendida o el rol desactivado (`User::motivoBloqueo()`). Los permisos se leen del rol en cada petición; nunca se copian a la cuenta. Las claves las siembra `PermisoSeeder`; `colaboradores.*` es el nombre que usa la spec para el módulo de personas.
- **Seeders:** `php artisan db:seed` corre `PermisoSeeder`, `RolSeeder` y `AdministradorSeeder`, los tres idempotentes. Con `ADMIN_PASSWORD` vacía, la contraseña del administrador se genera y se imprime una sola vez. El rol *Administrador* y la cuenta de arranque están `protegido`s: la aplicación no deja editar ni desactivar ese rol, ni dar de baja a la persona, suspender la cuenta o cambiarle el rol. Volver a correr `AdministradorSeeder` es la vía de recuperación: reactiva esa cuenta sin tocar su correo ni su contraseña.
- **Pruebas:** `TestCase::actuandoComo(...$permisos)` autentica una cuenta con exactamente esos permisos. Su rol, persona y departamento agregan una fila a cada tabla (`REGISTROS_DE_SESION`), y sus nombres ordenan al final.

---

## 📖 Vocabulario del dominio

La especificación está en español; conserva los nombres de dominio, estados y textos de interfaz en español:

- **Persona** — toda persona registrada; puede tener opcionalmente una *cuenta de sistema* con exactamente un *rol* y *permisos* granulares. Las bajas son lógicas, nunca borrado físico. El *número de empleado* es único y jamás se reasigna.
- **Gafete** — credencial con QR; emitir uno nuevo desactiva el anterior, que se conserva históricamente.
- **Periodo** — periodo de servicio con precio por día (*precio aplicado* congelado históricamente) y ventana de pago. Estados: `BORRADOR → ABIERTO → PAGO CERRADO → CONSOLIDADO`.
- **Ficha** — solicitud de días generada en el kiosco. Estados: `PENDIENTE → PAGADA` o `→ VENCIDA`. Una ficha por sí sola no otorga derecho a comer.
- **Derecho de consumo** — un derecho independiente por cada día pagado, creado sólo al confirmar el pago físico. Estados por día `UTILIZADO` / `VENCIDO`; nunca transferible, ni reprogramable, ni reembolsable.

Dos reglas que condicionan casi toda la lógica: **el pago es físico y lo confirma un cobrador — el sistema nunca procesa pagos digitales**, y **la proyección de porciones sólo cuenta derechos con pago confirmado**. Los permisos se validan en backend (§5.6) y el flujo de kiosco debe permanecer aislado de las sesiones administrativas (§10.2).

---

## 💅 Convenciones de Desarrollo

### Backend (PHP/Laravel)
1. **Controladores y Modelos:** Crea nuevos modelos bajo `app/Models/` y controladores bajo `app/Http/Controllers/`. Usa la inyección de dependencias y mantén los controladores delgados.
2. **Migraciones:** Siempre escribe migraciones para modificar la base de datos. Asegúrate de que sean reversibles (`down()`).
3. **Formateo:** Corre `vendor/bin/pint` antes de subir cambios.
4. **Contrato de la API:** Los controladores responden con API Resources (el Resource, no la migración, es el contrato público). Los listados paginan con `per_page` acotado a 100. Las bajas son lógicas: `PATCH …/desactivar` y `…/activar`, nunca una ruta `DELETE`.
5. **Idioma:** Textos de interfaz, mensajes de validación y mensajes de error de la API en español.

### Frontend (Vue/JS/Tailwind)
1. **Vistas y Componentes:** Las vistas de cada ruta van en `resources/js/views/<modulo>/` y la lógica de API del módulo en un composable de `resources/js/composables/`; los componentes reutilizables en `resources/js/components/`.
2. **Estado:** Pinia ya está registrado; declara los stores en `resources/js/stores/`.
3. **Estilos:** Aprovecha el motor de TailwindCSS v4. Declara tema o utilidades personalizadas en `resources/css/app.css` con la sintaxis moderna de Tailwind v4.
4. **Peticiones HTTP:** Usa `axios` (ya instalado) para comunicarte con los endpoints de la API.

### Pruebas (Testing)
1. **Cobertura:** Cada nueva ruta, controlador o lógica de negocio crítica debe venir acompañada de su prueba en `tests/Feature/` o `tests/Unit/`.
2. **Verificación:** Ejecuta siempre `composer run test` antes de dar una tarea por terminada.
3. **Sesión en pruebas:** Las rutas exigen sesión y permiso; usa `$this->actuandoComo('clave.permiso', ...)` para autenticar una cuenta con exactamente los permisos que la prueba necesita.
