# Especificación funcional y requerimientos del sistema de comedor

## 1. Objetivo del sistema

La aplicación tendrá como objetivo digitalizar y controlar el proceso de
planeación, solicitud, pago físico y validación del derecho de consumo
del servicio de comedor de una empresa.

Actualmente, el proceso se realiza manualmente mediante hojas de Excel y
pagos físicos en ventanilla. El sistema deberá centralizar la
información, controlar los periodos de servicio, permitir que los
colaboradores seleccionen anticipadamente los días en los que desean
comer, registrar la confirmación del pago físico y validar
posteriormente el derecho de consumo de cada persona.

El sistema deberá permitir proyectar con precisión la cantidad de
porciones que deben prepararse, considerando exclusivamente los derechos
de consumo cuyo pago haya sido confirmado.

------------------------------------------------------------------------

# 2. Principios generales del sistema

## 2.1. El pago continuará siendo físico

El sistema no procesará cobros digitales.

El pago seguirá realizándose físicamente en ventanilla debido a los
requerimientos y motivos fiscales de la empresa.

El sistema funcionará como:

-   Generador de la solicitud de días.
-   Generador de la ficha o ticket de pago.
-   Registro y confirmación del pago físico.
-   Control de los derechos de consumo.
-   Validación del acceso al comedor.
-   Sistema de proyección y consulta de información.

El sistema no sustituirá el proceso contable o fiscal que la empresa
realice fuera de la aplicación.

------------------------------------------------------------------------

## 2.2. El derecho de consumo depende del pago confirmado

La generación de una ficha no significa que el colaborador tenga derecho
a comer.

La regla será:

``` text
Ficha generada
        ↓
Pago pendiente
        ↓
Pago físico confirmado
        ↓
Derechos de consumo activos
```

Si el pago no se confirma antes del cierre de la ventana de pago:

``` text
Ficha pendiente
        ↓
Cierre del plazo
        ↓
Ficha vencida
        ↓
Sin derecho de consumo
        ↓
No se contabiliza en la proyección de porciones
```

------------------------------------------------------------------------

## 2.3. Cada día de comida es un derecho independiente

Si una persona paga cuatro días, cada día representa un derecho
independiente.

Ejemplo:

``` text
Lunes      → Derecho de consumo
Martes     → Derecho de consumo
Miércoles  → Derecho de consumo
Jueves     → Derecho de consumo
```

Cada día puede posteriormente encontrarse en un estado diferente:

``` text
PAGADO → UTILIZADO
PAGADO → VENCIDO
```

Un día pagado pero no utilizado no podrá:

-   Transferirse a otra persona.
-   Cambiarse a otro día.
-   Reutilizarse posteriormente.
-   Convertirse en saldo a favor.

------------------------------------------------------------------------

# 3. Módulo de gestión de personal

Este módulo será el punto central para registrar y administrar a todas
las personas que interactúan con el sistema.

Toda persona registrada será considerada potencialmente elegible para
utilizar el servicio de comedor. No será necesario mantener una
capacidad de consumidor independiente.

La cuenta de sistema será opcional.

Por lo tanto, una persona podrá:

``` text
Persona A:
- Consumidor
- Sin cuenta administrativa

Persona B:
- Consumidor
- Usuario del sistema

Persona C:
- Consumidor
- Administrador del sistema
```

La diferencia principal será si cuenta o no con una cuenta de acceso al
sistema.

------------------------------------------------------------------------

## 3.1. Alta de una persona

El módulo deberá permitir registrar:

### Datos personales

-   Nombre del colaborador.
-   Fotografía opcional.
-   Número de empleado.
-   Departamento.
-   Estado de la persona.

### Cuenta de sistema opcional

Durante el alta se deberá determinar si la persona tendrá acceso
administrativo al sistema.

Si se requiere una cuenta de sistema, se deberán registrar:

-   Credenciales de acceso.
-   Rol asignado.

Si no requiere cuenta de sistema:

-   La persona podrá utilizar su gafete para los procesos de comedor.
-   No podrá iniciar sesión en los módulos administrativos.

------------------------------------------------------------------------

## 3.2. Número de empleado

El número de empleado será un identificador empresarial permanente.

Reglas:

-   Debe ser único.
-   No puede duplicarse.
-   No puede reasignarse a otra persona.
-   Si una persona causa baja, su número de empleado queda
    permanentemente reservado.
-   La persona no debe eliminarse físicamente de la base de datos.

Ejemplo:

``` text
Juan Pérez
Número de empleado: 1234
Estado: INACTIVO
```

