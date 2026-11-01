<?php

// ¿La conexión es HTTPS? Directo, o detrás de un proxy como Traefik en Dokploy
$esHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

// Cookie de sesión endurecida. Tiene que configurarse antes de session_start()
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $esHttps,      // solo se envía por HTTPS cuando está disponible
        'httponly' => true,        // JavaScript no puede leerla
        'samesite' => 'Lax',       // otros sitios no la mandan en POST cruzados
    ]);
    session_start();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_verificar(): void
{
    $enviado = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $enviado)) {
        http_response_code(403);
        exit('Solicitud inválida. Volvé a cargar la página.');
    }
}