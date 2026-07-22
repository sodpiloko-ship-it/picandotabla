<?php
// Comanda de Picando Tabla — pedidos y solicitudes de eventos en una sola vista.
// Sin sesión: pide el magic-link. Con sesión: lista órdenes (nueva/atendida/entregada) + eventos.
declare(strict_types=1);
require __DIR__ . '/lib.php';

$c = cmd_cfg();
$yo = cmd_current();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string) ($_POST['accion'] ?? '');
    if ($accion === 'login') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $pass  = (string) ($_POST['pass'] ?? '');
        if (cmd_throttled()) {
            $msg = 'Demasiados intentos. Espera unos minutos e inténtalo de nuevo.';
        } elseif (cmd_is_allowed($email) && cmd_pass_check($pass)) {
            cmd_login($email);
            header('Location: ./'); exit;
        } else {
            cmd_fail();
            $msg = 'Correo o contraseña incorrectos.';
        }
    } elseif ($accion === 'setpass') {
        // Bootstrap (solo mientras NO exista clave) o cambio de clave con sesión + clave actual.
        $nueva = (string) ($_POST['nueva'] ?? '');
        if (cmd_pass_hash() === null) {
            $msg = cmd_pass_set($nueva) ? 'Contraseña creada. Ya puedes entrar.' : 'La contraseña debe tener al menos 8 caracteres.';
        } elseif ($yo && cmd_csrf_ok($_POST['csrf'] ?? null) && cmd_pass_check((string) ($_POST['actual'] ?? ''))) {
            $msg = cmd_pass_set($nueva) ? 'Contraseña actualizada.' : 'La nueva debe tener al menos 8 caracteres.';
        } else {
            cmd_fail();
            http_response_code(403);
            $msg = 'No autorizado.';
        }
    } elseif ($accion === 'estado' && $yo && cmd_csrf_ok($_POST['csrf'] ?? null)) {
        $key = (string) ($_POST['key'] ?? '');
        $estado = (string) ($_POST['estado'] ?? '');
        if ($key !== '' && in_array($estado, ['nueva', 'confirmada', 'entregada'], true)) {
            cmd_set_estado($key, $estado, $yo);
        }
        header('Location: ./'); exit;
    } elseif ($accion === 'manual' && $yo && cmd_csrf_ok($_POST['csrf'] ?? null)) {
        // Pedido cerrado por WhatsApp u otra vía: se registra a mano para que la comanda tenga TODO.
        $items = [];
        foreach (preg_split('/\r?\n/', (string) ($_POST['items'] ?? '')) as $ln) {
            $ln = substr(strip_tags(trim($ln)), 0, 120);
            if ($ln !== '') $items[] = $ln;
        }
        if (count($items)) {
            $rec = [
                'at' => date('c'),
                'nombre' => substr(strip_tags(trim((string) ($_POST['nombre'] ?? ''))), 0, 120),
                'whatsapp' => substr(strip_tags(trim((string) ($_POST['whatsapp'] ?? ''))), 0, 40),
                'zona' => substr(strip_tags(trim((string) ($_POST['zona'] ?? ''))), 0, 80),
                'fecha' => substr(strip_tags(trim((string) ($_POST['fecha'] ?? ''))), 0, 60),
                'total' => is_numeric($_POST['total'] ?? null) ? (float) $_POST['total'] : 0,
                'items' => $items,
                'origen' => 'manual (' . $yo . ')',
            ];
            @mkdir(dirname(CMD_ORDERS), 0755, true);
            $json = json_encode($rec, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($json !== false) {
                @file_put_contents(CMD_ORDERS, $json . PHP_EOL, FILE_APPEND | LOCK_EX);
                cmd_set_estado(cmd_key($rec), 'confirmada', $yo); // lo registrado a mano ya está confirmado
            }
        }
        header('Location: ./'); exit;
    } elseif ($accion === 'salir' && $yo) {
        cmd_logout();
        header('Location: ./'); exit;
    }
}

