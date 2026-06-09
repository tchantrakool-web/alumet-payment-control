<?php
// Detect base URL dynamically
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
$script   = dirname($_SERVER['SCRIPT_NAME'] ?? '');

// Walk up to project root (alumet-payment-control)
$parts    = explode('/', trim($script, '/'));
$rootIdx  = array_search('alumet-payment-control', $parts);
if ($rootIdx !== false) {
    $baseParts = array_slice($parts, 0, $rootIdx + 1);
    $basePath  = '/' . implode('/', $baseParts);
} else {
    $basePath = '/' . implode('/', $parts);
}

define('BASE_URL', $protocol . '://' . $host . $basePath);
define('ROOT_PATH', dirname(__DIR__));

require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';
require_once ROOT_PATH . '/config/functions.php';

// Ensure DB is initialized
getDB();
