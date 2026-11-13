<?php

// Envío de mails desde PHP con la API de Brevo + plantilla HTML común Taller Gómez.
// Usa las mismas variables que la API Node: BREVO_API_KEY y MAIL_FROM_EMAIL.

function plantilla_mail_taller(string $kicker, string $titulo, string $contenidoHtml, string $pieHtml = ''): string
{
    $e = fn (string $texto): string => htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
    $pie = $pieHtml !== ''
        ? $pieHtml
        : 'Taller Gómez · Tu vehículo, con presupuesto antes de tocar nada.';

    return '<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>
<body style="margin:0; padding:0; background-color:#0a0a0c; font-family:\'Plus Jakarta Sans\',\'Inter\',Arial,sans-serif; color:#f9fafb;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#0a0a0c; padding:28px 14px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:580px; background-color:#16191f; border:1px solid #262b35; border-radius:16px; overflow:hidden;">
          <!-- Header Marca -->
          <tr>
            <td style="background-color:#111317; padding:18px 24px; border-bottom:2px solid #ea580c;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td style="vertical-align:middle;">
                    <span style="display:inline-block; background-color:#ea580c; color:#ffffff; font-weight:800; font-size:13px; padding:6px 9px; border-radius:7px; letter-spacing:0.02em; vertical-align:middle;">TG</span>
                    <span style="display:inline-block; color:#ffffff; font-weight:800; font-size:15px; letter-spacing:0.12em; margin-left:10px; vertical-align:middle;">TALLER GÓMEZ</span>
                  </td>
                  <td align="right" style="vertical-align:middle;">
                    <span style="display:inline-block; background-color:#1c2028; border:1px solid #374151; color:#fb923c; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.06em; padding:4px 10px; border-radius:999px;">' . $e($kicker) . '</span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Cuerpo -->
          <tr>
            <td style="padding:28px 24px; color:#f9fafb; font-size:15px; line-height:1.6;">
              <h1 style="margin:0 0 14px; font-size:22px; line-height:1.25; font-weight:800; color:#ffffff; letter-spacing:-0.02em;">' . $e($titulo) . '</h1>
              ' . $contenidoHtml . '
            </td>
          </tr>

          <!-- Pie -->
          <tr>
            <td style="background-color:#111317; padding:16px 24px; border-top:1px solid #262b35; font-size:12px; color:#6b7280; line-height:1.5;">
              ' . $pie . '
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>';
}

function enviar_mail_brevo(string $destino, string $asunto, string $html): bool
{
    $apiKey = getenv('BREVO_API_KEY') ?: '';
    $remitenteEmail = getenv('MAIL_FROM_EMAIL') ?: '';
    $remitenteNombre = getenv('MAIL_FROM_NAME') ?: 'Taller Gómez';

    if ($apiKey === '' || $remitenteEmail === '') {
        error_log('Brevo: faltan BREVO_API_KEY o MAIL_FROM_EMAIL');
        return false;
    }

    $cuerpo = json_encode([
        'sender' => ['email' => $remitenteEmail, 'name' => $remitenteNombre],
        'to' => [['email' => $destino]],
        'subject' => $asunto,
        'htmlContent' => $html,
    ]);

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'api-key: ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => $cuerpo,
        CURLOPT_TIMEOUT => 10,
    ]);

    $respuesta = curl_exec($ch);
    $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($respuesta === false || $codigo < 200 || $codigo >= 300) {
        error_log("Brevo HTTP $codigo: " . var_export($respuesta, true));
        return false;
    }

    return true;
}
