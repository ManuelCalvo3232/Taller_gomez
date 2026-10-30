<?php

// Máximo de fallos permitidos dentro de la ventana de tiempo
const MAX_INTENTOS_LOGIN = 5;
const VENTANA_LOGIN_MINUTOS = 15;

// ¿Esta IP superó el límite de fallos recientes?
function login_bloqueado(PDO $pdo, string $ip): bool
{
    // Calculamos la fecha con PHP para usar la misma zona horaria al guardar y al comparar
    $desde = (new DateTime())->modify('-' . VENTANA_LOGIN_MINUTOS . ' minutes')->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM intentos_login WHERE ip = ? AND creado_en > ?");
    $stmt->execute([$ip, $desde]);

    return (int) $stmt->fetchColumn() >= MAX_INTENTOS_LOGIN;
}

// Guarda un fallo de login para esta IP
function registrar_fallo_login(PDO $pdo, string $ip): void
{
    $stmt = $pdo->prepare("INSERT INTO intentos_login (ip, creado_en) VALUES (?, ?)");
    $stmt->execute([$ip, (new DateTime())->format('Y-m-d H:i:s')]);
}

// Un login correcto borra los fallos previos de esa IP
function limpiar_fallos_login(PDO $pdo, string $ip): void
{
    $stmt = $pdo->prepare("DELETE FROM intentos_login WHERE ip = ?");
    $stmt->execute([$ip]);
}