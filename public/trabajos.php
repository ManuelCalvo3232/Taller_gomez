<?php

require __DIR__ . '/../src/config/database.php';
require __DIR__ . '/../src/auth/acceso.php';
require __DIR__ . '/../src/auth/flash.php';
require __DIR__ . '/../src/views/layout.php';
require __DIR__ . '/../src/dominio/trabajos.php';

requerir_rol('mecanico');

$idMecanico = (int) $_SESSION['usuario_id'];
$errores = [];
$mensaje = flash_get();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar();
    $idTrabajo = filter_var($_POST['id_trabajo'] ?? '', FILTER_VALIDATE_INT);

    if ($idTrabajo === false) {
        $errores[] = 'Trabajo inválido.';
    } else {
        try {
            $pdo = conectar();
            $stmt = $pdo->prepare(
                "UPDATE trabajos
                 SET id_mecanico = ?, estado = ?
                 WHERE id_trabajo = ? AND id_mecanico IS NULL AND estado = ?"
            );
            $stmt->execute([$idMecanico, 'en_diagnostico', $idTrabajo, 'recibido']);

            if ($stmt->rowCount() === 1) {
                $mensaje = 'Trabajo asignado. Ya podés cargar el diagnóstico.';
            } else {
                $errores[] = 'Ese trabajo ya fue tomado por otro mecánico o ya no está disponible.';
            }
        } catch (PDOException $e) {
            $errores[] = 'Error interno. Intentá de nuevo más tarde.';
            error_log($e->getMessage());
        }
    }

    if ($mensaje !== '' && empty($errores)) {
        flash_set($mensaje);
        header('Location: trabajos');
        exit;
    }
}

try {
    $pdo = conectar();
    $stmt = $pdo->prepare(
        "SELECT t.id_trabajo, t.estado, t.id_mecanico,
                tu.fecha_hora, tu.motivo,
                v.patente, v.modelo, v.anio, v.kilometros,
                u.nombre AS cliente, u.telefono
         FROM trabajos t
         JOIN turnos tu ON tu.id_turno = t.id_turno
         JOIN vehiculos v ON v.id_vehiculo = tu.id_vehiculo
         JOIN usuarios u ON u.id_usuario = v.id_cliente
         WHERE t.estado NOT IN ('listo', 'rechazado')
           AND (t.id_mecanico IS NULL OR t.id_mecanico = ?)
         ORDER BY tu.fecha_hora"
    );
    $stmt->execute([$idMecanico]);
    $trabajos = $stmt->fetchAll();
} catch (PDOException $e) {
    $trabajos = [];
    $errores[] = 'No se pudieron cargar los trabajos.';
    error_log($e->getMessage());
}

$sinAsignar = array_filter($trabajos, fn($t) => $t['id_mecanico'] === null);
$misTrabajos = array_filter($trabajos, fn($t) => $t['id_mecanico'] !== null);

