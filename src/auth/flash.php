<?php

// Mensajes de un solo uso: se guardan en la sesión antes de redirigir y se muestran una vez.
// Requiere que la sesión ya esté iniciada (csrf.php o acceso.php la inician).

function flash_set(string $mensaje): void
{
    $_SESSION['flash'] = $mensaje;
}

function flash_get(): string
{
    $mensaje = $_SESSION['flash'] ?? '';
    unset($_SESSION['flash']);
    return $mensaje;
}