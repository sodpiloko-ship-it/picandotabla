<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/lib.php';

function nl_transport(array $message, array $subscriber): bool {
    $body = $message['body'];
    $headers = [
        'From' => 'Picando Tabla <contacto@picandotabla.com>',
        'Reply-To' => 'contacto@picandotabla.com',
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/plain; charset=UTF-8',
        'Content-Transfer-Encoding' => 'quoted-printable',
        'Date' => gmdate('D, d M Y H:i:s') . ' +0000',
        'Message-ID' => '<pt-' . hash('sha256', $message['email'] . ':' . $message['campaign']) . '@picandotabla.com>'
    ];
    if ($message['kind'] === 'test') {
        $body .= "\n\nEste es un envío de prueba interno. Este correo no te incorpora a una lista de suscriptores.";
    } elseif ($message['kind'] === 'newsletter') {
        $url = nl_url('unsubscribe', $subscriber['unsubscribe_token']);
        $body .= "\n\nRecibes este correo porque confirmaste tu suscripción.\nDar de baja mi correo: " . $url;
        $headers['List-Unsubscribe'] = '<' . $url . '>';
        // Advertise RFC8058 only after the MTA signs BOTH List-* headers with valid DKIM.
        if (getenv('PT_NEWSLETTER_ONE_CLICK') === '1' || (nl_config()['one_click'] ?? false) === true) {
            $headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        }
    }
    if (getenv('PT_NEWSLETTER_TEST') === '1') {
        if (getenv('PT_NEWSLETTER_TEST_THROW') === '1') throw new RuntimeException('Simulated uncertain handoff');
        if (getenv('PT_NEWSLETTER_TEST_FAIL') === '1') return false;
        return file_put_contents(nl_dir() . '/test-outbox.jsonl', json_encode([
            'email' => $message['email'], 'subject' => $message['subject'], 'body' => $body, 'headers' => $headers
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX) !== false;
    }
    // true means accepted by the local MTA, never proof of final delivery.
    $normalized = str_replace(["\r\n", "\r"], "\n", $body);
    $encoded = quoted_printable_encode(str_replace("\n", "\r\n", $normalized));
    return mail($message['email'], '=?UTF-8?B?' . base64_encode($message['subject']) . '?=', $encoded, $headers);
}

function nl_status(): array {
    $states = nl_query('SELECT state,COUNT(*) AS total FROM queue GROUP BY state')->fetchAll(PDO::FETCH_KEY_PAIR);
    return [
        'send_enabled' => nl_delivery_enabled(),
        'test_mode' => getenv('PT_NEWSLETTER_TEST') === '1',
        'subscribers' => nl_query('SELECT status,COUNT(*) AS total FROM subscribers GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR),
        'queue' => $states,
        'attempts_last_24h' => (int)nl_query('SELECT COUNT(*) FROM delivery_attempts WHERE attempted>?', [time() - 86400])->fetchColumn(),
        'daily_limit' => nl_setting('PT_NEWSLETTER_DAILY_LIMIT', 'daily_limit', 50, 90),
        'batch_limit' => nl_setting('PT_NEWSLETTER_BATCH_LIMIT', 'batch_limit', 5, 5)
    ];
}

function nl_run_worker(): array {
    if (!nl_delivery_enabled()) return ['disabled' => true, 'message' => 'Delivery is disabled; no messages attempted.'];
    nl_db();
    $lock = fopen(nl_dir() . '/worker.lock', 'c');
    if (!$lock) throw new RuntimeException('Cannot open worker lock');
    if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return ['locked' => true]; }
    try {
        // A crash after acceptance is ambiguous. NEVER automatically resend these messages.
        nl_query('UPDATE queue SET state="review",body="",updated=? WHERE state="sending" AND updated<?', [time(), time() - 900]);
        nl_query('UPDATE queue SET state="cancelled",body="",updated=? WHERE state="pending" AND email IN (SELECT email FROM subscribers WHERE status="pending" AND requested<=?)', [time(), time() - 172800]);
        nl_query('DELETE FROM subscribers WHERE status="pending" AND requested<?', [time() - 2592000]);
        nl_query('DELETE FROM queue WHERE kind<>"test" AND email NOT IN (SELECT email FROM subscribers)');
        nl_query('DELETE FROM queue WHERE updated<? AND state IN ("accepted","cancelled","failed")', [time() - 7776000]);
        nl_query('DELETE FROM delivery_attempts WHERE attempted<=?', [time() - 86400]);
        $dailyLimit = nl_setting('PT_NEWSLETTER_DAILY_LIMIT', 'daily_limit', 50, 90);
        $batchLimit = nl_setting('PT_NEWSLETTER_BATCH_LIMIT', 'batch_limit', 5, 5);
        $counts = ['accepted' => 0, 'retry' => 0, 'failed' => 0, 'cancelled' => 0, 'review' => 0, 'limited' => false];
        $rows = nl_query('SELECT * FROM queue WHERE state="pending" AND available<=? ORDER BY CASE WHEN kind="confirmation" THEN 0 ELSE 1 END,id LIMIT ?', [time(), $batchLimit])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $message) {
            $claim = nl_transaction(function () use ($message, $dailyLimit): array {
                $current = nl_query('SELECT state FROM queue WHERE id=?', [$message['id']])->fetchColumn();
                if ($current !== 'pending') return ['skip' => true];
                $subscriber = nl_query('SELECT * FROM subscribers WHERE email=?', [$message['email']])->fetch(PDO::FETCH_ASSOC);
                $eligible = $message['kind'] === 'test' || ($subscriber && ($message['kind'] === 'confirmation'
                    ? ($subscriber['status'] === 'pending' && (int)$subscriber['requested'] > time() - 172800
                        && $message['campaign'] === 'confirm-' . $subscriber['confirm_hash'])
                    : $subscriber['status'] === 'active'));
                if (!$eligible) {
                    nl_query('UPDATE queue SET state="cancelled",body="",updated=? WHERE id=?', [time(), $message['id']]);
                    return ['cancelled' => true];
                }
                // Count attempts, including failures, across invocations. Reserve capacity before handoff.
                $day = (int)nl_query('SELECT COUNT(*) FROM delivery_attempts WHERE attempted>?', [time() - 86400])->fetchColumn();
                $minute = (int)nl_query('SELECT COUNT(*) FROM delivery_attempts WHERE attempted>?', [time() - 60])->fetchColumn();
                if ($day >= $dailyLimit || $minute >= 5) return ['limited' => true];
                nl_query('INSERT INTO delivery_attempts (attempted) VALUES (?)', [time()]);
                nl_query('UPDATE queue SET state="sending",attempts=attempts+1,updated=? WHERE id=?', [time(), $message['id']]);
                return ['subscriber' => $subscriber ?: []];
            });
            if (isset($claim['skip'])) continue;
            if (isset($claim['cancelled'])) { $counts['cancelled']++; continue; }
            if (isset($claim['limited'])) { $counts['limited'] = true; break; }
            $attempt = (int)$message['attempts'] + 1;
            try {
                $accepted = nl_transport($message, $claim['subscriber']);
                $state = $accepted ? 'accepted' : ($attempt >= 5 ? 'failed' : 'pending');
                $counts[$accepted ? 'accepted' : ($state === 'failed' ? 'failed' : 'retry')]++;
            } catch (Throwable $error) {
                // The provider may have accepted the message before throwing.
                $state = 'review';
                $counts['review']++;
                error_log('Newsletter: uncertain mail handoff requires review; queue id ' . (int)$message['id']);
            }
            nl_query('UPDATE queue SET state=?,body=CASE WHEN ? THEN "" ELSE body END,available=?,updated=? WHERE id=? AND state="sending"',
                [$state, $state !== 'pending' ? 1 : 0, time() + min(86400, 300 * (2 ** ($attempt - 1))), time(), $message['id']]);
        }
        return $counts;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $command = $argv[1] ?? '';
        if ($command === '--status') {
            $result = nl_status();
        } elseif ($command === '--queue-test') {
            if (!nl_delivery_enabled()) throw new RuntimeException('Delivery is disabled');
            $edition = nl_edition($argv[2] ?? '');
            $email = nl_email($argv[3] ?? '');
            $testId = 'test-' . hash('sha256', json_encode([$email, $edition['id'], $edition['subject'], $edition['body']], JSON_THROW_ON_ERROR));
            nl_queue($email, 'test', $testId, '[PRUEBA] ' . $edition['subject'], $edition['body']);
            $result = ['test_queued' => true, 'message' => 'Internal test queued without creating a subscription; run the worker to attempt delivery.'];
        } elseif ($command === '--enable' || $command === '--disable') {
            // Private shared configuration works for both PHP-FPM and cron on shared hosting.
            $path = nl_dir() . '/config.json';
            $config = nl_config();
            $config['send_enabled'] = $command === '--enable';
            $temporary = nl_dir() . '/config-' . bin2hex(random_bytes(8)) . '.tmp';
            if (file_put_contents($temporary, json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n", LOCK_EX) === false) throw new RuntimeException('Cannot save configuration');
            @chmod($temporary, 0600);
            if (!rename($temporary, $path)) throw new RuntimeException('Cannot activate configuration');
            $result = ['send_enabled' => $config['send_enabled'], 'message' => 'Private configuration saved; no email sent.'];
        } elseif ($command !== '') {
            fwrite(STDERR, "Uso: php newsletter/worker.php [--status|--enable|--disable|--queue-test archivo.json correo]\n");
            exit(1);
        } else {
            $result = nl_run_worker();
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (!empty($result['disabled'])) exit(2);
    } catch (Throwable $error) {
        fwrite(STDERR, "Newsletter: operation failed. Check private storage and PHP SQLite configuration.\n");
        exit(1);
    }
}
