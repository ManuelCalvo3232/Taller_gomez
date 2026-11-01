<?php

function conectar(): PDO
{
    // 1. Leer la configuración (desde el entorno)
    $config = require __DIR__ . '/../../config/config.php';

    // 2. Armar el DSN
    $dsn = "mysql:host={$config['host']};dbname={$config['base']};charset=utf8mb4";

    // 3. Crear la conexión con las opciones
    $pdo = new PDO($dsn, $config['usuario'], $config['clave'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // Preparadas reales del lado del servidor, no emuladas por PHP
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // 4. Devolver la conexión
    return $pdo;
}