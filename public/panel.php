<?php

require __DIR__ . '/../src/auth/acceso.php';
require __DIR__ . '/../src/auth/flash.php';
require __DIR__ . '/../src/config/database.php';
require __DIR__ . '/../src/views/layout.php';

requerir_login();

$nombre = $_SESSION['nombre'];
$rol = $_SESSION['rol'];
$mensaje = flash_get();

// Para el cliente: sin teléfono el taller no lo puede contactar
$sinTelefono = false;
// Para el cliente: presupuestos aprobados que todavía no están pagados y enviados sin responder
$pagosPendientes = 0;
$presupuestosParaAprobar = 0;
$trabajoActivo = null;

if ($rol === 'cliente') {
    $pdo = conectar();
    $idCliente = (int) $_SESSION['usuario_id'];

    $stmt = $pdo->prepare('SELECT telefono FROM usuarios WHERE id_usuario = ?');
    $stmt->execute([$idCliente]);
    $sinTelefono = trim((string) $stmt->fetchColumn()) === '';

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM presupuestos p
         JOIN trabajos t ON t.id_trabajo = p.id_trabajo
         JOIN turnos tu ON tu.id_turno = t.id_turno
         JOIN vehiculos v ON v.id_vehiculo = tu.id_vehiculo
         WHERE v.id_cliente = ? AND p.estado = 'enviado'"
    );
    $stmt->execute([$idCliente]);
    $presupuestosParaAprobar = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM presupuestos p
         JOIN trabajos t ON t.id_trabajo = p.id_trabajo
         JOIN turnos tu ON tu.id_turno = t.id_turno
         JOIN vehiculos v ON v.id_vehiculo = tu.id_vehiculo
         WHERE v.id_cliente = ? AND p.estado = 'aprobado'
           AND NOT EXISTS (SELECT 1 FROM pagos pg WHERE pg.id_presupuesto = p.id_presupuesto AND pg.estado = 'aprobado')"
    );
    $stmt->execute([$idCliente]);
    $pagosPendientes = (int) $stmt->fetchColumn();

    // Trabajo activo más reciente para seguimiento en vivo (o último finalizado)
    $stmt = $pdo->prepare(
        "SELECT t.id_trabajo, t.estado, tu.fecha_hora, tu.motivo,
                v.modelo, v.patente, v.kilometros,
                m.nombre AS mecanico,
                d.descripcion AS diagnostico
         FROM trabajos t
         JOIN turnos tu ON tu.id_turno = t.id_turno
         JOIN vehiculos v ON v.id_vehiculo = tu.id_vehiculo
         LEFT JOIN usuarios m ON m.id_usuario = t.id_mecanico
         LEFT JOIN diagnosticos d ON d.id_trabajo = t.id_trabajo
         WHERE v.id_cliente = ? AND t.estado <> 'rechazado'
         ORDER BY (t.estado = 'listo') ASC, tu.fecha_hora DESC
         LIMIT 1"
    );
    $stmt->execute([$idCliente]);
    $trabajoActivo = $stmt->fetch() ?: null;
}

$opciones = [
    'cliente' => [
        ['Pedir turno', 'Reservá el horario para tu auto o moto', 'turno_nuevo'],
        ['Mis presupuestos', 'Revisá, aprobá y pagá los presupuestos del taller', 'presupuestos'],
        ['Mis vehículos', 'Agregá, editá o quitá tus autos y motos', 'vehiculos'],
        ['Historial', 'Todos tus trabajos, con diagnóstico y total', 'historial'],
        ['Mi perfil', 'Tus datos, teléfono de contacto y clave', 'perfil'],
    ],
    'mecanico' => [
        ['Trabajos', 'Asignate vehículos y cargá diagnósticos y presupuestos', 'trabajos'],
        ['Repuestos del taller', 'Qué hay en stock y avisar si falta algo', 'stock'],
    ],
    'admin' => [
        ['Dashboard', 'Caja, trabajos activos, stock e insights del taller', 'dashboard'],
        ['Stock y proveedores', 'Repuestos, compras, costos, ganancia y proveedores', 'stock'],
    ],
];

// Para el mecánico: cuántos repuestos están por debajo del mínimo
$repuestosBajos = null;
if ($rol === 'mecanico') {
    require_once __DIR__ . '/../src/config/database.php';
    $repuestosBajos = (int) conectar()->query('SELECT COUNT(*) FROM repuestos WHERE stock <= stock_minimo')->fetchColumn();
}

$etapasCliente = ['Recibido', 'Diagnóstico', 'Presupuesto', 'Aprobado', 'En reparación', 'Listo'];
$indiceActivo = $trabajoActivo ? match ($trabajoActivo['estado']) {
    'recibido' => 0,
    'en_diagnostico' => 1,
    'presupuestado' => 2,
    'aprobado' => 3,
    'en_reparacion' => 4,
    'listo' => 5,
    default => -1,
} : -1;

