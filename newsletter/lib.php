<?php
declare(strict_types=1);

// Never import checkout customers. Only explicit, confirmed subscriptions can activate an address.
function nl_dir(): string {
    $dir = getenv('PT_NEWSLETTER_DATA') ?: dirname(__DIR__, 2) . '/.picandotabla-newsletter';
    if (getenv('PT_NEWSLETTER_TEST') === '1' && !getenv('PT_NEWSLETTER_DATA')) {
        throw new RuntimeException('Test storage must be explicit');
    }
    if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $dir)) throw new RuntimeException('Storage must be absolute');
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Storage unavailable');
    $resolved = realpath($dir);
    $root = realpath(dirname(__DIR__));
    if ($resolved === false || $root === false) throw new RuntimeException('Storage unavailable');
    $normalized = strtolower(str_replace('\\', '/', $resolved)) . '/';
    if (str_starts_with($normalized, strtolower(str_replace('\\', '/', $root)) . '/')) {
        throw new RuntimeException('Storage must be outside the public website');
    }
    return $resolved;
}

function nl_config(): array {
    $path = nl_dir() . '/config.json';
    if (!is_file($path)) return [];
    $config = json_decode(file_get_contents($path), true);
    if (!is_array($config)) throw new RuntimeException('Invalid private configuration');
    return $config;
}

function nl_delivery_enabled(): bool {
    if (getenv('PT_NEWSLETTER_TEST') === '1') return true;
    $value = getenv('PT_NEWSLETTER_SEND_ENABLED');
    return $value !== false ? $value === '1' : (nl_config()['send_enabled'] ?? false) === true;
}

function nl_setting(string $env, string $key, int $default, int $max): int {
    $raw = getenv($env);
    if ($raw === false) $raw = nl_config()[$key] ?? $default;
    return is_numeric($raw) ? max(1, min($max, (int)$raw)) : $default;
}

function nl_db(): PDO {
    static $db;
    if ($db instanceof PDO) return $db;
    $path = nl_dir() . '/newsletter.sqlite';
    $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    @chmod($path, 0600);
    $db->exec('PRAGMA busy_timeout=5000');
    $db->exec('CREATE TABLE IF NOT EXISTS subscribers (email TEXT PRIMARY KEY, status TEXT NOT NULL, confirm_hash TEXT NOT NULL, unsubscribe_token TEXT NOT NULL, requested INTEGER NOT NULL, confirmed INTEGER, source TEXT, consent_version TEXT NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS queue (id INTEGER PRIMARY KEY, email TEXT NOT NULL, kind TEXT NOT NULL, campaign TEXT NOT NULL, subject TEXT NOT NULL, body TEXT NOT NULL, state TEXT NOT NULL DEFAULT "pending", attempts INTEGER NOT NULL DEFAULT 0, available INTEGER NOT NULL, updated INTEGER NOT NULL, UNIQUE(email,campaign))');
    $db->exec('CREATE TABLE IF NOT EXISTS rate_limits (key TEXT PRIMARY KEY, window INTEGER NOT NULL, count INTEGER NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS delivery_attempts (attempted INTEGER NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS campaigns (id TEXT PRIMARY KEY, created INTEGER NOT NULL, recipients INTEGER NOT NULL)');
    $db->exec('CREATE INDEX IF NOT EXISTS queue_ready ON queue(state,available)');
    $db->exec('CREATE INDEX IF NOT EXISTS subscriber_confirmation ON subscribers(confirm_hash)');
    $db->exec('CREATE INDEX IF NOT EXISTS subscriber_unsubscribe ON subscribers(unsubscribe_token)');
    return $db;
}

function nl_query(string $sql, array $params = []): PDOStatement {
    $statement = nl_db()->prepare($sql);
    $statement->execute($params);
    return $statement;
}

function nl_transaction(callable $operation): mixed {
    $db = nl_db();
    // Reserve the writer before reading; concurrent confirmations cannot race.
    $db->exec('BEGIN IMMEDIATE');
    try {
        $result = $operation();
        $db->exec('COMMIT');
        return $result;
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}

function nl_email(string $email): string {
    $email = strtolower(trim($email));
    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n\x00]/', $email)) {
        throw new InvalidArgumentException('Invalid email');
    }
    return $email;
}

function nl_valid_token(string $token): bool { return preg_match('/^[a-f0-9]{64}$/D', $token) === 1; }

function nl_queue(string $email, string $kind, string $campaign, string $subject, string $body): void {
    $email = nl_email($email);
    if (!in_array($kind, ['confirmation', 'newsletter', 'test'], true) || !preg_match('/^[a-z0-9-]{3,100}$/D', $campaign)
        || trim($subject) === '' || strlen($subject) > 200 || preg_match('/[\r\n\x00]/', $subject)
        || trim($body) === '' || strlen($body) > 100000) throw new InvalidArgumentException('Invalid message');
    nl_query('INSERT OR IGNORE INTO queue (email,kind,campaign,subject,body,available,updated) VALUES (?,?,?,?,?,?,?)',
        [$email, $kind, $campaign, $subject, $body, time(), time()]);
}

function nl_url(string $action, string $token): string {
    return 'https://picandotabla.com/newsletter/?action=' . $action . '&token=' . rawurlencode($token);
}

