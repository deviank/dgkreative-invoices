<?php
declare(strict_types=1);

/**
 * Regression: ensureStorageFolders must recreate storage/.htaccess when missing.
 * Without it, invoice JSON and PDFs are directly HTTP-readable after a storage wipe.
 */

$root = dirname(__DIR__);
$testRoot = sys_get_temp_dir() . '/dgk-storage-protect-' . bin2hex(random_bytes(4));
mkdir($testRoot, 0775, true);

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

function http_request(string $base, string $method, string $action, ?array $body = null): array
{
    $url = $base . '/index.php?' . http_build_query(['api' => $action]);
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => "Content-Type: application/json\r\n",
            'content' => $body === null ? '' : (string) json_encode($body, JSON_UNESCAPED_SLASHES),
            'ignore_errors' => true,
            'timeout' => 5,
        ],
    ]);

    $raw = file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? 'HTTP/1.1 0';
    preg_match('/\s(\d{3})\s/', $statusLine, $matches);

    return [
        'status' => (int) ($matches[1] ?? 0),
        'body' => json_decode((string) $raw, true) ?: [],
    ];
}

$source = (string) file_get_contents($root . '/index.php');

// --- Unit: wiped storage root gets deny rules before invoice/PDF folders are used ---
$unitStorage = $testRoot . '/unit-storage';
$unitInvoices = $unitStorage . '/invoices';
$unitPdfs = $unitStorage . '/pdfs';

eval('const INVOICE_STORAGE = ' . var_export($unitInvoices, true) . ';');
eval('const PDF_STORAGE = ' . var_export($unitPdfs, true) . ';');
eval(extract_function($source, 'function storageHtaccessContents(): string'));
eval(extract_function($source, 'function ensureStorageProtection(string $storageRoot): void'));
eval(extract_function($source, 'function ensureStorageFolders(): void'));

ensureStorageFolders();

assert_true(is_dir($unitInvoices), 'creates invoices folder');
assert_true(is_dir($unitPdfs), 'creates pdfs folder');
assert_true(is_file($unitStorage . '/.htaccess'), 'writes storage/.htaccess on first create');

$htaccess = (string) file_get_contents($unitStorage . '/.htaccess');
assert_true(
    str_contains($htaccess, 'Require all denied') && str_contains($htaccess, 'Deny from all'),
    'htaccess denies HTTP access (Apache 2.2 and 2.4 rules)'
);

// Simulate operator deleting only the protection file after invoices exist.
unlink($unitStorage . '/.htaccess');
file_put_contents($unitInvoices . '/DGK-2026-08-001.json', "{\"invoiceNumber\":\"DGK-2026-08-001\"}\n");
assert_true(!is_file($unitStorage . '/.htaccess'), 'precondition: htaccess removed');

ensureStorageFolders();

assert_true(is_file($unitStorage . '/.htaccess'), 'recreates htaccess when missing');
assert_true(
    is_file($unitInvoices . '/DGK-2026-08-001.json'),
    'recreate leaves existing invoice records intact'
);
$restored = (string) file_get_contents($unitStorage . '/.htaccess');
assert_true(
    str_contains($restored, 'Require all denied'),
    'restored htaccess contains Require all denied'
);

// Broken/empty protection file must be replaced (fail closed).
file_put_contents($unitStorage . '/.htaccess', "# accidentally emptied\n");
ensureStorageFolders();
$repaired = (string) file_get_contents($unitStorage . '/.htaccess');
assert_true(
    str_contains($repaired, 'Require all denied') && str_contains($repaired, 'Deny from all'),
    'replaces broken htaccess that no longer denies access'
);

// Intact custom deny file must not be clobbered.
$custom = "Require all denied\n# custom operator note\n";
file_put_contents($unitStorage . '/.htaccess', $custom);
ensureStorageFolders();
assert_true(
    file_get_contents($unitStorage . '/.htaccess') === $custom,
    'leaves an existing deny htaccess unchanged'
);

// --- End-to-end via built-in PHP server: API recreate restores protection ---
$appDir = $testRoot . '/app';
mkdir($appDir, 0775, true);
mkdir($appDir . '/assets', 0775, true);
copy($root . '/index.php', $appDir . '/index.php');
// Start with no storage tree at all (common after a File Manager wipe / incomplete upload).
assert_true(!is_dir($appDir . '/storage'), 'precondition: app has no storage directory');

$port = 18080 + random_int(0, 200);
$serverCmd = sprintf(
    'php -S 127.0.0.1:%d -t %s >/dev/null 2>&1 & echo $!',
    $port,
    escapeshellarg($appDir)
);
$serverPid = (int) trim((string) shell_exec($serverCmd));
usleep(250000);

try {
    $base = "http://127.0.0.1:{$port}";
    $list = http_request($base, 'GET', 'list');
    assert_true(($list['body']['ok'] ?? false) === true, 'list API works on fresh storage');
    assert_true(is_file($appDir . '/storage/.htaccess'), 'API bootstrap creates storage/.htaccess');
    assert_true(is_dir($appDir . '/storage/invoices'), 'API bootstrap creates invoices dir');
    assert_true(is_dir($appDir . '/storage/pdfs'), 'API bootstrap creates pdfs dir');

    // Wipe protection only, keep a sensitive file, hit API again.
    unlink($appDir . '/storage/.htaccess');
    file_put_contents(
        $appDir . '/storage/invoices/SENSITIVE.json',
        "{\"invoiceNumber\":\"SENSITIVE\",\"bankDetails\":\"secret-account\"}\n"
    );
    $listAgain = http_request($base, 'GET', 'list');
    assert_true(($listAgain['body']['ok'] ?? false) === true, 'list API still works after htaccess wipe');
    assert_true(is_file($appDir . '/storage/.htaccess'), 'next API call restores htaccess after wipe');
    assert_true(
        is_file($appDir . '/storage/invoices/SENSITIVE.json'),
        'restoring protection does not delete invoice files'
    );
} finally {
    if ($serverPid > 0) {
        posix_kill($serverPid, SIGTERM);
    }
    cleanup_tree($testRoot);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
