<?php
declare(strict_types=1);

/*
 * Pago en línea de Picando Tabla (Checkout Pro de Mercado Pago, cuenta de Jessica).
 * Mismo patrón probado que el playbook de patologicos.com (knowledge/aprendizajes.md de PATO).
 *
 * El repo es PÚBLICO: aquí no hay secretos. Todo eso vive fuera del document root, en una carpeta
 * hermana de public_html que David sube por hPanel (python -m pato_brain.mercadopago_setup picandotabla):
 *
 *   <padre de public_html>/picandotabla-private/mercadopago.php   config con el Access Token
 *   <padre de public_html>/picandotabla-private/pagos/            pedidos/<folio>.json + correos/ + eventos.log
 *
 * Flujo: pedido.php (precio calculado AQUÍ desde catalogo.js, nunca desde el navegador) → Mercado Pago
 *        → webhook.php y gracias.php (verifican el pago contra la API) → comanda (Pagado) + correo a Jessica.
 * Si falta la config, estado.php responde activo=false y la ficha solo ofrece WhatsApp (como antes).
 */

if (basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)
    && basename(dirname((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))) === basename(__DIR__)) {
  http_response_code(404);
  exit;
}

const PTPG_ACTIVO = true;   // interruptor: false apaga el botón de pago sin tocar la config privada
const PTPG_PRIVADA = 'picandotabla-private';
const PTPG_SUB = 'pagos';
const PTPG_MONEDA = 'MXN';
const PTPG_TZ = 'America/Mexico_City';
const PTPG_FOLIO_RE = '/\APT-\d{6}-[A-F0-9]{8}\z/';
const PTPG_PAGO_RE = '/\A\d{1,20}\z/';
const PTPG_AVISO_A = ['contacto@picandotabla.com', 'sodpiloko@gmail.com'];
const PTPG_REMITENTE = 'Picando Tabla <contacto@picandotabla.com>';
const PTPG_WA = '525623632404';
const PTPG_MAX_DIAS = 120;

// ---------------------------------------------------------------- utilidades

function ptpg_security_headers(): void {
  header('X-Content-Type-Options: nosniff');
  header('X-Frame-Options: DENY');
  header('Referrer-Policy: no-referrer');
  header('Cache-Control: no-store, private, max-age=0');
  header('X-Robots-Tag: noindex, nofollow, noarchive');
}

