<?php
declare(strict_types=1);

// Regreso desde Mercado Pago (back_urls). El pedido solo se da por pagado si el pago se verifica contra la API:
// los parámetros de la URL (status, external_reference) no bastan.
require __DIR__ . '/lib.php';

$pagoId = (string)($_GET['payment_id'] ?? $_GET['collection_id'] ?? '');
$estado = 'desconocido';
$pedido = null;
$verificado = false;

if (preg_match(PTPG_PAGO_RE, $pagoId) === 1) {
  $r = ptpg_procesar_pago($pagoId);
  if (isset($r['pedido'])) {
    $pedido = $r['pedido'];
    $estado = (string)$pedido['estado'];
    $verificado = true;
  } elseif (($r['error'] ?? '') === 'api') {
    $estado = 'verificando';
  }
} elseif (preg_match(PTPG_FOLIO_RE, (string)($_GET['external_reference'] ?? '')) === 1) {
  $pedido = ptpg_leer_pedido((string)$_GET['external_reference']);
  $estado = $pedido ? (string)$pedido['estado'] : 'desconocido';
}

$folio = $pedido ? (string)$pedido['folio'] : '';
$primerNombre = $pedido ? explode(' ', trim((string)($pedido['cliente']['nombre'] ?? '')))[0] : '';
$waTexto = $folio !== ''
  ? '¡Hola! Tengo el pedido ' . $folio . ' (' . ($pedido['titulo'] ?? '') . ') para el ' . ptpg_fecha_larga((string)$pedido['fecha']) . '.'
  : '¡Hola! Tengo una duda con mi pago en Picando Tabla.';
$wa = 'https://wa.me/' . PTPG_WA . '?text=' . rawurlencode($waTexto);
$btnWa = '<a class="btn" href="' . ptpg_h($wa) . '" target="_blank" rel="noopener"><span class="dot"></span>Escríbenos por WhatsApp</a>';

$html = '';
if ($estado === 'pagado' && $verificado) {
  $anticipo = ($pedido['tipo'] ?? '') === 'anticipo';
  $html = '<p class="eyebrow">Pago recibido</p>'
    . '<h1>¡Listo' . ($primerNombre !== '' ? ', ' . ptpg_h($primerNombre) : '') . '! ' . ($anticipo ? 'Tu fecha quedó apartada.' : 'Tu tabla está pagada.') . '</h1>'
    . '<p>Te escribimos por WhatsApp para confirmar la hora de entrega. Si no tuviéramos disponibilidad para tu fecha, '
    . 'te proponemos otra o te devolvemos tu pago completo.</p>'
    . '<div class="box"><div class="lbl">Tu pedido · ' . ptpg_h($folio) . '</div>'
    . implode('<br>', array_map('ptpg_h', ptpg_renglones($pedido)))
    . '<hr><b>' . ptpg_h(ptpg_resumen_cobro($pedido)) . '</b><br>'
    . 'Entrega: ' . ptpg_h(ptpg_fecha_larga((string)$pedido['fecha'])) . '</div>'
    . (!empty($pedido['correo']) ? '<p class="muted">Te mandamos el comprobante a ' . ptpg_h($pedido['correo']) . '.</p>' : '')
    . $btnWa
    . '<script>try{var k="ptpg_compra_' . ptpg_h($folio) . '";if(!localStorage.getItem(k)){'
    . 'gtag("event","purchase",{transaction_id:"' . ptpg_h($folio) . '",value:' . (float)$pedido['cobro'] . ',currency:"MXN",'
    . 'items:[{item_id:"' . ptpg_h($pedido['producto']) . '",item_name:"' . ptpg_h($pedido['titulo']) . '",price:' . (float)$pedido['cobro'] . '}]});'
    . 'if(window.fbq)fbq("track","Purchase",{value:' . (float)$pedido['cobro'] . ',currency:"MXN",content_ids:["' . ptpg_h($pedido['producto']) . '"],content_type:"product"},{eventID:"' . ptpg_h($folio) . '"});'
    . 'localStorage.setItem(k,"1");}}catch(e){}</script>';
} elseif ($estado === 'pagado') {
  $html = '<h1>Tu pedido está pagado.</h1><p>Te escribimos por WhatsApp para confirmar la entrega.</p>' . $btnWa;
} elseif ($estado === 'pendiente') {
  $html = '<p class="eyebrow">Pago en proceso</p><h1>Ya casi.</h1>'
    . '<p>Si elegiste OXXO o transferencia, Mercado Pago tarda un poco en acreditarlo. Paga tu ficha antes de que venza: '
    . 'en cuanto se refleje te escribimos por WhatsApp para confirmar tu entrega.</p>'
    . ($folio !== '' ? '<p class="muted">Folio: ' . ptpg_h($folio) . '</p>' : '') . $btnWa;
} elseif ($estado === 'verificando') {
  $html = '<h1>Estamos confirmando tu pago.</h1><p>Recarga esta página en un par de minutos. Si ya pasó un rato, escríbenos.</p>' . $btnWa;
} elseif ($estado === 'reembolsado') {
  $html = '<h1>Este pago fue devuelto.</h1><p>Si crees que es un error, escríbenos.</p>' . $btnWa;
} elseif ($estado === 'rechazado' || $estado === 'creado') {
  $otra = '/?tabla=' . rawurlencode((string)($pedido['producto'] ?? ''));
  $html = '<p class="eyebrow">Pago no completado</p><h1>El pago no se completó.</h1>'
    . '<p>No se hizo ningún cargo. Puedes intentarlo otra vez con otra tarjeta, en OXXO o por transferencia, o pedirla por WhatsApp.</p>'
    . '<a class="btn btn-dark" href="' . ptpg_h($otra) . '">Intentar de nuevo</a> ' . $btnWa;
} else {
  $html = '<h1>No encontramos este pago.</h1><p>Si ya pagaste, escríbenos con tu comprobante y lo revisamos.</p>' . $btnWa;
}

