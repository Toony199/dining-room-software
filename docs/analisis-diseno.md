# Análisis y diseño técnico — Sistema de comedor

> Documento derivado de [software-comedor.md](software-comedor.md) (spec funcional, fuente de
> verdad de las **reglas de negocio**). Este documento traduce esa spec a los artefactos de
> ingeniería que exige su §26: modelo de dominio, modelo de datos, catálogo de estados y
> transiciones, catálogo de permisos, reglas de negocio detalladas, casos de uso, diseño de API,
> procesos programados y requerimientos no funcionales.
>
> Estado: **borrador de diseño para revisión**. Las secciones marcadas con 🟡 contienen decisiones
> pendientes de confirmar. Cuando la spec y este documento difieran, gana la spec; corrige aquí.

---

## 0. Decisiones cerradas

Confirmadas con el negocio antes de modelar:

| # | Tema | Decisión |
|---|------|----------|
| D1 | **Ventana de generación vs. pago** | **Ventana única**: un solo rango de fechas por periodo controla generación de fichas *y* confirmación de pago. Al pasar la `fecha_fin` de la ventana: no se generan nuevas fichas y las `PENDIENTE` vencen. |
| D2 | **Autenticación de cuentas de sistema** | **Email + contraseña** (estándar Laravel). Toda *cuenta de sistema* requiere email único. Las personas sin cuenta no necesitan email. |
| D3 | **Generación automática de periodos** | Un job programado crea en `BORRADOR` el periodo de la **próxima semana (Lun–Vie)**. El gestor ajusta festivos/precio y lo abre manualmente. |
| D4 | **Una ficha por persona/periodo** | La operación es **definitiva** tras confirmar el pago. No se genera una segunda ficha en el mismo periodo. Agregar/quitar días solo es posible **antes** de confirmar el pago (con el cobrador, §13). |

Supuestos por defecto (🟡 confirmar, ver §10):

- **Contenido del QR**: token opaco aleatorio (UUID/ULID) por gafete, **no** el número de empleado.
- **Dinero**: `DECIMAL(8,2)` en pesos. Sin manejo de centavos fraccionarios más allá de 2 decimales.
- **Zona horaria**: `America/Mexico_City`. Toda regla de "fecha actual" usa la fecha local del servidor.
- **Idioma**: dominio, enums y copy de UI en **español** (consistente con la spec y CLAUDE.md).

---

## 1. Modelo de dominio

### 1.1. Entidades y responsabilidades

| Entidad | Responsabilidad | Notas clave de la spec |
|---------|-----------------|------------------------|
| **Departamento** | Catálogo de áreas. | Desactivación lógica; no se borra si tiene historial (§3.4). |
| **Persona** | Toda persona registrada; potencial consumidor del comedor. | Nº de empleado único e irreasignable; baja lógica (§3.2–3.3). |
| **CuentaSistema** (`users`) | Acceso administrativo **opcional** de una persona. | 1 persona → 0..1 cuenta; 1 cuenta → 1 rol (§3.1, §5.1). |
| **Rol** | Agrupa permisos. Dinámico, no hardcodeado (§5.2). | No desactivable con usuarios asignados sin reasignar (§5.5). |
| **Permiso** | Acción concreta protegida en backend (§5.3, §5.6). | Catálogo ampliable. |
| **Gafete** | Credencial QR de una persona. | Nuevo gafete desactiva el anterior; QR único; historial (§4.3). |
| **Tarifa** | Precio por día vigente (general para todos, §6.4, §18). | Historial de precios por vigencia. |
| **Periodo** | Semana de servicio + ventana única + estado. | `BORRADOR→ABIERTO→PAGO_CERRADO→CONSOLIDADO` (§8). |
| **DiaPeriodo** | Día individual del periodo con sus propiedades. | Disponibilidad, festivo, `precio_aplicado` congelado (§6.2, §6.5). |
| **Ficha** | Solicitud de días generada en kiosco. | `PENDIENTE→PAGADA`/`VENCIDA`; una válida por persona+periodo (§11, §17.1). |
| **FichaDia** | Día seleccionado dentro de una ficha (pivote). | Antes del pago es "selección"; al pagar respalda un derecho. |
| **Pago** | Confirmación del cobro físico de una ficha. | Monto recibido, cambio, cobrador, fecha (§12, §14). |
| **DerechoConsumo** | Derecho independiente por día pagado. | `UTILIZADO`/`VENCIDO`; intransferible (§2.3, §15). |
| **Consumo** | Registro del uso de un derecho en el comedor. | Marca el derecho como `UTILIZADO` (§16). |
| **ReportePorciones** | Consolidado de porciones por periodo. | Solo pagos confirmados; acción explícita; quién lo generó (§20). |
| **Auditoria** | Bitácora de acciones relevantes. | Usuario, acción, fecha/hora, registro afectado (§19). |

