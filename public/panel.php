<?php
require __DIR__ . '/../src/auth/acceso.php';
require __DIR__ . '/../src/views/layout.php';

requerir_login();

$nombre = $_SESSION['nombre'];
$rol = $_SESSION['rol'];

// Opciones según el rol: cada una apunta a una vista que se protege sola
$opciones = [
    'cliente' => [
        ['Pedir turno', 'Reservá el horario para tu vehículo', 'turno_nuevo.php'],
        ['Mis presupuestos', 'Revisá y aprobá los presupuestos del taller', 'presupuestos.php'],
    ],
    'mecanico' => [
        ['Trabajos', 'Asignate vehículos y cargá diagnósticos y presupuestos', 'trabajos.php'],
    ],
    'admin' => [
        ['Dashboard', 'Caja, trabajos, stock e insights del taller', 'dashboard.php'],
    ],
];

inicio_html('Panel');
?>
  <h1>Hola, <?= htmlspecialchars($nombre) ?></h1>
  <p class="vacio">Sesión de <?= htmlspecialchars($rol) ?></p>

  <div class="lista">
    <?php foreach ($opciones[$rol] ?? [] as [$titulo, $detalle, $url]): ?>
      <a class="item" href="<?= htmlspecialchars($url) ?>" style="text-decoration:none; color:inherit;">
        <div>
          <div class="titulo"><?= htmlspecialchars($titulo) ?></div>
          <div class="detalle"><?= htmlspecialchars($detalle) ?></div>
        </div>
        <span class="btn">Entrar</span>
      </a>
    <?php endforeach; ?>
  </div>
<?php
fin_html();