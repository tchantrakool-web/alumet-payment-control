<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/sap_importer.php';

$folder = $argv[1] ?? (__DIR__ . '/../PO data');
$resolvedFolder = realpath($folder);
if ($resolvedFolder === false || !is_dir($resolvedFolder)) {
    fwrite(STDERR, "PO data folder not found: {$folder}" . PHP_EOL);
    exit(1);
}

$files = array_merge(
    glob($resolvedFolder . DIRECTORY_SEPARATOR . '*.xlsx') ?: [],
    glob($resolvedFolder . DIRECTORY_SEPARATOR . '*.csv') ?: []
);
sort($files, SORT_NATURAL | SORT_FLAG_CASE);
if (empty($files)) {
    fwrite(STDERR, "No .xlsx or .csv files found in: {$resolvedFolder}" . PHP_EOL);
    exit(1);
}

$db = getDB();
$adminId = (int)($db->query(
    "SELECT id FROM users WHERE username = 'admin' LIMIT 1"
)->fetchColumn() ?: 0);
$failed = false;

foreach ($files as $file) {
    try {
        $result = importSapPurchaseFile(
            $db,
            $file,
            basename($file),
            $adminId > 0 ? $adminId : null
        );
        echo json_encode(
            $result,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) . PHP_EOL;
    } catch (Throwable $error) {
        $failed = true;
        fwrite(STDERR, basename($file) . ': ' . $error->getMessage() . PHP_EOL);
    }
}

exit($failed ? 1 : 0);