### 1.2. Relaciones (texto)

```
Departamento 1───N Persona
Persona      1───0..1 CuentaSistema (users) ──N:1── Rol ──M:N── Permiso
Persona      1───N Gafete            (0..1 activo)
Persona      1───N Ficha             (0..1 "válida" por Periodo)
Tarifa       1───N DiaPeriodo        (precio_aplicado se copia, no se referencia vivo)

Periodo      1───N DiaPeriodo
Periodo      1───N Ficha
Ficha        1───N FichaDia   ──N:1── DiaPeriodo
Ficha        1───0..1 Pago     ──N:1── CuentaSistema (cobrador)
Ficha        1───N DerechoConsumo ──N:1── DiaPeriodo
DerechoConsumo 1───0..1 Consumo

Auditoria    N───1 CuentaSistema (autor, nullable para procesos del sistema)
```

Regla de aislamiento importante: **`DerechoConsumo` se crea solo al confirmar el pago** (§2.2), a
partir de las `FichaDia` vigentes en ese momento. Las `FichaDia` describen la *selección*; los
`DerechoConsumo` son la *verdad pagada* e inmutable.

---

## 2. Diseño de base de datos

Convenciones: MySQL (dev), migraciones reversibles (CLAUDE.md). Claves `id` BIGINT autoincrement
salvo indicación. `timestamps` (`created_at`,`updated_at`) en todas. Baja lógica con
`activo`/`estado` según la entidad, **nunca** `softDeletes` que oculte historial de auditoría
(preferimos banderas explícitas para poder listar inactivos).

### 2.1. `departamentos`
| Columna | Tipo | Reglas |
|--------|------|-------|
| id | BIGINT PK | |
| nombre | VARCHAR(120) | único entre activos 🟡 (o único global) |
| activo | BOOLEAN | default `true`; desactivación lógica |

### 2.2. `personas`
| Columna | Tipo | Reglas |
|--------|------|-------|
| id | BIGINT PK | |
| numero_empleado | VARCHAR(30) | **UNIQUE**, inmutable, nunca reasignado (§3.2) |
| nombre | VARCHAR(150) | |
| departamento_id | BIGINT FK→departamentos | `RESTRICT` on delete (no borrado físico) |
| foto_path | VARCHAR(255) NULL | fotografía opcional |
| estado | ENUM('ACTIVO','INACTIVO') | default `ACTIVO`; baja lógica (§3.3) |

- Índices: `unique(numero_empleado)`, `index(departamento_id)`, `index(estado)`.
- **No** hay FK física de borrado en cascada hacia personas.

### 2.3. `users` (cuenta de sistema)
Reutiliza la tabla `users` de Laravel, extendida. 1:1 opcional con persona.
| Columna | Tipo | Reglas |
|--------|------|-------|
| id | BIGINT PK | |
| persona_id | BIGINT FK→personas | **UNIQUE** (1 persona → máx 1 cuenta) |
| name | VARCHAR | nombre visible (puede espejar persona.nombre) |
| email | VARCHAR UNIQUE | credencial de acceso (D2) |
| password | VARCHAR | hash |
| rol_id | BIGINT FK→roles | **exactamente uno** (§5.1); `RESTRICT` |
| activo | BOOLEAN | cuenta habilitada; se apaga si la persona se inactiva |
| remember_token, timestamps | | estándar |

> Nota: la persona puede quedar `INACTIVA` y su cuenta debe dejar de poder iniciar sesión
> (§3.3). Se valida en login: `persona.estado = ACTIVO AND users.activo = true`.

### 2.4. `roles`
| Columna | Tipo | Reglas |
|--------|------|-------|
| id | BIGINT PK | |
| nombre | VARCHAR(80) UNIQUE | |
| descripcion | VARCHAR(255) NULL | |
| activo | BOOLEAN | no desactivable con usuarios asignados (§5.5) |

### 2.5. `permisos`
| Columna | Tipo | Reglas |
|--------|------|-------|
| id | BIGINT PK | |
| clave | VARCHAR(80) UNIQUE | p.ej. `pagos.confirmar` (§5.3) |
| descripcion | VARCHAR(255) NULL | |
| modulo | VARCHAR(40) | agrupador para UI |

