<?php
declare(strict_types=1);

// ¿Está encendido el pago en línea? La ficha lo consulta para mostrar o no el botón de Mercado Pago.
// Si está apagado dice por qué (para diagnosticar la subida por hPanel), sin rutas ni datos de la cuenta.
require __DIR__ . '/lib.php';

$motivo = '';
if (!PTPG_ACTIVO) $motivo = 'apagado';
elseif (ptpg_private_root() === false) $motivo = 'sin_carpeta';      // no hay picandotabla-private junto a public_html
elseif (ptpg_config() === false) $motivo = 'sin_config';             // la carpeta está, pero sin mercadopago.php válido
elseif (ptpg_catalogo() === null) $motivo = 'sin_catalogo';
$respuesta = ['activo' => $motivo === ''];
if ($motivo !== '') {
  $padre = basename(dirname((string)@realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''))));
  $respuesta['motivo'] = $motivo;
  // Solo si es un nombre de dominio (domains/<dominio>/public_html); nunca el usuario de la cuenta.
  if (preg_match('/\A[a-z0-9-]+(\.[a-z0-9-]+)+\z/i', $padre) === 1) $respuesta['public_html_esta_en'] = $padre;
}
ptpg_json(200, $respuesta);
