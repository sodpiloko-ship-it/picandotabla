<?php
declare(strict_types=1);

// ¿Está encendido el pago en línea? La ficha lo consulta para mostrar o no el botón de Mercado Pago.
// Solo responde sí/no: nunca dice dónde está la config ni de qué cuenta es.
require __DIR__ . '/lib.php';

ptpg_json(200, ['activo' => ptpg_activo() && ptpg_catalogo() !== null]);