### 2.6. `permiso_rol` (pivote)
`rol_id` FK, `permiso_id` FK, `unique(rol_id,permiso_id)`. Cambios en el pivote afectan a todos los
usuarios del rol de inmediato (§5.4) — se evalúa por consulta, sin cachear permanentemente.

### 2.7. `gafetes`
| Columna | Tipo | Reglas |
|--------|------|-------|
| id | BIGINT PK | |
| persona_id | BIGINT FK→personas | |
| qr_token | CHAR(26/36) UNIQUE | token opaco (ULID/UUID), único por emisión (§4.3) |
| estado | ENUM('ACTIVO','INACTIVO') | solo **uno** ACTIVO por persona |
| emitido_en | TIMESTAMP | |

- Constraint lógico (aplicado en servicio + índice parcial 🟡): a lo sumo un `ACTIVO` por persona.
  En MySQL sin índices parciales, se garantiza en la transacción de reposición.
- Emitir nuevo gafete = transacción: `UPDATE ... SET estado=INACTIVO` al anterior + `INSERT` nuevo.

### 2.8. `tarifas`
| Columna | Tipo | Reglas |
|--------|------|-------|
| id | BIGINT PK | |
| precio | DECIMAL(8,2) | precio por día |
| vigente_desde | DATE | |
| vigente_hasta | DATE NULL | NULL = vigente actual |
| creado_por | BIGINT FK→users NULL | auditoría |

Resolución de precio para un día `f`: la tarifa cuyo rango `[vigente_desde, vigente_hasta]` contiene
`f`. Ese valor se **copia** a `dias_periodo.precio_aplicado` al generar/editar el periodo (§6.5).

### 2.9. `periodos`
| Columna | Tipo | Reglas |
|--------|------|-------|
| id | BIGINT PK | |
| fecha_inicio | DATE | lunes de la semana de servicio |
| fecha_fin | DATE | viernes (o último día) |
| ventana_inicio | DATE | inicio de generación **y** pago (D1) |
| ventana_fin | DATE | fin de generación **y** pago (D1) |
| estado | ENUM('BORRADOR','ABIERTO','PAGO_CERRADO','CONSOLIDADO') | §8 |
| generado_auto | BOOLEAN | trazabilidad del job |

- **Idempotencia** (§6.1): `unique(fecha_inicio, fecha_fin)` para que el job no duplique.
- Validaciones de ventana (§7.2): `ventana_inicio >= fecha_inicio`, `ventana_fin <= fecha_fin`,
  `ventana_inicio <= ventana_fin`. Se valida en `FormRequest` + a nivel de servicio.

### 2.10. `dias_periodo`
| Columna | Tipo | Reglas |
|--------|------|-------|
| id | BIGINT PK | |
| periodo_id | BIGINT FK→periodos | |
| fecha | DATE | `unique(periodo_id, fecha)` |
| dia_semana | TINYINT | 1=Lun … 7=Dom |
| disponible | BOOLEAN | festivo/excepción ⇒ `false` (§6.3) |
| es_festivo | BOOLEAN | |
| motivo_indisponibilidad | VARCHAR(150) NULL | |
| precio_aplicado | DECIMAL(8,2) | congelado del catálogo de tarifas (§6.5) |

### 2.11. `fichas`
| Columna | Tipo | Reglas |
|--------|------|-------|
| id | BIGINT PK | |
| folio | VARCHAR(20) UNIQUE | folio único legible (§11 paso 4) |
| persona_id | BIGINT FK→personas | |
| periodo_id | BIGINT FK→periodos | |
| estado | ENUM('PENDIENTE','PAGADA','VENCIDA') | §15 |
| precio_dia_snapshot | DECIMAL(8,2) | precio por día al momento (informativo; la verdad por día está en dias_periodo) |
| total | DECIMAL(8,2) | recomputado; = Σ precio_aplicado de días seleccionados |
| generada_en | TIMESTAMP | |

- **Unicidad de ficha válida (§17.1, D4)**: índice único parcial lógico
  `unique(persona_id, periodo_id) WHERE estado IN ('PENDIENTE','PAGADA')`. En MySQL 8 sin índices
  parciales se implementa con columna generada o validación transaccional 🟡 (ver §10). Una ficha
  `VENCIDA` no bloquea… pero como la ventana ya cerró, tampoco se puede crear otra.

### 2.12. `ficha_dias` (pivote ficha × día)
| Columna | Tipo | Reglas |
|--------|------|-------|
| id | BIGINT PK | |
| ficha_id | BIGINT FK→fichas | `unique(ficha_id, dia_periodo_id)` |
| dia_periodo_id | BIGINT FK→dias_periodo | debe pertenecer al mismo periodo y estar `disponible` |

