<?php
// Solicitud de CATERING / EVENTO de Picando Tabla (/eventos/). La llama el formulario por fetch.
// Guarda la solicitud con FOLIO (PII en data/, gitignored + .htaccess), AVISA por TELEGRAM y manda correo.
// Recepción real: solo responde ok cuando la solicitud quedó guardada o, si el disco falla, cuando
// el aviso salió por correo o Telegram. El formulario muestra "Recibimos tu solicitud" SOLO con ok.
// Idempotente: el formulario manda una llave `idem`. Un reintento IDÉNTICO (misma llave y mismo
// contenido) devuelve el mismo folio sin duplicar ni volver a avisar; si el cliente corrigió algún dato
// antes de reintentar, se guarda como solicitud nueva que indica a qué folio reemplaza.
// SEGURIDAD: el token de Telegram NUNCA vive en el repo. Se lee, en este orden:
//   1) variables de entorno TG_BOT_TOKEN / TG_CHAT_ID (hPanel -> PHP -> Environment/Variables)
//   2) secrets/telegram.json  ->  {"bot_token":"...","chat_id":"..."}  (subir A MANO al server, NO por git)
// Si Telegram no está configurado, la solicitud igual se guarda y se envía por correo (degradación suave).
// Generado por PATO.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// A quién avisa PATO de cada solicitud: buzón real del dominio (entrega confiable) + David.
$NOTIFY = ['contacto@picandotabla.com', 'sodpiloko@gmail.com'];
$FROM   = 'Picando Tabla <contacto@picandotabla.com>';

function pt_out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') pt_out(405, ['ok' => false, 'error' => 'usa POST']);

$raw = file_get_contents('php://input');
$d = json_decode($raw, true);
if (!is_array($d)) pt_out(400, ['ok' => false, 'error' => 'sin datos']);

function pt_clean($v, $n) {
  $s = trim(strip_tags((string)($v ?? '')));
  return function_exists('mb_substr') ? mb_substr($s, 0, $n, 'UTF-8') : substr($s, 0, $n);
}

$nombre       = pt_clean($d['nombre']            ?? '', 120);
$empresa      = pt_clean($d['empresa']           ?? '', 120);
$correo       = pt_clean($d['correo']            ?? '', 120);
$telefono     = pt_clean($d['telefono']          ?? '', 40);
$tipo         = pt_clean($d['tipo']              ?? '', 80);
$fecha        = pt_clean($d['fecha']             ?? '', 40);
$fechaFlex    = !empty($d['fecha_flexible']);
$zona         = pt_clean($d['zona']              ?? '', 120);
$personas     = pt_clean($d['personas']          ?? '', 20);
$presentacion = pt_clean($d['presentacion']      ?? '', 60);
$momento      = pt_clean($d['momento']           ?? '', 160);
$formato      = pt_clean($d['formato']           ?? '', 80);
$presTipo     = pt_clean($d['presupuesto_tipo']  ?? '', 60);
$presupu      = pt_clean($d['presupuesto']       ?? '', 60);
$presCubre    = pt_clean($d['presupuesto_cubre'] ?? '', 80);
$vinos        = pt_clean($d['vinos']             ?? '', 600);
$restric      = pt_clean($d['restricciones']     ?? '', 600);
$detalles     = pt_clean($d['detalles']          ?? '', 1400);
$origen       = pt_clean($d['origen']            ?? 'eventos_form', 60);
$idem         = preg_replace('/[^a-f0-9]/', '', strtolower((string)($d['idem'] ?? '')));
$idem         = substr($idem, 0, 32);
if (strlen($idem) < 16) $idem = '';   // llaves cortas no deduplican (evita colisiones entre clientes)

$servicios = '';
if (!empty($d['servicios']) && is_array($d['servicios'])) {
  $ss = array_map(function ($x) { return pt_clean($x, 60); }, $d['servicios']);
  $ss = array_filter($ss, function ($x) { return $x !== ''; });
  $servicios = implode(', ', array_slice($ss, 0, 12));
}

// Nombre + al menos una vía de contacto.
if ($nombre === '' || ($correo === '' && $telefono === '')) {
  pt_out(400, ['ok' => false, 'error' => 'faltan nombre y un medio de contacto']);
}
// Asistentes: si viene, un entero positivo razonable.
if ($personas !== '' && (!preg_match('/^\d{1,5}$/', $personas) || (int)$personas < 1 || (int)$personas > 5000)) {
  pt_out(400, ['ok' => false, 'error' => 'número de asistentes no válido']);
}

$dir  = __DIR__ . '/data';
$file = $dir . '/eventos.jsonl';
@mkdir($dir, 0755, true);
@file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");

// Firma del contenido: distingue un reintento idéntico de uno con datos corregidos.
$sig = substr(hash('sha256', json_encode([$nombre, $empresa, $correo, $telefono, $tipo, $fecha, $fechaFlex, $zona,
  $personas, $presentacion, $momento, $formato, $presTipo, $presupu, $presCubre, $servicios, $vinos, $restric,
  $detalles], JSON_UNESCAPED_UNICODE)), 0, 16);
$reemplaza = '';

