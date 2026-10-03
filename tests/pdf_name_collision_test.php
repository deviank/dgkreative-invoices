<?php
declare(strict_types=1);

/**
 * Regression: Save PDF must not overwrite another invoice's PDF when distinct
 * logical invoice numbers sanitize to the same filename (e.g. "INV 001" vs "INV-001").
 */

$root = dirname(__DIR__);
$testStorage = sys_get_temp_dir() . '/dgk-pdf-collision-' . bin2hex(random_bytes(4));
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

function http_request(string $base, string $method, string $action, ?array $body = null, array $query = []): array
{
    $params = array_merge(['api' => $action], $query);
    $url = $base . '/index.php?' . http_build_query($params);
    $payload = $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES);
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => "Content-Type: application/json\r\n",
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

$appDir = $testStorage . '/app';
mkdir($appDir . '/storage/invoices', 0775, true);
mkdir($appDir . '/storage/pdfs', 0775, true);

$source = (string) file_get_contents($root . '/index.php');
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

$port = 8900 + random_int(0, 999);
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
usleep(200000);

try {
    $originalPdf = "%PDF-1.4\n% billing PDF for INV-001\n";
    $clobberPdf = "%PDF-1.4\n% should not replace INV-001 PDF\n";

    $saveJson = http_request($base, 'POST', 'save', [
        'invoiceNumber' => 'INV-001',
        'clientName' => 'Existing Client',
        'billingPeriod' => '2026-10',
        'lines' => [['description' => 'Hosting', 'quantity' => 1, 'rate' => 100]],
    ]);
    assert_true($saveJson['status'] === 200 && ($saveJson['body']['ok'] ?? false) === true, 'seed invoice INV-001 saves');

    $saveOwnPdf = http_request($base, 'POST', 'save-pdf', [
        'invoiceNumber' => 'INV-001',
        'pdfBase64' => base64_encode($originalPdf),
    ]);
    assert_true($saveOwnPdf['status'] === 200 && ($saveOwnPdf['body']['ok'] ?? false) === true, 'Save PDF succeeds for matching INV-001');

    $pdfPath = $appDir . '/storage/pdfs/INV-001.pdf';
    assert_true(is_file($pdfPath), 'companion PDF exists on disk');
    assert_true(file_get_contents($pdfPath) === $originalPdf, 'seed PDF bytes are intact');

    $conflict = http_request($base, 'POST', 'save-pdf', [
        'invoiceNumber' => 'INV 001',
        'pdfBase64' => base64_encode($clobberPdf),
    ]);
    assert_true(
        $conflict['status'] === 409 && ($conflict['body']['ok'] ?? true) === false,
        'Save PDF rejects sanitized-name collision with a different logical invoice number'
    );
    assert_true(
        file_get_contents($pdfPath) === $originalPdf,
        'rejected collision leaves the existing invoice PDF unchanged'
    );

    $refreshOwn = http_request($base, 'POST', 'save-pdf', [
        'invoiceNumber' => 'INV-001',
        'pdfBase64' => base64_encode("%PDF-1.4\n% refreshed INV-001 PDF\n"),
    ]);
    assert_true($refreshOwn['status'] === 200 && ($refreshOwn['body']['ok'] ?? false) === true, 'Save PDF still allows overwrite for the same logical invoice number');
    assert_true(
        str_contains((string) file_get_contents($pdfPath), 'refreshed INV-001 PDF'),
        'matching invoice number can update its own PDF'
    );
} finally {
    proc_terminate($process);
    proc_close($process);
    cleanup_tree($testStorage);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
