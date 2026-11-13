<?php

require __DIR__ . '/../src/auth/acceso.php';
require __DIR__ . '/../src/auth/flash.php';
require __DIR__ . '/../src/config/database.php';
require __DIR__ . '/../src/views/layout.php';

requerir_login();

$nombre = $_SESSION['nombre'];
$rol = $_SESSION['rol'];
$mensaje = flash_get();

$sinTelefono = false;
$presupuestosParaAprobar = 0;
$pagosPendientes = 0;
$presupuestoAlerta = null;
$trabajoActivo = null;
$totalVehiculos = 0;
$repuestosBajos = null;

if ($rol === 'cliente') {
    $pdo = conectar();
    $idCliente = (int) $_SESSION['usuario_id'];

    $stmt = $pdo->prepare('SELECT telefono FROM usuarios WHERE id_usuario = ?');
    $stmt->execute([$idCliente]);
    $sinTelefono = trim((string) $stmt->fetchColumn()) === '';

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM vehiculos WHERE id_cliente = ?');
    $stmt->execute([$idCliente]);
    $totalVehiculos = (int) $stmt->fetchColumn();

    // Presupuesto prioritario pendiente de aprobación o de pago
    $stmt = $pdo->prepare(
        "SELECT p.id_presupuesto, p.total, p.estado, v.modelo, v.patente
         FROM presupuestos p
         JOIN trabajos t ON t.id_trabajo = p.id_trabajo
         JOIN turnos tu ON tu.id_turno = t.id_turno
         JOIN vehiculos v ON v.id_vehiculo = tu.id_vehiculo
         WHERE v.id_cliente = ?
           AND (p.estado = 'enviado' OR (
             p.estado = 'aprobado'
             AND NOT EXISTS (SELECT 1 FROM pagos pg WHERE pg.id_presupuesto = p.id_presupuesto AND pg.estado = 'aprobado')
           ))
         ORDER BY (p.estado = 'enviado') DESC, p.fecha_envio DESC
         LIMIT 1"
    );
    $stmt->execute([$idCliente]);
    $presupuestoAlerta = $stmt->fetch() ?: null;

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

    // Trabajo activo más reciente para seguimiento en vivo
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
} elseif ($rol === 'mecanico') {
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
  <?php mostrar_mensajes($mensaje, []); ?>

  <!-- Hero Portal -->
  <section class="hero-portal">
    <div>
      <div class="hero-kicker">
        <span><?= $rol === 'cliente' ? 'Portal del Propietario' : ($rol === 'mecanico' ? 'Portal Técnico' : 'Administración General') ?></span>
        <span>·</span>
        <span style="color:var(--text-secondary);">Taller Gómez</span>
      </div>
      <h1>Hola, <?= htmlspecialchars($nombre) ?></h1>
      <p class="subtitulo">
        <?php if ($rol === 'cliente'): ?>
          <?php if ($trabajoActivo && $trabajoActivo['estado'] !== 'listo'): ?>
            Tenés <strong style="color:#fb923c;">1 vehículo activo</strong> en el taller con seguimiento en vivo disponible.
          <?php else: ?>
            Reservá tu turno online, aprobá presupuestos y seguí el estado de tu vehículo en tiempo real.
          <?php endif; ?>
        <?php elseif ($rol === 'mecanico'): ?>
          Tomá órdenes sin asignar, cargá diagnósticos y enviá presupuestos vinculados al stock.
        <?php else: ?>
          Controlá la caja en pesos, trabajos activos, stock crítico y desempeño del equipo.
        <?php endif; ?>
      </p>
    </div>

    <div class="hero-actions">
      <?php if ($rol === 'cliente'): ?>
        <a href="turno_nuevo" class="btn"><?= icono_svg('calendar') ?><span>Pedir Nuevo Turno</span></a>
        <a href="https://wa.me/5491149823344" target="_blank" rel="noreferrer" class="btn btn-whatsapp">
          <?= icono_svg('whatsapp') ?><span>WhatsApp Taller</span>
        </a>
      <?php elseif ($rol === 'mecanico'): ?>
        <a href="trabajos" class="btn"><?= icono_svg('wrench') ?><span>Ver Trabajos</span></a>
        <a href="stock" class="btn btn-outline"><?= icono_svg('box') ?><span>Consultar Stock</span></a>
      <?php else: ?>
        <a href="dashboard" class="btn"><?= icono_svg('chart') ?><span>Ir al Dashboard</span></a>
        <a href="stock" class="btn btn-outline"><?= icono_svg('box') ?><span>Stock y Proveedores</span></a>
      <?php endif; ?>
    </div>
  </section>

  <?php if ($sinTelefono): ?>
    <div class="alerta alerta-error" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
      <span>Cargá tu teléfono en Mi perfil para que el taller pueda avisarte novedades o confirmar tu turno.</span>
      <a href="perfil" class="btn btn-outline" style="min-height:36px; padding:6px 14px;">Completar teléfono</a>
    </div>
  <?php endif; ?>

  <?php if ($repuestosBajos !== null && $repuestosBajos > 0): ?>
    <div class="alerta alerta-error" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
      <span>Hay <?= $repuestosBajos ?> repuesto<?= $repuestosBajos === 1 ? '' : 's' ?> por debajo del stock mínimo.</span>
      <a href="stock" class="btn btn-outline" style="min-height:36px; padding:6px 14px;">Ver repuestos</a>
    </div>
  <?php endif; ?>

  <?php if ($rol === 'cliente'): ?>
    <!-- Callout de Presupuesto Pendiente -->
    <?php if ($presupuestoAlerta): ?>
      <section class="callout-presupuesto">
        <div class="callout-left">
          <div class="callout-ico"><?= icono_svg('credit-card') ?></div>
          <div>
            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
              <strong style="color:#ffffff; font-size:0.9rem;">
                <?= $presupuestoAlerta['estado'] === 'enviado'
                    ? 'Presupuesto #' . (int) $presupuestoAlerta['id_presupuesto'] . ' enviado para tu aprobación'
                    : 'Presupuesto #' . (int) $presupuestoAlerta['id_presupuesto'] . ' aprobado — listo para abonar' ?>
              </strong>
              <span class="badge badge-activo mono">$ <?= number_format((float) $presupuestoAlerta['total'], 2, ',', '.') ?></span>
            </div>
            <p class="subtitulo" style="margin-top:3px;">
              Corresponde a tu <strong style="color:#fff;"><?= htmlspecialchars($presupuestoAlerta['modelo']) ?></strong>
              (<span class="mono"><?= htmlspecialchars($presupuestoAlerta['patente']) ?></span>).
            </p>
          </div>
        </div>
        <a href="presupuestos" class="btn">
          <span><?= $presupuestoAlerta['estado'] === 'enviado' ? 'Revisar y Aprobar' : 'Abonar Online' ?></span>
          <?= icono_svg('arrow-right') ?>
        </a>
      </section>
    <?php endif; ?>

    <!-- Live Vehicle Tracking Card -->
    <?php if ($trabajoActivo && $indiceActivo >= 0): ?>
      <div class="tracking-section-head">
        <h2 class="tracking-section-title"><?= icono_svg('wrench') ?><span>Vehículo en Servicio Activo</span></h2>
        <span class="live-pill"><span class="live-dot"></span>Seguimiento en tiempo real</span>
      </div>

      <section class="tracking-box" aria-label="Seguimiento del vehículo">
        <div class="tracking-header">
          <div class="tracking-vehicle">
            <div class="tracking-avatar"><?= icono_svg('car') ?></div>
            <div>
              <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <h3 style="margin:0; font-size:1.1rem;"><?= htmlspecialchars($trabajoActivo['modelo']) ?></h3>
                <span class="patente-placa"><?= htmlspecialchars($trabajoActivo['patente']) ?></span>
              </div>
              <p class="subtitulo" style="margin-top:4px;">
                Orden #<?= (int) $trabajoActivo['id_trabajo'] ?> · <?= htmlspecialchars($trabajoActivo['motivo']) ?>
              </p>
            </div>
          </div>
          <?= badge_estado($trabajoActivo['estado']) ?>
        </div>

        <div class="tracking-body">
          <ol class="etapas">
            <?php foreach ($etapasCliente as $i => $etapa): ?>
              <li class="<?= $i < $indiceActivo ? 'hecha' : ($i === $indiceActivo ? 'activa' : '') ?>"><?= $etapa ?></li>
            <?php endforeach; ?>
          </ol>

          <div class="tracking-meta-grid">
            <div class="meta-tile">
              <span>Fecha del turno</span>
              <strong><?= date('d/m/Y · H:i', strtotime($trabajoActivo['fecha_hora'])) ?> hs</strong>
            </div>
            <div class="meta-tile">
              <span>Mecánico asignado</span>
              <strong><?= htmlspecialchars($trabajoActivo['mecanico'] ?? 'Por asignar en recepción') ?></strong>
            </div>
            <div class="meta-tile">
              <span>Kilometraje ingresado</span>
              <strong class="mono"><?= number_format((int) $trabajoActivo['kilometros'], 0, ',', '.') ?> km</strong>
            </div>
            <div class="meta-tile">
              <span>Presupuestos</span>
              <strong>
                <?php if ($presupuestosParaAprobar > 0): ?>
                  <a href="presupuestos">Tenés <?= $presupuestosParaAprobar ?> para aprobar →</a>
                <?php elseif ($pagosPendientes > 0): ?>
                  <a href="presupuestos">Tenés <?= $pagosPendientes ?> para abonar →</a>
                <?php else: ?>
                  Al día
                <?php endif; ?>
              </strong>
            </div>
          </div>

          <?php if (!empty($trabajoActivo['diagnostico'])): ?>
            <div class="meta-tile" style="border-left:3px solid var(--accent);">
              <span>Diagnóstico técnico del taller</span>
              <strong style="font-weight:500; color:var(--text-primary);"><?= nl2br(htmlspecialchars($trabajoActivo['diagnostico'])) ?></strong>
            </div>
          <?php endif; ?>
        </div>
      </section>
    <?php endif; ?>

    <!-- 4 Hub Cards Modulares -->
    <section class="hub-grid" aria-label="Accesos principales">
      <a href="turno_nuevo" class="hub-card">
        <div class="hub-icon orange"><?= icono_svg('calendar') ?></div>
        <div>
          <div class="hub-title"><span>Pedir Turno Online</span><span class="hub-arrow"><?= icono_svg('arrow-right') ?></span></div>
          <p class="hub-desc">Reservá fecha y horario para revisión mecánica o service de mantenimiento.</p>
        </div>
      </a>

      <a href="presupuestos" class="hub-card">
        <div class="hub-icon sky"><?= icono_svg('credit-card') ?></div>
        <div>
          <div class="hub-title"><span>Mis Presupuestos</span><span class="hub-arrow"><?= icono_svg('arrow-right') ?></span></div>
          <p class="hub-desc">Revisá ítems, aprobá y pagá online con Mercado Pago o en el taller.</p>
        </div>
      </a>

      <a href="vehiculos" class="hub-card">
        <div class="hub-icon emerald"><?= icono_svg('car') ?></div>
        <div>
          <div class="hub-title"><span>Mis Vehículos</span><span class="hub-arrow"><?= icono_svg('arrow-right') ?></span></div>
          <p class="hub-desc"><?= $totalVehiculos ?> rodado<?= $totalVehiculos === 1 ? '' : 's' ?> guardado<?= $totalVehiculos === 1 ? '' : 's' ?> con patente y kilometraje.</p>
        </div>
      </a>

      <a href="historial" class="hub-card">
        <div class="hub-icon amber"><?= icono_svg('history') ?></div>
        <div>
          <div class="hub-title"><span>Historial Clínico</span><span class="hub-arrow"><?= icono_svg('arrow-right') ?></span></div>
          <p class="hub-desc">Todos tus trabajos realizados, diagnósticos anteriores y comprobantes.</p>
        </div>
      </a>
    </section>

    <!-- Regla de Oro Banner -->
    <section class="golden-rule">
      <div class="golden-rule-left">
        <div class="golden-ico"><?= icono_svg('shield') ?></div>
        <div style="font-size:0.82rem;">
          <strong style="color:#ffffff; display:block;">La Regla de Oro de Taller Gómez:</strong>
          <span style="color:var(--text-secondary);">"Tu auto, con presupuesto antes de tocar nada." Ningún trabajo extra se realiza sin tu autorización previa.</span>
        </div>
      </div>
      <a href="perfil" class="btn btn-outline" style="min-height:38px;">Revisar Mi Perfil & Teléfono</a>
    </section>

  <?php else: ?>
    <!-- Hub Cards para Mecánico / Admin -->
    <section class="hub-grid" style="grid-template-columns: repeat(2, minmax(0, 1fr));">
      <?php if ($rol === 'mecanico'): ?>
        <a href="trabajos" class="hub-card">
          <div class="hub-icon orange"><?= icono_svg('wrench') ?></div>
          <div>
            <div class="hub-title"><span>Tablero de Trabajos</span><span class="hub-arrow"><?= icono_svg('arrow-right') ?></span></div>
            <p class="hub-desc">Tomá trabajos sin asignar, cargá diagnósticos, armá presupuestos y registrá cobros en el taller.</p>
          </div>
        </a>
        <a href="stock" class="hub-card">
          <div class="hub-icon amber"><?= icono_svg('box') ?></div>
          <div>
            <div class="hub-title"><span>Repuestos del Taller</span><span class="hub-arrow"><?= icono_svg('arrow-right') ?></span></div>
            <p class="hub-desc">Consultá disponibilidad de repuestos y enviá alertas de faltantes al administrador.</p>
          </div>
        </a>
      <?php else: ?>
        <a href="dashboard" class="hub-card">
          <div class="hub-icon orange"><?= icono_svg('chart') ?></div>
          <div>
            <div class="hub-title"><span>Dashboard Gerencial</span><span class="hub-arrow"><?= icono_svg('arrow-right') ?></span></div>
            <p class="hub-desc">Cobrado vs pendiente, trabajos activos, realizados e insights mensuales de la API.</p>
          </div>
        </a>
        <a href="stock" class="hub-card">
          <div class="hub-icon emerald"><?= icono_svg('box') ?></div>
          <div>
            <div class="hub-title"><span>Stock y Proveedores</span><span class="hub-arrow"><?= icono_svg('arrow-right') ?></span></div>
            <p class="hub-desc">Repuestos, costos, margen de ganancia, movimientos de entrada/salida y proveedores.</p>
          </div>
        </a>
      <?php endif; ?>
    </section>
  <?php endif; ?>
<?php
fin_html();