Representa la **selección** (editable mientras la ficha esté `PENDIENTE`).

### 2.13. `pagos`
| Columna | Tipo | Reglas |
|--------|------|-------|
| id | BIGINT PK | |
| ficha_id | BIGINT FK→fichas | **UNIQUE** (1 pago por ficha) |
| total_cobrado | DECIMAL(8,2) | = total de la ficha al confirmar |
| monto_recibido | DECIMAL(8,2) | `>= total_cobrado` (§12.1) |
| cambio | DECIMAL(8,2) | `monto_recibido - total_cobrado` |
| cobrador_id | BIGINT FK→users | quién confirmó (§14) |
| confirmado_en | TIMESTAMP | |

### 2.14. `derechos_consumo`
| Columna | Tipo | Reglas |
|--------|------|-------|
| id | BIGINT PK | |
| persona_id | BIGINT FK→personas | denormalizado para validación rápida en comedor |
| periodo_id | BIGINT FK→periodos | |
| dia_periodo_id | BIGINT FK→dias_periodo | `unique(persona_id, dia_periodo_id)` |
| ficha_id | BIGINT FK→fichas | origen |
| estado | ENUM('VIGENTE','UTILIZADO','VENCIDO') | §15, §16 |
| precio_pagado | DECIMAL(8,2) | congelado |

> La spec usa "PAGADO → UTILIZADO/VENCIDO". Aquí `VIGENTE` = pagado y aún no consumido ni vencido
> (estado inicial al crear el derecho). Ver catálogo de estados §3.5.

### 2.15. `consumos`
| Columna | Tipo | Reglas |
|--------|------|-------|
| id | BIGINT PK | |
| derecho_consumo_id | BIGINT FK→derechos_consumo | **UNIQUE** (un consumo por derecho) |
| gafete_id | BIGINT FK→gafetes | gafete usado al validar |
| consumido_en | TIMESTAMP | |

### 2.16. `reportes_porciones`
| Columna | Tipo | Reglas |
|--------|------|-------|
| id | BIGINT PK | |
| periodo_id | BIGINT FK→periodos | `unique(periodo_id)` 🟡 (¿regenerable?) |
| generado_por | BIGINT FK→users | (§20) |
| generado_en | TIMESTAMP | |
| datos_json | JSON | snapshot: porciones por día, totales |

### 2.17. `auditorias`
| Columna | Tipo | Reglas |
|--------|------|-------|
| id | BIGINT PK | |
| user_id | BIGINT FK→users NULL | NULL = acción de proceso del sistema (jobs) |
| accion | VARCHAR(80) | p.ej. `periodo.abrir`, `pago.confirmar` |
| auditable_type / auditable_id | morph | registro afectado |
| datos | JSON NULL | contexto relevante (§19) |
| ip | VARCHAR(45) NULL | |
| created_at | TIMESTAMP | fecha/hora |

---

## 3. Catálogo de estados y transiciones

### 3.1. Persona `estado`
`ACTIVO ⇄ INACTIVO` (baja/reactivación lógica). `INACTIVO` bloquea login, gafete, fichas, derechos y
consumo (§3.3). Nunca hay borrado físico.

### 3.2. Gafete `estado`
`ACTIVO → INACTIVO` (irreversible por emisión). Reponer = nuevo gafete `ACTIVO` + anterior a
`INACTIVO`. Máx. 1 `ACTIVO` por persona.

### 3.3. Rol `activo`
`true → false` solo si no tiene usuarios asignados (§5.5): el sistema obliga a reasignar antes.

### 3.4. Periodo `estado` (máquina principal)

```
                (job semanal / creación)
                         │
                         ▼
                     BORRADOR ──── editar días, festivos, precios, ventana
                         │
             periodos.abrir │ (manual, valida ventana)
                         ▼
                      ABIERTO ──── kiosco genera fichas; cobrador confirma pagos
                         │
        ┌────────────────┼─────────────────┐
   (auto: hoy>ventana_fin)          periodos.cerrar (manual)
        └────────────────┼─────────────────┘
                         ▼
                   PAGO_CERRADO ──── fichas PENDIENTE→VENCIDA; se habilita reporte
                         │
         periodos.consolidar (GENERAR REPORTE, explícito)
                         ▼
                   CONSOLIDADO ──── inmutable; reporte asociado

periodos.reabrir: PAGO_CERRADO → ABIERTO  🟡 (ver §10: ¿revive fichas vencidas?)
```

Transiciones permitidas (todo lo demás ⇒ 409/422):

