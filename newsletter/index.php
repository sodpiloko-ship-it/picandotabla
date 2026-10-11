<?php
declare(strict_types=1);
require __DIR__.'/lib.php';
header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer'); header('X-Robots-Tag: noindex');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
if (!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD','POST'],true)) { header('Allow: GET, HEAD, POST'); http_response_code(405); exit; }
$action=$_GET['action']??''; $token=$_GET['token']??'';
$valid=is_string($token) && nl_valid_token($token);
$title='La sobremesa'; $message='Ideas para compartir, novedades y reuniones con Picando Tabla.'; $show=false;
try {
    if (in_array($action,['confirm','unsubscribe'],true)) {
        if (!$valid) { http_response_code(400); $message='El enlace no es válido.'; }
        elseif (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
            $ok=$action==='confirm'?nl_confirm($token):nl_unsubscribe($token);
            $title=$ok?($action==='confirm'?'¡Ya eres parte de La sobremesa!':'Tu baja quedó registrada'):'El enlace ya no está disponible';
            $message=$ok?($action==='confirm'?'Recibirás ideas para disfrutar tu próxima reunión. Puedes darte de baja en cualquier envío.':'No recibirás más newsletters. Gracias por habernos acompañado.'):'Puedes solicitar un nuevo enlace desde el sitio.';
            if(!$ok) http_response_code(400);
        } else {
            $title=$action==='confirm'?'Confirma tu suscripción':'¿Quieres dejar de recibir La sobremesa?';
            $message=$action==='confirm'?'Haz clic para recibir ideas, novedades y propuestas de Picando Tabla por correo.':'Confirma tu baja con el siguiente botón.'; $show=true;
        }
    }
} catch(Throwable $e) { http_response_code(503); $message='No pudimos procesar tu solicitud. Intenta de nuevo más tarde.'; }
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>La sobremesa · Picando Tabla</title><link rel="stylesheet" href="/newsletter/community.css"></head><body class="pt-letter-page"><main class="pt-letter-card"><a href="/">PICANDO TABLA</a><h1><?=htmlspecialchars($title)?></h1><p><?=htmlspecialchars($message)?></p><?php if($show): ?><form method="post"><button class="pt-community-button"><?= $action==='confirm'?'Confirmar mi suscripción':'Dar de baja mi correo' ?></button></form><?php endif; ?><p><a href="/">Volver a Picando Tabla</a></p></main></body></html>
