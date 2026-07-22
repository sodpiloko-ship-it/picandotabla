<?php
// Comanda de Picando Tabla — núcleo (magic-link + lectura de pedidos/eventos).
// Adaptado del patrón probado del Panel de Fundador (Fanatia/Patológicos).
// Sin DB: pedidos en ../data/orders.jsonl y ../data/eventos.jsonl (los escribe order.php / evento.php);
// las cuentas y el estado de cada orden viven en comanda/data/ (gitignored, negado por .htaccess).
declare(strict_types=1);

date_default_timezone_set('America/Mexico_City');

const CMD_DATA   = __DIR__ . '/data';
const CMD_ACC    = __DIR__ . '/data/cuentas.jsonl';
const CMD_ESTADO = __DIR__ . '/data/estado.json';
const CMD_ORDERS  = __DIR__ . '/../data/orders.jsonl';
const CMD_EVENTOS = __DIR__ . '/../data/eventos.jsonl';

function cmd_cfg(): array {
    static $c = null;
    if ($c === null) $c = require __DIR__ . '/config.php';
    return $c;
}

function cmd_boot(): void {
    if (!is_dir(CMD_DATA)) @mkdir(CMD_DATA, 0750, true);
    $h = CMD_DATA . '/.htaccess';
    if (!is_file($h)) @file_put_contents($h, "Require all denied\nDeny from all\n");
}

function cmd_session(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_set_cookie_params(['lifetime' => 0, 'path' => '/comanda', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
        @session_start();
    }
}

// ---------- cuentas + magic-link ----------
function cmd_accounts(): array {
    if (!is_file(CMD_ACC)) return [];
    $out = [];
    foreach (file(CMD_ACC, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $r = json_decode($line, true);
        if (is_array($r)) $out[] = $r;
    }
    return $out;
}
function cmd_write_all(array $rows): void {
    cmd_boot();
    $lines = array_map(fn($r) => json_encode($r, JSON_UNESCAPED_UNICODE), $rows);
    @file_put_contents(CMD_ACC, implode("\n", $lines) . "\n", LOCK_EX);
}
function cmd_is_allowed(string $email): bool {
    $email = strtolower(trim($email));
    return in_array($email, array_map('strtolower', cmd_cfg()['admins'] ?? []), true);
}
function cmd_request_login(string $email): ?string {
    cmd_boot();
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !cmd_is_allowed($email)) return null;
    $raw = bin2hex(random_bytes(32));
    $hash = hash('sha256', $raw);
    $exp = time() + 1800; // 30 min
    $rows = cmd_accounts();
    $found = false;
    foreach ($rows as &$r) {
        if (($r['email'] ?? '') === $email) { $r['token_hash'] = $hash; $r['token_exp'] = $exp; $found = true; break; }
    }
    unset($r);
    if (!$found) $rows[] = ['email' => $email, 'created' => date('c'), 'token_hash' => $hash, 'token_exp' => $exp];
    cmd_write_all($rows);
    return $raw;
}
function cmd_verify_token(string $email, string $raw): bool {
    $email = strtolower(trim($email));
    $rows = cmd_accounts();
    $ok = false;
    foreach ($rows as &$r) {
        if (($r['email'] ?? '') === $email) {
            if (($r['token_exp'] ?? 0) >= time() && hash_equals($r['token_hash'] ?? '', hash('sha256', $raw))) {
                $r['token_hash'] = ''; $r['token_exp'] = 0; $r['last_login'] = date('c'); $ok = true;
            }
            break;
        }
    }
    unset($r);
    if ($ok) cmd_write_all($rows);
    return $ok;
}
function cmd_login(string $email): void {
    cmd_session();
    @session_regenerate_id(true);
    $_SESSION['cmd_email'] = strtolower(trim($email));
}
function cmd_current(): ?string {
    cmd_session();
    $e = $_SESSION['cmd_email'] ?? '';
    return ($e && cmd_is_allowed($e)) ? $e : null;
}
function cmd_logout(): void { cmd_session(); $_SESSION = []; @session_destroy(); }

function cmd_send_magic(string $email, string $raw): void {
    $c = cmd_cfg();
    $link = $c['base'] . '/entrar.php?e=' . urlencode($email) . '&t=' . urlencode($raw);
    $body = "Tu acceso a la Comanda de {$c['brand']}\n\n"
          . "Entra con este enlace (válido 30 minutos, un solo uso):\n$link\n\n"
          . "Desde aquí ves todos los pedidos y solicitudes de eventos, y marcas cada orden como confirmada o entregada.\n\n"
          . "Si no solicitaste esto, ignora el correo.\n";
    // Correo con envelope sender del dominio (-f): sin él, Gmail suele mandar el mail() de Hostinger a spam.
    $headers = "From: " . $c['from'] . "\r\n"
             . "Reply-To: contacto@picandotabla.com\r\n"
             . "Content-Type: text/plain; charset=UTF-8";
    $mail_ok = @mail($email, "Comanda {$c['brand']} — tu enlace de acceso", $body, $headers, '-fcontacto@picandotabla.com');

    // Respaldo por Telegram (mismo secrets/telegram.json que usa evento.php; si no está, se omite).
    $tg_ok = cmd_send_telegram($email, "🧀 Comanda Picando Tabla — enlace de acceso para {$email} (vale 30 min):\n{$link}");

    cmd_boot();
    @file_put_contents(CMD_DATA . '/envios.log',
        date('c') . " magic-link {$email} mail=" . ($mail_ok ? 'ok' : 'FALLO') . " tg=" . ($tg_ok ? 'ok' : 'no') . "\n",
        FILE_APPEND | LOCK_EX);
}

// Manda un texto por Telegram al chat asociado al correo ('default' = chat_id de secrets/telegram.json).
function cmd_send_telegram(string $email, string $text): bool {
    $f = __DIR__ . '/../secrets/telegram.json';
    if (!is_file($f)) return false;
    $s = json_decode((string) @file_get_contents($f), true);
    if (!is_array($s) || empty($s['bot_token'])) return false;
    $map = cmd_cfg()['telegram'] ?? [];
    $chat = $map[strtolower(trim($email))] ?? null;
    if ($chat === 'default') $chat = $s['chat_id'] ?? null;
    if (!$chat) return false;
    $url = 'https://api.telegram.org/bot' . $s['bot_token'] . '/sendMessage';
    $post = http_build_query(['chat_id' => (string) $chat, 'text' => substr($text, 0, 4090), 'disable_web_page_preview' => 'true']);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code >= 200 && $code < 300;
    }
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: application/x-www-form-urlencoded', 'content' => $post, 'timeout' => 15]]);
    return @file_get_contents($url, false, $ctx) !== false;
}

