<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

function currentUser(): ?array {
    if (!isLoggedIn()) return null;
    return [
        'id'        => $_SESSION['user_id'],
        'username'  => $_SESSION['username'],
        'full_name' => $_SESSION['full_name'],
        'role_id'   => $_SESSION['role_id'],
        'role_name' => $_SESSION['role_name'],
    ];
}

function hasRole(string ...$roles): bool {
    $roleName = $_SESSION['role_name'] ?? '';
    return in_array($roleName, $roles, true);
}

function isAdmin(): bool {
    return hasRole('admin');
}

function canAccess(string $module): bool {
    $role = $_SESSION['role_name'] ?? '';
    $map = [
        'import'          => ['admin', 'maker', 'finance_manager'],
        'ap_invoices'     => ['admin', 'maker', 'checker', 'finance_manager'],
        'payment_requests'=> ['admin', 'maker', 'checker', 'approver', 'finance_manager', 'executive'],
        'approval'        => ['admin', 'checker', 'approver', 'finance_manager', 'executive'],
        'payment_batch'   => ['admin', 'finance_manager'],
        'cheques'         => ['admin', 'finance_manager'],
        'calendar'        => ['admin', 'maker', 'checker', 'approver', 'finance_manager', 'executive'],
        'reports'         => ['admin', 'finance_manager', 'executive'],
        'bpmn'            => ['admin', 'maker', 'checker', 'approver', 'finance_manager', 'executive'],
        'dashboard'       => ['admin', 'maker', 'checker', 'approver', 'finance_manager', 'executive'],
        'settings'        => ['admin'],
    ];
    return in_array($role, $map[$module] ?? [], true);
}

function login(array $user): void {
    session_regenerate_id(true);
    $_SESSION['user_id']   = $user['id'];
    $_SESSION['username']  = $user['username'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role_id']   = $user['role_id'];
    $_SESSION['role_name'] = $user['role_name'];
}

function logout(): void {
    $_SESSION = [];
    session_destroy();
}
