<?php
require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = getDB();
$stmtUser = $db->prepare("
    SELECT u.id, u.username, u.full_name, u.role_id, r.name AS role_name
    FROM users u JOIN roles r ON r.id = u.role_id
    WHERE u.id = ? AND u.is_active = 1
");
$stmtUser->execute([(int) $_SESSION['user_id']]);
$user = $stmtUser->fetch();
if (!$user) {
    logout();
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid session token']);
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');
    $current = getActionableNotifications($db, $user);
    $validKeys = array_column($current, null, 'key');

    if ($action === 'mark_read') {
        $key = (string) ($_POST['key'] ?? '');
        if (isset($validKeys[$key])) {
            markNotificationRead($db, (int) $user['id'], $key);
        }
    } elseif ($action === 'mark_all_read') {
        $db->beginTransaction();
        foreach ($validKeys as $key => $_item) {
            markNotificationRead($db, (int) $user['id'], (string) $key);
        }
        $db->commit();
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Unsupported action']);
        exit;
    }
}

echo json_encode(notificationPayload($db, $user), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
