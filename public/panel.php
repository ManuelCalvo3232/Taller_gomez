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
        ['Mis vehículos', 'Agregá, editá o quitá tus autos y motos', 'vehiculos.php'],
        ['Historial', 'Todos tus trabajos, con diagnóstico y total', 'historial.php'],
        ['Mi perfil', 'Tus datos, teléfono y clave', 'perfil.php'],
    ],
    'mecanico' => [
        ['Trabajos', 'Asignate vehículos y cargá diagnósticos y presupuestos', 'trabajos.php'],
    ],
    'admin' => [
        ['Dashboard', 'Caja, trabajos, stock e insights del taller', 'dashboard.php'],
        ['Stock de repuestos', 'Compras, ventas, costos y ganancia por repuesto', 'stock.php'],
        ['Proveedores', 'Quién te vende cada repuesto', 'proveedores.php'],
    ],
];

inicio_html('Panel');
?>
  <h1>Hola, <?= htmlspecialchars($nombre) ?></h1>

  <div class="lista">
    <?php foreach ($opciones[$rol] ?? [] as [$titulo, $detalle, $url]): ?>
      <a class="item" href="<?= htmlspecialchars($url) ?>" style="text-decoration:none; color:inherit;">
        <div>
          <div class="titulo"><?= htmlspecialchars($titulo) ?></div>
          <div class="detalle"><?= htmlspecialchars($detalle) ?></div>
        </div>
        <span class="flecha" aria-hidden="true">&rarr;</span>
      </a>
    <?php endforeach; ?>
  </div>
<?php
fin_html();