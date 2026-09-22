# Plantilla del gafete — requisitos del diseño

> Qué debe cumplir el archivo SVG del gafete (§4.1) para que el sistema lo acepte y coloque encima
> la fotografía, el nombre, el departamento, el número de empleado y el código QR de cada persona.
>
> Está dirigido a quien diseña. Las reglas de aquí son las que comprueba `App\Support\PlantillaGafete`;
> si una cambia en el código, hay que cambiarla también en este documento.

**Formato SVG · 54 × 85.6 mm vertical · máx. 1 MB · solo frente · 5 zonas obligatorias**

---

## 1. El lienzo

Tamaño de credencial CR80 en vertical: **54 mm de ancho por 85.6 mm de alto**. El archivo puede
estar en milímetros o en píxeles; lo que se revisa es la proporción, con una tolerancia del 1 % para
lo que redondean los programas al exportar. Un diseño en horizontal se rechaza.

El gafete se imprime a tamaño real, nueve por hoja, y se recorta con tijeras. Conviene dejar
respirar al menos 2 mm los elementos que no deben perderse en el corte; el color de fondo sí puede
llegar hasta la orilla.

---

## 2. Las cinco zonas

Los datos no van dibujados: se marcan con cinco rectángulos que llevan un **id** exacto. El sistema
lee su posición, los borra del dibujo y pone encima el dato real. Nunca se ven en el gafete
impreso, así que pueden ir de cualquier color o incluso invisibles.

| id del rectángulo   | Qué recibe                | x, y · ancho × alto (mm) |
|---------------------|---------------------------|--------------------------|
| `zona-foto`         | Fotografía de la persona  | 17, 10 · 20 × 20         |
| `zona-numero`       | Número de empleado        | 3, 36 · 48 × 4           |
| `zona-nombre`       | Nombre y primer apellido  | 3, 40.3 · 48 × 8         |
| `zona-departamento` | Departamento              | 3, 48.6 · 48 × 4         |
| `zona-qr`           | Código QR del gafete      | 16, 59.6 · 22 × 22       |

Son las medidas de la plantilla que se descarga desde la pantalla *Diseño del gafete*
(`resources/gafetes/plantilla-gafete.svg`). Se pueden mover y redimensionar con libertad: lo único
fijo son los cinco id y las reglas de abajo.

---

## 3. Reglas de las zonas

| Regla | Por qué |
|---|---|
| Cada zona aparece **una sola vez**, con su id exacto | Si el id está repetido, no se sabe cuál manda |
| Se marca con un **rectángulo**; si el editor lo guarda como trazo, también sirve | Los editores convierten en trazo los rectángulos con esquinas redondeadas. Se usa el rectángulo que encierra la figura |
| Sin **rotar ni inclinar** | Los datos se colocan derechos; una zona girada no cuadraría con el texto |
| Completamente **dentro del gafete** | Lo que se sale del lienzo no se imprime (se admiten 0.5 mm de redondeo) |
| `zona-qr` de **20 × 20 mm** como mínimo | Más chico, los lectores del kiosco y del comedor fallan |
| El **relleno** de la zona es el color de las letras | Se pinta del color que se quieren las letras; sin relleno salen en negro |
| Las **esquinas redondeadas** de `zona-foto` se respetan | La fotografía se recorta con ese mismo redondeo. Solo se conservan si la zona sigue siendo un rectángulo: un trazo ya no las declara |
| El **alto** de la zona manda el tamaño de la letra | Una zona de 4 mm da letras de ~3.5 mm (10 pt). Si el texto no cabe, se achica solo hasta un 45 % |

`zona-nombre` admite dos líneas; las otras tres, una. La fotografía se toma en proporción 3:4 y se
recorta para llenar su zona, así que una zona muy apaisada corta bastante de la cara.

---

## 4. Qué dibuja el diseño y qué pone el sistema

**Lo pone el sistema, no se dibuja:**

- Los cinco datos, cada uno en su zona.
- La línea de corte del contorno y las esquinas redondeadas.
- El fondo blanco del QR y su margen, dentro de `zona-qr`.
- Las iniciales de la persona cuando no tiene fotografía.

**Lo aporta el diseño:**

- Fondo, franjas, colores y logotipo.
- Textos fijos: nombre de la empresa, leyendas, vigencia.
- El marco de la fotografía, si lleva.
- Etiquetas como «No.» o «Departamento», si se quieren.