ptpg_security_headers();
header('Content-Type: text/html; charset=UTF-8');
?><!DOCTYPE html>
<html lang="es-MX">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Tu pedido | Picando Tabla</title>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<script async src="https://www.googletagmanager.com/gtag/js?id=G-4BMCS7P6DQ"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','G-4BMCS7P6DQ');</script>
<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','1537185354529246');fbq('track','PageView');</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Lora:wght@500;600&family=Work+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box}
  body{margin:0;background:#e9eaea;color:#26282a;font-family:"Work Sans",system-ui,sans-serif;font-size:16px;line-height:1.6}
  .wrap{max-width:600px;margin:0 auto;padding:36px 16px 64px}
  .logo{font-family:Lora,serif;font-weight:600;font-size:20px;color:#26282a;text-decoration:none;display:inline-block;margin-bottom:32px}
  .eyebrow{font-size:11px;letter-spacing:1.5px;color:#7c2d3e;font-weight:600;text-transform:uppercase;margin:0 0 8px}
  h1{font-family:Lora,serif;font-weight:600;font-size:30px;line-height:1.2;margin:0 0 14px}
  .box{background:#fbfcfc;border:1px solid #d3d5d4;border-radius:14px;padding:18px 20px;margin:20px 0;font-size:14.5px;color:#55585a}
  .box .lbl{font-size:12px;letter-spacing:1.2px;color:#75797a;font-weight:600;text-transform:uppercase;margin-bottom:8px}
  .box hr{border:none;border-top:1px solid #d3d5d4;margin:12px 0}
  .box b{color:#26282a}
  .btn{display:inline-flex;align-items:center;gap:10px;background:#fbfcfc;color:#26282a;border:1.5px solid #26282a;padding:13px 22px;border-radius:999px;font-weight:600;text-decoration:none;margin:8px 8px 8px 0}
  .btn-dark{background:#26282a;color:#fff}
  .dot{width:14px;height:14px;border-radius:50%;background:#25D366;display:inline-block}
  .muted{color:#75797a;font-size:14px}
</style>
</head>
<body><div class="wrap">
<a class="logo" href="/">Picando Tabla</a>
<?= $html ?>
</div></body>
</html>