El número `1234` no podrá asignarse posteriormente a otra persona.

------------------------------------------------------------------------

## 3.3. Baja y desactivación

La baja se realizará mediante desactivación lógica.

No deberá realizarse:

``` text
DELETE físico
```

La persona conservará sus datos históricos, pero pasará a estado
inactivo.

Una persona inactiva:

-   No podrá iniciar sesión.
-   No podrá utilizar su gafete.
-   No podrá generar nuevas fichas.
-   No podrá obtener nuevos derechos de consumo.
-   No podrá validar nuevos consumos.

Sus operaciones históricas permanecerán disponibles para auditoría y
estadísticas.

------------------------------------------------------------------------

## 3.4. Cambio de departamento

El departamento no será almacenado como texto libre.

Los departamentos deberán existir como catálogo independiente.

Relación conceptual:

``` text
Departamento
      │
      │ 1:N
      ▼
Colaboradores
```

El sistema deberá permitir:

-   Crear departamentos.
-   Editar departamentos.
-   Desactivar departamentos.

Un departamento que tenga colaboradores o historial no deberá eliminarse
físicamente.

Ejemplo:

``` text
Producción
Estado: INACTIVO
```

El departamento permanecerá en la base de datos para conservar el
historial.

Si una persona cambia de departamento:

``` text
Antes:
Producción

Después:
Mantenimiento
```

Su departamento actual se actualizará.

Los registros históricos deberán conservar la información necesaria para
conocer el contexto correspondiente al momento de la operación.

------------------------------------------------------------------------

# 4. Módulo de gafetes

Todas las personas registradas deberán contar con un gafete generado por
el sistema.

El gafete tendrá un formato único y predefinido.

## 4.1. Información del gafete

El diseño deberá incluir:

-   Fotografía opcional.
-   Nombre del colaborador.
-   Departamento.
-   Número de empleado.
-   Código QR único.
-   Elementos visuales corporativos definidos por la empresa.

Ejemplo conceptual:

``` text
┌─────────────────────────────┐
│            LOGO             │
│                             │
│          [FOTO]             │
│                             │
│       NOMBRE                │
│       DEPARTAMENTO          │
│       No. EMPLEADO          │
│                             │
│           [ QR ]            │
└─────────────────────────────┘
```

------------------------------------------------------------------------

## 4.2. Generación e impresión

El sistema deberá permitir:

``` text
Crear persona
      ↓
Guardar información
      ↓
Generar gafete
      ↓
Mostrar diseño predefinido
      ↓
Imprimir
```

El administrativo podrá imprimir el gafete y entregarlo físicamente al
colaborador.

------------------------------------------------------------------------

## 4.3. Reposición de gafete

Si se genera un nuevo gafete:

``` text
Gafete anterior → INACTIVO
Gafete nuevo    → ACTIVO
```

El gafete anterior dejará de funcionar inmediatamente.

La persona seguirá siendo la misma y conservará:

-   Su número de empleado.
-   Su historial.
-   Sus operaciones.
-   Su cuenta de sistema, si existe.

El sistema deberá conservar el historial de gafetes anteriores.

Ejemplo:

``` text
Juan Pérez
Empleado 1234

Gafete A:
Estado: INACTIVO

Gafete B:
Estado: ACTIVO
```

El QR deberá ser único para cada gafete emitido.

------------------------------------------------------------------------

# 5. Módulo de usuarios, roles y permisos

El sistema deberá utilizar un modelo de control de acceso basado en
roles.

## 5.1. Un usuario solamente puede tener un rol

La relación será:

``` text
Usuario
   ↓
Un único rol
   ↓
Permisos del rol
```

Un usuario no podrá tener múltiples roles simultáneamente.

Ejemplo:

``` text
Juan Pérez
Rol: Cobrador
```

Sus permisos serán exclusivamente los que pertenezcan al rol Cobrador.

------------------------------------------------------------------------

## 5.2. Administración de roles

El sistema deberá contar con un módulo donde se puedan:

-   Crear roles.
-   Editar roles.
-   Consultar roles.
-   Desactivar roles.
-   Asignar permisos a roles.

Los roles no deberán estar codificados de manera rígida en el sistema.

Ejemplos iniciales:

``` text
Administrador
Cobrador
Gestor de periodos
Supervisor
Consulta
```

La empresa podrá crear nuevos roles según sus necesidades.

------------------------------------------------------------------------

## 5.3. Permisos granulares

Los permisos deberán representar acciones concretas del sistema.

Ejemplos:

