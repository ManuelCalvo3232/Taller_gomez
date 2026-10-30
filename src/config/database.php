<?php

function conectar(): PDO
{
    // 1. Leer la configuración
    $config = require __DIR__ . '/../../config/local.php';

    // 2. Armar el DSN (texto que le dice a PDO dónde está la base)
    $dsn = "mysql:host={$config['host']};dbname={$config['base']};charset=utf8mb4";

    // 3. Crear la conexión con las opciones
    $pdo = new PDO($dsn, $config['usuario'], $config['clave'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, 
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false, 
    ]);

    // 4. Devolver la conexión
    return $pdo;
}