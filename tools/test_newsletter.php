<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('PT_NEWSLETTER_TEST') !== '1') {
    fwrite(STDERR, "Tests require CLI and PT_NEWSLETTER_TEST=1.\n"); exit(1);
}
$storage = getenv('PT_NEWSLETTER_DATA');
if (!$storage || !preg_match('~(?:^|[\\\\/])work[\\\\/]~i', $storage) || is_file(rtrim($storage, '/\\') . '/newsletter.sqlite')) {
    fwrite(STDERR, "Use a new, empty PT_NEWSLETTER_DATA directory below work/.\n"); exit(1);
}
require dirname(__DIR__) . '/newsletter/worker.php';
$passed = 0;
function check(bool $condition, string $description): void {
    global $passed;
    if (!$condition) throw new RuntimeException('FAIL: ' . $description);
    $passed++;
    echo "PASS: " . $description . "\n";
}
function count_rows(string $table, string $where = '1'): int {
    return (int)nl_query('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where)->fetchColumn();
}
function reset_data(): void {
    foreach (['queue', 'subscribers', 'rate_limits', 'delivery_attempts', 'campaigns'] as $table) nl_query('DELETE FROM ' . $table);
    file_put_contents(nl_dir() . '/test-outbox.jsonl', '');
    foreach (['PT_NEWSLETTER_TEST_FAIL', 'PT_NEWSLETTER_TEST_THROW', 'PT_NEWSLETTER_DAILY_LIMIT', 'PT_NEWSLETTER_SEND_ENABLED'] as $key) putenv($key);
}
function token_for(string $email): string {
    $body = (string)nl_query('SELECT body FROM queue WHERE email=? AND kind="confirmation" ORDER BY id DESC LIMIT 1', [$email])->fetchColumn();
    if (!preg_match('/token=([a-f0-9]{64})/', $body, $matches)) throw new RuntimeException('Test token missing');
    return $matches[1];
}
function activate(string $email): string {
    nl_subscribe($email, '/test');
    $token = token_for($email);
    if (!nl_confirm($token)) throw new RuntimeException('Activation failed');
    return $token;
}
function cli_run(string $script, array $args = []): array {
    $command = array_merge([PHP_BINARY, '-d', 'extension_dir=' . ini_get('extension_dir'), '-d', 'extension=pdo_sqlite', $script], $args);
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('Cannot start PHP subprocess');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($process), $out, $err];
}

nl_db();
reset_data();
nl_subscribe(' Alice@Example.invalid ', '/blog/');
$subscriber = nl_query('SELECT * FROM subscribers')->fetch(PDO::FETCH_ASSOC);
check($subscriber['email'] === 'alice@example.invalid' && $subscriber['status'] === 'pending', 'An address starts pending and is normalized');
check($subscriber['confirmed'] === null && $subscriber['consent_version'] === '2026-10-10', 'Consent request and confirmation remain distinct');
$token = token_for('alice@example.invalid');
check($subscriber['confirm_hash'] === hash('sha256', $token), 'Only the hash is stored in subscriber confirmation data');
nl_subscribe('alice@example.invalid', '/');
check(count_rows('queue') === 1, 'Duplicate pending subscriptions do not produce duplicate emails');
check(!nl_confirm(str_repeat('a', 64)) && !nl_confirm('invalid'), 'Unknown or malformed tokens cannot activate an address');
$first = nl_run_worker();
check($first['accepted'] === 1 && count_rows('subscribers', 'status="active"') === 0, 'Confirmation delivery does not activate the address');
check(nl_confirm($token), 'A valid double opt-in activates the address');
check(nl_confirm($token) && count_rows('queue', 'kind="newsletter"') === 1, 'Repeated confirmation produces one welcome message');
$second = nl_run_worker();
check($second['accepted'] === 1, 'Welcome is queued and delivered only after confirmation');
$outbox = array_map(fn($line) => json_decode($line, true), file(nl_dir() . '/test-outbox.jsonl', FILE_IGNORE_NEW_LINES));
check(!isset($outbox[0]['headers']['List-Unsubscribe']) && isset($outbox[1]['headers']['List-Unsubscribe']), 'Marketing emails include the unsubscribe URL');
check(str_contains($outbox[1]['body'], '61591673554258') && str_contains($outbox[1]['body'], 'instagram.com/picandotabla/'), 'Welcome includes both official social accounts');
check(!isset($outbox[1]['headers']['List-Unsubscribe-Post']), 'One-click advertising remains off until DKIM verification');
check(count_rows('queue', 'state="accepted" AND body<>""') === 0, 'Accepted message bodies are cleared');
check(nl_run_worker()['accepted'] === 0, 'A repeated worker does not resend accepted messages');
$unsubscribe = (string)nl_query('SELECT unsubscribe_token FROM subscribers')->fetchColumn();
nl_queue('alice@example.invalid', 'newsletter', 'edition-cancel', 'Test', 'Hello');
check(nl_unsubscribe($unsubscribe) && nl_unsubscribe($unsubscribe), 'Unsubscribe is idempotent');
check(count_rows('queue', 'state="pending"') === 0 && !nl_confirm($token), 'Unsubscribe cancels queued messages and invalidates confirmation');
nl_subscribe('alice@example.invalid', '/');
$newToken = token_for('alice@example.invalid');
check($newToken !== $token && count_rows('subscribers', 'status="pending"') === 1, 'A fresh subscription after unsubscribe requires fresh confirmation');
check(!nl_unsubscribe($unsubscribe), 'An old unsubscribe link cannot affect the new opt-in attempt');
check(nl_confirm($newToken) && count_rows('queue', 'kind="newsletter"') === 3, 'Immediate re-subscription gets a fresh welcome identity');

