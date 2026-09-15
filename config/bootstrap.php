<?php
define('ROOT_PATH', dirname(__DIR__));

// Build the URL from the actual project path relative to the web document root.
// This works at the domain root, in a subdirectory, and after renaming the folder.
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$projectRoot = str_replace('\\', '/', (string)realpath(ROOT_PATH));
$documentRoot = str_replace('\\', '/', (string)realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
$basePath = '';

if ($documentRoot !== '' && ($projectRoot === $documentRoot || str_starts_with($projectRoot, $documentRoot . '/'))) {
    $basePath = substr($projectRoot, strlen($documentRoot));
} else {
    // Conservative fallback for non-standard server setups.
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $scriptFile = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
    if ($scriptFile !== '' && str_starts_with($scriptFile, $projectRoot)) {
        $relativeScript = substr($scriptFile, strlen($projectRoot));
        if ($relativeScript !== '' && str_ends_with($scriptName, $relativeScript)) {
            $basePath = substr($scriptName, 0, -strlen($relativeScript));
        }
    }
}

$basePath = rtrim('/' . trim($basePath, '/'), '/');
define('BASE_URL', $protocol . '://' . $host . $basePath);

require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';
require_once ROOT_PATH . '/config/functions.php';
require_once ROOT_PATH . '/config/notifications.php';

// Language detection — must run after session_start() (called inside auth.php)
if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'th'], true)) {
    $_SESSION['app_lang'] = $_GET['lang'];
}
define('APP_LANG', $_SESSION['app_lang'] ?? 'en');

// Ensure DB is initialized
getDB();
