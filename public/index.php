<?php
require __DIR__ . '/../src/config/database.php';

$pdo = conectar();
echo "Conectado a la base<br>";

$filas = $pdo->query("SELECT COUNT(*) AS total FROM usuarios")->fetchAll();
echo "Usuarios en la tabla: " . $filas[0]['total'];