reset_data();
nl_subscribe('expired@example.invalid', '/');
$token = token_for('expired@example.invalid');
nl_query('UPDATE subscribers SET requested=?', [time() - 172801]);
check(!nl_confirm($token), 'Confirmation expires after 48 hours');
nl_run_worker();
check(count_rows('queue', 'state="cancelled"') === 1 && filesize(nl_dir() . '/test-outbox.jsonl') === 0, 'Expired confirmation is cancelled before mail handoff');
nl_subscribe('expired@example.invalid', '/new');
$newToken = token_for('expired@example.invalid');
check($newToken !== $token && !nl_confirm($token) && nl_confirm($newToken), 'Renewed pending subscription invalidates the old token');

reset_data();
nl_subscribe('retry@example.invalid', '/');
putenv('PT_NEWSLETTER_TEST_FAIL=1');
for ($i = 1; $i <= 5; $i++) {
    $result = nl_run_worker();
    $queued = nl_query('SELECT * FROM queue')->fetch(PDO::FETCH_ASSOC);
    check((int)$queued['attempts'] === $i, 'Failure attempt ' . $i . ' is recorded exactly once');
    if ($i < 5) {
        check($result['retry'] === 1 && (int)$queued['available'] >= time() + 295, 'Rejected handoff ' . $i . ' waits before retrying');
        check(nl_run_worker()['retry'] === 0, 'Retry ' . $i . ' cannot run before its schedule');
        nl_query('UPDATE queue SET available=0');
        nl_query('DELETE FROM delivery_attempts');
    }
}
check($queued['state'] === 'failed' && $queued['body'] === '', 'Five rejected handoffs fail without an infinite retry');
putenv('PT_NEWSLETTER_TEST_FAIL');
reset_data();
nl_subscribe('uncertain@example.invalid', '/');
putenv('PT_NEWSLETTER_TEST_THROW=1');
$result = nl_run_worker();
check($result['review'] === 1 && count_rows('queue', 'state="review"') === 1, 'An uncertain handoff is held for review');
putenv('PT_NEWSLETTER_TEST_THROW');
check(nl_run_worker()['accepted'] === 0, 'An uncertain handoff is never automatically resent');
reset_data();
nl_subscribe('crash@example.invalid', '/');
nl_query('UPDATE queue SET state="sending",updated=?', [time() - 901]);
nl_run_worker();
check(count_rows('queue', 'state="review"') === 1, 'An abandoned sending state is held for review');

reset_data();
for ($i = 0; $i < 7; $i++) nl_subscribe('limit' . $i . '@example.invalid', '/');
check(nl_run_worker()['accepted'] === 5, 'A batch accepts at most five attempts');
check(nl_run_worker()['limited'] === true && count_rows('queue', 'state="pending"') === 2, 'Repeated workers share the five-per-minute ceiling');
nl_query('UPDATE delivery_attempts SET attempted=?', [time() - 61]);
putenv('PT_NEWSLETTER_DAILY_LIMIT=5');
check(nl_run_worker()['limited'] === true, 'The rolling daily ceiling survives a new minute');
nl_query('UPDATE delivery_attempts SET attempted=?', [time() - 86401]);
check(nl_run_worker()['accepted'] === 2, 'Capacity becomes available after the rolling 24-hour window');
putenv('PT_NEWSLETTER_DAILY_LIMIT');
for ($i = 0; $i < 8; $i++) check(nl_limit('test-client'), 'Allowed subscription request ' . ($i + 1));
check(!nl_limit('test-client'), 'The ninth request from one address is rate limited');
nl_query('UPDATE rate_limits SET window=window-1');
check(nl_limit('test-client'), 'The IP request counter resets with a new hour');

