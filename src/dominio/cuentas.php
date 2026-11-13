<?php

require_once __DIR__ . '/../integraciones/brevo.php';

// Clave: 8 caracteres o más, con al menos una minúscula, una mayúscula, un número y un símbolo
function clave_valida(string $clave): bool
{
    return preg_match('/^(?=.*\p{Ll})(?=.*\p{Lu})(?=.*\p{N})(?=.*[^\p{L}\p{N}]).{8,}$/su', $clave) === 1;
}

// Crea un token de verificación de un solo uso (vence en 24 horas) y devuelve el token en claro.
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
    $enlace = htmlspecialchars($appUrl . '/verificar?token=' . $token, ENT_QUOTES, 'UTF-8');
    $nombreSeguro = htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8');

    $contenido = '
      <p style="margin:0 0 16px; color:#d1d5db;">
        Hola <strong style="color:#ffffff;">' . $nombreSeguro . '</strong>, ya casi está lista tu cuenta en el portal de <strong style="color:#ffffff;">Taller Gómez</strong>.
      </p>
      <p style="margin:0 0 22px; color:#9ca3af; font-size:14px;">
        Confirmá tu dirección de correo para pedir turnos online, aprobar presupuestos y seguir el avance de tu vehículo en tiempo real.
      </p>
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 22px;">
        <tr>
          <td align="center">
            <a href="' . $enlace . '"
               style="display:block; background-color:#ea580c; color:#ffffff; text-decoration:none; font-weight:700; font-size:15px; padding:14px 20px; border-radius:10px; text-align:center;">
              Confirmar mi cuenta
            </a>
          </td>
        </tr>
      </table>
      <div style="background-color:#0d0f13; border:1px solid #262b35; border-radius:10px; padding:12px 14px; font-size:12px; color:#9ca3af; word-break:break-all;">
        <span style="display:block; color:#6b7280; margin-bottom:4px;">Si el botón no funciona, copiá este enlace en tu navegador:</span>
        <a href="' . $enlace . '" style="color:#fb923c; text-decoration:none;">' . $enlace . '</a>
      </div>';

    $pie = 'Este enlace de verificación vence en 24 horas. Si no creaste una cuenta en Taller Gómez, podés ignorar este mensaje.';
    $html = plantilla_mail_taller('Verificación', 'Confirmá tu email', $contenido, $pie);

    return enviar_mail_brevo($email, 'Confirmá tu cuenta · Taller Gómez', $html);
}
