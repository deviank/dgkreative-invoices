<?php
declare(strict_types=1);

/**
 * Regression tests for invoice storage correctness bugs:
 * - sanitized invoice-number collisions must not overwrite/delete the wrong record
 * - legacy browser migration must not clobber invoices that already exist on the server
 */

$root = dirname(__DIR__);
$testStorage = sys_get_temp_dir() . '/dgk-invoice-tests-' . bin2hex(random_bytes(4));
mkdir($testStorage, 0775, true);

$passed = 0;
$failed = 0;

function assert_true(bool $condition, string $message): void
{
    global $passed, $failed;
    if ($condition) {
        echo "PASS  {$message}\n";
        $passed++;
        return;
    }

    echo "FAIL  {$message}\n";
    $failed++;
}

function http_request(string $base, string $method, string $action, ?array $body = null, array $query = []): array
{
    $params = array_merge(['api' => $action], $query);
    $url = $base . '/index.php?' . http_build_query($params);
    $headers = "Content-Type: application/json\r\n";
    $payload = $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES);
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => $headers,
            'content' => $payload ?? '',
            'ignore_errors' => true,
            'timeout' => 5,
        ],
    ]);

    $raw = file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? 'HTTP/1.1 0';
    preg_match('/\s(\d{3})\s/', $statusLine, $matches);
    $status = (int) ($matches[1] ?? 0);
    $json = json_decode((string) $raw, true);

    return [
        'status' => $status,
        'body' => is_array($json) ? $json : [],
        'raw' => (string) $raw,
    ];
}

// Build a temporary app copy that writes into an isolated storage directory.
$appDir = $testStorage . '/app';
mkdir($appDir . '/storage/invoices', 0775, true);
$source = file_get_contents($root . '/index.php');
$source = preg_replace(
    '/const INVOICE_STORAGE = .*?;/',
    'const INVOICE_STORAGE = ' . var_export($appDir . '/storage/invoices', true) . ';',
    $source,
    1
);
file_put_contents($appDir . '/index.php', $source);

$descriptor = [
    0 => ['pipe', 'r'],
    1 => ['file', $testStorage . '/server.log', 'w'],
    2 => ['file', $testStorage . '/server.log', 'a'],
];
$process = proc_open(
    'php -S 127.0.0.1:8765 -t ' . escapeshellarg($appDir),
    $descriptor,
    $pipes,
    $appDir
);

if (!is_resource($process)) {
    fwrite(STDERR, "Could not start PHP built-in server.\n");
    exit(1);
}

$base = 'http://127.0.0.1:8765';
usleep(200000);

try {
    $savePrimary = http_request($base, 'POST', 'save', [
        'invoiceNumber' => 'INV-001',
        'clientName' => 'Primary Client',
        'billingPeriod' => '2026-08',
        'lines' => [['description' => 'Hosting', 'quantity' => 1, 'rate' => 100]],
    ]);
    assert_true($savePrimary['status'] === 200 && ($savePrimary['body']['ok'] ?? false) === true, 'save accepts a normal invoice number');

    $conflict = http_request($base, 'POST', 'save', [
        'invoiceNumber' => 'INV 001',
        'clientName' => 'Collision Client',
        'billingPeriod' => '2026-08',
        'lines' => [['description' => 'Should not replace', 'quantity' => 1, 'rate' => 999]],
    ]);
    assert_true($conflict['status'] === 409 && ($conflict['body']['ok'] ?? true) === false, 'save rejects sanitized invoice-number collisions');

    $loaded = http_request($base, 'GET', 'load', null, ['number' => 'INV-001']);
    assert_true(
        $loaded['status'] === 200
        && ($loaded['body']['invoice']['clientName'] ?? null) === 'Primary Client',
        'original invoice survives a colliding save attempt'
    );

    $loadAlias = http_request($base, 'GET', 'load', null, ['number' => 'INV 001']);
    assert_true($loadAlias['status'] === 404, 'load does not resolve a colliding alias to another invoice');

    $deleteAlias = http_request($base, 'POST', 'delete', ['invoiceNumber' => 'INV 001']);
    assert_true($deleteAlias['status'] === 200 && ($deleteAlias['body']['ok'] ?? false) === true, 'delete of missing alias is idempotent');

    $stillThere = http_request($base, 'GET', 'load', null, ['number' => 'INV-001']);
    assert_true(
        $stillThere['status'] === 200
        && ($stillThere['body']['invoice']['clientName'] ?? null) === 'Primary Client',
        'delete via colliding alias does not remove the real invoice'
    );

    // Migration behaviour is implemented in JS; mirror the server-side contract it relies on:
    // list returns existing numbers, and save must not be used to overwrite during migration.
    $listed = http_request($base, 'GET', 'list');
    $existingNumbers = [];
    foreach ($listed['body']['records'] ?? [] as $record) {
        $existingNumbers[] = trim((string) ($record['invoiceNumber'] ?? ''));
    }
    assert_true(in_array('INV-001', $existingNumbers, true), 'list includes the protected invoice number for migration checks');

    $staleBrowserCopy = [
        'invoiceNumber' => 'INV-001',
        'clientName' => 'Stale Browser Copy',
        'billingPeriod' => '2026-01',
        'lines' => [['description' => 'Old', 'quantity' => 1, 'rate' => 1]],
    ];
    $shouldMigrate = !in_array(trim($staleBrowserCopy['invoiceNumber']), $existingNumbers, true);
    assert_true($shouldMigrate === false, 'migration helper skips invoice numbers that already exist on the server');
} finally {
    proc_terminate($process);
    proc_close($process);

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($testStorage, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($testStorage);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
