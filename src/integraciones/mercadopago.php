<?php

// Mercado Pago (Checkout Pro). Usa MP_ACCESS_TOKEN: en sandbox es el token de prueba (TEST-...).

// Llamada a la API de Mercado Pago. Devuelve los datos o lanza RuntimeException si falla.
function mp_request(string $metodo, string $ruta, ?array $cuerpo = null): array
{
    $token = getenv('MP_ACCESS_TOKEN') ?: '';
    if ($token === '') {
        throw new RuntimeException('Falta MP_ACCESS_TOKEN');
    }

    $ch = curl_init('https://api.mercadopago.com' . $ruta);
    $opciones = [
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
    ];
    if ($cuerpo !== null) {
        $opciones[CURLOPT_POSTFIELDS] = json_encode($cuerpo);
    }
    curl_setopt_array($ch, $opciones);

    $respuesta = curl_exec($ch);
    $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($respuesta === false) {
        throw new RuntimeException('No se pudo conectar con Mercado Pago');
    }
    $datos = json_decode($respuesta, true) ?? [];
    if ($codigo < 200 || $codigo >= 300) {
        throw new RuntimeException("Mercado Pago respondió $codigo: " . ($datos['message'] ?? 'error'));
    }
    return $datos;
}

// Crea la preferencia de pago. external_reference es el id del pago en nuestra base,
// así el webhook sabe a qué cobro corresponde.
function mp_crear_preferencia(int $idPago, string $titulo, float $monto): array
{
    $appUrl = rtrim(getenv('APP_URL') ?: 'http://localhost:8000', '/');

    return mp_request('POST', '/checkout/preferences', [
        'items' => [[
            'title' => $titulo,
            'quantity' => 1,
            'currency_id' => 'ARS',
            'unit_price' => round($monto, 2),
        ]],
        'external_reference' => (string) $idPago,
        'back_urls' => [
            'success' => "$appUrl/pago_retorno.php",
            'failure' => "$appUrl/pago_retorno.php",
            'pending' => "$appUrl/pago_retorno.php",
        ],
        'auto_return' => 'approved',
        'notification_url' => "$appUrl/webhook_pagos.php",
    ]);
}

// Consulta un pago directamente a la API. Así no hace falta confiar en el aviso del webhook.
function mp_obtener_pago(string $idPago): array
{
    return mp_request('GET', '/v1/payments/' . rawurlencode($idPago));
}

// En sandbox se usa la URL de prueba. Con MP_SANDBOX=0 se usa la de producción.
function mp_url_pago(array $preferencia): string
{
    return getenv('MP_SANDBOX') === '0' ? $preferencia['init_point'] : $preferencia['sandbox_init_point'];
}