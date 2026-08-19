<?php
declare(strict_types=1);

/**
 * Regression tests for atomic PDF saves and delete cleanup:
 * - a failed PDF write must not truncate/destroy an existing PDF
 * - deleting an invoice must also remove its companion PDF
 */

$root = dirname(__DIR__);
$testStorage = sys_get_temp_dir() . '/dgk-pdf-atomic-' . bin2hex(random_bytes(4));
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

function extract_function(string $source, string $marker): string
{
    $start = strpos($source, $marker);
    if ($start === false) {
        throw new RuntimeException("Could not locate {$marker}");
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

    return substr($source, $start, $end - $start + 1);
}

$source = (string) file_get_contents($root . '/index.php');

// --- Unit-level: writeStorageFile failure must leave the original PDF intact ---
$unitDir = $testStorage . '/unit';
mkdir($unitDir, 0775, true);
$unitPath = $unitDir . '/KEEP-ME.pdf';
$originalPdf = "%PDF-1.4\n% original invoice PDF bytes\n";
file_put_contents($unitPath, $originalPdf);

eval(extract_function($source, 'function writeStorageFile(string $path, string $contents): bool'));

chmod($unitDir, 0555);
$writeFailed = writeStorageFile($unitPath, "%PDF-1.4\n% clobbered\n") === false;
chmod($unitDir, 0775);

assert_true($writeFailed, 'writeStorageFile fails when a temp file cannot be created');
assert_true(
    file_get_contents($unitPath) === $originalPdf,
    'failed PDF write leaves the existing PDF bytes unchanged'
);

$okWrite = writeStorageFile($unitPath, "%PDF-1.4\n% updated invoice PDF\n");
assert_true($okWrite === true, 'writeStorageFile succeeds for a normal PDF update');
assert_true(
    str_contains((string) file_get_contents($unitPath), 'updated invoice PDF'),
    'successful atomic write updates the PDF'
);
$leftoverTemps = glob($unitDir . '/.tmp-*') ?: [];
assert_true($leftoverTemps === [], 'successful PDF write does not leave temp files behind');

// --- HTTP-level: save-pdf + delete remove companion files correctly ---
$appDir = $testStorage . '/app';
mkdir($appDir . '/storage/invoices', 0775, true);
mkdir($appDir . '/storage/pdfs', 0775, true);

$appSource = preg_replace(
    [
        '/const INVOICE_STORAGE = .*?;/',
        '/const PDF_STORAGE = .*?;/',
    ],
    [
        'const INVOICE_STORAGE = ' . var_export($appDir . '/storage/invoices', true) . ';',
        'const PDF_STORAGE = ' . var_export($appDir . '/storage/pdfs', true) . ';',
    ],
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
    $invoiceNumber = 'DGK-2026-08-001';
    $pdfBinary = "%PDF-1.4\n% DGKreative invoice fixture\n%%EOF\n";
    $pdfBase64 = base64_encode($pdfBinary);

    $savePdf = http_request($base, 'POST', 'save-pdf', [
        'invoiceNumber' => $invoiceNumber,
        'pdfBase64' => 'data:application/pdf;base64,' . $pdfBase64,
    ]);
    assert_true(
        $savePdf['status'] === 200 && ($savePdf['body']['ok'] ?? false) === true,
        'API save-pdf stores a PDF'
    );

    $pdfPath = $appDir . '/storage/pdfs/' . $invoiceNumber . '.pdf';
    assert_true(is_file($pdfPath), 'saved PDF exists on disk');
    assert_true(
        file_get_contents($pdfPath) === $pdfBinary,
        'saved PDF bytes match the uploaded document'
    );

    $updatedPdf = "%PDF-1.4\n% updated fixture\n%%EOF\n";
    $updatePdf = http_request($base, 'POST', 'save-pdf', [
        'invoiceNumber' => $invoiceNumber,
        'pdfBase64' => base64_encode($updatedPdf),
    ]);
    assert_true(
        $updatePdf['status'] === 200 && ($updatePdf['body']['ok'] ?? false) === true,
        'API save-pdf overwrites an existing PDF atomically'
    );
    assert_true(
        file_get_contents($pdfPath) === $updatedPdf,
        'updated PDF bytes are visible after overwrite'
    );

    $apiTemps = glob($appDir . '/storage/pdfs/.tmp-*') ?: [];
    assert_true($apiTemps === [], 'API save-pdf does not leave temp files in storage');

    $saveJson = http_request($base, 'POST', 'save', [
        'invoiceNumber' => $invoiceNumber,
        'clientName' => 'CH Logistics',
        'billingPeriod' => '2026-08',
        'lines' => [['description' => 'Hosting', 'quantity' => 1, 'rate' => 249]],
    ]);
    assert_true(
        $saveJson['status'] === 200 && ($saveJson['body']['ok'] ?? false) === true,
        'API save creates the companion invoice JSON'
    );

    $delete = http_request($base, 'POST', 'delete', [
        'invoiceNumber' => $invoiceNumber,
    ]);
    assert_true(
        $delete['status'] === 200 && ($delete['body']['ok'] ?? false) === true,
        'API delete succeeds'
    );
    // The built-in server unlinks in another process; clear cached stats first.
    clearstatcache(true, $pdfPath);
    clearstatcache(true, $appDir . '/storage/invoices/' . $invoiceNumber . '.json');
    assert_true(!is_file($pdfPath), 'delete removes the companion PDF');
    assert_true(
        !is_file($appDir . '/storage/invoices/' . $invoiceNumber . '.json'),
        'delete removes the invoice JSON record'
    );

    $oversized = http_request($base, 'POST', 'save-pdf', [
        'invoiceNumber' => 'DGK-2026-08-002',
        'pdfBase64' => str_repeat('A', (16 * 1024 * 1024) + 1),
    ]);
    assert_true(
        $oversized['status'] === 422,
        'API save-pdf rejects oversized base64 before decoding'
    );
} finally {
    $status = proc_get_status($process);
    if (!empty($status['pid'])) {
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
