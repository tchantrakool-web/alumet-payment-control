<?php
require_once __DIR__ . '/config/bootstrap.php';
if (isLoggedIn()) {
    redirect(BASE_URL . '/dashboard.php');
} else {
    redirect(BASE_URL . '/login.php');
}
