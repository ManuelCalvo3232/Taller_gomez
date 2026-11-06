<?php
require __DIR__ . '/../src/auth/acceso.php';
require __DIR__ . '/../src/config/database.php';
require __DIR__ . '/../src/views/layout.php';

requerir_login();

$nombre = $_SESSION['nombre'];
$rol = $_SESSION['rol'];

// Opciones según el rol: cada una apunta a una vista que se protege sola
// Para el cliente: sin teléfono el taller no lo puede contactar
$sinTelefono = false;
// Para el cliente: presupuestos aprobados que todavía no están pagados
$pagosPendientes = null;
if ($rol === 'cliente') {
    $stmt = conectar()->prepare('SELECT telefono FROM usuarios WHERE id_usuario = ?');
    $stmt->execute([(int) $_SESSION['usuario_id']]);
    $sinTelefono = trim((string) $stmt->fetchColumn()) === '';

    $stmt = conectar()->prepare(
        "SELECT COUNT(*) FROM presupuestos p
         JOIN trabajos t ON t.id_trabajo = p.id_trabajo
         JOIN turnos tu ON tu.id_turno = t.id_turno
         JOIN vehiculos v ON v.id_vehiculo = tu.id_vehiculo
         WHERE v.id_cliente = ? AND p.estado = 'aprobado'
           AND NOT EXISTS (SELECT 1 FROM pagos pg WHERE pg.id_presupuesto = p.id_presupuesto AND pg.estado = 'aprobado')"
    );
    $stmt->execute([(int) $_SESSION['usuario_id']]);
    $pagosPendientes = (int) $stmt->fetchColumn();
}

$opciones = [
    'cliente' => [
        ['Pedir turno', 'Reservá el horario para tu vehículo', 'turno_nuevo'],
        ['Mis presupuestos', 'Revisá y aprobá los presupuestos del taller', 'presupuestos'],
        ['Mis vehículos', 'Agregá, editá o quitá tus autos y motos', 'vehiculos'],
        ['Historial', 'Todos tus trabajos, con diagnóstico y total', 'historial'],
        ['Mi perfil', 'Tus datos, teléfono y clave', 'perfil'],
    ],
    'mecanico' => [
        ['Trabajos', 'Asignate vehículos y cargá diagnósticos y presupuestos', 'trabajos'],
        ['Repuestos del taller', 'Qué hay en stock y avisar si falta algo', 'stock'],
    ],
    'admin' => [
        ['Dashboard', 'Caja, trabajos, stock e insights del taller', 'dashboard'],
        ['Stock y proveedores', 'Repuestos, compras, costos, ganancia y proveedores', 'stock'],
    ],
];

// Para el mecánico: cuántos repuestos están por debajo del mínimo
$repuestosBajos = null;
if ($rol === 'mecanico') {
    require_once __DIR__ . '/../src/config/database.php';
    $repuestosBajos = (int) conectar()->query('SELECT COUNT(*) FROM repuestos WHERE stock <= stock_minimo')->fetchColumn();
}

inicio_html('Panel');
?>
  <h1>Hola, <?= htmlspecialchars($nombre) ?></h1>
  <?php if ($sinTelefono): ?>
    <a href="perfil" class="alerta alerta-error" style="display:block; text-decoration:none;">
      Cargá tu teléfono en Mi perfil. Es la única forma en que el taller puede avisarte.
    </a>
  <?php endif; ?>
  <?php if ($repuestosBajos > 0): ?>
    <a href="stock" class="alerta alerta-error" style="display:block; text-decoration:none;">
      <?= $repuestosBajos ?> repuesto<?= $repuestosBajos === 1 ? '' : 's' ?> con stock bajo · Ver stock
    </a>
  <?php endif; ?>

  <?php if ($rol === 'cliente'): ?>
    <?php if ($pagosPendientes > 0): ?>
      <section class="paso" aria-labelledby="paso-titulo">
        <div>
          <h2 id="paso-titulo">Tenés un presupuesto para pagar</h2>
          <p>Revisalo y pagalo para que el taller entregue tu vehículo.</p>
        </div>
        <a href="presupuestos" class="btn">Revisar y pagar</a>
      </section>
    <?php else: ?>
      <section class="paso" aria-labelledby="paso-titulo">
        <div>
          <h2 id="paso-titulo">¿Qué necesitás hacer?</h2>
          <p>Pedí un turno para revisar tu vehículo.</p>
        </div>
        <a href="turno_nuevo" class="btn">Pedir turno</a>
      </section>
    <?php endif; ?>
  <?php endif; ?>

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