// --- Reintento con la misma llave: idéntico = mismo folio; corregido = solicitud nueva ---
if ($idem !== '' && is_file($file)) {
  $size = filesize($file);
  $fh = @fopen($file, 'rb');
  if ($fh) {
    $len = min($size, 131072);
    fseek($fh, -$len, SEEK_END);
    $tail = fread($fh, $len);
    fclose($fh);
    foreach (array_reverse(explode("\n", (string)$tail)) as $line) {
      if ($line === '' || strpos($line, $idem) === false) continue;
      $prev = json_decode($line, true);
      if (!is_array($prev) || ($prev['idem'] ?? '') !== $idem || empty($prev['folio'])) continue;
      if (($prev['sig'] ?? '') === $sig) pt_out(200, ['ok' => true, 'folio' => $prev['folio'], 'duplicado' => true]);
      $reemplaza = $prev['folio'];
      break;
    }
  }
}

// --- Folio legible: EV-AAMMDD-XXXX (sin caracteres ambiguos) ---
$alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
$suffix = '';
for ($i = 0; $i < 4; $i++) $suffix .= $alpha[random_int(0, strlen($alpha) - 1)];
$folio = 'EV-' . date('ymd') . '-' . $suffix;

// --- Guardar (PII) ---
$rec = [
  'at' => date('c'), 'tipo_solicitud' => 'evento', 'folio' => $folio, 'idem' => $idem, 'sig' => $sig,
  'reemplaza' => $reemplaza, 'origen' => $origen,
  'nombre' => $nombre, 'empresa' => $empresa, 'correo' => $correo, 'telefono' => $telefono,
  'evento' => $tipo, 'fecha' => $fecha, 'fecha_flexible' => $fechaFlex ? 'sí' : '', 'zona' => $zona,
  'personas' => $personas, 'presentacion' => $presentacion, 'momento' => $momento, 'formato' => $formato,
  'presupuesto_tipo' => $presTipo, 'presupuesto' => $presupu, 'presupuesto_cubre' => $presCubre,
  'servicios' => $servicios, 'vinos' => $vinos, 'restricciones' => $restric, 'detalles' => $detalles,
  'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
];
$saved = @file_put_contents($file, json_encode($rec, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;

// --- Texto del aviso ---
$L = [];
$L[] = "🧀 Nueva solicitud de CATERING / EVENTO — Picando Tabla";
$L[] = "Folio: " . $folio . ($reemplaza !== '' ? " (corrige la solicitud " . $reemplaza . ")" : "");
$L[] = "";
$L[] = "Nombre: " . $nombre;
if ($empresa !== '') $L[] = "Empresa: " . $empresa;
$L[] = "Tel/WhatsApp: " . ($telefono ?: '—');
$L[] = "Correo: " . ($correo ?: '—');
$L[] = "Tipo de evento: " . ($tipo ?: '—');
$L[] = "Asistentes: " . ($personas ?: 'por definir');
$L[] = "Fecha: " . ($fecha ?: 'por definir') . ($fechaFlex ? ' (flexible)' : '');
$L[] = "Zona: " . ($zona ?: '—');
if ($momento !== '')      $L[] = "Momento/horario: " . $momento;
$L[] = "Presentación: " . ($presentacion ?: 'por definir');
if ($formato !== '')      $L[] = "Formato: " . $formato;
$L[] = "Presupuesto: " . ($presupu ?: 'por definir') . ($presTipo ? " (" . $presTipo . ")" : "")
     . ($presCubre ? " · cubre: " . $presCubre : "");
if ($servicios !== '')    $L[] = "Necesita: " . $servicios;
if ($vinos !== '')        $L[] = "Vinos: " . $vinos;
if ($restric !== '')      $L[] = "Restricciones: " . $restric;
if ($detalles !== '')     $L[] = "Comentarios: " . $detalles;
if (!$saved)              $L[] = "⚠️ No se pudo guardar en data/eventos.jsonl: este aviso es la única copia.";
$L[] = "";
$L[] = "Comanda: https://picandotabla.com/comanda/";
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
    'text' => function_exists('mb_strcut') ? mb_strcut($text, 0, 4000, 'UTF-8') : substr($text, 0, 4000),
    'disable_web_page_preview' => 'true',
  ]);
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post,
      CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $tg_ok = ($code >= 200 && $code < 300);
  } else {
    $ctx = stream_context_create(['http' => [
      'method' => 'POST',
      'header' => 'Content-Type: application/x-www-form-urlencoded',
      'content' => $post, 'timeout' => 5,
    ]]);
    $tg_ok = (@file_get_contents($url, false, $ctx) !== false);
  }
}

// --- Correo (respaldo + copia al buzón real) ---
$subj = "Solicitud de evento " . $folio . " — " . $nombre . ($tipo ? (" · " . $tipo) : "");
$headers = "From: " . $FROM . "\r\n";
if ($correo !== '' && filter_var($correo, FILTER_VALIDATE_EMAIL)) $headers .= "Reply-To: " . $correo . "\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8";
$mail_ok = false;
foreach ($NOTIFY as $to) { $mail_ok = @mail($to, '=?UTF-8?B?' . base64_encode($subj) . '?=', $text, $headers) || $mail_ok; }

if (!$saved && !$mail_ok && !$tg_ok) {
  pt_out(500, ['ok' => false, 'error' => 'no pudimos registrar la solicitud']);
}
pt_out(200, ['ok' => true, 'folio' => $folio]);