inicio_html('Trabajos');
?>
  <div class="encabezado-pagina">
    <div>
      <h1>Tablero del Mecánico</h1>
      <p class="subtitulo">Tomá ingresos sin asignar, gestioná diagnósticos, armá presupuestos y cerrá órdenes listas.</p>
    </div>
    <a href="stock" class="btn btn-outline"><?= icono_svg('box') ?><span>Consultar stock</span></a>
  </div>

  <?php mostrar_mensajes($mensaje, $errores); ?>

  <section class="kpi-strip">
    <div class="kpi-card">
      <div class="kpi-ico hub-icon amber"><?= icono_svg('calendar') ?></div>
      <div>
        <div class="kpi-label">Sin asignar</div>
        <div class="kpi-valor"><?= count($sinAsignar) ?></div>
      </div>
    </div>
    <div class="kpi-card">
      <div class="kpi-ico hub-icon orange"><?= icono_svg('wrench') ?></div>
      <div>
        <div class="kpi-label">Mis trabajos activos</div>
        <div class="kpi-valor"><?= count($misTrabajos) ?></div>
      </div>
    </div>
  </section>

  <div class="dos-columnas">
    <section>
      <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
        <h2 style="margin:0;">Sin asignar (Recepción)</h2>
        <span class="badge badge-pendiente"><?= count($sinAsignar) ?> en espera</span>
      </div>

      <?php if (empty($sinAsignar)): ?>
        <div class="tarjeta">
          <p class="vacio" style="margin:0;">No hay vehículos esperando asignación en recepción.</p>
        </div>
      <?php else: ?>
        <div class="lista">
          <?php foreach ($sinAsignar as $t): ?>
            <article class="tarjeta" style="margin-bottom:0;">
              <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px; flex-wrap:wrap; margin-bottom:12px;">
                <div>
                  <span class="mono" style="font-size:0.75rem; color:var(--text-muted);">Orden #<?= (int) $t['id_trabajo'] ?></span>
                  <h3 style="margin:2px 0 0; font-size:1.05rem;">
                    <?= htmlspecialchars($t['modelo']) ?> (<?= (int) $t['anio'] ?>)
                  </h3>
                </div>
                <span class="patente-placa"><?= htmlspecialchars($t['patente']) ?></span>
              </div>

              <div class="meta-tile" style="margin-bottom:12px;">
                <span>Motivo ingresado</span>
                <strong style="font-weight:500;"><?= htmlspecialchars($t['motivo']) ?></strong>
              </div>

              <div class="detalle" style="display:flex; flex-wrap:wrap; gap:12px; font-size:0.8rem; color:var(--text-secondary); margin-bottom:14px;">
                <span>Dueño: <strong style="color:#fff;"><?= htmlspecialchars($t['cliente']) ?></strong></span>
                <span>Tel: <strong style="color:#fff;"><?= htmlspecialchars($t['telefono'] ?? '-') ?></strong></span>
                <span class="mono"><?= number_format((int) $t['kilometros'], 0, ',', '.') ?> km</span>
                <span>Turno: <?= date('d/m H:i', strtotime($t['fecha_hora'])) ?> hs</span>
              </div>

              <form method="POST" action="trabajos">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="id_trabajo" value="<?= (int) $t['id_trabajo'] ?>">
                <button type="submit" class="btn btn-grande">Asignarme este vehículo</button>
              </form>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section>
      <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
        <h2 style="margin:0;">Mis trabajos en taller</h2>
        <span class="badge badge-activo"><?= count($misTrabajos) ?> activos</span>
      </div>

      <?php if (empty($misTrabajos)): ?>
        <div class="tarjeta">
          <p class="vacio" style="margin:0;">Todavía no tenés trabajos asignados.</p>
        </div>
      <?php else: ?>
        <div class="lista">
          <?php foreach ($misTrabajos as $t): ?>
            <article class="tarjeta destacada" style="margin-bottom:0;">
              <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px; flex-wrap:wrap; margin-bottom:10px;">
                <div>
                  <span class="mono" style="font-size:0.75rem; color:#fb923c;">Orden #<?= (int) $t['id_trabajo'] ?></span>
                  <h3 style="margin:2px 0 0; font-size:1.05rem;"><?= htmlspecialchars($t['modelo']) ?></h3>
                </div>
                <span class="patente-placa"><?= htmlspecialchars($t['patente']) ?></span>
              </div>

              <p class="subtitulo" style="margin-bottom:12px;">
                Dueño: <strong style="color:#fff;"><?= htmlspecialchars($t['cliente']) ?></strong> · <?= htmlspecialchars($t['motivo']) ?>
              </p>

              <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; padding-top:10px; border-top:1px solid var(--border);">
                <?= badge_estado($t['estado']) ?>
                <a class="btn btn-outline" href="trabajo?id=<?= (int) $t['id_trabajo'] ?>" style="min-height:38px;">
                  <span>Abrir orden</span><?= icono_svg('arrow-right') ?>
                </a>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>
<?php
link_panel();
fin_html();
