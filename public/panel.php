<?php
require __DIR__ . '/../src/auth/acceso.php';

requerir_login();

$nombre = htmlspecialchars($_SESSION['nombre']);
$rol = $_SESSION['rol'];

// Menú según el rol: cada opción apunta a una vista que se protege sola
$opciones = [
    'cliente' => [
        ['Pedir turno', 'turno_nuevo.php'],
        ['Mis vehículos', 'vehiculos.php'],
        ['Mis presupuestos', 'presupuestos.php'],
    ],
    'mecanico' => [
        ['Trabajos sin asignar y asignados', 'trabajos.php'],
    ],
    'admin' => [
        ['Dashboard', 'dashboard.php'],
    ],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Panel - Taller Gómez</title>
</head>
<body>
  <h1>Hola, <?= $nombre ?></h1>
  <p>Rol: <?= htmlspecialchars($rol) ?></p>

  <ul>
    <?php foreach ($opciones[$rol] ?? [] as [$texto, $url]): ?>
      <li><a href="<?= htmlspecialchars($url) ?>"><?= htmlspecialchars($texto) ?></a></li>
    <?php endforeach; ?>
  </ul>

    <form method="POST" action="logout.php">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
    <button type="submit">Cerrar sesión</button>
  </form>
</body>
</html>