| Desde | Acción | Hacia | Permiso | Efectos |
|-------|--------|-------|---------|---------|
| BORRADOR | abrir | ABIERTO | `periodos.abrir` | Valida ventana. Congela `precio_aplicado` de días si no estaba. |
| ABIERTO | cerrar (auto o manual) | PAGO_CERRADO | `periodos.cerrar` / job | Vence fichas `PENDIENTE`. |
| PAGO_CERRADO | consolidar | CONSOLIDADO | `periodos.consolidar` | Genera `reportes_porciones`. |
| PAGO_CERRADO | reabrir | ABIERTO | `periodos.reabrir` | 🟡 política de fichas vencidas. |
| BORRADOR | editar | BORRADOR | `periodos.editar` | Días/festivos/precio/ventana. |

### 3.5. Ficha `estado`

```
PENDIENTE ──(pago.confirmar)──► PAGADA        (crea derechos_consumo VIGENTE por día)
PENDIENTE ──(cierre ventana)──► VENCIDA       (no crea derechos, no cuenta porciones)
```
`PAGADA` y `VENCIDA` son terminales. Mientras `PENDIENTE`: `ficha_dias` editable (§13).

### 3.6. Derecho de consumo `estado`

```
VIGENTE ──(consumo válido, §16.1)──► UTILIZADO
VIGENTE ──(fin del día, no usado)──► VENCIDO   (job diario)
```
`UTILIZADO` y `VENCIDO` son terminales; intransferible e irreutilizable (§2.3, §16.5).

---

## 4. Catálogo completo de permisos

Base (§5.3) más los necesarios para cubrir todos los módulos. Formato `modulo.accion`.

```
# Cuentas de sistema / usuarios
usuarios.ver  usuarios.crear  usuarios.editar  usuarios.desactivar

# Roles y permisos
roles.ver  roles.crear  roles.editar  roles.desactivar  roles.asignar_permisos

# Personas / colaboradores
colaboradores.ver  colaboradores.crear  colaboradores.editar  colaboradores.desactivar

# Departamentos
departamentos.ver  departamentos.crear  departamentos.editar  departamentos.desactivar

# Gafetes
gafetes.ver  gafetes.emitir  gafetes.reimprimir

# Tarifas
tarifas.ver  tarifas.editar

# Periodos
periodos.ver  periodos.crear  periodos.editar
periodos.abrir  periodos.cerrar  periodos.reabrir  periodos.consolidar

# Fichas (administración; el kiosco NO usa permisos, ver §5.6/§10.2)
fichas.ver  fichas.editar

# Pagos
pagos.ver  pagos.confirmar  pagos.cancelar

# Consumo (validación en comedor)
consumo.validar  consumo.ver

# Reportes
reportes.ver  reportes.generar

# Auditoría
auditoria.ver
```

Roles sugeridos iniciales (§5.2, editables):

| Rol | Permisos (resumen) |
|-----|--------------------|
| Administrador | todos |
| Gestor de periodos | `periodos.*`, `tarifas.*`, `reportes.*`, `departamentos.ver` |
| Cobrador | `fichas.ver`, `fichas.editar`, `pagos.ver`, `pagos.confirmar` |
| Supervisor | `*.ver`, `reportes.ver`, `auditoria.ver` |
| Consulta | `*.ver` |
| Validador comedor | `consumo.validar`, `consumo.ver` |

---

## 5. Reglas de negocio detalladas

Identificadas como `RN-xx` para poder referenciarlas en pruebas.