---

## 5. Qué acepta el archivo

El SVG se limpia al subirlo, porque un archivo de estos puede traer código que se ejecuta en la
sesión de quien lo abra. Lo que se quita no impide que el diseño se use: desaparece del dibujo y
queda avisado en la vista previa.

**Se conserva:**

- Figuras, trazos, grupos, máscaras y recortes.
- Degradados, patrones y filtros (sombras, desenfoques).
- Imágenes **incrustadas** en PNG, JPG o WebP.
- Tipografías incrustadas en el propio archivo.
- Textos, aunque conviene convertirlos a curvas.

**Se quita:**

- Scripts, animaciones y enlaces.
- Imágenes, tipografías u hojas de estilo **enlazadas** a otro sitio.
- Cualquier referencia fuera del archivo.
- Metadatos y capas del programa de diseño.

> **Las tipografías son el descuido más común.** Si un texto fijo queda como texto y la computadora
> que imprime no tiene esa tipografía, el gafete sale con otra. Hay que convertirlos a curvas, o
> incrustar la tipografía en el archivo. El sistema avisa cuando encuentra textos sin convertir,
> pero no puede arreglarlo.

El logotipo va incrustado, no enlazado (en Illustrator, *Imágenes: incrustar*; en Figma sale así de
forma normal). El color va en RGB: la impresión sale del navegador, no de una prensa.

---

## 6. Cómo se ponen los id

| Programa | Dónde se escribe el id |
|---|---|
| Illustrator | Nombrar el objeto en el panel *Capas*; al exportar el SVG, elegir *Nombres de objeto* como id |
| Figma | Nombrar la capa del rectángulo; el nombre viaja como id al exportar |
| Inkscape | *Propiedades del objeto* (Ctrl + Mayús + O), campo *ID* |

Lo más seguro es partir de la plantilla que entrega el sistema: ya trae los cinco rectángulos con su
id puesto. Conviene moverlos y redimensionarlos en lugar de crearlos de cero, y no duplicarlos.

---

## 7. Si el archivo se rechaza

| Mensaje | Qué lo provoca |
|---|---|
| Falta la zona «zona-qr» | No hay rectángulo con ese id, o el id quedó escrito distinto |
| La zona «zona-nombre» debe ser un rectángulo (o el trazo de uno) | El id quedó en un texto, un grupo o una imagen |
| La zona «zona-foto» está girada o inclinada | El rectángulo, o el grupo que lo contiene, tiene una rotación |
| La zona del QR mide 15 × 15 mm | Quedó por debajo de los 20 × 20 mm |
| El diseño debe tener la proporción del gafete | La mesa de trabajo no es 54 × 85.6, o quedó en horizontal |
| El diseño no debe pesar más de 1 MB | Casi siempre, una fotografía incrustada sin reducir |

---

## 8. Antes de entregar

- [ ] La mesa de trabajo mide 54 × 85.6 mm, en vertical.
- [ ] Están los cinco rectángulos, con su id exacto y una sola vez.
- [ ] Ninguna zona está girada ni se sale del gafete.
- [ ] `zona-qr` mide 20 mm o más y tiene fondo claro debajo.
- [ ] Las zonas de texto están pintadas del color que deben tener las letras.
- [ ] Los textos fijos están convertidos a curvas.
- [ ] El logotipo va incrustado, no enlazado, y el color es RGB.
- [ ] Nada importante queda a menos de 2 mm de la orilla.
- [ ] El archivo es `.svg` y pesa menos de 1 MB.

---

## 9. Cómo se pone en uso

1. En *Diseño del gafete*, descargar la plantilla y trabajar sobre ella.
2. Subir el SVG. Queda como borrador: todavía no cambia nada.
3. Revisar la vista previa, que muestra el gafete con un nombre corto y uno largo, y los avisos de
   lo que se quitó.
4. Activarlo. Desde ese momento todos los gafetes se ven e imprimen así.
5. Imprimir uno de prueba y cortarlo antes de mandar a hacer el lote completo.

Los diseños anteriores quedan en el historial: si algo no convence, se vuelve al anterior o al
predeterminado en un clic. Los gafetes ya entregados siguen funcionando, porque el código QR no
depende del diseño.

Subir y activar diseños requiere el permiso `gafetes.disenar`.