function ptpg_h($value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ptpg_json(int $status, array $data): void {
  http_response_code($status);
  ptpg_security_headers();
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function ptpg_log(string $evento, array $datos = []): void {
  $dir = ptpg_dir('');
  if ($dir === false) return;
  $linea = json_encode(['at' => gmdate('c'), 'evento' => $evento] + $datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  @file_put_contents($dir . '/eventos.log', $linea . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function ptpg_dinero(float $v): string {
  return '$' . number_format($v, 0);
}

function ptpg_hoy(): DateTimeImmutable {
  return new DateTimeImmutable('today', new DateTimeZone(PTPG_TZ));
}

function ptpg_fecha_larga(string $iso): string {
  $d = DateTimeImmutable::createFromFormat('!Y-m-d', $iso, new DateTimeZone(PTPG_TZ));
  if ($d === false) return $iso;
  $dias = ['', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
  $meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
  return $dias[(int)$d->format('N')] . ' ' . (int)$d->format('j') . ' de ' . $meses[(int)$d->format('n')];
}

// ---------------------------------------------------------------- carpeta privada

function ptpg_path_within(string $path, string $root): bool {
  $path = str_replace('\\', '/', rtrim($path, '\\/')) . '/';
  $root = str_replace('\\', '/', rtrim($root, '\\/')) . '/';
  if (DIRECTORY_SEPARATOR === '\\') {
    $path = strtolower($path);
    $root = strtolower($root);
  }
  return strpos($path, $root) === 0;
}

/**
 * Raíz privada (debe existir y estar FUERA del document root) o false.
 * Acepta las ubicaciones en que suele quedar al subirla por hPanel: junto a public_html, la anidada que deja
 * "Extraer" (carpeta con el nombre del zip) y la raíz de la cuenta. Gana la primera que tenga mercadopago.php.
 */
function ptpg_private_root() {
  static $cache = null;
  if ($cache !== null) return $cache;
  $docroot = @realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
  if ($docroot === false || !is_dir($docroot)) return $cache = false;
  $sep = DIRECTORY_SEPARATOR;
  $configurada = trim((string)getenv('PTPG_PRIVATE_DIR'));
  $candidatas = [];
  if ($configurada !== '') {
    $candidatas[] = $configurada;
  } else {
    $junto = dirname($docroot) . $sep . PTPG_PRIVADA;
    $candidatas[] = $junto;
    $candidatas[] = $junto . $sep . PTPG_PRIVADA;
    if (preg_match('~\A(/home/[^/]+)/~', str_replace('\\', '/', $docroot), $m) === 1) {
      $candidatas[] = $m[1] . '/' . PTPG_PRIVADA;
      $candidatas[] = $m[1] . '/' . PTPG_PRIVADA . '/' . PTPG_PRIVADA;
    }
  }
  $validas = [];
  foreach (array_unique($candidatas) as $candidata) {
    $real = @realpath($candidata);
    if ($real === false || !is_dir($real) || is_link($candidata) || ptpg_path_within($real, $docroot)) continue;
    if (is_file($real . $sep . 'mercadopago.php')) return $cache = $real;
    $validas[] = $real;
  }
  return $cache = ($validas[0] ?? false);
}

function ptpg_private_mode(string $path, int $mode): bool {
  if (DIRECTORY_SEPARATOR === '\\') return true; // solo desarrollo local: Windows no tiene permisos POSIX
  if (!@chmod($path, $mode)) return false;
  clearstatcache(true, $path);
  $perms = @fileperms($path);
  return is_int($perms) && (($perms & 0777) === $mode);
}

/** Subcarpeta privada de pagos (se crea con 0700 y un .htaccess que niega todo). */
function ptpg_dir(string $sub) {
  $root = ptpg_private_root();
  if ($root === false) return false;
  $dir = $root . DIRECTORY_SEPARATOR . PTPG_SUB . ($sub !== '' ? DIRECTORY_SEPARATOR . $sub : '');
  if (!is_dir($dir)) {
    $old = umask(0077);
    $ok = @mkdir($dir, 0700, true);
    umask($old);
    if (!$ok && !is_dir($dir)) return false;
  }
  if (!ptpg_private_mode($dir, 0700)) return false;
  $guard = $dir . DIRECTORY_SEPARATOR . '.htaccess';
  if (!is_file($guard)) @file_put_contents($guard, "Require all denied\nDeny from all\n", LOCK_EX);
  return $dir;
}

function ptpg_escribir_privado(string $path, string $contenido): bool {
  $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
  $old = umask(0077);
  $n = @file_put_contents($tmp, $contenido, LOCK_EX);
  umask($old);
  if ($n === false || $n !== strlen($contenido) || !ptpg_private_mode($tmp, 0600)) {
    @unlink($tmp);
    return false;
  }
  if (DIRECTORY_SEPARATOR === '\\' && is_file($path)) @unlink($path);
  if (!@rename($tmp, $path)) {
    @unlink($tmp);
    return false;
  }
  return true;
}

// ---------------------------------------------------------------- configuración y API

/** Config de Mercado Pago (la coloca David fuera del repo) o false si falta. */
function ptpg_config() {
  static $cfg = null;
  if ($cfg !== null) return $cfg;
  $root = ptpg_private_root();
  if ($root === false) return $cfg = false;
  $file = $root . DIRECTORY_SEPARATOR . 'mercadopago.php';
  if (!is_file($file)) return $cfg = false;
  $data = include $file;
  if (!is_array($data) || !is_string($data['access_token'] ?? null) || trim($data['access_token']) === '') return $cfg = false;
  return $cfg = $data;
}

function ptpg_activo(): bool {
  return PTPG_ACTIVO && ptpg_config() !== false;
}

function ptpg_base_url(): string {
  $cfg = ptpg_config();
  $base = is_array($cfg) && is_string($cfg['base_url'] ?? null) ? $cfg['base_url'] : 'https://picandotabla.com';
  return rtrim($base, '/');
}

/** Llamada HTTP a la API de Mercado Pago. Devuelve [status, json|null]. */
function ptpg_mp(string $metodo, string $ruta, ?array $cuerpo = null): array {
  $cfg = ptpg_config();
  if ($cfg === false) return [0, null];
  $base = is_string($cfg['api_base'] ?? null) ? rtrim($cfg['api_base'], '/') : 'https://api.mercadopago.com';
  $url = $base . $ruta;
  $headers = [
    'Authorization: Bearer ' . trim((string)$cfg['access_token']),
    'Accept: application/json',
  ];
  $payload = null;
  if ($cuerpo !== null) {
    $payload = json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $headers[] = 'Content-Type: application/json';
    $headers[] = 'X-Idempotency-Key: ' . bin2hex(random_bytes(16));
  }
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_CUSTOMREQUEST => $metodo,
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 20,
      CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
  } else {
    $ctx = stream_context_create(['http' => [
      'method' => $metodo,
      'header' => implode("\r\n", $headers),
      'content' => $payload ?? '',
      'timeout' => 20,
      'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach (($http_response_header ?? []) as $h) {
      if (preg_match('~\AHTTP/\S+\s+(\d{3})~', $h, $m)) $status = (int)$m[1];
    }
  }
  if (!is_string($resp)) return [$status, null];
  $json = json_decode($resp, true);
  return [$status, is_array($json) ? $json : null];
}

// ---------------------------------------------------------------- catálogo y precio

/** El mismo catálogo que ve el cliente (catalogo.js lo genera tools/build_catalog.py desde data/catalogo.json). */
function ptpg_catalogo(): ?array {
  static $cat = false;
  if ($cat !== false) return $cat;
  $js = (string)@file_get_contents(dirname(__DIR__) . '/catalogo.js');
  if (preg_match('/^\s*var catalog=(\{.*\});\s*$/m', $js, $m) !== 1) return $cat = null;
  $data = json_decode($m[1], true);
  return $cat = (is_array($data) && is_array($data['products'] ?? null) ? $data : null);
}

/**
 * Calcula el pedido en el servidor. Devuelve ['lineas', 'total', 'cobro', 'tipo', 'producto', ...] o ['error' => texto].
 * Reglas del catálogo: entregas en delivery_days_iso; tablas estándar con 2 días de anticipación, de evento con
 * event_lead_time_days; las de evento pagan deposit_pct % y liquidan después, salvo que falten
 * balance_due_days_before_delivery días o menos (entonces se cobra completa).
 */
function ptpg_cotizar(string $clave, bool $premium, array $extras, string $fecha, ?DateTimeImmutable $hoy = null): array {
  $cat = ptpg_catalogo();
  if ($cat === null) return ['error' => 'No pudimos leer el catálogo.'];
  $hoy = $hoy ?? ptpg_hoy();
  $producto = null;
  foreach ($cat['products'] as $p) {
    if (($p['key'] ?? '') === $clave) $producto = $p;
  }
  if ($producto === null) return ['error' => 'Esa tabla no existe.'];

  $entrega = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha, new DateTimeZone(PTPG_TZ));
  if ($entrega === false || $entrega->format('Y-m-d') !== $fecha) return ['error' => 'Elige la fecha de entrega.'];
  $log = $cat['logistics'];
  if (!in_array((int)$entrega->format('N'), array_map('intval', (array)$log['delivery_days_iso']), true)) {
    return ['error' => 'Entregamos ' . implode(' y ', (array)$log['delivery_days_labels']) . '. Elige uno de esos días.'];
  }
  $dias = (int)$hoy->diff($entrega)->format('%r%a');
  $minimo = !empty($producto['is_event']) ? (int)$log['event_lead_time_days'] : (int)ceil($log['standard_lead_time_hours'] / 24);
  if ($dias < $minimo) {
    return ['error' => !empty($producto['is_event'])
      ? 'Las tablas de evento se piden con ' . $minimo . ' días de anticipación.'
      : 'Pide con al menos ' . $log['standard_lead_time_hours'] . ' h de anticipación.'];
  }
  if ($dias > PTPG_MAX_DIAS) return ['error' => 'Esa fecha está muy lejos; escríbenos por WhatsApp.'];

  $precio = (float)$producto['price_mxn'];
  $titulo = (string)$producto['title'];
  if ($premium) {
    $mod = null;
    foreach ((array)$cat['modifiers'] as $m) {
      if (($m['key'] ?? '') === 'premium' && ($m['status'] ?? '') === 'active') $mod = $m;
    }
    if ($mod === null || !in_array($clave, (array)($mod['confirmed_product_keys'] ?? []), true)) {
      return ['error' => 'La versión Premium de esta tabla se confirma por WhatsApp.'];
    }
    $precio += (float)$mod['prices_mxn_by_product_key'][$clave];
    $titulo .= ' (Premium)';
  }
  $lineas = [['id' => (string)$producto['id'], 'titulo' => $titulo, 'precio' => $precio]];

  foreach ((array)$cat['promotions'] as $promo) {
    if (($promo['type'] ?? '') === 'gift' && in_array($clave, (array)($promo['eligible_product_keys'] ?? []), true)) {
      $lineas[] = ['id' => (string)$promo['id'], 'titulo' => $promo['title'] . ' (regalo)', 'precio' => 0.0];
    }
  }
  $vistos = [];
  foreach ($extras as $e) {
    $e = (string)$e;
    if (isset($vistos[$e])) continue;
    $vistos[$e] = true;
    $extra = null;
    foreach ((array)$cat['extras'] as $x) {
      if (($x['key'] ?? '') === $e && ($x['status'] ?? '') === 'active') $extra = $x;
    }
    if ($extra === null) return ['error' => 'Uno de los extras ya no está disponible.'];
    $lineas[] = ['id' => (string)$extra['id'], 'titulo' => (string)$extra['title'], 'precio' => (float)$extra['price_mxn']];
  }
  $envio = $cat['delivery'];
  $lineas[] = ['id' => (string)$envio['id'], 'titulo' => (string)$envio['title'], 'precio' => (float)$envio['price_mxn']];

  $total = 0.0;
  foreach ($lineas as $l) $total += $l['precio'];
  $tipo = 'total';
  $cobro = $total;
  $pago = $log['payment']['event'] ?? [];
  if (!empty($producto['is_event']) && $dias > (int)($pago['balance_due_days_before_delivery'] ?? 0)) {
    $tipo = 'anticipo';
    $cobro = (float)round($total * (int)$pago['deposit_pct'] / 100);
  }
  return [
    'producto' => $clave,
    'titulo' => $titulo,
    'lineas' => $lineas,
    'total' => $total,
    'cobro' => $cobro,
    'tipo' => $tipo,
    'porcentaje' => $tipo === 'anticipo' ? (int)$pago['deposit_pct'] : 100,
    'liquidar_dias_antes' => $tipo === 'anticipo' ? (int)$pago['balance_due_days_before_delivery'] : 0,
    'fecha' => $fecha,
    'dias' => $dias,
  ];
}

// ---------------------------------------------------------------- pedidos

function ptpg_nuevo_folio(): string {
  return 'PT-' . gmdate('ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
}

function ptpg_pedido_path(string $folio) {
  if (preg_match(PTPG_FOLIO_RE, $folio) !== 1) return false;
  $dir = ptpg_dir('pedidos');
  return $dir === false ? false : $dir . DIRECTORY_SEPARATOR . $folio . '.json';
}

function ptpg_leer_pedido(string $folio): ?array {
  $path = ptpg_pedido_path($folio);
  if ($path === false || !is_file($path)) return null;
  $data = json_decode((string)@file_get_contents($path), true);
  return is_array($data) ? $data : null;
}

function ptpg_guardar_pedido(array $pedido): bool {
  $path = ptpg_pedido_path((string)($pedido['folio'] ?? ''));
  if ($path === false) return false;
  $pedido['actualizado'] = gmdate('c');
  $json = json_encode($pedido, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  return $json !== false && ptpg_escribir_privado($path, $json . PHP_EOL);
}

/** Ejecuta $fn con el candado del pedido (webhook y página de gracias pueden llegar a la vez). */
function ptpg_con_candado(string $folio, callable $fn) {
  $dir = ptpg_dir('pedidos');
  if ($dir === false || preg_match(PTPG_FOLIO_RE, $folio) !== 1) return null;
  $lock = @fopen($dir . DIRECTORY_SEPARATOR . $folio . '.lock', 'c');
  if (!is_resource($lock)) return null;
  flock($lock, LOCK_EX);
  try {
    return $fn();
  } finally {
    flock($lock, LOCK_UN);
    fclose($lock);
  }
}

/** Renglones legibles del pedido (mismo formato que order.php, para la comanda y los correos). */
function ptpg_renglones(array $pedido): array {
  $out = [];
  foreach ((array)($pedido['lineas'] ?? []) as $l) {
    $out[] = '1x ' . $l['titulo'] . ' (' . ptpg_dinero((float)$l['precio']) . ')';
  }
  return $out;
}

// ---------------------------------------------------------------- pagos

const PTPG_ORDEN_ESTADOS = ['creado' => 0, 'rechazado' => 1, 'pendiente' => 2, 'pagado' => 3, 'reembolsado' => 4];

function ptpg_estado_mp(string $status): string {
  if ($status === 'approved') return 'pagado';
  if (in_array($status, ['pending', 'in_process', 'authorized', 'in_mediation'], true)) return 'pendiente';
  if (in_array($status, ['refunded', 'charged_back'], true)) return 'reembolsado';
  return 'rechazado';
}

/**
 * Verifica un pago contra la API de Mercado Pago y actualiza el pedido.
 * Devuelve ['pedido' => array] o ['error' => 'api'|'desconocido'|'invalido'].
 */
function ptpg_procesar_pago(string $pagoId): array {
  if (preg_match(PTPG_PAGO_RE, $pagoId) !== 1) return ['error' => 'invalido'];
  list($status, $pago) = ptpg_mp('GET', '/v1/payments/' . $pagoId);
  if ($status === 404) return ['error' => 'desconocido'];
  if ($status < 200 || $status >= 300 || !is_array($pago)) {
    ptpg_log('api_error', ['pago' => $pagoId, 'status' => $status]);
    return ['error' => 'api'];
  }
  $folio = (string)($pago['external_reference'] ?? '');
  if (preg_match(PTPG_FOLIO_RE, $folio) !== 1) return ['error' => 'desconocido'];

  $resultado = ptpg_con_candado($folio, function () use ($folio, $pago, $pagoId) {
    $pedido = ptpg_leer_pedido($folio);
    if ($pedido === null) return ['error' => 'desconocido'];
    $monto_ok = abs((float)($pago['transaction_amount'] ?? 0) - (float)($pedido['cobro'] ?? -1)) < 0.01
      && (string)($pago['currency_id'] ?? '') === PTPG_MONEDA;
    $nuevo = ptpg_estado_mp((string)($pago['status'] ?? ''));
    if ($nuevo === 'pagado' && !$monto_ok) {
      ptpg_log('monto_invalido', ['folio' => $folio, 'pago' => $pagoId]);
      $nuevo = 'rechazado';
    }
    $actual = (string)($pedido['estado'] ?? 'creado');
    // Nunca se retrocede de estado (un aviso viejo de "pendiente" no des-paga un pedido).
    if ((PTPG_ORDEN_ESTADOS[$nuevo] ?? 0) >= (PTPG_ORDEN_ESTADOS[$actual] ?? 0)) {
      $pedido['estado'] = $nuevo;
    }
    $pedido['pago_id'] = $pagoId;
    $pedido['metodo'] = (string)($pago['payment_method_id'] ?? '') . '/' . (string)($pago['payment_type_id'] ?? '');
    $correo = (string)($pago['payer']['email'] ?? '');
    if ($correo !== '' && filter_var($correo, FILTER_VALIDATE_EMAIL)) $pedido['correo'] = $correo;
    if ($pedido['estado'] === 'pagado' && empty($pedido['pagado_en'])) $pedido['pagado_en'] = gmdate('c');
    if ($pedido['estado'] === 'reembolsado' && empty($pedido['reembolsado_en'])) $pedido['reembolsado_en'] = gmdate('c');
    $avisos = is_array($pedido['avisos'] ?? null) ? $pedido['avisos'] : [];
    if ($pedido['estado'] === 'pagado' && empty($avisos['negocio'])) {
      $avisos['negocio'] = ptpg_aviso_negocio($pedido) ? gmdate('c') : 'fallo ' . gmdate('c');
    }
    if ($pedido['estado'] === 'pagado' && empty($avisos['cliente']) && !empty($pedido['correo'])) {
      $avisos['cliente'] = ptpg_aviso_cliente($pedido) ? gmdate('c') : 'fallo ' . gmdate('c');
    }
    if ($pedido['estado'] === 'reembolsado' && empty($avisos['reembolso'])) {
      $avisos['reembolso'] = ptpg_aviso_reembolso($pedido) ? gmdate('c') : 'fallo ' . gmdate('c');
    }
    $pedido['avisos'] = $avisos;
    ptpg_guardar_pedido($pedido);
    return ['pedido' => $pedido];
  });
  return is_array($resultado) ? $resultado : ['error' => 'api'];
}

// ---------------------------------------------------------------- correo

/** Guarda una copia privada del correo y lo envía. */
function ptpg_mail(string $para, string $asunto, string $cuerpo, string $tipo, string $folio): bool {
  $dir = ptpg_dir('correos');
  if ($dir !== false) {
    ptpg_escribir_privado($dir . DIRECTORY_SEPARATOR . $folio . '-' . $tipo . '-' . md5($para) . '.txt',
      "Para: $para\nAsunto: $asunto\n\n$cuerpo\n");
  }
  $headers = 'From: ' . PTPG_REMITENTE . "\r\n"
    . "Reply-To: contacto@picandotabla.com\r\n"
    . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
  return @mail($para, '=?UTF-8?B?' . base64_encode($asunto) . '?=', $cuerpo, $headers);
}

function ptpg_resumen_cobro(array $pedido): string {
  $cobro = (float)$pedido['cobro'];
  if (($pedido['tipo'] ?? '') === 'anticipo') {
    return 'Anticipo pagado: ' . ptpg_dinero($cobro) . ' de ' . ptpg_dinero((float)$pedido['total'])
      . ' (resta ' . ptpg_dinero((float)$pedido['total'] - $cobro) . ', se liquida '
      . (int)$pedido['liquidar_dias_antes'] . ' días antes de la entrega)';
  }
  return 'Pagado completo: ' . ptpg_dinero($cobro);
}

function ptpg_aviso_negocio(array $pedido): bool {
  $c = (array)($pedido['cliente'] ?? []);
  $cuerpo = implode("\n", array_merge([
    'Pedido PAGADO en línea en Picando Tabla.',
    '',
  ], ptpg_renglones($pedido), [
    '',
    'Total del pedido: ' . ptpg_dinero((float)$pedido['total']),
    ptpg_resumen_cobro($pedido),
    'Entrega: ' . ptpg_fecha_larga((string)$pedido['fecha']),
    'Nombre: ' . ($c['nombre'] ?? ''),
    'WhatsApp del cliente: ' . ($c['whatsapp'] ?? ''),
    'Colonia (CDMX): ' . ($c['zona'] ?? ''),
    (!empty($c['notas']) ? "Notas: " . $c['notas'] : 'Notas: (sin notas)'),
    'Correo (Mercado Pago): ' . ($pedido['correo'] ?? '(sin correo)'),
    'Folio: ' . $pedido['folio'] . ' · pago de Mercado Pago ' . ($pedido['pago_id'] ?? '') . ' · ' . ($pedido['metodo'] ?? ''),
    '',
    'Confírmale la entrega por WhatsApp. Si no hay disponibilidad para esa fecha, devuelve el pago desde',
    'Mercado Pago (Actividad > el pago > Devolver) y la comanda lo marcará como reembolsado.',
    '',
    'Comanda: https://picandotabla.com/comanda/',
  ]));
  $ok = true;
  foreach (PTPG_AVISO_A as $para) {
    $ok = ptpg_mail($para, 'Pedido PAGADO ' . ptpg_dinero((float)$pedido['cobro']) . ' · ' . ($c['nombre'] ?? '') . ' · ' . $pedido['folio'],
      $cuerpo, 'negocio', (string)$pedido['folio']) && $ok;
  }
  return $ok;
}

function ptpg_aviso_cliente(array $pedido): bool {
  $c = (array)($pedido['cliente'] ?? []);
  $cuerpo = implode("\n", array_merge([
    '¡Hola' . (!empty($c['nombre']) ? ' ' . $c['nombre'] : '') . '! Recibimos tu pago. Gracias por pedir en Picando Tabla.',
    '',
  ], ptpg_renglones($pedido), [
    '',
    ptpg_resumen_cobro($pedido),
    'Entrega: ' . ptpg_fecha_larga((string)$pedido['fecha']) . ' en ' . ($c['zona'] ?? 'CDMX'),
    'Folio: ' . $pedido['folio'],
    '',
    'Te escribimos por WhatsApp para confirmar la hora de entrega. Si no tuviéramos disponibilidad para',
    'tu fecha, te proponemos otra o te devolvemos tu pago completo.',
    '',
    '¿Dudas? WhatsApp: https://wa.me/' . PTPG_WA,
    '— Picando Tabla · picandotabla.com',
  ]));
  return ptpg_mail((string)$pedido['correo'], 'Recibimos tu pago · Picando Tabla · ' . $pedido['folio'], $cuerpo, 'cliente', (string)$pedido['folio']);
}

function ptpg_aviso_reembolso(array $pedido): bool {
  $c = (array)($pedido['cliente'] ?? []);
  $cuerpo = 'Se registró un reembolso en Mercado Pago del pedido ' . $pedido['folio'] . ' (' . ($c['nombre'] ?? '') . ', '
    . ptpg_dinero((float)$pedido['cobro']) . ').' . "\n\nComanda: https://picandotabla.com/comanda/";
  $ok = true;
  foreach (PTPG_AVISO_A as $para) {
    $ok = ptpg_mail($para, 'Reembolso · ' . $pedido['folio'], $cuerpo, 'reembolso', (string)$pedido['folio']) && $ok;
  }
  return $ok;
}
