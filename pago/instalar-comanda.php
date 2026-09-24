<?php
declare(strict_types=1);

/*
 * Instalador de UN SOLO USO: coloca en picandotabla-private/comanda-claves.txt el hash bcrypt de una clave
 * adicional de la comanda (la contraseña en claro nunca viaja ni se guarda). Solo POST, llave de 64 hex cuyo
 * SHA-256 está aquí, vencimiento, formato de hash estricto; se borra al terminar.
 */

const INSTALAR_HASH = 'd807c3fc24a4c045f6df3a2be6b21d590a788da2b885782e0709ebfa982416da';
const INSTALAR_VENCE = '2026-09-24T14:58:16Z';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

function responder(int $code, array $data): void {
  http_response_code($code);
  echo json_encode($data);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') responder(404, ['ok' => false]);
if (preg_match('/\A[a-f0-9]{64}\z/', INSTALAR_HASH) !== 1) responder(404, ['ok' => false]);
$vence = strtotime(INSTALAR_VENCE);
if ($vence === false || time() > $vence) responder(410, ['ok' => false, 'error' => 'vencido']);
$llave = (string)($_SERVER['HTTP_X_INSTALAR_CLAVE'] ?? '');
if (preg_match('/\A[a-f0-9]{64}\z/', $llave) !== 1 || !hash_equals(INSTALAR_HASH, hash('sha256', $llave))) responder(403, ['ok' => false]);

$hash = trim((string)($_POST['hash'] ?? ''));
if (preg_match('/\A\$2y\$1[0-4]\$[.\/A-Za-z0-9]{53}\z/', $hash) !== 1) responder(422, ['ok' => false, 'error' => 'hash_invalido']);

$docroot = @realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
$privado = $docroot === false ? false : @realpath(dirname($docroot) . DIRECTORY_SEPARATOR . 'picandotabla-private');
if ($privado === false || !is_dir($privado)) responder(500, ['ok' => false, 'error' => 'sin_carpeta_privada']);
$f = $privado . DIRECTORY_SEPARATOR . 'comanda-claves.txt';
$tmp = $f . '.tmp-' . bin2hex(random_bytes(6));
$old = umask(0077);
$ok = @file_put_contents($tmp, $hash . "\n", LOCK_EX) !== false;
umask($old);
if (!$ok || !@rename($tmp, $f)) { @unlink($tmp); responder(500, ['ok' => false, 'error' => 'escritura']); }
if (DIRECTORY_SEPARATOR !== '\\') @chmod($f, 0600);
responder(200, ['ok' => true, 'escrito' => 'picandotabla-private/comanda-claves.txt', 'instalador_borrado' => @unlink(__FILE__)]);
