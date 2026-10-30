<?php
require __DIR__ . '/../src/auth/acceso.php';
require __DIR__ . '/../src/views/layout.php';

requerir_login();

$nombre = $_SESSION['nombre'];
$rol = $_SESSION['rol'];

// Menú según el rol: cada opción apunta a una vista que se protege sola
$opciones = [
    'cliente' => [
        ['Pedir turno', 'turno_nuevo.php'],
        ['Mis presupuestos', 'presupuestos.php'],
    ],
    'mecanico' => [
        ['Trabajos sin asignar y asignados', 'trabajos.php'],
    ],
    'admin' => [
        ['Dashboard', 'dashboard.php'],
    ],
];

inicio_html('Panel');
?>
  <h1>Hola, <?= htmlspecialchars($nombre) ?></h1>
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
<?php
fin_html();