inicio_html('Panel');
?>
  <div class="encabezado-pagina">
    <div>
      <h1>Hola, <?= htmlspecialchars($nombre) ?></h1>
      <p class="subtitulo">
        <?php if ($rol === 'cliente'): ?>
          Seguimiento de tu vehículo, turnos online y presupuestos en un solo lugar.
        <?php elseif ($rol === 'mecanico'): ?>
          Gestión de órdenes activas en bahía, diagnósticos y consulta de repuestos.
        <?php else: ?>
          Control operativo del taller, facturación e inventario.
        <?php endif; ?>
      </p>
    </div>
    <?php if ($rol === 'cliente'): ?>
      <a href="turno_nuevo" class="btn">Pedir nuevo turno</a>
    <?php endif; ?>
  </div>

  <?php mostrar_mensajes($mensaje, []); ?>

  <div class="panel-grid<?= ($sinTelefono || ($repuestosBajos !== null && $repuestosBajos > 0)) ? '' : ' solo' ?>">
    <div class="panel-principal">
      <?php if ($rol === 'cliente'): ?>
        <?php if ($presupuestosParaAprobar > 0): ?>
          <section class="paso" aria-labelledby="paso-titulo">
            <div>
              <h2 id="paso-titulo">Tenés <?= $presupuestosParaAprobar ?> presupuesto<?= $presupuestosParaAprobar === 1 ? '' : 's' ?> para revisar</h2>
              <p>Aprobalo online antes de empezar la reparación.</p>
            </div>
            <a href="presupuestos" class="btn">Revisar y aprobar</a>
          </section>
        <?php elseif ($pagosPendientes > 0): ?>
          <section class="paso" aria-labelledby="paso-titulo">
            <div>
              <h2 id="paso-titulo">Tenés un presupuesto listo para pagar</h2>
              <p>Podés abonarlo con Mercado Pago o en el taller con efectivo/transferencia.</p>
            </div>
            <a href="presupuestos" class="btn">Ir a pagar</a>
          </section>
        <?php elseif (!$trabajoActivo): ?>
          <section class="paso" aria-labelledby="paso-titulo">
            <div>
              <h2 id="paso-titulo">¿Qué necesitás hacer?</h2>
              <p>Reservá turno online en menos de un minuto para revisar tu vehículo.</p>
            </div>
            <a href="turno_nuevo" class="btn">Pedir turno</a>
          </section>
        <?php endif; ?>

        <?php if ($trabajoActivo && $indiceActivo >= 0): ?>
          <section class="tracking-card" aria-label="Seguimiento en vivo">
            <div class="tracking-top">
              <div>
                <div class="tracking-kicker">Seguimiento en vivo · Orden #<?= (int) $trabajoActivo['id_trabajo'] ?></div>
                <h2 style="margin:6px 0 4px;">
                  <?= htmlspecialchars($trabajoActivo['modelo']) ?>
                  <span class="patente-tag" style="margin-left:6px; vertical-align:middle;"><?= htmlspecialchars($trabajoActivo['patente']) ?></span>
                </h2>
                <p class="subtitulo"><?= htmlspecialchars($trabajoActivo['motivo']) ?></p>
              </div>
              <div><?= badge_estado($trabajoActivo['estado']) ?></div>
            </div>

            <ol class="etapas">
              <?php foreach ($etapasCliente as $i => $etapa): ?>
                <li class="<?= $i < $indiceActivo ? 'hecha' : ($i === $indiceActivo ? 'activa' : '') ?>"><?= $etapa ?></li>
              <?php endforeach; ?>
            </ol>

            <div class="tracking-meta">
              <div>
                <span>Turno</span>
                <strong><?= date('d/m/Y · H:i', strtotime($trabajoActivo['fecha_hora'])) ?> hs</strong>
              </div>
              <div>
                <span>Mecánico asignado</span>
                <strong><?= htmlspecialchars($trabajoActivo['mecanico'] ?? 'Por asignar') ?></strong>
              </div>
              <div>
                <span>Kilometraje</span>
                <strong class="mono"><?= number_format((int) $trabajoActivo['kilometros'], 0, ',', '.') ?> km</strong>
              </div>
              <?php if (!empty($trabajoActivo['diagnostico'])): ?>
                <div style="grid-column: 1 / -1;">
                  <span>Diagnóstico del taller</span>
                  <strong><?= htmlspecialchars($trabajoActivo['diagnostico']) ?></strong>
                </div>
              <?php endif; ?>
            </div>
          </section>
        <?php endif; ?>
      <?php endif; ?>

      <div class="lista">
        <?php foreach ($opciones[$rol] ?? [] as [$titulo, $detalle, $url]): ?>
          <a class="item" href="<?= htmlspecialchars($url) ?>">
            <div>
              <div class="titulo"><?= htmlspecialchars($titulo) ?></div>
              <div class="detalle"><?= htmlspecialchars($detalle) ?></div>
            </div>
            <span class="flecha" aria-hidden="true">&rarr;</span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if ($sinTelefono || ($repuestosBajos !== null && $repuestosBajos > 0)): ?>
      <aside class="panel-lateral" aria-label="Avisos">
        <h2 style="margin:0; font-size:1rem;">Avisos importantes</h2>
        <?php if ($sinTelefono): ?>
          <a href="perfil" class="alerta alerta-error" style="display:block; text-decoration:none; margin:0;">
            Cargá tu teléfono en Mi perfil. Es la única forma en que el taller puede avisarte novedades o confirmar tu turno.
          </a>
        <?php endif; ?>
        <?php if ($repuestosBajos !== null && $repuestosBajos > 0): ?>
          <a href="stock" class="alerta alerta-error" style="display:block; text-decoration:none; margin:0;">
            <?= $repuestosBajos ?> repuesto<?= $repuestosBajos === 1 ? '' : 's' ?> con stock bajo · Ver stock
          </a>
        <?php endif; ?>
      </aside>
    <?php endif; ?>
  </div>
<?php
fin_html();
