<?php
// Solicitud de EVENTO de Picando Tabla (/eventos/). La llama el formulario por fetch.
// Guarda la solicitud (PII en data/, gitignored + .htaccess), AVISA por TELEGRAM y manda correo.
// SEGURIDAD: el token de Telegram NUNCA vive en el repo. Se lee, en este orden:
//   1) variables de entorno TG_BOT_TOKEN / TG_CHAT_ID (hPanel -> PHP -> Environment/Variables)
//   2) secrets/telegram.json  ->  {"bot_token":"...","chat_id":"..."}  (subir A MANO al server, NO por git)
// Si Telegram no está configurado, la solicitud igual se guarda y se envía por correo (degradación suave).
// Generado por PATO.
header('Content-Type: application/json; charset=utf-8');

// A quién avisa PATO de cada solicitud: buzón real del dominio (entrega confiable) + David.
$NOTIFY = ['contacto@picandotabla.com', 'sodpiloko@gmail.com'];
$FROM   = 'Picando Tabla <contacto@picandotabla.com>';

$raw = file_get_contents('php://input');
$d = json_decode($raw, true);
if (!is_array($d)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'sin datos']); exit; }

function pt_clean($v, $n) { return substr(strip_tags(trim((string)($v ?? ''))), 0, $n); }

$nombre    = pt_clean($d['nombre']           ?? '', 120);
$empresa   = pt_clean($d['empresa']          ?? '', 120);
$correo    = pt_clean($d['correo']           ?? '', 120);
$telefono  = pt_clean($d['telefono']         ?? '', 40);
$tipo      = pt_clean($d['tipo']             ?? '', 80);
$fecha     = pt_clean($d['fecha']            ?? '', 40);
$zona      = pt_clean($d['zona']             ?? '', 120);
$personas  = pt_clean($d['personas']         ?? '', 20);
$presTipo  = pt_clean($d['presupuesto_tipo'] ?? '', 60);
$presupu   = pt_clean($d['presupuesto']      ?? '', 60);
$vinos     = pt_clean($d['vinos']            ?? '', 600);
$detalles  = pt_clean($d['detalles']         ?? '', 1400);

$servicios = '';
if (!empty($d['servicios']) && is_array($d['servicios'])) {
  $ss = array_map(function ($x) { return substr(strip_tags(trim((string)$x)), 0, 60); }, $d['servicios']);
  $ss = array_filter($ss, function ($x) { return $x !== ''; });
  $servicios = implode(', ', array_slice($ss, 0, 12));
}

// Necesitamos al menos una vía de contacto.
if ($nombre === '' && $correo === '' && $telefono === '') {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'faltan datos de contacto']);
  exit;
}

// --- Guardar (PII) ---
$rec = [
  'at' => date('c'), 'tipo_solicitud' => 'evento',
  'nombre' => $nombre, 'empresa' => $empresa, 'correo' => $correo, 'telefono' => $telefono,
  'evento' => $tipo, 'fecha' => $fecha, 'zona' => $zona, 'personas' => $personas,
  'presupuesto_tipo' => $presTipo, 'presupuesto' => $presupu,
  'servicios' => $servicios, 'vinos' => $vinos, 'detalles' => $detalles,
  'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
];
$dir = __DIR__ . '/data';
@mkdir($dir, 0755, true);
@file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
@file_put_contents($dir . '/eventos.jsonl', json_encode($rec, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);

// --- Texto del aviso ---
$L = [];
$L[] = "🧀 Nueva solicitud de EVENTO — Picando Tabla";
$L[] = "";
$L[] = "Nombre: " . $nombre;
if ($empresa !== '') $L[] = "Empresa: " . $empresa;
$L[] = "Correo: " . ($correo ?: '—');
$L[] = "Tel/WhatsApp: " . ($telefono ?: '—');
$L[] = "Tipo de evento: " . ($tipo ?: '—');
$L[] = "Fecha: " . ($fecha ?: 'por definir');
$L[] = "Colonia/locación: " . ($zona ?: '—');
$L[] = "Personas: " . ($personas ?: '—');
$L[] = "Presupuesto: " . ($presupu ?: 'por definir') . ($presTipo ? " (" . $presTipo . ")" : "");
if ($servicios !== '') $L[] = "Le interesa: " . $servicios;
if ($vinos !== '')    $L[] = "Vinos/bebidas: " . $vinos;
if ($detalles !== '') $L[] = "Detalles: " . $detalles;
$text = implode("\n", $L);

// --- Config de Telegram (env o secrets/telegram.json) ---
function pt_tg_cfg() {
  $tok = getenv('TG_BOT_TOKEN');
  $chat = getenv('TG_CHAT_ID');
  if ($tok && $chat) return [$tok, $chat];
  $f = __DIR__ . '/secrets/telegram.json';
  if (is_file($f)) {
    $c = json_decode(@file_get_contents($f), true);
    if (is_array($c) && !empty($c['bot_token']) && !empty($c['chat_id'])) {
      return [$c['bot_token'], $c['chat_id']];
    }
  }
  return [null, null];
}

$tg_ok = false;
list($tok, $chat) = pt_tg_cfg();
if ($tok && $chat) {
  // Blindar la carpeta secrets/ para que no sea legible por web.
  @file_put_contents(__DIR__ . '/secrets/.htaccess', "Require all denied\nDeny from all\n");
  $url = "https://api.telegram.org/bot" . $tok . "/sendMessage";
  $post = http_build_query([
    'chat_id' => (string)$chat,
    'text' => substr($text, 0, 4090),
    'disable_web_page_preview' => 'true',
  ]);
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post,
      CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $tg_ok = ($code >= 200 && $code < 300);
  } else {
    $ctx = stream_context_create(['http' => [
      'method' => 'POST',
      'header' => 'Content-Type: application/x-www-form-urlencoded',
      'content' => $post, 'timeout' => 15,
    ]]);
    $tg_ok = (@file_get_contents($url, false, $ctx) !== false);
  }
}

// --- Correo (respaldo + copia al buzón real) ---
$subj = "Solicitud de evento Picando Tabla — " . $nombre . ($tipo ? (" · " . $tipo) : "");
$headers = "From: " . $FROM . "\r\n";
if ($correo !== '') $headers .= "Reply-To: " . $correo . "\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8";
foreach ($NOTIFY as $to) { @mail($to, $subj, $text, $headers); }

echo json_encode(['ok' => true, 'telegram' => $tg_ok], JSON_UNESCAPED_UNICODE);