function cmd_head(string $title): void {
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="robots" content="noindex,nofollow"><title>' . cmd_esc($title) . '</title>'
       . '<style>'
       . '*{margin:0;padding:0;box-sizing:border-box}'
       . 'body{background:#e9eaea;color:#26282a;font-family:system-ui,sans-serif;min-height:100vh}'
       . '.wrap{max-width:860px;margin:0 auto;padding:28px 18px 60px}'
       . '.brand{font-family:Georgia,serif;font-weight:700;font-size:22px}'
       . '.sub{font-size:11px;letter-spacing:2px;color:#75797a;text-transform:uppercase}'
       . '.card{background:#fff;border:1px solid #d3d5d4;border-radius:14px;padding:18px 20px;margin-bottom:12px}'
       . '.btn{display:inline-block;background:#26282a;color:#fff;border:none;border-radius:999px;padding:11px 22px;font-size:14px;font-weight:600;cursor:pointer}'
       . '.btn2{background:transparent;border:1.5px solid #7c2d3e;color:#7c2d3e;border-radius:999px;padding:7px 14px;font-size:12.5px;font-weight:600;cursor:pointer}'
       . '.in{width:100%;background:#fbfcfc;border:1px solid #d3d5d4;border-radius:10px;padding:11px 13px;font-size:15px}'
       . '.pill{display:inline-block;font-size:11px;font-weight:700;letter-spacing:.5px;border-radius:999px;padding:3px 10px;text-transform:uppercase}'
       . '.p-nueva{background:#7c2d3e;color:#fff}.p-confirmada{background:#c9a44a;color:#fff}.p-entregada{background:#315c48;color:#fff}'
       . '.muted{color:#75797a;font-size:13px}'
       . '</style></head><body><div class="wrap">';
}

if (!$yo) {
    cmd_head('Comanda · ' . $c['brand']);
    echo '<div style="text-align:center;margin:40px 0 26px"><div class="brand">' . cmd_esc($c['emoji'] . ' ' . $c['brand']) . '</div><div class="sub">Comanda de órdenes</div></div>';
    echo '<div class="card" style="max-width:420px;margin:0 auto">';
    if ($msg) echo '<p style="background:#fbfcfc;border:1px solid #d3d5d4;border-radius:10px;padding:12px;font-size:14px;margin-bottom:14px">' . cmd_esc($msg) . '</p>';
    echo '<form method="post"><input type="hidden" name="accion" value="login">'
       . '<label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px">Tu correo</label>'
       . '<input class="in" type="email" name="email" required placeholder="tucorreo@ejemplo.com" style="margin-bottom:12px" autocomplete="username">'
       . '<label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px">Contraseña</label>'
       . '<input class="in" type="password" name="pass" required placeholder="Tu contraseña" style="margin-bottom:12px" autocomplete="current-password">'
       . '<button class="btn" style="width:100%">Entrar</button></form>'
       . '</div></div></body></html>';
    exit;
}

// ---------- vista con sesión ----------
$estados = cmd_estados();
$orders  = array_reverse(cmd_jsonl(CMD_ORDERS));   // más reciente primero
$eventos = array_reverse(cmd_jsonl(CMD_EVENTOS));
$csrf = cmd_csrf();

$cuenta = ['nueva' => 0, 'confirmada' => 0, 'entregada' => 0];
foreach ($orders as $o) {
    $e = cmd_estado_de($estados, cmd_key($o));
    $cuenta[$e] = ($cuenta[$e] ?? 0) + 1;
}
$dia = cmd_dia_compras();
$DIAS = ['Monday'=>'lunes','Tuesday'=>'martes','Wednesday'=>'miércoles','Thursday'=>'jueves','Friday'=>'viernes','Saturday'=>'sábado','Sunday'=>'domingo'];
$diaTxt = ($DIAS[$dia->format('l')] ?? $dia->format('l')) . ' ' . $dia->format('d/m');
$gcal = 'https://calendar.google.com/calendar/render?action=TEMPLATE'
      . '&text=' . rawurlencode('Compras Picando Tabla (' . $cuenta['confirmada'] . ' pedidos)')
      . '&dates=' . $dia->format('Ymd') . 'T090000/' . $dia->format('Ymd') . 'T110000'
      . '&ctz=America/Mexico_City'
      . '&details=' . rawurlencode('Lista completa en https://picandotabla.com/comanda/');

cmd_head('Comanda · ' . $c['brand']);
echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:20px;flex-wrap:wrap">'
   . '<div><div class="brand">' . cmd_esc($c['emoji'] . ' ' . $c['brand']) . '</div><div class="sub">Comanda de órdenes</div></div>'
   . '<form method="post" style="display:flex;align-items:center;gap:10px"><span class="muted">' . cmd_esc($yo) . '</span>'
   . '<input type="hidden" name="accion" value="salir"><button class="btn2">Salir</button></form></div>';