| ID | Regla | Origen |
|----|-------|--------|
| RN-01 | El sistema **no** procesa cobros digitales; el pago es físico y solo se *registra*. | §2.1 |
| RN-02 | El derecho de consumo existe **solo** tras pago confirmado. | §2.2 |
| RN-03 | Ficha `PENDIENTE` al cerrar la ventana ⇒ `VENCIDA`, sin derechos ni porciones. | §2.2, §8.3 |
| RN-04 | Cada día pagado = 1 derecho independiente; intransferible, no reprogramable, no reembolsable, no saldo. | §2.3 |
| RN-05 | `numero_empleado` único, inmutable, jamás reasignado; persona nunca se borra físicamente. | §3.2, §3.3 |
| RN-06 | Baja = desactivación lógica; persona inactiva pierde todas sus capacidades operativas. | §3.3 |
| RN-07 | Departamento es catálogo; con historial no se elimina físicamente. | §3.4 |
| RN-08 | Un nuevo gafete desactiva el anterior; QR único por emisión; historial conservado. | §4.3 |
| RN-09 | Una cuenta de sistema tiene **exactamente un** rol. | §5.1 |
| RN-10 | Permisos se validan en **backend** en cada operación protegida (403 si falta). | §5.6 |
| RN-11 | Cambiar permisos de un rol impacta de inmediato a todos sus usuarios. | §5.4 |
| RN-12 | Rol con usuarios no se desactiva sin reasignar. | §5.5 |
| RN-13 | Periodos se generan automáticamente en `BORRADOR`, idempotente. | §6.1, D3 |
| RN-14 | Día no disponible: no seleccionable, no cobrable, sin derecho, fuera de proyección. | §6.3 |
| RN-15 | Precio es por día, general, y se **congela** por día del periodo (`precio_aplicado`). | §6.4, §6.5 |
| RN-16 | Ventana válida: `inicio_periodo ≤ ventana_inicio ≤ ventana_fin ≤ fin_periodo`. | §7.2 |
| RN-17 | Ventana controlada solo por fecha (sin horas); activa todo el día calendario. | §7.3 |
| RN-18 | Estados de periodo y sus transiciones son las de §3.4; solo esas se permiten. | §8, §9 |
| RN-19 | Kiosco aislado: sin acceso a administración ni datos de terceros. | §10.1, §10.2 |
| RN-20 | Identificación (kiosco y comedor): QR existe + gafete `ACTIVO` + persona `ACTIVO`. | §11 p1, §16 |
| RN-21 | Generación de ficha exige periodo `ABIERTO` y `hoy ∈ [ventana_inicio, ventana_fin]`. | §11 p2 |
| RN-22 | `total = Σ precio_aplicado` de días seleccionados disponibles. | §11 p3 |
| RN-23 | Una sola ficha válida (`PENDIENTE`/`PAGADA`) por persona+periodo. | §17.1, D4 |
| RN-24 | Cobrador puede editar días **solo** con ficha `PENDIENTE`; recalcula total. | §13, §17.3 |
| RN-25 | `monto_recibido < total` ⇒ pago rechazado; `=` ⇒ cambio 0; `>` ⇒ calcula cambio. Ambos ≥ confirman. | §12.1 |
| RN-26 | Al confirmar pago: ficha→`PAGADA`, se crean derechos `VIGENTE` por día pagado, se genera ticket. | §12, §14, §22 |
| RN-27 | Operación definitiva tras el pago: no se generan más fichas ese periodo. | D4 |
| RN-28 | Consumo válido: persona activa + gafete activo + derecho `VIGENTE` del día ⇒ `UTILIZADO`. | §16.1 |
| RN-29 | Rechazo de consumo si derecho `UTILIZADO`, inexistente/no pagado, o `VENCIDO`. | §16.2–16.4 |
| RN-30 | Derecho `VIGENTE` no usado al fin del día ⇒ `VENCIDO` (job diario). | §16.5 |
| RN-31 | Reporte de porciones: explícito, post-cierre, solo pagos confirmados, asociado al periodo, con autor. | §20 |
| RN-32 | Acciones relevantes se auditan (periodos, pagos, consolidación, etc.). | §19 |

---

## 6. Casos de uso principales

Notación breve: **Actor** → objetivo. Se detallan solo los de mayor riesgo.

1. **Administrativo — Alta de persona (+ cuenta opcional + gafete)** (§22).
   Precondición: permiso `colaboradores.crear`. Flujo: captura datos → valida `numero_empleado`
   único → (opcional) crea `users` con email+rol → emite gafete `ACTIVO` con `qr_token` → imprime.
2. **Administrativo — Reposición de gafete** (§4.3). Transacción: desactiva gafete previo, emite nuevo.
3. **Gestor — Configurar y abrir periodo** (§22). Edita días/festivos/ventana en `BORRADOR` → `abrir`.
4. **Sistema (job) — Generar periodo semanal** (D3). Idempotente; crea `BORRADOR` Lun–Vie con
   `precio_aplicado` desde tarifa vigente.
5. **Colaborador (kiosco) — Generar ficha** (§11). Escanear QR → validar identidad+periodo+ventana →
   seleccionar días disponibles → calcular total → crear ficha `PENDIENTE` (respetando RN-23).
6. **Cobrador — Confirmar pago** (§12–14). Buscar por folio → (opcional) editar días → capturar
   `monto_recibido` → validar (RN-25) → confirmar: ficha `PAGADA`, crear derechos, emitir ticket.
7. **Validador (comedor) — Validar consumo** (§16). Escanear QR → buscar derecho `VIGENTE` del día →
   permitir/rechazar → marcar `UTILIZADO`.
