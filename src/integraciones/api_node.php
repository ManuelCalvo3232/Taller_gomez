<?php

// Llama a la API de Node para avisar al cliente que el auto está listo.
// Devuelve true si el aviso salió bien. Si falla, no interrumpe el flujo del taller.
function notificar_listo_api(int $idTrabajo, string $token, string $apiUrl): bool
{
    if ($token === '') {
        return false;
    }

    $ch = curl_init($apiUrl . '/api/trabajos/' . $idTrabajo . '/notificar-listo');
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
        error_log("notificar_listo falló para el trabajo $idTrabajo (HTTP $codigo)");
        return false;
    }

    $datos = json_decode($respuesta, true);
    return ($datos['enviado'] ?? false) === true;
}