``` text
usuarios.ver
usuarios.crear
usuarios.editar
usuarios.desactivar

colaboradores.ver
colaboradores.crear
colaboradores.editar
colaboradores.desactivar

departamentos.ver
departamentos.crear
departamentos.editar
departamentos.desactivar

periodos.ver
periodos.crear
periodos.editar
periodos.abrir
periodos.cerrar
periodos.reabrir

pagos.ver
pagos.confirmar
pagos.cancelar

reportes.ver
reportes.generar
```

Los permisos deberán poder agregarse conforme se incorporen nuevos
módulos.

------------------------------------------------------------------------

## 5.4. Modificación de roles

Si un rol es modificado, todos los usuarios que tengan dicho rol
adquirirán automáticamente los permisos configurados actualmente.

Ejemplo:

``` text
Rol: Cobrador
```

Se agrega:

``` text
pagos.cancelar
```

Todos los usuarios con el rol Cobrador adquieren ese permiso.

------------------------------------------------------------------------

## 5.5. Desactivación de roles

Un rol asignado a usuarios no debería poder desactivarse sin resolver
previamente sus asignaciones.

Recomendación:

``` text
Rol activo
      ↓
Tiene usuarios asignados
      ↓
Intento de desactivar
      ↓
El sistema obliga a reasignar usuarios
      ↓
El rol puede desactivarse
```

------------------------------------------------------------------------

## 5.6. Validación de permisos

La seguridad deberá validarse en el backend.

Ocultar un botón en la interfaz no es suficiente.

El backend deberá comprobar el permiso correspondiente en cada operación
protegida.

Ejemplo:

``` text
Request:
POST /api/usuarios

        ↓

¿Tiene usuarios.crear?

        ↓

NO → 403 Forbidden

SÍ → Ejecutar operación
```

------------------------------------------------------------------------

# 6. Módulo de periodos de servicio

El sistema deberá trabajar con periodos semanales de servicio.

Cada periodo representa una semana de comedor.

Normalmente:

``` text
Lunes a viernes
```

Ejemplo:

``` text
Periodo:
20/07/2026 - 24/07/2026
```

------------------------------------------------------------------------

## 6.1. Generación automática de periodos

El sistema deberá generar automáticamente los periodos semanales.

El periodo generado inicialmente deberá quedar en estado de borrador.

Ejemplo:

``` text
Periodo:
20-24 julio

Estado:
BORRADOR
```

La generación automática deberá ser idempotente.

Si el proceso se ejecuta dos veces y el periodo ya existe:

``` text
Periodo ya existente
        ↓
No crear duplicado
```

------------------------------------------------------------------------

## 6.2. Días del periodo

Cada periodo deberá tener días individuales asociados.

No se recomienda manejar únicamente columnas como:

``` text
lunes
martes
miércoles
jueves
viernes
```

El modelo deberá permitir que cada día tenga sus propias propiedades.

Conceptualmente:

``` text
Periodo
   ├── Lunes
   ├── Martes
   ├── Miércoles
   ├── Jueves
   └── Viernes
```

Cada día podrá contener:

-   Fecha.
-   Día de la semana.
-   Disponibilidad.
-   Estado de festivo.
-   Precio aplicable.
-   Motivo de indisponibilidad.

------------------------------------------------------------------------

## 6.3. Días festivos y excepciones

El sistema deberá permitir modificar un periodo generado
automáticamente.

Ejemplo:

``` text
Lunes       ✓
Martes      ✗ Festivo
Miércoles   ✓
Jueves      ✓
Viernes     ✓
```

Un día no disponible:

-   No podrá seleccionarse.
-   No se cobrará.
-   No generará derecho de consumo.
-   No se incluirá en la proyección de porciones.

El gestor podrá modificar el periodo cuando existan excepciones.

------------------------------------------------------------------------

## 6.4. Precio por día

El precio será por día.

No habrá precios diferenciados por:

-   Tipo de empleado.
-   Departamento.
-   Rol.
-   Categoría de usuario.

El precio será general para todos los usuarios.

Sin embargo, deberá existir un módulo o configuración que permita
modificar el precio por día para el futuro.

------------------------------------------------------------------------

## 6.5. Conservación del precio histórico

El precio aplicado a cada día deberá conservarse como parte de la
información histórica del periodo.

Ejemplo:

``` text
Periodo de agosto
Lunes:
Precio aplicado: $50
```

Si posteriormente la tarifa general cambia:

``` text
Nueva tarifa:
$55
```

El periodo anterior deberá conservar:

