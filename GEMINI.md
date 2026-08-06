# Guía del Proyecto: Comedor

Este archivo sirve como contexto de instrucción y referencia rápida para el desarrollo del proyecto **Comedor**, diseñado tanto para desarrolladores como para agentes de IA que colaboren en el proyecto.

> Este documento y `CLAUDE.md` describen la misma realidad del repositorio. Si corriges uno, corrige el otro.

---

## 📋 Resumen del Proyecto

**Comedor** es una aplicación web construida como una **Single Page Application (SPA)**. Utiliza **Laravel** en el backend como API y despachador del frontend, y **Vue.js** en el frontend, estilizado con **TailwindCSS**.

El objetivo del sistema es digitalizar la planeación, solicitud, pago físico y validación del derecho de consumo del servicio de comedor de una empresa. **La especificación funcional completa está en [`software-comedor.md`](docs/software-comedor.md)** y es la fuente de verdad del negocio: consúltala antes de implementar cualquier funcionalidad de dominio.

**Estado actual:** el repositorio es todavía un andamiaje (scaffold) de Laravel + Vue. El dominio (personas, periodos, fichas, gafetes, consumo) **aún no está implementado**: `app/` sólo contiene el modelo `User` y el `Controller` base, `routes/api.php` está vacío y `database/migrations/` sólo tiene las tablas de framework (users, cache, jobs).

### Tecnologías Principales
- **Backend:** PHP 8.3+ / Laravel 13.x
- **Frontend:** Vue.js 3.x
- **Enrutamiento Frontend:** Vue Router 5.x
- **Gestión de Estado:** Pinia 4.x (registrado en `resources/js/app.js`)
- **Estilos:** TailwindCSS v4.x (integrado mediante `@tailwindcss/vite`)
- **Compilación de Activos:** Vite 8.x con `laravel-vite-plugin`
- **Base de Datos:** MySQL (XAMPP) — base `comedor` en `127.0.0.1:3306`, configurada en `.env`
- **Pruebas:** PHPUnit 12.x (corren sobre SQLite en memoria, ver `phpunit.xml`)
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
- **API:** `routes/api.php` está actualmente vacío; las respuestas deben ser JSON.
- **Mapeo de Clases:** Sigue el estándar PSR-4 bajo el namespace `App\` (`app/`).

### Frontend (Vue.js)
- **Punto de Entrada:** `resources/js/app.js` inicializa la aplicación Vue, registra Pinia y el enrutador, y la monta en `#app`.
- **Layout General:** `resources/js/App.vue` actúa como componente raíz que renderiza `<router-view />`.
- **Enrutador:** `resources/js/router.js` maneja la navegación del lado del cliente. Rutas declaradas:
  - `/` -> `resources/js/pages/Home.vue`
  - `/usuarios` -> `resources/js/pages/Usuarios.vue`
- **Ubicación de páginas:** todas las vistas de página viven en `resources/js/pages/`. `resources/views/` queda reservado exclusivamente para plantillas Blade.
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

### Frontend (Vue/JS/Tailwind)
1. **Componentes y Páginas:** Las páginas van en `resources/js/pages/`; los componentes reutilizables en `resources/js/components/`.
2. **Estado:** Pinia ya está registrado; declara los stores en `resources/js/stores/`.
3. **Estilos:** Aprovecha el motor de TailwindCSS v4. Declara tema o utilidades personalizadas en `resources/css/app.css` con la sintaxis moderna de Tailwind v4.
4. **Peticiones HTTP:** Usa `axios` (ya instalado) para comunicarte con los endpoints de la API.

### Pruebas (Testing)
1. **Cobertura:** Cada nueva ruta, controlador o lógica de negocio crítica debe venir acompañada de su prueba en `tests/Feature/` o `tests/Unit/`.
2. **Verificación:** Ejecuta siempre `composer run test` antes de dar una tarea por terminada.
