<?php
require_once __DIR__ . '/config/bootstrap.php';
if (isLoggedIn()) {
    auditLog('LOGOUT', 'auth', currentUser()['id']);
}
logout();
redirect(BASE_URL . '/login.php');
