<?php
declare(strict_types=1);

/*
 * Instalador de UN SOLO USO: coloca la config privada del pago (Access Token de Mercado Pago de Jessica) en
 * picandotabla-private/ (junto a public_html) sin que el token pase por el chat ni por el repo.
 *
 * Defensas: solo POST; llave de 64 hex cuyo SHA-256 está aquí (la llave vive solo en la PC de David, en
 * secrets/instalador-privado-picandotabla.json); vencimiento; el token se valida con una expresión estricta y el
 * archivo de config lo escribe este script con una plantilla fija (no acepta código); al terminar se borra a sí
 * mismo. Cliente: python -m pato_brain.instalar_privado subir --cuenta picandotabla
 */

const INSTALAR_HASH = '16e2353cd8b109bf1f8303470fc48883804c369856a662be71585c09c3c02b72';
const INSTALAR_VENCE = '2026-09-23T23:55:57Z';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

function instalar_responder(int $code, array $data): void {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function instalar_escribir(string $path, string $contenido): bool {
  $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
  $old = umask(0077);
  $n = @file_put_contents($tmp, $contenido, LOCK_EX);
  umask($old);
  if ($n !== strlen($contenido)) {
    @unlink($tmp);
    return false;
  }
  if (DIRECTORY_SEPARATOR !== '\\') @chmod($tmp, 0600);
  if (DIRECTORY_SEPARATOR === '\\' && is_file($path)) @unlink($path);
  return @rename($tmp, $path);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') instalar_responder(404, ['ok' => false]);
if (preg_match('/\A[a-f0-9]{64}\z/', INSTALAR_HASH) !== 1) instalar_responder(404, ['ok' => false]);
$vence = strtotime(INSTALAR_VENCE);
if ($vence === false || time() > $vence) instalar_responder(410, ['ok' => false, 'error' => 'vencido']);
$llave = (string)($_SERVER['HTTP_X_INSTALAR_CLAVE'] ?? '');
if (preg_match('/\A[a-f0-9]{64}\z/', $llave) !== 1 || !hash_equals(INSTALAR_HASH, hash('sha256', $llave))) {
  instalar_responder(403, ['ok' => false]);
}

$token = trim((string)($_POST['access_token'] ?? ''));
$secreto = trim((string)($_POST['webhook_secret'] ?? ''));
if (preg_match('/\A(APP_USR|TEST)-[A-Za-z0-9_-]{20,300}\z/', $token) !== 1) {
  instalar_responder(422, ['ok' => false, 'error' => 'token_invalido']);
}
if ($secreto !== '' && preg_match('/\A[A-Za-z0-9_-]{8,200}\z/', $secreto) !== 1) {
  instalar_responder(422, ['ok' => false, 'error' => 'secreto_invalido']);
}

$docroot = @realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
if ($docroot === false) instalar_responder(500, ['ok' => false, 'error' => 'docroot']);
$privado = dirname($docroot) . DIRECTORY_SEPARATOR . 'picandotabla-private';
if (!is_dir($privado)) {
  $old = umask(0077);
  @mkdir($privado, 0700, true);
  umask($old);
}
$real = @realpath($privado);
if ($real === false || !is_dir($real)) instalar_responder(500, ['ok' => false, 'error' => 'carpeta']);
if (strpos(str_replace('\\', '/', $real) . '/', str_replace('\\', '/', $docroot) . '/') === 0) {
  instalar_responder(500, ['ok' => false, 'error' => 'carpeta_dentro_de_public_html']);
}
if (DIRECTORY_SEPARATOR !== '\\') @chmod($real, 0700);
$guard = $real . DIRECTORY_SEPARATOR . '.htaccess';
if (!is_file($guard)) @file_put_contents($guard, "Require all denied\nDeny from all\n", LOCK_EX);

$config = "<?php return ['access_token' => '" . $token . "'"
  . ($secreto !== '' ? ", 'webhook_secret' => '" . $secreto . "'" : '') . "];\n";
if (!instalar_escribir($real . DIRECTORY_SEPARATOR . 'mercadopago.php', $config)) {
  instalar_responder(500, ['ok' => false, 'error' => 'escritura']);
}

$borrado = @unlink(__FILE__);
instalar_responder(200, ['ok' => true, 'escritos' => ['picandotabla-private/mercadopago.php'], 'instalador_borrado' => $borrado]);
