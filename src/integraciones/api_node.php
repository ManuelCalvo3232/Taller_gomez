<?php

// Llama a un endpoint POST de la API de Node con el token de la sesión.
// Devuelve el campo "enviado" de la respuesta, o false si la llamada falló.
// Un fallo no interrumpe el flujo del taller: solo queda registrado en el log.
function llamar_api_enviar(string $ruta, string $token, string $apiUrl): bool
{
    if ($token === '') {
        return false;
    }

    $ch = curl_init($apiUrl . $ruta);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT => 10,
    ]);

    $respuesta = curl_exec($ch);
    $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($respuesta === false || $codigo !== 200) {
        error_log("Llamada a $ruta falló (HTTP $codigo)");
        return false;
    }

    $datos = json_decode($respuesta, true);
    return ($datos['enviado'] ?? false) === true;
}

// Aviso al cliente de que el auto está listo
function notificar_listo_api(int $idTrabajo, string $token, string $apiUrl): bool
{
    return llamar_api_enviar("/api/trabajos/$idTrabajo/notificar-listo", $token, $apiUrl);
}

// Aviso al cliente con el detalle del presupuesto
function notificar_presupuesto_api(int $idPresupuesto, string $token, string $apiUrl): bool
{
    return llamar_api_enviar("/api/presupuestos/$idPresupuesto/notificar", $token, $apiUrl);
}