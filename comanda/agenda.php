<?php
// Agenda del día de compras (.ics): evento de calendario con los pedidos CONFIRMADOS pendientes.
// Solo con sesión de la comanda. El día por defecto es el próximo jueves (víspera de entregas vie/sáb).
declare(strict_types=1);
require __DIR__ . '/lib.php';

if (!cmd_current()) { http_response_code(403); exit('Necesitas entrar a la comanda.'); }

$dia = cmd_dia_compras();
$q = (string) ($_GET['d'] ?? '');
if ($q !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $q)) {
    try { $dia = new DateTime($q); } catch (Throwable $t) {}
}

$conf = cmd_confirmados();
$L = [];
$total = 0.0;
foreach ($conf as $o) {
    $total += (float) ($o['total'] ?? 0);
    $L[] = '· ' . ($o['nombre'] ?: 'Sin nombre')
         . (($o['fecha'] ?? '') !== '' ? ' (entrega: ' . $o['fecha'] . ')' : '')
         . ' — ' . implode(' / ', (array) ($o['items'] ?? []));
}
$desc = count($conf)
    ? "Compras para " . count($conf) . " pedido(s) confirmados — total $" . number_format($total, 0) . "\n\n" . implode("\n", $L)
    : "Sin pedidos confirmados por ahora. Revisa la comanda antes de salir de compras.";

$dt = $dia->format('Ymd');
$uid = 'compras-' . $dt . '@picandotabla.com';
$now = gmdate('Ymd\THis\Z');
$summary = 'Compras Picando Tabla (' . count($conf) . ' pedidos)';
$esc = fn(string $s): string => str_replace(["\\", "\n", ",", ";"], ["\\\\", "\\n", "\\,", "\\;"], $s);

$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Picando Tabla//Comanda//ES\r\n"
     . "BEGIN:VEVENT\r\nUID:{$uid}\r\nDTSTAMP:{$now}\r\n"
     . "DTSTART:{$dt}T090000\r\nDTEND:{$dt}T110000\r\n"
     . "SUMMARY:" . $esc($summary) . "\r\n"
     . "DESCRIPTION:" . $esc($desc) . "\r\n"
     . "END:VEVENT\r\nEND:VCALENDAR\r\n";

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="compras-picando-tabla-' . $dt . '.ics"');
echo $ics;
