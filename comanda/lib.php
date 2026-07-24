<?php
// Comanda de Picando Tabla — núcleo (magic-link + lectura de pedidos/eventos).
// Adaptado del patrón probado del Panel de Fundador (Fanatia/Patológicos).
// Sin DB: pedidos en ../data/orders.jsonl y ../data/eventos.jsonl (los escribe order.php / evento.php);
// las cuentas y el estado de cada orden viven en comanda/data/ (gitignored, negado por .htaccess).
declare(strict_types=1);

date_default_timezone_set('America/Mexico_City');

// IMPORTANTE (2026-07-24): el estado de la comanda vive junto a los pedidos, en ../data/comanda/.
// El deploy por FTP SINCRONIZA la carpeta comanda/ y borra lo que no esta en el repo: cuando los
// datos vivian en comanda/data/ cada publicacion tiraba la contrasena y los estados de los pedidos.
// ../data/ sobrevive a los deploys (ahi ya vivian orders.jsonl y eventos.jsonl sin perderse).
const CMD_DATA   = __DIR__ . '/../data/comanda';
const CMD_ESTADO = __DIR__ . '/../data/comanda/estado.json';
const CMD_VIEJO  = __DIR__ . '/data';   // ruta anterior: solo para migrar lo que quede
const CMD_ORDERS  = __DIR__ . '/../data/orders.jsonl';
const CMD_EVENTOS = __DIR__ . '/../data/eventos.jsonl';

function cmd_cfg(): array {
    static $c = null;
    if ($c === null) $c = require __DIR__ . '/config.php';
    return $c;
}

function cmd_boot(): void {
    if (!is_dir(CMD_DATA)) {
        @mkdir(CMD_DATA, 0750, true);
        // Migracion de un solo paso desde la ruta vieja (si el deploy no la borro antes).
        foreach (['clave.txt', 'estado.json', 'accesos.log'] as $f) {
            if (is_file(CMD_VIEJO . '/' . $f) && !is_file(CMD_DATA . '/' . $f)) {
                @copy(CMD_VIEJO . '/' . $f, CMD_DATA . '/' . $f);
            }
        }
    }
    $h = CMD_DATA . '/.htaccess';
    if (!is_file($h)) @file_put_contents($h, "Require all denied\nDeny from all\n");
}

function cmd_session(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_set_cookie_params(['lifetime' => 0, 'path' => '/comanda', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
        @session_start();
    }
}

// ---------- login con contraseña (una clave compartida por las cuentas de la allowlist) ----------
// La clave NUNCA vive en el repo (es público): su hash se guarda en comanda/data/clave.txt (runtime,
// denegado por web + gitignored). Si existe ../secrets/comanda-clave.txt en el server, ese hash MANDA
// (vía de rotación manual). El bootstrap (primer set) solo funciona mientras no exista ningún hash.
const CMD_CLAVE = __DIR__ . '/../data/comanda/clave.txt';

function cmd_is_allowed(string $email): bool {
    $email = strtolower(trim($email));
    return in_array($email, array_map('strtolower', cmd_cfg()['admins'] ?? []), true);
}
function cmd_pass_hash(): ?string {
    $ov = __DIR__ . '/../secrets/comanda-clave.txt';
    foreach ([$ov, CMD_CLAVE] as $f) {
        if (is_file($f)) {
            $h = trim((string) @file_get_contents($f));
            if ($h !== '') return $h;
        }
    }
    return null;
}
function cmd_pass_set(string $raw): bool {
    if (strlen($raw) < 8) return false;
    cmd_boot();
    return @file_put_contents(CMD_CLAVE, password_hash($raw, PASSWORD_BCRYPT) . "\n", LOCK_EX) !== false;
}
function cmd_pass_check(string $raw): bool {
    $h = cmd_pass_hash();
    return $h !== null && password_verify($raw, $h);
}

// Freno anti fuerza bruta POR IP (antes era global: un solo cliente equivocandose dejaba fuera
// a las dos duenas del negocio — paso el 2026-07-24 durante las pruebas). Se pausa esa IP tras
// 8 fallos en 10 min; el tope global es un respaldo muy alto contra un ataque distribuido.
function cmd_ip(): string {
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    return substr(hash('sha256', $ip), 0, 12);   // no guardamos la IP en claro
}
function cmd_fallos(): array {
    $f = CMD_DATA . '/fallos.log';
    if (!is_file($f)) return [];
    $out = [];
    $corte = time() - 600;
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
        $p = explode(' ', $ln, 2);
        if ((int) $p[0] > $corte) $out[] = ['t' => (int) $p[0], 'ip' => $p[1] ?? ''];
    }
    return $out;
}
function cmd_throttled(): bool {
    $f = cmd_fallos();
    if (count($f) > 40) return true;                 // respaldo global
    $yo = cmd_ip();
    $n = 0;
    foreach ($f as $x) if ($x['ip'] === $yo) $n++;
    return $n > 8;
}
function cmd_fail(): void {
    cmd_boot();
    // Reescribimos solo lo vigente: el archivo no crece para siempre.
    $lines = array_map(function ($x) { return $x['t'] . ' ' . $x['ip']; }, cmd_fallos());
    $lines[] = time() . ' ' . cmd_ip();
    @file_put_contents(CMD_DATA . '/fallos.log', implode("\n", $lines) . "\n", LOCK_EX);
    usleep(400000);
}

function cmd_login(string $email): void {
    cmd_session();
    @session_regenerate_id(true);
    $_SESSION['cmd_email'] = strtolower(trim($email));
    cmd_boot();
    @file_put_contents(CMD_DATA . '/accesos.log', date('c') . ' login ' . strtolower(trim($email)) . "\n", FILE_APPEND | LOCK_EX);
}
function cmd_current(): ?string {
    cmd_session();
    $e = $_SESSION['cmd_email'] ?? '';
    return ($e && cmd_is_allowed($e)) ? $e : null;
}
function cmd_logout(): void { cmd_session(); $_SESSION = []; @session_destroy(); }

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

// Botón directo al WhatsApp del cliente: normaliza a 52XXXXXXXXXX y arma el wa.me con el mensaje listo.
function cmd_wa_link(array $o): ?string {
    $tel = preg_replace('/\D+/', '', (string) ($o['whatsapp'] ?? ''));
    if (strlen($tel) === 10) $tel = '52' . $tel;
    if (strlen($tel) < 12) return null;
    $nom = trim((string) ($o['nombre'] ?? ''));
    $msg = '¡Hola' . ($nom !== '' ? ' ' . $nom : '') . '! Soy Jessica de Picando Tabla 🧀 Recibimos tu pedido por $'
         . number_format((float) ($o['total'] ?? 0), 0)
         . (($o['fecha'] ?? '') !== '' ? ' para el ' . $o['fecha'] : '')
         . '. Te comparto los datos para la transferencia y con eso queda apartada tu fecha. ¡Gracias!';
    return 'https://wa.me/' . $tel . '?text=' . rawurlencode($msg);
}

// Próximo jueves (día de compras: víspera de las entregas de viernes/sábado).
function cmd_dia_compras(): DateTime {
    $d = new DateTime('thursday this week');
    if ($d < new DateTime('today')) $d->modify('+7 days');
    return $d;
}
