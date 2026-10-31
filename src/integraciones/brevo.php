<?php

// Envío de mails desde PHP con la API de Brevo.
// Usa las mismas variables que la API Node: BREVO_API_KEY y MAIL_FROM_EMAIL.
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