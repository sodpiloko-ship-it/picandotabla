<?php
// Verifica el magic-link (?e=email&t=token) -> inicia sesión -> redirige a la comanda.
declare(strict_types=1);
require __DIR__ . '/lib.php';

$email = (string) ($_GET['e'] ?? '');
$raw   = (string) ($_GET['t'] ?? '');
if ($email && $raw && cmd_verify_token($email, $raw)) {
    cmd_login($email);
    header('Location: ./');
    exit;
}
http_response_code(403);
$c = cmd_cfg();
echo '<!doctype html><meta charset="utf-8"><title>Enlace inválido</title>'
   . '<body style="font-family:system-ui;background:#e9eaea;color:#26282a;text-align:center;padding:60px">'
   . '<h1>' . cmd_esc($c['emoji']) . ' Enlace inválido o vencido</h1>'
   . '<p>Pide uno nuevo desde <a style="color:' . cmd_esc($c['accent']) . '" href="./">la comanda</a>.</p>';