reset_data();
activate('campaign@example.invalid');
nl_subscribe('pending@example.invalid', '/');
activate('optedout@example.invalid');
$token = nl_query('SELECT unsubscribe_token FROM subscribers WHERE email=?', ['optedout@example.invalid'])->fetchColumn();
nl_unsubscribe($token);
$editionPath = nl_dir() . '/edition.json';
file_put_contents($editionPath, json_encode(['id' => 'test-edition', 'subject' => 'Mesa de prueba', 'body' => 'Contenido de prueba']));
$campaignScript = dirname(__DIR__) . '/newsletter/campaign.php';
$preview = cli_run($campaignScript, [$editionPath]);
check($preview[0] === 0 && count_rows('campaigns') === 0, 'Campaign preview does not enqueue an edition');
$enqueue = cli_run($campaignScript, [$editionPath, '--queue']);
check($enqueue[0] === 0 && count_rows('queue', 'campaign="edition-test-edition"') === 1, 'A campaign includes confirmed subscribers only');
cli_run($campaignScript, [$editionPath, '--queue']);
check(count_rows('queue', 'campaign="edition-test-edition"') === 1, 'Repeated campaign invocation does not duplicate recipients');
nl_query('DELETE FROM queue WHERE campaign="edition-test-edition"');
cli_run($campaignScript, [$editionPath, '--queue']);
check(count_rows('queue', 'campaign="edition-test-edition"') === 0, 'Edition deduplication survives queue retention cleanup');
$badPath = nl_dir() . '/invalid-edition.json';
file_put_contents($badPath, json_encode(['id' => [], 'subject' => "Subject\r\nBcc: someone@example.invalid", 'body' => 'Text']));
check(cli_run($campaignScript, [$badPath, '--queue'])[0] === 1, 'Malformed edition data and header injection are rejected');
$invalid = false;
try { nl_subscribe("x@example.invalid\r\nBcc: y@example.invalid", '/'); } catch (InvalidArgumentException $error) { $invalid = true; }
check($invalid, 'Recipient header injection is rejected by the shared library');
$invalid = false;
try { nl_queue('good@example.invalid', 'newsletter', 'test-header', "Hi\r\nBcc: bad", 'Text'); } catch (InvalidArgumentException $error) { $invalid = true; }
check($invalid, 'Subject header injection is rejected by the shared library');

reset_data();
$workerScript = dirname(__DIR__) . '/newsletter/worker.php';
$result = cli_run($workerScript, ['--queue-test', $editionPath, 'internal@example.invalid']);
check($result[0] === 0 && count_rows('subscribers') === 0 && count_rows('queue', 'kind="test"') === 1, 'An internal test queues without creating a subscription');
check(nl_run_worker()['accepted'] === 1, 'Internal tests use the normal worker');
$outbox = json_decode(trim(file_get_contents(nl_dir() . '/test-outbox.jsonl')), true);
check(str_starts_with($outbox['subject'], '[PRUEBA]') && !isset($outbox['headers']['List-Unsubscribe']), 'Internal test is identified and does not pretend to subscribe the recipient');
check(count_rows('delivery_attempts') === 1, 'Internal tests consume the shared sending quota');
cli_run($workerScript, ['--queue-test', $editionPath, 'internal@example.invalid']);
check(count_rows('queue', 'kind="test"') === 1 && nl_run_worker()['accepted'] === 0, 'Re-running an identical internal test cannot resend it');
$status = cli_run($workerScript, ['--status']);
check($status[0] === 0 && !str_contains($status[1], 'internal@example.invalid'), 'Operational status does not expose recipient addresses');

putenv('PT_NEWSLETTER_TEST=0');
putenv('PT_NEWSLETTER_SEND_ENABLED=0');
check(!nl_delivery_enabled() && isset(nl_run_worker()['disabled']), 'Production mail is disabled until explicitly enabled');
putenv('PT_NEWSLETTER_SEND_ENABLED');
check(!nl_delivery_enabled(), 'Missing private configuration cannot enable production mail');
check(cli_run($workerScript, ['--enable'])[0] === 0 && nl_delivery_enabled(), 'CLI enable creates private configuration usable by web and worker');
putenv('PT_NEWSLETTER_SEND_ENABLED=0');
check(!nl_delivery_enabled(), 'An explicit environment disable overrides private configuration');
putenv('PT_NEWSLETTER_SEND_ENABLED');
check(cli_run($workerScript, ['--disable'])[0] === 0 && !nl_delivery_enabled(), 'CLI disable stops production mail');
putenv('PT_NEWSLETTER_TEST=1');
echo "\n" . $passed . " assertions passed; all mail stayed in the local test outbox.\n";