``` text
$50
```

Esto evita que modificar el precio actual altere operaciones históricas.

------------------------------------------------------------------------

# 7. Ventana de generación y pago

El periodo de servicio y la ventana para generar fichas son conceptos
independientes.

Ejemplo:

``` text
Periodo de servicio:
20-24 julio
```

La ventana de generación:

``` text
22-22 julio
```

Significa que las fichas solamente podrán generarse el día 22.

------------------------------------------------------------------------

## 7.1. Configuración de la ventana

El gestor podrá configurar:

``` text
Fecha inicial de generación
Fecha final de generación
```

Ejemplo:

``` text
Inicio: 22/07/2026
Fin:    22/07/2026
```

Esto representa una ventana de un solo día.

Para varios días:

``` text
Inicio: 20/07/2026
Fin:    22/07/2026
```

La generación estará disponible los días:

``` text
20/07 ✓
21/07 ✓
22/07 ✓
23/07 ✗
24/07 ✗
```

------------------------------------------------------------------------

## 7.2. Validaciones del rango

El sistema deberá validar:

``` text
fecha_inicio >= inicio_periodo
fecha_fin <= fin_periodo
fecha_inicio <= fecha_fin
```

Configuraciones inválidas:

``` text
19/07 → 22/07
```

porque inicia antes del periodo.

``` text
22/07 → 25/07
```

porque termina después del periodo.

``` text
24/07 → 20/07
```

porque la fecha inicial es posterior a la fecha final.

------------------------------------------------------------------------

## 7.3. No se manejarán horas

La ventana será controlada únicamente por fecha calendario.

No será necesario configurar:

``` text
08:00
18:00
```

La regla será:

``` text
Fecha actual >= inicio
Y
Fecha actual <= fin
```

Entonces la ventana estará activa durante todo el día calendario
correspondiente.

------------------------------------------------------------------------

# 8. Estados del periodo

El periodo deberá tener estados claramente definidos.

## 8.1. BORRADOR

El periodo puede configurarse.

Se pueden definir o modificar:

-   Días disponibles.
-   Días festivos.
-   Precios.
-   Fecha inicial de generación.
-   Fecha final de generación.

------------------------------------------------------------------------

## 8.2. ABIERTO

La ventana de generación puede estar activa.

Los colaboradores pueden:

-   Identificarse mediante QR.
-   Seleccionar días disponibles.
-   Generar fichas.

Los cobradores pueden:

-   Consultar fichas pendientes.
-   Modificar la selección de días dentro de las reglas permitidas.
-   Confirmar pagos.

------------------------------------------------------------------------

## 8.3. PAGO CERRADO

Una vez terminada la fecha final de la ventana:

``` text
No se pueden generar nuevas fichas.
```

Las fichas pendientes pasan a vencidas.

Las fichas vencidas:

-   No generan derechos de consumo.
-   No se incluyen en la proyección de porciones.

En este estado se habilita la generación del reporte de porciones.

------------------------------------------------------------------------

## 8.4. CONSOLIDADO

El gestor ejecuta explícitamente:

``` text
GENERAR REPORTE
```

El sistema toma exclusivamente los pagos confirmados y genera la
información consolidada.

------------------------------------------------------------------------

# 9. Gestión de periodos mediante permisos

La gestión de periodos será completamente controlada por permisos.

Podrá existir un rol específico:

``` text
Gestor de periodos
```

Con permisos como:

``` text
periodos.ver
periodos.crear
periodos.editar
periodos.abrir
periodos.cerrar
periodos.reabrir
```

No deberá existir un usuario codificado permanentemente como responsable
de los periodos.

La responsabilidad será determinada por el rol y sus permisos.

------------------------------------------------------------------------

# 10. Módulo de generación de fichas

El colaborador utilizará un kiosco físico ubicado estratégicamente
dentro de la empresa.

El kiosco será utilizado para:

``` text
Escanear gafete
      ↓
Identificar colaborador
      ↓
Seleccionar días
      ↓
Generar ficha
```

La identificación será mediante QR.

------------------------------------------------------------------------

## 10.1. El kiosco no requiere sesión administrativa

El kiosco no deberá exponer el sistema administrativo completo.

El colaborador no deberá poder:

-   Regresar a menús administrativos.
-   Ver módulos de administración.
-   Acceder a configuraciones.
-   Ver información de otros usuarios.

El kiosco deberá funcionar como una experiencia aislada y limitada
exclusivamente al flujo de generación de fichas.

------------------------------------------------------------------------