function nl_edition(string $path): array {
    if (!is_file($path) || filesize($path) > 120000) throw new InvalidArgumentException('Invalid edition file');
    $edition = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($edition) || !is_string($edition['id'] ?? null) || !preg_match('/^[a-z0-9-]{3,80}$/D', $edition['id'])
        || !is_string($edition['subject'] ?? null) || trim($edition['subject']) === '' || strlen($edition['subject']) > 180
        || preg_match('/[\r\n\x00]/', $edition['subject']) || !is_string($edition['body'] ?? null)
        || trim($edition['body']) === '' || strlen($edition['body']) > 100000) throw new InvalidArgumentException('Invalid edition');
    return $edition;
}

function nl_limit(string $ip): bool {
    return nl_transaction(function () use ($ip): bool {
        $key = hash('sha256', $ip);
        $window = (int)floor(time() / 3600);
        nl_query('DELETE FROM rate_limits WHERE window < ?', [$window - 1]);
        nl_query('INSERT INTO rate_limits (key,window,count) VALUES (?,?,1) ON CONFLICT(key) DO UPDATE SET count=CASE WHEN window=excluded.window THEN count+1 ELSE 1 END, window=excluded.window', [$key, $window]);
        return (int)nl_query('SELECT count FROM rate_limits WHERE key=?', [$key])->fetchColumn() <= 8;
    });
}

function nl_subscribe(string $email, string $source): void {
    $email = nl_email($email);
    $source = substr(preg_replace('/[\x00-\x1F\x7F]/', '', $source) ?? '/', 0, 160);
    nl_transaction(function () use ($email, $source): void {
        $old = nl_query('SELECT * FROM subscribers WHERE email=?', [$email])->fetch(PDO::FETCH_ASSOC);
        if ($old && ($old['status'] === 'active' || ($old['status'] === 'pending' && (int)$old['requested'] > time() - 86400))) return;
        $token = bin2hex(random_bytes(32));
        $unsub = bin2hex(random_bytes(32));
        nl_query('INSERT INTO subscribers (email,status,confirm_hash,unsubscribe_token,requested,source,consent_version) VALUES (?,"pending",?,?,?,?,?) ON CONFLICT(email) DO UPDATE SET status="pending",confirm_hash=excluded.confirm_hash,unsubscribe_token=excluded.unsubscribe_token,requested=excluded.requested,confirmed=NULL,source=excluded.source,consent_version=excluded.consent_version',
            [$email, hash('sha256', $token), $unsub, time(), $source, '2026-10-10']);
        nl_query('UPDATE queue SET state="cancelled",body="",updated=? WHERE email=? AND state="pending"', [time(), $email]);
        nl_queue($email, 'confirmation', 'confirm-' . hash('sha256', $token), 'Confirma tu suscripción · Picando Tabla',
            "Hola,\n\nSolicitaste recibir La sobremesa, el newsletter de Picando Tabla: ideas para compartir, novedades y propuestas para reuniones.\n\nConfirma tu correo en las próximas 48 horas:\n"
            . nl_url('confirm', $token) . "\n\nSi no lo solicitaste, ignora este mensaje. No recibirás el newsletter sin confirmar.\n\nPicando Tabla · CDMX\ncontacto@picandotabla.com");
    });
}

function nl_confirm(string $token): bool {
    if (!nl_valid_token($token)) return false;
    return nl_transaction(function () use ($token): bool {
        $subscriber = nl_query('SELECT * FROM subscribers WHERE confirm_hash=?', [hash('sha256', $token)])->fetch(PDO::FETCH_ASSOC);
        if (!$subscriber || $subscriber['status'] === 'unsubscribed') return false;
        if ($subscriber['status'] === 'active') return true;
        if ((int)$subscriber['requested'] <= time() - 172800) return false;
        nl_query('UPDATE subscribers SET status="active",confirmed=? WHERE email=?', [time(), $subscriber['email']]);
        nl_query('UPDATE queue SET state="cancelled",body="",updated=? WHERE email=? AND kind="confirmation" AND state="pending"', [time(), $subscriber['email']]);
        nl_queue($subscriber['email'], 'newsletter', 'welcome-' . hash('sha256', $token), 'Bienvenido a La sobremesa · Picando Tabla',
            "Hay reuniones que empiezan con una tabla.\n\nGracias por hacer un lugar para Picando Tabla en tu mesa. En La sobremesa compartiremos ideas para recibir, combinar sabores y preparar tu próxima celebración.\n\nPara empezar: piensa cuántas personas vienen, si la tabla será un aperitivo o el centro de la reunión y qué restricciones de alimentos debemos conocer. Con eso podemos ayudarte a elegir.\n\nExplora nuestras tablas: https://picandotabla.com/tablas/\nCuéntanos de tu evento: https://picandotabla.com/eventos/\n\nSíguenos en Instagram: https://www.instagram.com/picandotabla/\nEncuéntranos en Facebook: https://www.facebook.com/profile.php?id=61591673554258\n\nPicando Tabla · CDMX\ncontacto@picandotabla.com");
        return true;
    });
}

function nl_unsubscribe(string $token): bool {
    if (!nl_valid_token($token)) return false;
    return nl_transaction(function () use ($token): bool {
        $email = nl_query('SELECT email FROM subscribers WHERE unsubscribe_token=?', [$token])->fetchColumn();
        if (!$email) return false;
        nl_query('UPDATE subscribers SET status="unsubscribed",confirm_hash="" WHERE email=?', [$email]);
        nl_query('UPDATE queue SET state="cancelled",body="",updated=? WHERE email=? AND state="pending"', [time(), $email]);
        return true;
    });
}
