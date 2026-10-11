<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function respond(int $status, string $message): never {
    http_response_code($status);
    echo json_encode(['ok' => $status === 200, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, 'Usa el formulario de suscripción.');
}
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$testOrigin = getenv('PT_NEWSLETTER_TEST') === '1' && preg_match('~^http://(?:127\.0\.0\.1|localhost)(?::[0-9]{1,5})?$~D', $origin);
if ($origin && !in_array($origin, ['https://picandotabla.com', 'https://www.picandotabla.com'], true) && !$testOrigin) {
    respond(403, 'Abre el formulario desde Picando Tabla.');
}
// Same-site forms use JSON. CORS preflight cannot permit foreign-site JavaScript.
$type = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
if ($type !== 'application/json') respond(415, 'Usa el formulario de suscripción.');
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 4096) respond(413, 'Solicitud demasiado grande.');
$raw = file_get_contents('php://input', false, null, 0, 4097);
if ($raw === false || strlen($raw) > 4096) respond(413, 'Solicitud demasiado grande.');
$data = json_decode($raw, true);
if (!is_array($data)) respond(400, 'Revisa los datos del formulario.');
try {
    $email = nl_email(is_string($data['email'] ?? null) ? $data['email'] : '');
} catch (InvalidArgumentException $error) {
    respond(400, 'Escribe un correo válido.');
}
if (($data['consent'] ?? false) !== true) respond(400, 'Acepta recibir el newsletter para continuar.');
$message = 'Si tu correo necesita confirmación, recibirás un enlace. Revisa también spam.';
if (!empty($data['website'])) respond(200, $message);
try {
    if (!nl_delivery_enabled()) respond(503, 'El newsletter estará disponible pronto. Por ahora, síguenos en nuestras redes.');
    if (!nl_limit($_SERVER['REMOTE_ADDR'] ?? 'unknown')) {
        header('Retry-After: 3600');
        respond(429, 'Has hecho varios intentos. Vuelve a intentar en una hora.');
    }
    $source = is_string($data['source'] ?? null) ? $data['source'] : '/';
    nl_subscribe($email, $source);
    respond(200, $message);
} catch (Throwable $error) {
    error_log('Newsletter: subscription storage unavailable');
    respond(503, 'No pudimos guardar tu solicitud. Intenta de nuevo más tarde.');
}
