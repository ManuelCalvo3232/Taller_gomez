<?php

require_once __DIR__ . '/../integraciones/brevo.php';

// Clave: 8 caracteres o más, con al menos una minúscula, una mayúscula, un número y un símbolo
function clave_valida(string $clave): bool
{
    return preg_match('/^(?=.*\p{Ll})(?=.*\p{Lu})(?=.*\p{N})(?=.*[^\p{L}\p{N}]).{8,}$/su', $clave) === 1;
}

// Crea un token de verificación de un solo uso (vence en 24 horas) y devuelve el token en claro.
// Un nuevo token invalida los anteriores que no se usaron.
function crear_token_verificacion(PDO $pdo, int $idUsuario): string
{
    $token = bin2hex(random_bytes(32));

    $pdo->prepare('DELETE FROM tokens_verificacion WHERE id_usuario = ? AND usado_en IS NULL')
        ->execute([$idUsuario]);
    $pdo->prepare(
        'INSERT INTO tokens_verificacion (token_hash, id_usuario, creado_en, expira_en)
         VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR))'
    )->execute([hash('sha256', $token), $idUsuario]);

    return $token;
}

function enviar_verificacion_mail(string $nombre, string $email, string $token): bool
{
    $appUrl = rtrim(getenv('APP_URL') ?: 'http://localhost:8000', '/');
    $enlace = htmlspecialchars($appUrl . '/verificar?token=' . $token, ENT_QUOTES);
    $nombreSeguro = htmlspecialchars($nombre, ENT_QUOTES);

    $html = '<div style="font-family:Inter,Arial,sans-serif;max-width:560px;margin:0 auto;background:#F2F2F2;padding:24px;">'
        . '<div style="background:#171717;color:#FFFFFF;padding:16px 20px;border-left:4px solid #E63946;font-weight:800;letter-spacing:.08em;">TALLER GÓMEZ</div>'
        . '<div style="background:#FFFFFF;padding:24px 20px;color:#171717;">'
        . '<h2 style="margin:0 0 12px;">Confirmá tu email</h2>'
        . '<p>Hola ' . $nombreSeguro . ', para activar tu cuenta hacé clic en el botón. El enlace vence en 24 horas.</p>'
        . '<a href="' . $enlace . '" style="display:block;text-align:center;background:#E63946;color:#FFFFFF;text-decoration:none;font-weight:700;padding:14px;border-radius:8px;">Verificar mi cuenta</a>'
        . '</div></div>';

    return enviar_mail_brevo($email, 'Confirmá tu cuenta', $html);
}