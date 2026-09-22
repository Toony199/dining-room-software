<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * El SVG subido no sirve como diseño de gafete. El mensaje va dirigido a quien lo subió: dice qué
 * falta o qué corregir en el editor de diseño.
 */
class PlantillaGafeteInvalida extends InvalidArgumentException {}
