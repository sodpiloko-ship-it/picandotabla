<?php
// Comanda de Picando Tabla — núcleo (magic-link + lectura de pedidos/eventos).
// Adaptado del patrón probado del Panel de Fundador (Fanatia/Patológicos).
// Sin DB: pedidos en ../data/orders.jsonl y ../data/eventos.jsonl (los escribe order.php / evento.php);
// las cuentas y el estado de cada orden viven en comanda/data/ (gitignored, negado por .htaccess).
declare(strict_types=1);

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
          . "Desde aquí ves todos los pedidos y solicitudes de eventos, y marcas cada orden como atendida o entregada.\n\n"
          . "Si no solicitaste esto, ignora el correo.\n";
    @mail($email, "Comanda {$c['brand']} — tu enlace de acceso", $body, "From: " . $c['from'] . "\r\n");
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