## 10.2. Prevención de acceso al sistema

La interfaz del kiosco deberá impedir que un usuario pueda presionar
"regresar" y acceder a las opciones administrativas.

Se deberá diseñar una navegación aislada para el kiosco.

La aplicación deberá distinguir entre:

``` text
Modo kiosco
```

y:

``` text
Modo administrativo
```

El modo kiosco deberá limitar la navegación a:

``` text
Pantalla de identificación
        ↓
Selección de días
        ↓
Confirmación
        ↓
Generación de ficha
        ↓
Finalización
        ↓
Regreso a pantalla inicial
```

------------------------------------------------------------------------

# 11. Flujo de generación de una ficha

## Paso 1: identificación

El colaborador escanea su gafete.

El sistema obtiene el QR.

``` text
QR
  ↓
Identificar gafete
  ↓
Identificar persona
```

El sistema deberá validar:

-   Que el QR exista.
-   Que el gafete esté activo.
-   Que la persona esté activa.

Si la persona está inactiva:

``` text
Acceso rechazado
```

------------------------------------------------------------------------

## Paso 2: validación del periodo

El sistema debe verificar:

-   Que exista un periodo válido.
-   Que la fecha actual esté dentro de la ventana de generación.
-   Que existan días disponibles.

Si la ventana está cerrada:

``` text
No se pueden generar nuevas fichas
```

------------------------------------------------------------------------

## Paso 3: selección de días

El usuario podrá seleccionar los días disponibles del periodo.

Ejemplo:

``` text
☑ Lunes
☑ Martes
☐ Miércoles
☑ Jueves
☐ Viernes
```

El sistema calculará:

``` text
Días seleccionados:
3

Precio por día:
$50

Total:
$150
```

Los días festivos o no disponibles deberán aparecer bloqueados o no
seleccionables.

------------------------------------------------------------------------

## Paso 4: generación

El sistema generará una ficha con:

-   Folio único.
-   Colaborador.
-   Número de empleado.
-   Periodo.
-   Días seleccionados.
-   Precio por día.
-   Total.
-   Estado pendiente de pago.

Estado inicial:

``` text
PENDIENTE
```

------------------------------------------------------------------------

# 12. Módulo de cobro físico y confirmación de pago

El cobrador utilizará una tablet o laptop.

El proceso será similar al de un POS, pero el cobro físico se realizará
fuera del sistema.

## Flujo:

``` text
Empleado presenta ficha
        ↓
Cobrador escanea o escribe folio
        ↓
Sistema muestra operación
        ↓
Cobrador recibe dinero físico
        ↓
Ingresa monto recibido
        ↓
Sistema valida
        ↓
Confirma pago
```

------------------------------------------------------------------------

## 12.1. Validación del monto

El sistema deberá comparar:

``` text
Total de la ficha
```

contra:

``` text
Monto recibido
```

### Monto menor

``` text
Total: $200
Recibido: $150
```

Resultado:

``` text
Pago rechazado
```

El cobrador deberá recibir más dinero.

------------------------------------------------------------------------

### Monto exacto

``` text
Total: $200
Recibido: $200
```

Resultado:

``` text
Cambio: $0
Pago confirmado
```

------------------------------------------------------------------------

### Monto mayor

``` text
Total: $200
Recibido: $250
```

Resultado:

``` text
Cambio: $50
Pago confirmado
```

El sistema deberá calcular automáticamente el cambio.

------------------------------------------------------------------------

# 13. Modificación de la ficha por el cobrador

El colaborador podrá solicitar agregar o quitar días al momento del
pago.

El cobrador tendrá permiso para editar la selección de la ficha antes de
confirmar el pago.

Ejemplo:

``` text
Ficha original:
Lunes
Martes
Miércoles
Jueves

Total:
$200
```

El colaborador decide quitar jueves:

``` text
Nueva selección:
Lunes
Martes
Miércoles

Nuevo total:
$150
```

O puede agregar un día disponible:

``` text
Nueva selección:
Lunes
Martes
Miércoles
Jueves
Viernes

Nuevo total:
$250
```

El sistema deberá recalcular:

-   Días seleccionados.
-   Precio por día.
-   Total a pagar.

Una vez confirmado el pago:

``` text
Ficha pendiente
        ↓
Pago confirmado
        ↓
Ticket final
```

La operación final deberá reflejar únicamente los días realmente
pagados.

------------------------------------------------------------------------

# 14. Ticket final de pago

Una vez confirmado el pago, el sistema deberá generar un ticket final.

El ticket deberá servir como respaldo para el colaborador.

