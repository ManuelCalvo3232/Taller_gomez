<?php
require __DIR__ . '/../src/config/database.php';
require __DIR__ . '/../src/auth/csrf.php';

// Cerrar sesión solo por POST con token CSRF: un enlace de otra página no debería poder cerrarla
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: panel.php');
    exit;
}
csrf_verificar();

// 1. Invalidar el token de la API en la base (solo guardamos su hash)
if (!empty($_SESSION['api_token'])) {
    try {
        $pdo = conectar();
        $stmt = $pdo->prepare("DELETE FROM tokens_api WHERE token_hash = ?");
        $stmt->execute([hash('sha256', $_SESSION['api_token'])]);
    } catch (PDOException $e) {
        error_log($e->getMessage());
    }
}

// 2. Vaciar la sesión y borrar la cookie del navegador
$_SESSION = [];
$params = session_get_cookie_params();
setcookie(session_name(), '', [
    'expires' => time() - 42000,
    'path' => $params['path'],
    'domain' => $params['domain'],
    'secure' => $params['secure'],
    'httponly' => $params['httponly'],
    'samesite' => $params['samesite'] ?? 'Lax',
]);
session_destroy();

header('Location: login.php');
exit;