<?php
declare(strict_types=1);

// Pago con monto libre (/pagar/): el cliente escribe concepto y cantidad, y se le genera la liga de Checkout Pro.
// Sirve para cotizaciones de eventos, anticipos o saldos acordados por WhatsApp. El pedido entra al mismo
// circuito que pedido.php: folio, comanda, aviso de pedido nuevo y verificación del pago en webhook.php.
require __DIR__ . '/lib.php';

const PTPG_LIBRE_MIN = 50;
const PTPG_LIBRE_MAX = 50000;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  ptpg_json(405, ['ok' => false, 'error' => 'metodo']);
  exit;
}
if (!ptpg_activo()) {
  ptpg_log('sin_config');
  ptpg_json(503, ['ok' => false, 'error' => 'El pago en línea no está disponible ahora. Escríbenos por WhatsApp.']);
  exit;
}

$raw = (string)file_get_contents('php://input', false, null, 0, 8192);
$d = json_decode($raw, true);
if (!is_array($d)) {
  ptpg_json(400, ['ok' => false, 'error' => 'Datos incompletos.']);
  exit;
}
// Campo trampa: los bots lo llenan, las personas no lo ven.
if (is_string($d['sitio'] ?? null) && $d['sitio'] !== '') {
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
$concepto = $txt('concepto', 120);
$zona = $txt('zona', 160);
$notas = $txt('notas', 600);
$fecha = $txt('fecha', 10);
$monto = is_numeric($d['monto'] ?? null) ? round((float)$d['monto'], 2) : 0.0;

$digitos = preg_replace('/\D+/', '', $whatsapp) ?? '';
$tz = new DateTimeZone(PTPG_TZ);
$dia = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha, $tz);
$error = '';
if ($nombre === '') $error = 'Escribe tu nombre.';
elseif (strlen($digitos) < 10 || strlen($digitos) > 15) $error = 'Revisa tu WhatsApp (10 dígitos).';
elseif ($concepto === '') $error = 'Escribe el concepto del pago.';
elseif ($monto < PTPG_LIBRE_MIN || $monto > PTPG_LIBRE_MAX) $error = 'La cantidad debe ser de ' . ptpg_dinero(PTPG_LIBRE_MIN) . ' a ' . ptpg_dinero(PTPG_LIBRE_MAX) . '.';
elseif ($dia === false || $dia->format('Y-m-d') !== $fecha || $dia < ptpg_hoy() || $dia > ptpg_hoy()->modify('+1 year')) $error = 'Elige la fecha de entrega o del evento.';
if ($error !== '') {
  ptpg_json(400, ['ok' => false, 'error' => $error]);
  exit;
}

$cat = ptpg_catalogo();
$meses = max(1, (int)($cat['logistics']['payment']['full']['installments_max'] ?? 1));
$folio = ptpg_nuevo_folio();
$titulo = 'Pago: ' . $concepto;
$pedido = [
  'producto' => 'pago-libre',
  'titulo' => $titulo,
  'lineas' => [['id' => 'picandotabla:pago-libre', 'titulo' => $titulo, 'precio' => $monto]],
  'total' => $monto,
  'cobro' => $monto,
  'tipo' => 'total',
  'porcentaje' => 100,
  'liquidar_dias_antes' => 0,
  'meses_max' => $meses,
  'fecha' => $fecha,
  'folio' => $folio,
  'estado' => 'creado',
  'moneda' => PTPG_MONEDA,
  'cliente' => ['nombre' => $nombre, 'whatsapp' => $whatsapp, 'zona' => $zona, 'notas' => $notas],
  'creado' => gmdate('c'),
  'utm' => [],
  'avisos' => [],
];
if (!ptpg_guardar_pedido($pedido)) {
  ptpg_log('sin_almacen', ['folio' => $folio]);
  ptpg_json(503, ['ok' => false, 'error' => 'No pudimos preparar tu pago. Escríbenos por WhatsApp.']);
  exit;
}
ptpg_aviso_nuevo($pedido);

$rec = [
  'at' => (new DateTimeImmutable('now', $tz))->format('c'),
  'nombre' => $nombre, 'whatsapp' => $whatsapp, 'zona' => $zona,
  'fecha' => ptpg_fecha_larga($fecha), 'total' => $monto,
  'items' => ptpg_renglones($pedido), 'notas' => $notas,
  'origen' => 'pago_libre', 'folio' => $folio,
];
$dataDir = dirname(__DIR__) . '/data';
@mkdir($dataDir, 0755, true);
if (!is_file($dataDir . '/.htaccess')) @file_put_contents($dataDir . '/.htaccess', "Require all denied\nDeny from all\n");
@file_put_contents($dataDir . '/orders.jsonl', json_encode($rec, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);

$base = ptpg_base_url();
// La liga vence en 3 días o la víspera de la fecha, lo que ocurra primero (igual que pedido.php).
$vence = max(
  new DateTimeImmutable('+2 hours', $tz),
  min(new DateTimeImmutable('+3 days', $tz), $dia->setTime(20, 0)->modify('-1 day'))
);
$preferencia = [
  'items' => [[
    'id' => 'pago-libre',
    'title' => $titulo,
    'description' => 'Folio ' . $folio . ' · ' . ptpg_fecha_larga($fecha),
    'quantity' => 1,
    'currency_id' => PTPG_MONEDA,
    'unit_price' => $monto,
  ]],
  'payer' => ['name' => $nombre, 'phone' => ['area_code' => '', 'number' => $digitos]],
  'external_reference' => $folio,
  'back_urls' => [
    'success' => $base . '/pago/gracias.php',
    'pending' => $base . '/pago/gracias.php',
    'failure' => $base . '/pago/gracias.php',
  ],
  'auto_return' => 'approved',
  'notification_url' => $base . '/pago/webhook.php',
  'statement_descriptor' => 'PICANDOTABLA',
  // Tarjeta de crédito: hasta los mismos meses (con intereses a cargo del cliente) que el pago completo de la tienda.
  'payment_methods' => ['installments' => $meses],
  'date_of_expiration' => $vence->format('Y-m-d\TH:i:s.vP'),
  'metadata' => ['folio' => $folio, 'tabla' => 'pago-libre', 'tipo' => 'libre',
    'nombre' => $nombre, 'whatsapp' => $digitos, 'zona' => $zona, 'fecha' => $fecha],
];

list($status, $respuesta) = ptpg_mp('POST', '/checkout/preferences', $preferencia);
if ($status < 200 || $status >= 300 || !is_array($respuesta) || !is_string($respuesta['init_point'] ?? null)) {
  ptpg_log('preferencia_error', ['folio' => $folio, 'status' => $status,
    'mp' => is_array($respuesta) ? substr((string)($respuesta['message'] ?? ''), 0, 200) : '']);
  ptpg_json(502, ['ok' => false, 'error' => 'Mercado Pago no respondió. Intenta de nuevo o escríbenos por WhatsApp.', 'folio' => $folio]);
  exit;
}

$pedido['preferencia_id'] = (string)($respuesta['id'] ?? '');
ptpg_guardar_pedido($pedido);
$cfg = ptpg_config();
$url = !empty($cfg['sandbox']) && is_string($respuesta['sandbox_init_point'] ?? null)
  ? $respuesta['sandbox_init_point']
  : $respuesta['init_point'];
ptpg_log('pago_libre', ['folio' => $folio, 'cobro' => $monto]);
ptpg_json(200, ['ok' => true, 'url' => $url, 'folio' => $folio, 'cobro' => $monto, 'vence' => $vence->format('c')]);