Deberá contener al menos:

-   Folio de la operación.
-   Datos del colaborador.
-   Número de empleado.
-   Periodo.
-   Días pagados.
-   Precio por día.
-   Total pagado.
-   Monto recibido.
-   Cambio entregado.
-   Fecha de confirmación.
-   Identificación del cobrador.

El ticket final será el comprobante de que los derechos fueron
confirmados mediante pago.

------------------------------------------------------------------------

# 15. Estados de la ficha y derechos

La ficha podrá manejar estados como:

``` text
PENDIENTE
PAGADA
VENCIDA
```

El derecho diario podrá manejar estados independientes.

Ejemplo:

``` text
Ficha:
PAGADA

Lunes:
UTILIZADO

Martes:
UTILIZADO

Miércoles:
VENCIDO

Jueves:
UTILIZADO
```

------------------------------------------------------------------------

# 16. Módulo de validación de consumo

Cuando el colaborador se presente a comer, deberá identificarse mediante
su gafete QR.

El sistema deberá:

``` text
Escanear QR
      ↓
Identificar persona
      ↓
Identificar fecha actual
      ↓
Buscar derecho de consumo
      ↓
Validar estado
```

------------------------------------------------------------------------

## 16.1. Consumo válido

``` text
Persona activa
        +
Gafete activo
        +
Día pagado
        +
Derecho no utilizado
        ↓
PERMITIR CONSUMO
```

El derecho pasará a:

``` text
UTILIZADO
```

------------------------------------------------------------------------

## 16.2. Día ya utilizado

Si el colaborador intenta consumir nuevamente el mismo día:

``` text
Derecho:
UTILIZADO
```

Resultado:

``` text
Acceso rechazado
```

------------------------------------------------------------------------

## 16.3. Día no pagado

``` text
No existe derecho pagado
```

Resultado:

``` text
Acceso rechazado
```

------------------------------------------------------------------------

## 16.4. Día vencido

``` text
Derecho:
VENCIDO
```

Resultado:

``` text
Acceso rechazado
```

------------------------------------------------------------------------

## 16.5. Día pagado pero no utilizado

Al finalizar el día correspondiente, si el colaborador no utilizó su
derecho:

``` text
PAGADO
        ↓
Fin del día
        ↓
No utilizado
        ↓
VENCIDO
```

El derecho no podrá transferirse ni reutilizarse.

------------------------------------------------------------------------

# 17. Reglas de negocio principales

## 17.1. Un colaborador no puede tener más de una ficha válida para el mismo periodo

El sistema deberá evitar la generación de múltiples fichas activas para
una misma persona dentro del mismo periodo.

Regla:

``` text
Persona
   +
Periodo
   ↓
Una única operación válida
```

Esto evita que una persona genere múltiples tickets para el mismo
periodo.

------------------------------------------------------------------------

## 17.2. El colaborador puede seleccionar múltiples días en una sola ficha

Ejemplo:

``` text
Lunes
Martes
Jueves
Viernes
```

Se genera una sola ficha con:

``` text
4 días
```

------------------------------------------------------------------------

## 17.3. La ficha pendiente puede modificarse durante el proceso de pago

El cobrador podrá:

-   Agregar días.
-   Quitar días.
-   Recalcular el total.
-   Confirmar el pago.

Una vez confirmado el pago, la operación deberá considerarse finalizada.

------------------------------------------------------------------------

## 17.4. El periodo cerrado no acepta nuevas fichas

Una vez terminado el rango de generación:

``` text
No se generan nuevas fichas
```

Las fichas pendientes se vencen.

------------------------------------------------------------------------

# 18. Módulo de tarifas

El sistema deberá contar con una configuración de precio por día.

La tarifa será general para todos los colaboradores.

No habrá diferenciación por:

-   Departamento.
-   Rol.
-   Tipo de empleado.

El sistema deberá conservar el precio aplicado en cada periodo para
evitar que los cambios futuros alteren la información histórica.

------------------------------------------------------------------------

# 19. Módulo de auditoría

Debido a que el sistema controla pagos, derechos de consumo y
operaciones administrativas, deberá conservar un historial de acciones
relevantes.

Se deberá identificar:

-   Usuario que realizó la acción.
-   Acción realizada.
-   Fecha.
-   Hora.
-   Registro afectado.
-   Información relevante de la operación.

Ejemplos:

``` text
Usuario A
CREÓ periodo
01/08/2026

Usuario B
EDITÓ periodo
02/08/2026

Usuario C
CONFIRMÓ pago
02/08/2026

Usuario D
GENERÓ reporte
03/08/2026
```