8. **Sistema (job) — Cerrar ventana / vencer fichas** (§8.3). `hoy > ventana_fin` ⇒ periodo
   `PAGO_CERRADO`, fichas `PENDIENTE`→`VENCIDA`.
9. **Sistema (job) — Vencer derechos** (RN-30). Al fin del día, `VIGENTE`→`VENCIDO`.
10. **Gestor — Consolidar y generar reporte** (§20). Periodo `PAGO_CERRADO`→`CONSOLIDADO` + reporte.

---

## 7. Diseño de API (borrador)

Todo bajo `/api` (el catch-all web sirve la SPA; CLAUDE.md). Auth con **Sanctum** (sesión SPA).
Kiosco y comedor usan endpoints **dedicados** que no requieren sesión administrativa pero sí un
mecanismo de dispositivo (🟡 ver §10). Cada endpoint protegido valida permiso (RN-10) vía middleware
`can:<permiso>`.

### 7.1. Autenticación
```
POST   /api/login                 email+password → sesión (D2)
POST   /api/logout
GET    /api/me                    usuario + rol + permisos
```

### 7.2. Administración
```
# Departamentos
GET/POST           /api/departamentos
PUT /api/departamentos/{id}       ·  DELETE→ desactivar (lógico)

# Personas
GET/POST           /api/personas
GET/PUT            /api/personas/{id}
POST /api/personas/{id}/desactivar   ·  POST /api/personas/{id}/reactivar
POST /api/personas/{id}/gafetes       emitir/reponer gafete
GET  /api/personas/{id}/gafetes       historial

# Cuentas de sistema, roles, permisos
GET/POST           /api/usuarios     (crea users con persona_id, email, rol_id)
PUT  /api/usuarios/{id}   ·  POST /api/usuarios/{id}/desactivar
GET/POST           /api/roles
PUT  /api/roles/{id}   ·  POST /api/roles/{id}/desactivar
PUT  /api/roles/{id}/permisos       asignar permisos
GET  /api/permisos

# Tarifas
GET/POST           /api/tarifas      (nueva vigencia)
```

### 7.3. Periodos
```
GET/POST           /api/periodos
GET/PUT            /api/periodos/{id}          (editar solo en BORRADOR)
GET  /api/periodos/{id}/dias   ·  PUT /api/periodos/{id}/dias/{diaId}   (festivo/disponibilidad)
POST /api/periodos/{id}/abrir       (→ABIERTO)     [periodos.abrir]
POST /api/periodos/{id}/cerrar      (→PAGO_CERRADO)[periodos.cerrar]
POST /api/periodos/{id}/reabrir     (→ABIERTO)     [periodos.reabrir]  🟡
POST /api/periodos/{id}/consolidar  (→CONSOLIDADO + reporte) [periodos.consolidar]
```

### 7.4. Kiosco (aislado, §10)
```
POST /api/kiosco/identificar        { qr_token } → persona + periodo abierto + días disponibles
POST /api/kiosco/fichas             { qr_token, dias[] } → crea ficha PENDIENTE (RN-20..23)
```
Devuelve solo datos del propio colaborador; nunca lista terceros ni menús admin.

### 7.5. Cobro
```
GET  /api/fichas/{folio}            buscar por folio            [fichas.ver]
PUT  /api/fichas/{folio}/dias       editar selección (PENDIENTE)[fichas.editar]
POST /api/fichas/{folio}/pago       { monto_recibido } confirmar[pagos.confirmar]
POST /api/pagos/{id}/cancelar                                   [pagos.cancelar] 🟡
GET  /api/fichas/{folio}/ticket     ticket final
```

### 7.6. Consumo (comedor)
```
POST /api/consumo/validar           { qr_token } → permite/rechaza + marca UTILIZADO [consumo.validar]
```

### 7.7. Reportes y auditoría
```
GET  /api/periodos/{id}/reporte-porciones      [reportes.ver]
GET  /api/auditoria                             [auditoria.ver]
```

Convención de respuestas: `422` validación, `403` permiso, `409` transición de estado inválida,
`200/201` éxito. Cuerpo de error uniforme `{ mensaje, errores }`.

---

## 8. Procesos programados (jobs / scheduler)

Registrar en `routes/console.php` / `Kernel` (Laravel scheduler; requiere cron del SO o
`schedule:work`). Todos escriben auditoría con `user_id = NULL`.