echo '<div style="display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap">'
   . '<div class="card" style="flex:1;min-width:110px;text-align:center;margin:0"><div style="font-size:26px;font-weight:700;color:#7c2d3e">' . $cuenta['nueva'] . '</div><div class="muted">Nuevas</div></div>'
   . '<div class="card" style="flex:1;min-width:110px;text-align:center;margin:0"><div style="font-size:26px;font-weight:700;color:#c9a44a">' . $cuenta['confirmada'] . '</div><div class="muted">Confirmadas</div></div>'
   . '<div class="card" style="flex:1;min-width:110px;text-align:center;margin:0"><div style="font-size:26px;font-weight:700;color:#315c48">' . $cuenta['entregada'] . '</div><div class="muted">Entregadas</div></div>'
   . '</div>';

// --- Día de compras: los pedidos confirmados de la semana, agendables con un clic ---
echo '<div class="card" style="border-left:4px solid #c9a44a">'
   . '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:6px">'
   . '<b>🛒 Día de compras: ' . cmd_esc($diaTxt) . '</b>'
   . '<span class="muted">' . $cuenta['confirmada'] . ' pedido(s) confirmados por comprar</span></div>';
if ($cuenta['confirmada']) {
    $agg = [];
    foreach (cmd_confirmados() as $o) foreach ((array) ($o['items'] ?? []) as $it) {
        $nm = preg_replace('/^\d+x\s*/', '', preg_replace('/\s*\(\$[\d,\.]+\)\s*$/', '', (string) $it));
        $agg[$nm] = ($agg[$nm] ?? 0) + (preg_match('/^(\d+)x/', (string) $it, $m) ? (int) $m[1] : 1);
    }
    echo '<div style="font-size:14px;line-height:1.7;margin-bottom:10px">';
    foreach ($agg as $nm => $qty) echo cmd_esc($qty . '× ' . $nm) . '<br>';
    echo '</div>';
} else {
    echo '<p class="muted" style="margin-bottom:10px">Confirma pedidos (botón "Marcar confirmada") y aquí se arma sola la lista de compras de la semana.</p>';
}
echo '<div style="display:flex;gap:8px;flex-wrap:wrap">'
   . '<a class="btn2" style="text-decoration:none" href="agenda.php">Agendar (.ics)</a>'
   . '<a class="btn2" style="text-decoration:none" target="_blank" rel="noopener" href="' . cmd_esc($gcal) . '">Google Calendar</a>'
   . '</div></div>';

// --- Registro manual: pedidos cerrados por WhatsApp también viven aquí ---
echo '<details class="card" style="margin-top:6px"><summary style="cursor:pointer;font-weight:600;font-size:14.5px">＋ Registrar pedido manual (cerrado por WhatsApp)</summary>'
   . '<form method="post" style="margin-top:14px"><input type="hidden" name="accion" value="manual">'
   . '<input type="hidden" name="csrf" value="' . cmd_esc($csrf) . '">'
   . '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">'
   . '<input class="in" name="nombre" placeholder="Nombre del cliente" required>'
   . '<input class="in" name="whatsapp" placeholder="WhatsApp del cliente">'
   . '<input class="in" name="zona" placeholder="Zona / colonia">'
   . '<input class="in" name="fecha" placeholder="Día de entrega (ej. viernes 31)">'
   . '</div>'
   . '<textarea class="in" name="items" rows="3" style="margin-top:10px" placeholder="Un item por línea, ej.&#10;1x Tabla de Fiesta ($1,600)&#10;1x Mensajería CDMX ($120)" required></textarea>'
   . '<div style="display:flex;gap:10px;margin-top:10px;align-items:center">'
   . '<input class="in" name="total" type="number" step="1" min="0" placeholder="Total $" style="max-width:140px">'
   . '<button class="btn">Registrar (queda confirmado)</button></div>'
   . '</form></details>';

