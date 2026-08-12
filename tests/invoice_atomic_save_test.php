<?php
declare(strict_types=1);

/**
 * Regression tests for atomic invoice saves:
 * a failed write must not truncate/destroy an existing invoice record.
 */

$root = dirname(__DIR__);
$testStorage = sys_get_temp_dir() . '/dgk-invoice-atomic-' . bin2hex(random_bytes(4));
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

function cleanup_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($path);
}

// --- Unit-level: writeInvoiceRecord failure must leave the original intact ---
$unitDir = $testStorage . '/unit';
mkdir($unitDir, 0775, true);
$unitPath = $unitDir . '/KEEP-ME.json';
$original = "{\"invoiceNumber\":\"KEEP-ME\",\"clientName\":\"Original Client\"}\n";
file_put_contents($unitPath, $original);

// Extract writeInvoiceRecord from index.php so we exercise the real helper.
$source = (string) file_get_contents($root . '/index.php');
$marker = 'function writeInvoiceRecord(string $path, string $contents): bool';
$start = strpos($source, $marker);
if ($start === false) {
    fwrite(STDERR, "Could not locate writeInvoiceRecord() in index.php\n");
    cleanup_tree($testStorage);
    exit(1);
}
$braceStart = strpos($source, '{', $start);
$depth = 0;
$end = $braceStart;
$length = strlen($source);
for ($i = $braceStart; $i < $length; $i++) {
    $char = $source[$i];
    if ($char === '{') {
        $depth++;
    } elseif ($char === '}') {
        $depth--;
        if ($depth === 0) {
            $end = $i;
            break;
        }
    }
}
eval(substr($source, $start, $end - $start + 1));

chmod($unitDir, 0555);
$writeFailed = writeInvoiceRecord($unitPath, "{\"invoiceNumber\":\"KEEP-ME\",\"clientName\":\"Clobbered\"}\n") === false;
chmod($unitDir, 0775);

assert_true($writeFailed, 'writeInvoiceRecord fails when a temp file cannot be created');
assert_true(
    file_get_contents($unitPath) === $original,
    'failed write leaves the existing invoice bytes unchanged'
);

$okWrite = writeInvoiceRecord($unitPath, "{\"invoiceNumber\":\"KEEP-ME\",\"clientName\":\"Updated Client\"}\n");
assert_true($okWrite === true, 'writeInvoiceRecord succeeds for a normal update');
assert_true(
    str_contains((string) file_get_contents($unitPath), 'Updated Client'),
    'successful atomic write updates the invoice record'
);
$leftoverTemps = glob($unitDir . '/.tmp-*.json') ?: [];
assert_true($leftoverTemps === [], 'successful write does not leave temp files behind');

// --- HTTP-level: save/load still works end-to-end through the API ---
$appDir = $testStorage . '/app';
mkdir($appDir . '/storage/invoices', 0775, true);
$appSource = preg_replace(
    '/const INVOICE_STORAGE = .*?;/',
    'const INVOICE_STORAGE = ' . var_export($appDir . '/storage/invoices', true) . ';',
    $source,
    1
);
file_put_contents($appDir . '/index.php', $appSource);

$port = 8800 + random_int(0, 999);
$descriptor = [
    0 => ['pipe', 'r'],
    1 => ['file', $testStorage . '/server.log', 'w'],
    2 => ['file', $testStorage . '/server.log', 'a'],
];
$process = proc_open(
    'php -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($appDir),
    $descriptor,
    $pipes,
    $appDir
);

if (!is_resource($process)) {
    fwrite(STDERR, "Could not start PHP built-in server.\n");
    cleanup_tree($testStorage);
    exit(1);
}

$base = 'http://127.0.0.1:' . $port;
usleep(300000);

try {
    $save = http_request($base, 'POST', 'save', [
        'invoiceNumber' => 'DGK-2026-08-001',
        'clientName' => 'CH Logistics',
        'billingPeriod' => '2026-08',
        'lines' => [['description' => 'Hosting', 'quantity' => 1, 'rate' => 249]],
    ]);
    assert_true($save['status'] === 200 && ($save['body']['ok'] ?? false) === true, 'API save creates an invoice');

    $update = http_request($base, 'POST', 'save', [
        'invoiceNumber' => 'DGK-2026-08-001',
        'clientName' => 'CH Logistics Updated',
        'billingPeriod' => '2026-08',
        'lines' => [['description' => 'Hosting', 'quantity' => 1, 'rate' => 249]],
    ]);
    assert_true($update['status'] === 200 && ($update['body']['ok'] ?? false) === true, 'API save updates an existing invoice');

    $loaded = http_request($base, 'GET', 'load', null, ['number' => 'DGK-2026-08-001']);
    assert_true(
        $loaded['status'] === 200
        && ($loaded['body']['invoice']['clientName'] ?? null) === 'CH Logistics Updated',
        'API load returns the atomically updated invoice'
    );

    $apiTemps = glob($appDir . '/storage/invoices/.tmp-*.json') ?: [];
    assert_true($apiTemps === [], 'API save does not leave temp files in storage');
} finally {
    $status = proc_get_status($process);
    if (!empty($status['pid'])) {
        // Built-in server spawns workers; kill the whole group when possible.
        if (function_exists('posix_kill')) {
            @posix_kill(-$status['pid'], SIGTERM);
            @posix_kill($status['pid'], SIGTERM);
        }
    }
    proc_terminate($process);
    proc_close($process);
    cleanup_tree($testStorage);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