Las acciones importantes sobre periodos deberán registrarse:

-   Creación.
-   Edición.
-   Apertura.
-   Cierre.
-   Reapertura.
-   Consolidación.

Las operaciones de pago también deberán ser auditables.

------------------------------------------------------------------------

# 20. Módulo de reportes

El diseño definitivo de los reportes se definirá después de completar
todos los campos y reglas del sistema.

Por ahora, queda establecido que el reporte de porciones:

-   Se genera después del cierre de la ventana de pago.
-   Se genera mediante una acción explícita.
-   No debe incluir fichas pendientes.
-   No debe incluir fichas vencidas.
-   Debe utilizar únicamente pagos confirmados.
-   Debe quedar asociado al periodo.
-   Debe registrar quién lo generó.

La estructura final del reporte se definirá posteriormente cuando se
haya completado el modelo de datos.

------------------------------------------------------------------------

# 21. Arquitectura conceptual de entidades

La aplicación deberá organizarse conceptualmente alrededor de las
siguientes entidades:

``` text
PERSONA
   │
   ├── DEPARTAMENTO
   │
   ├── GAFETES
   │
   └── CUENTA DE SISTEMA
          │
          └── ROL
                 │
                 └── PERMISOS


PERIODO
   │
   ├── DÍAS DEL PERIODO
   │       └── Precio aplicado
   │
   └── OPERACIONES DE COMIDA
           │
           ├── Ficha
           ├── Pago
           └── Derechos diarios
                    │
                    └── Consumo
```

------------------------------------------------------------------------

# 22. Flujo completo del sistema

## Alta de colaborador

``` text
Administrativo
      ↓
Crea persona
      ↓
Registra:
- Nombre
- Fotografía
- Número de empleado
- Departamento
      ↓
¿Cuenta de sistema?
      │
      ├── No
      │
      └── Sí
            ↓
        Credenciales
            ↓
        Rol
      ↓
Generar gafete
      ↓
Imprimir
      ↓
Entregar al colaborador
```

------------------------------------------------------------------------

## Generación de periodo

``` text
Sistema
      ↓
Genera periodo semanal
      ↓
Estado:
BORRADOR
      ↓
Gestor revisa
      ↓
Modifica festivos o excepciones
      ↓
Configura ventana de generación
      ↓
Abre periodo
```

------------------------------------------------------------------------

## Generación de ficha

``` text
Colaborador
      ↓
Escanea gafete
      ↓
Sistema valida:
- QR
- Gafete activo
- Persona activa
- Periodo
- Ventana de generación
      ↓
Selecciona días
      ↓
Sistema calcula total
      ↓
Genera ficha
      ↓
Estado:
PENDIENTE
```

------------------------------------------------------------------------

## Confirmación de pago

``` text
Colaborador
      ↓
Presenta ficha
      ↓
Cobrador escanea folio
      ↓
Sistema muestra ficha
      ↓
Cobrador modifica días si es necesario
      ↓
Sistema recalcula total
      ↓
Cobrador recibe dinero físico
      ↓
Ingresa monto recibido
      ↓
Sistema calcula:
- Validación
- Cambio
      ↓
Confirma pago
      ↓
Ficha:
PAGADA
      ↓
Se generan derechos diarios
      ↓
Se imprime/genera ticket final
```

------------------------------------------------------------------------

## Cierre y consolidación

``` text
Termina ventana de pago
      ↓
No se aceptan nuevas fichas
      ↓
Fichas pendientes
      ↓
VENCIDAS
      ↓
Gestor genera reporte
      ↓
Se consideran únicamente:
PAGOS CONFIRMADOS
```

------------------------------------------------------------------------

## Consumo

``` text
Colaborador llega al comedor
      ↓
Escanea gafete
      ↓
Sistema identifica persona
      ↓
Busca derecho del día
      ↓
¿Existe y está disponible?
      │
      ├── No → Rechazar
      │
      └── Sí
            ↓
        Permitir consumo
            ↓
        Marcar como UTILIZADO
```

------------------------------------------------------------------------

# 23. Reglas de seguridad

La aplicación deberá aplicar controles de seguridad en varios niveles:

## Identidad

-   QR único por gafete.
-   Gafete activo.
-   Persona activa.
-   Número de empleado único.

## Autorización

-   Un usuario tiene un único rol.
-   El rol determina los permisos.
-   Los permisos se validan en backend.

## Operaciones