echo '<h2 style="font-size:17px;margin:0 0 12px">Pedidos (' . count($orders) . ')</h2>';
if (!count($orders)) echo '<div class="card muted">Aún no hay pedidos. Cada pedido del sitio aparece aquí y además llega por correo.</div>';
foreach ($orders as $o) {
    $key = cmd_key($o);
    $estado = cmd_estado_de($estados, $key);
    $fecha = $o['at'] ?? '';
    try { $fecha = (new DateTime($fecha))->format('d/m/Y H:i'); } catch (Throwable $t) {}
    echo '<div class="card">'
       . '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:8px">'
       . '<div><span class="pill p-' . cmd_esc($estado) . '">' . cmd_esc($estado) . '</span> '
       . '<b>$' . number_format((float) ($o['total'] ?? 0), 0) . '</b> · ' . cmd_esc($o['nombre'] ?: 'Sin nombre') . '</div>'
       . '<span class="muted">' . cmd_esc($fecha) . '</span></div>'
       . '<div style="font-size:14px;line-height:1.6;margin-bottom:8px">' . implode('<br>', array_map('cmd_esc', (array) ($o['items'] ?? []))) . '</div>'
       . '<div class="muted" style="margin-bottom:10px">'
       . (!empty($o['whatsapp']) ? 'WhatsApp: ' . cmd_esc($o['whatsapp']) . ' · ' : '')
       . (!empty($o['zona']) ? 'Zona: ' . cmd_esc($o['zona']) . ' · ' : '')
       . (!empty($o['fecha']) ? 'Entrega: ' . cmd_esc($o['fecha']) . ' · ' : '')
       . (!empty($o['origen']) ? 'Origen: ' . cmd_esc($o['origen']) : 'Origen: sitio web') . '</div>'
       . '<div style="display:flex;gap:8px;flex-wrap:wrap">';
    foreach (['nueva' => 'Nueva', 'confirmada' => 'Confirmada', 'entregada' => 'Entregada'] as $val => $lbl) {
        if ($val === $estado) continue;
        echo '<form method="post" style="display:inline"><input type="hidden" name="accion" value="estado">'
           . '<input type="hidden" name="csrf" value="' . cmd_esc($csrf) . '">'
           . '<input type="hidden" name="key" value="' . cmd_esc($key) . '">'
           . '<input type="hidden" name="estado" value="' . cmd_esc($val) . '">'
           . '<button class="btn2">Marcar ' . cmd_esc(strtolower($lbl)) . '</button></form>';
    }
    echo '</div></div>';
}

echo '<h2 style="font-size:17px;margin:24px 0 12px">Solicitudes de eventos (' . count($eventos) . ')</h2>';
if (!count($eventos)) echo '<div class="card muted">Aún no hay solicitudes de eventos.</div>';
foreach ($eventos as $ev) {
    $fecha = $ev['at'] ?? '';
    try { $fecha = (new DateTime($fecha))->format('d/m/Y H:i'); } catch (Throwable $t) {}
    echo '<div class="card"><div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:6px">'
       . '<b>' . cmd_esc($ev['nombre'] ?? 'Sin nombre') . '</b><span class="muted">' . cmd_esc($fecha) . '</span></div>'
       . '<div style="font-size:14px;line-height:1.7">';
    foreach (['evento' => 'Tipo', 'empresa' => 'Empresa', 'personas' => 'Personas', 'fecha' => 'Fecha del evento',
              'zona' => 'Zona', 'presupuesto_tipo' => 'Tipo de presupuesto', 'presupuesto' => 'Presupuesto',
              'telefono' => 'Teléfono', 'correo' => 'Correo',
              'servicios' => 'Le interesa', 'vinos' => 'Vinos', 'detalles' => 'Detalles'] as $k => $lbl) {
        $v = $ev[$k] ?? '';
        if (is_array($v)) $v = implode(', ', $v);
        if ((string) $v !== '') echo '<span class="muted">' . cmd_esc($lbl) . ':</span> ' . cmd_esc((string) $v) . '<br>';
    }
    echo '</div></div>';
}

echo '<details style="margin-top:26px"><summary class="muted" style="cursor:pointer;text-align:center">Cambiar contraseña</summary>'
   . '<form method="post" class="card" style="max-width:420px;margin:12px auto 0">'
   . '<input type="hidden" name="accion" value="setpass"><input type="hidden" name="csrf" value="' . cmd_esc($csrf) . '">'
   . '<input class="in" type="password" name="actual" required placeholder="Contraseña actual" style="margin-bottom:10px" autocomplete="current-password">'
   . '<input class="in" type="password" name="nueva" required minlength="8" placeholder="Nueva contraseña (mín. 8)" style="margin-bottom:10px" autocomplete="new-password">'
   . '<button class="btn" style="width:100%">Actualizar (aplica a las dos cuentas)</button></form></details>';
echo '<p class="muted" style="margin-top:14px;text-align:center">Comanda de ' . cmd_esc($c['brand']) . ' · los datos nunca salen de este servidor.</p>';
echo '</div></body></html>';
