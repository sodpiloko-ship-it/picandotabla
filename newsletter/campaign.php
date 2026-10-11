<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib.php';
// Preview is the default; queue an approved edition explicitly with --queue.
$path = $argv[1] ?? '';
if (!is_file($path) || filesize($path) > 120000 || !in_array($argv[2] ?? '', ['', '--queue'], true)) {
    fwrite(STDERR, "Uso: php newsletter/campaign.php archivo.json [--queue]\n");
    exit(1);
}
try {
    $edition = nl_edition($path);
    $count = (int)nl_query('SELECT COUNT(*) FROM subscribers WHERE status="active"')->fetchColumn();
    echo $edition['subject'] . "\n\n" . $edition['body'] . "\n\nSuscriptores confirmados: " . $count . "\n";
    if (($argv[2] ?? '') !== '--queue') {
        echo "Vista previa: no se ha encolado ningún envío.\n";
        exit;
    }
    if (!nl_delivery_enabled()) {
        fwrite(STDERR, "El envío está desactivado. Configura el transporte y activa el newsletter antes de encolar.\n");
        exit(2);
    }
    $queued = nl_transaction(function () use ($edition): ?int {
        // Keep the edition ID after queue retention cleanup, preventing an accidental repeat years later.
        if (nl_query('SELECT 1 FROM campaigns WHERE id=?', [$edition['id']])->fetchColumn()) return null;
        $emails = nl_query('SELECT email FROM subscribers WHERE status="active"')->fetchAll(PDO::FETCH_COLUMN);
        if (!$emails) return 0;
        foreach ($emails as $email) nl_queue($email, 'newsletter', 'edition-' . $edition['id'], $edition['subject'], $edition['body']);
        nl_query('INSERT INTO campaigns (id,created,recipients) VALUES (?,?,?)', [$edition['id'], time(), count($emails)]);
        return count($emails);
    });
    echo $queued === null ? "Esta edición ya fue encolada. No se repitió ningún envío.\n"
        : "Edición encolada para " . $queued . " suscriptores. Las bajas se vuelven a comprobar antes de cada envío.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "No se pudo preparar la edición. Revisa el JSON y el almacenamiento privado.\n");
    exit(1);
}
