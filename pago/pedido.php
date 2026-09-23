<?php
declare(strict_types=1);

// Crea el pedido con folio y la preferencia de Checkout Pro; responde {ok, url} para mandar al cliente a pagar.
// El precio sale de ptpg_cotizar (catálogo del servidor): lo que mande el navegador como total se ignora.
require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  ptpg_json(405, ['ok' => false, 'error' => 'metodo']);
  exit;
}
if (!ptpg_activo()) {
  ptpg_log('sin_config');
  ptpg_json(503, ['ok' => false, 'error' => 'El pago en línea no está disponible ahora. Pídela por WhatsApp.']);
  exit;
}

$raw = (string)file_get_contents('php://input', false, null, 0, 16384);
$d = json_decode($raw, true);
if (!is_array($d)) {
  ptpg_json(400, ['ok' => false, 'error' => 'Datos incompletos.']);
  exit;
}
$txt = function (string $k, int $max) use ($d): string {
  $v = is_string($d[$k] ?? null) ? $d[$k] : '';
  $v = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($v)) ?? '');
  return function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
};
$nombre = $txt('nombre', 120);
$whatsapp = $txt('whatsapp', 40);
$zona = $txt('zona', 160);
$notas = $txt('notas', 600);
$fecha = $txt('fecha', 10);
$clave = $txt('tabla', 40);
$premium = ($d['premium'] ?? false) === true;
$modo = ($d['modo'] ?? 'completo') === 'anticipo' ? 'anticipo' : 'completo';
$extras = array_values(array_filter(is_array($d['extras'] ?? null) ? $d['extras'] : [], 'is_string'));

$digitos = preg_replace('/\D+/', '', $whatsapp) ?? '';
if ($nombre === '' || $zona === '' || strlen($digitos) < 10 || strlen($digitos) > 15) {
  ptpg_json(400, ['ok' => false, 'error' => $nombre === '' ? 'Escribe tu nombre.' : ($zona === '' ? 'Escribe tu colonia.' : 'Revisa tu WhatsApp (10 dígitos).')]);
  exit;
}
$cot = ptpg_cotizar($clave, $premium, array_slice($extras, 0, 5), $fecha, null, $modo);
if (isset($cot['error'])) {
  ptpg_json(400, ['ok' => false, 'error' => $cot['error']]);
  exit;
}

$utm = [];
foreach ((array)($d['utm'] ?? []) as $k => $v) {
  if (in_array($k, ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid', 'gclid'], true)
      && is_string($v) && preg_match('/\A[\w.\-|]{1,120}\z/', $v) === 1) $utm[$k] = $v;
}

$folio = ptpg_nuevo_folio();
$pedido = $cot + [
  'folio' => $folio,
  'estado' => 'creado',
  'moneda' => PTPG_MONEDA,
  'cliente' => ['nombre' => $nombre, 'whatsapp' => $whatsapp, 'zona' => $zona, 'notas' => $notas],
  'creado' => gmdate('c'),
  'utm' => $utm,
  'avisos' => [],
];
if (!ptpg_guardar_pedido($pedido)) {
  ptpg_log('sin_almacen', ['folio' => $folio]);
  ptpg_json(503, ['ok' => false, 'error' => 'No pudimos preparar tu pedido. Pídela por WhatsApp.']);
  exit;
}

// La comanda de Jessica lee data/orders.jsonl: el pedido aparece ahí con su folio y el estado del pago.
$rec = [
  'at' => (new DateTimeImmutable('now', new DateTimeZone(PTPG_TZ)))->format('c'),
  'nombre' => $nombre, 'whatsapp' => $whatsapp, 'zona' => $zona,
  'fecha' => ptpg_fecha_larga($cot['fecha']), 'total' => $cot['total'],
  'items' => ptpg_renglones($pedido), 'notas' => $notas,
  'origen' => 'pago_en_linea', 'folio' => $folio,
];
$dataDir = dirname(__DIR__) . '/data';
@mkdir($dataDir, 0755, true);
if (!is_file($dataDir . '/.htaccess')) @file_put_contents($dataDir . '/.htaccess', "Require all denied\nDeny from all\n");
@file_put_contents($dataDir . '/orders.jsonl', json_encode($rec, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);

$base = ptpg_base_url();
if ($cot['tipo'] === 'anticipo') {
  $items = [[
    'id' => $cot['producto'] . '-anticipo',
    'title' => 'Anticipo ' . $cot['porcentaje'] . ' % · ' . $cot['titulo'],
    'description' => 'Pedido ' . $folio . ' · entrega ' . ptpg_fecha_larga($cot['fecha']),
    'quantity' => 1,
    'currency_id' => PTPG_MONEDA,
    'unit_price' => $cot['cobro'],
  ]];
} else {
  $items = [];
  foreach ($cot['lineas'] as $l) {
    if ($l['precio'] <= 0) continue;
    $items[] = ['id' => $l['id'], 'title' => $l['titulo'], 'quantity' => 1, 'currency_id' => PTPG_MONEDA, 'unit_price' => $l['precio']];
  }
}
// Los pagos en efectivo (OXXO) vencen antes de la entrega; si alguien paga tarde, Mercado Pago le devuelve el dinero.
$tz = new DateTimeZone(PTPG_TZ);
$vence = min(
  new DateTimeImmutable('+3 days', $tz),
  DateTimeImmutable::createFromFormat('!Y-m-d H:i', $cot['fecha'] . ' 20:00', $tz)->modify('-1 day')
);
$preferencia = [
  'items' => $items,
  'payer' => ['name' => $nombre],
  'external_reference' => $folio,
  'back_urls' => [
    'success' => $base . '/pago/gracias.php',
    'pending' => $base . '/pago/gracias.php',
    'failure' => $base . '/pago/gracias.php',
  ],
  'auto_return' => 'approved',
  'notification_url' => $base . '/pago/webhook.php',
  'statement_descriptor' => 'PICANDOTABLA',
  // Completo: meses con intereses a cargo del cliente (Mercado Pago los ofrece con tarjeta de crédito). Anticipo: una exhibición.
  'payment_methods' => ['installments' => $cot['meses_max']],
  'date_of_expiration' => $vence->format('Y-m-d\TH:i:s.vP'),
  'metadata' => ['folio' => $folio, 'tabla' => $cot['producto'], 'tipo' => $cot['tipo']],
];

list($status, $respuesta) = ptpg_mp('POST', '/checkout/preferences', $preferencia);
if ($status < 200 || $status >= 300 || !is_array($respuesta) || !is_string($respuesta['init_point'] ?? null)) {
  ptpg_log('preferencia_error', ['folio' => $folio, 'status' => $status,
    'mp' => is_array($respuesta) ? substr((string)($respuesta['message'] ?? ''), 0, 200) : '']);
  ptpg_json(502, ['ok' => false, 'error' => 'Mercado Pago no respondió. Intenta de nuevo o pídela por WhatsApp.', 'folio' => $folio]);
  exit;
}

$pedido['preferencia_id'] = (string)($respuesta['id'] ?? '');
ptpg_guardar_pedido($pedido);
$cfg = ptpg_config();
$url = !empty($cfg['sandbox']) && is_string($respuesta['sandbox_init_point'] ?? null)
  ? $respuesta['sandbox_init_point']
  : $respuesta['init_point'];
ptpg_log('pedido', ['folio' => $folio, 'tipo' => $cot['tipo'], 'cobro' => $cot['cobro']]);
ptpg_json(200, ['ok' => true, 'url' => $url, 'folio' => $folio, 'cobro' => $cot['cobro'], 'tipo' => $cot['tipo']]);