-   No permitir duplicidad de fichas.
-   No permitir pagos menores al total.
-   No permitir consumo sin derecho válido.
-   No permitir consumo duplicado en el mismo día.
-   No permitir utilizar gafetes desactivados.

## Historial

-   No eliminar físicamente personas con historial.
-   No eliminar gafetes históricos.
-   No eliminar departamentos con historial.
-   Auditar acciones relevantes.

------------------------------------------------------------------------

# 24. Requerimientos de extensibilidad

La arquitectura deberá permitir ampliar el sistema posteriormente.

Se deberá evitar diseñar reglas rígidas que dificulten:

-   Agregar nuevos módulos.
-   Agregar nuevos permisos.
-   Agregar nuevos roles.
-   Agregar nuevos tipos de reportes.
-   Agregar nuevas configuraciones de tarifas.
-   Agregar nuevos estados controlados.
-   Agregar nuevos dispositivos de identificación.

El sistema deberá mantener una separación clara entre:

``` text
Identidad
Autorización
Periodos
Operaciones de pago
Derechos de consumo
Consumo
Reportes
Auditoría
```

------------------------------------------------------------------------

# 25. Decisiones de negocio consolidadas

1.  El pago se realiza físicamente.
2.  El sistema no procesará pagos digitales.
3.  Los colaboradores se identifican mediante gafete con QR.
4.  El número de empleado es único y nunca se reasigna.
5.  Las bajas se realizan mediante desactivación lógica.
6.  Una persona inactiva pierde todas sus capacidades operativas.
7.  Toda persona registrada puede utilizar el beneficio del comedor.
8.  La cuenta de sistema es opcional.
9.  Un usuario solo puede tener un rol.
10. Los roles pueden crearse y configurarse dinámicamente.
11. Los permisos son granulares.
12. Los permisos se validan en backend.
13. Los departamentos son un catálogo independiente.
14. Los departamentos con historial no se eliminan físicamente.
15. Los gafetes tienen un formato único.
16. Un nuevo gafete desactiva el anterior.
17. Los gafetes anteriores se conservan históricamente.
18. Los periodos se generan automáticamente.
19. Los periodos inicialmente se crean como borrador.
20. Los periodos normalmente cubren lunes a viernes.
21. Los días festivos pueden marcarse como no disponibles.
22. Los días no disponibles no se cobran ni generan derechos.
23. El precio es por día.
24. El precio es general para todos.
25. El precio aplicado se conserva históricamente.
26. La ventana de generación se configura mediante fecha inicial y fecha
    final.
27. Una misma fecha en ambos campos representa una ventana de un solo
    día.
28. No se requiere configurar horas.
29. La ventana debe estar dentro del periodo de servicio.
30. Las fichas se generan mediante kiosco.
31. El kiosco debe estar aislado de la administración.
32. La ficha inicialmente queda pendiente.
33. El cobrador puede editar días antes de confirmar el pago.
34. El sistema valida el monto recibido como un POS.
35. Los pagos menores se rechazan.
36. Los pagos mayores calculan cambio.
37. El pago confirmado genera derechos de consumo.
38. Las fichas pendientes al cierre vencen.
39. Las fichas vencidas no cuentan para porciones.
40. Cada día pagado es un derecho independiente.
41. Un derecho utilizado no puede volver a utilizarse.
42. Un derecho no utilizado no se transfiere.
43. Un derecho no utilizado vence.
44. El consumo se valida mediante QR.
45. El reporte se genera explícitamente después del cierre.
46. El reporte se basa únicamente en pagos confirmados.
47. Las operaciones relevantes deben auditarse.
48. El diseño final de reportes se definirá después de completar todos
    los campos.

------------------------------------------------------------------------

# 26. Siguiente etapa de análisis

Antes de iniciar el desarrollo, todavía será necesario convertir esta
especificación funcional en:

1.  Modelo de dominio.
2.  Modelo entidad-relación.
3.  Catálogo completo de estados.
4.  Catálogo completo de permisos.
5.  Reglas de transición de estados.
6.  Casos de uso.
7.  Flujos de usuario.
8.  Requerimientos funcionales detallados.
9.  Requerimientos no funcionales.
10. Arquitectura técnica.
11. Diseño de API.
12. Diseño de base de datos.
13. Estrategia de auditoría.
14. Estrategia de seguridad.
15. Diseño del kiosco.
16. Diseño de impresión de gafetes.
17. Diseño de tickets.
18. Estrategia de pruebas.
19. Plan de despliegue.
20. Plan de respaldo y recuperación.