function cmd_csrf(): string {
    cmd_session();
    if (empty($_SESSION['cmd_csrf'])) $_SESSION['cmd_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['cmd_csrf'];
}
function cmd_csrf_ok(?string $t): bool {
    cmd_session();
    return !empty($_SESSION['cmd_csrf']) && is_string($t) && hash_equals($_SESSION['cmd_csrf'], $t);
}

// ---------- pedidos + eventos + estado ----------
function cmd_jsonl(string $file): array {
    if (!is_file($file)) return [];
    $out = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $r = json_decode($line, true);
        if (is_array($r)) $out[] = $r;
    }
    return $out;
}
// Clave estable de una orden: timestamp + nombre (suficiente para marcar estado sin DB).
function cmd_key(array $r): string { return substr(hash('sha256', ($r['at'] ?? '') . '|' . ($r['nombre'] ?? '')), 0, 16); }

function cmd_estados(): array {
    if (!is_file(CMD_ESTADO)) return [];
    $d = json_decode((string) @file_get_contents(CMD_ESTADO), true);
    return is_array($d) ? $d : [];
}
function cmd_set_estado(string $key, string $estado, string $por): void {
    cmd_boot();
    $all = cmd_estados();
    if ($estado === 'nueva') unset($all[$key]);
    else $all[$key] = ['estado' => $estado, 'por' => $por, 'at' => date('c')];
    @file_put_contents(CMD_ESTADO, json_encode($all, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

function cmd_esc(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

// Estado efectivo de una orden ('atendida' legado = 'confirmada').
function cmd_estado_de(array $estados, string $key): string {
    $e = $estados[$key]['estado'] ?? 'nueva';
    return $e === 'atendida' ? 'confirmada' : $e;
}

// Pedidos CONFIRMADOS y no entregados: la lista de compras de la semana.
function cmd_confirmados(): array {
    $estados = cmd_estados();
    $out = [];
    foreach (cmd_jsonl(CMD_ORDERS) as $o) {
        if (cmd_estado_de($estados, cmd_key($o)) === 'confirmada') $out[] = $o;
    }
    return $out;
}

// Próximo jueves (día de compras: víspera de las entregas de viernes/sábado).
function cmd_dia_compras(): DateTime {
    $d = new DateTime('thursday this week');
    if ($d < new DateTime('today')) $d->modify('+7 days');
    return $d;
}