| Job | Frecuencia | Acción |
|-----|-----------|--------|
| `GenerarPeriodoSemanal` | semanal (p.ej. jueves) | Crea `BORRADOR` de la próxima semana Lun–Vie, idempotente (RN-13). |
| `CerrarVentanasVencidas` | diario 00:05 | Periodos `ABIERTO` con `hoy > ventana_fin` ⇒ `PAGO_CERRADO`; fichas `PENDIENTE`→`VENCIDA` (RN-03). |
| `VencerDerechos` | diario 00:10 | `derechos_consumo` `VIGENTE` de días ya pasados ⇒ `VENCIDO` (RN-30). |

> Nota: en XAMPP/Windows el scheduler necesita una Tarea Programada que ejecute
> `php artisan schedule:run` cada minuto. 🟡 Confirmar entorno de producción.

---

## 9. Requerimientos no funcionales

- **Seguridad**: permisos en backend (RN-10); kiosco aislado (RN-19); QR opaco; contraseñas
  hasheadas (bcrypt/argon); rate‑limit en `/api/login`, `/api/kiosco/*` y `/api/consumo/validar`.
- **Integridad**: operaciones críticas (emitir gafete, confirmar pago, validar consumo, transiciones
  de periodo) en **transacción** con bloqueo para evitar dobles derechos/consumos.
- **Auditoría**: toda acción de §19 registrada; inmutable.
- **Historial**: sin borrados físicos de personas, gafetes, departamentos con historial.
- **Rendimiento**: validación de consumo debe responder rápido (índice
  `unique(persona_id, dia_periodo_id)` y `qr_token`).
- **Disponibilidad kiosco/comedor**: tolerar reintentos; idempotencia en creación de ficha por
  (persona, periodo).
- **Pruebas**: cada regla `RN-xx` con test en `tests/Feature` o `tests/Unit` (CLAUDE.md); SQLite en
  memoria.
- **Extensibilidad** (§24): permisos/roles/estados/tarifas/dispositivos ampliables sin refactor.
- **Localización**: es-MX, zona horaria `America/Mexico_City`, moneda MXN.

---

## 10. Decisiones abiertas (🟡 a confirmar)

1. **Autenticación del kiosco y del comedor**: ¿el kiosco es un dispositivo con token propio
   (`device token`) o una URL pública en red interna? ¿La validación de consumo la hace un
   *Validador* logueado o un dispositivo dedicado? Afecta §7.4 y §7.6.
2. **`periodos.reabrir`**: al reabrir un `PAGO_CERRADO`, ¿las fichas `VENCIDA` reviven, se quedan
   vencidas, o se prohíbe reabrir si ya se consolidó? Recomendación: reabrir solo antes de
   consolidar y **no** revivir fichas vencidas.
3. **Unicidad de ficha válida en MySQL**: implementación del índice único condicional (RN-23). Opción
   recomendada: columna generada `periodo_activo_key` (= `periodo_id` si estado∈{PENDIENTE,PAGADA},
   NULL si VENCIDA) con `unique(persona_id, periodo_activo_key)`.
4. **`pagos.cancelar`**: ¿qué implica cancelar un pago ya confirmado? ¿Revierte derechos no
   utilizados? ¿Solo el mismo día? Define alcance antes de exponerlo.
5. **Regeneración de reporte de porciones**: ¿único por periodo o versionado?
6. **Contenido/beneficio del gafete impreso**: plantilla, logo corporativo, tamaño (§4.1). Diseño de
   impresión pendiente (§26.16).
7. **Ticket** (§14): ¿impresión térmica (58/80mm) o PDF? Formato pendiente (§26.17).
8. **Folio de ficha**: formato/secuencia (p.ej. `AAAA-Pnn-000123`).
9. **Número de empleado**: ¿numérico o alfanumérico? Se modeló `VARCHAR(30)` por flexibilidad.

---

## 11. Orden sugerido de implementación

Fases incrementales, cada una con migraciones + modelos + endpoints + tests:

1. **Cimientos de identidad y acceso**: departamentos, personas, gafetes, roles, permisos,
   cuentas de sistema, login, middleware de permisos. (Módulos §3, §4, §5)
2. **Periodos y tarifas**: catálogo de tarifas, periodos + días, estados y transiciones,
   job de generación semanal. (§6, §7, §8, §9)
3. **Kiosco y fichas**: identificación por QR, generación de ficha, reglas RN-20..23. (§10, §11)
4. **Cobro y derechos**: confirmación de pago, creación de derechos, ticket. (§12, §13, §14)
5. **Consumo**: validación en comedor, vencimiento de derechos. (§16)
6. **Cierre, consolidación, reportes y auditoría**: jobs de cierre/vencimiento, reporte de
   porciones, bitácora. (§8.3, §19, §20)

Cada fase deja el sistema desplegable y probado antes de pasar a la siguiente.
