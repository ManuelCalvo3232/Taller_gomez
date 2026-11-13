<?php

require __DIR__ . '/../src/config/database.php';
require __DIR__ . '/../src/auth/acceso.php';
require __DIR__ . '/../src/auth/flash.php';
require __DIR__ . '/../src/views/layout.php';
require __DIR__ . '/../src/dominio/trabajos.php';

requerir_rol('cliente');

$idCliente = (int) $_SESSION['usuario_id'];
$pdo = conectar();
$errores = [];
$mensaje = flash_get();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar();
    $idPresupuesto = filter_var($_POST['id_presupuesto'] ?? '', FILTER_VALIDATE_INT);
    $accion = $_POST['accion'] ?? '';

    try {
        if ($idPresupuesto === false || !in_array($accion, ['aprobar', 'rechazar'], true)) {
            throw new DomainException('Solicitud inválida.');
        }

        $stmt = $pdo->prepare(
            "SELECT p.id_presupuesto, p.id_trabajo, t.estado
             FROM presupuestos p
             JOIN trabajos t ON t.id_trabajo = p.id_trabajo
             JOIN turnos tu ON tu.id_turno = t.id_turno
             JOIN vehiculos v ON v.id_vehiculo = tu.id_vehiculo
             WHERE p.id_presupuesto = ? AND v.id_cliente = ? AND p.estado = 'enviado'"
        );
        $stmt->execute([$idPresupuesto, $idCliente]);
        $presupuesto = $stmt->fetch();

        if (!$presupuesto) {
            throw new DomainException('El presupuesto no existe o ya fue respondido.');
        }

        $nuevoEstado = $accion === 'aprobar' ? 'aprobado' : 'rechazado';
        if (!puede_pasar($presupuesto['estado'], $nuevoEstado)) {
            throw new DomainException('Ese cambio no está permitido para este trabajo.');
        }

        $pdo->beginTransaction();

        if ($accion === 'aprobar') {
            $stmt = $pdo->prepare(
                "SELECT i.id_repuesto, i.cantidad, r.nombre
                 FROM items_presupuesto i
                 JOIN repuestos r ON r.id_repuesto = i.id_repuesto
                 WHERE i.id_presupuesto = ?"
            );
            $stmt->execute([$idPresupuesto]);
            $repuestos = $stmt->fetchAll();

            $stmtStock = $pdo->prepare(
                "UPDATE repuestos SET stock = stock - ? WHERE id_repuesto = ? AND stock >= ?"
            );
            foreach ($repuestos as $r) {
                $stmtStock->execute([$r['cantidad'], $r['id_repuesto'], $r['cantidad']]);
                if ($stmtStock->rowCount() === 0) {
                    throw new DomainException("No hay stock suficiente de {$r['nombre']}. Consultá con el taller.");
                }
            }
            $mensaje = 'Presupuesto aprobado. El taller ya puede empezar la reparación.';
        } else {
            $mensaje = 'Presupuesto rechazado.';
        }

        $pdo->prepare("UPDATE presupuestos SET estado = ?, fecha_respuesta = NOW() WHERE id_presupuesto = ?")
            ->execute([$nuevoEstado, $idPresupuesto]);

        $pdo->prepare("UPDATE trabajos SET estado = ? WHERE id_trabajo = ?")
            ->execute([$nuevoEstado, $presupuesto['id_trabajo']]);

        $pdo->commit();
    } catch (DomainException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errores[] = $e->getMessage();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errores[] = 'Error interno. Intentá de nuevo más tarde.';
        error_log($e->getMessage());
    }

    if ($mensaje !== '' && empty($errores)) {
        flash_set($mensaje);
        header('Location: presupuestos');
        exit;
    }
}

$stmt = $pdo->prepare(
    "SELECT p.id_presupuesto, p.total, p.estado, p.fecha_envio, p.fecha_respuesta,
            v.patente, v.modelo, d.descripcion AS diagnostico,
            (SELECT pg.estado FROM pagos pg WHERE pg.id_presupuesto = p.id_presupuesto
             ORDER BY pg.id_pago DESC LIMIT 1) AS estado_pago,
            (SELECT pg.metodo FROM pagos pg WHERE pg.id_presupuesto = p.id_presupuesto AND pg.estado = 'aprobado'
             LIMIT 1) AS metodo_pago
     FROM presupuestos p
     JOIN trabajos t ON t.id_trabajo = p.id_trabajo
     JOIN turnos tu ON tu.id_turno = t.id_turno
     JOIN vehiculos v ON v.id_vehiculo = tu.id_vehiculo
     LEFT JOIN diagnosticos d ON d.id_trabajo = t.id_trabajo
     WHERE v.id_cliente = ?
     ORDER BY p.fecha_envio DESC"
);
$stmt->execute([$idCliente]);
$presupuestos = $stmt->fetchAll();

$stmtItems = $pdo->prepare(
    "SELECT descripcion, tipo, cantidad, precio_unitario FROM items_presupuesto WHERE id_presupuesto = ?"
);
foreach ($presupuestos as &$p) {
    $stmtItems->execute([$p['id_presupuesto']]);
    $p['items'] = $stmtItems->fetchAll();
}
unset($p);

$paraAprobar = count(array_filter($presupuestos, fn($p) => $p['estado'] === 'enviado'));
$paraPagar = count(array_filter($presupuestos, fn($p) => $p['estado'] === 'aprobado' && $p['estado_pago'] !== 'aprobado'));
$totalAbonado = array_sum(array_map(
    fn($p) => ($p['estado'] === 'aprobado' && $p['estado_pago'] === 'aprobado') ? (float) $p['total'] : 0,
    $presupuestos
));

inicio_html('Mis presupuestos');
?>
  <div class="encabezado-pagina">
    <div>
      <h1>Presupuestos y Pagos</h1>
      <p class="subtitulo">Aprobá los trabajos antes de comenzar y aboná con Mercado Pago o en el taller.</p>
    </div>
    <a href="panel" class="btn btn-outline">← Volver al inicio</a>
  </div>

  <?php mostrar_mensajes($mensaje, $errores); ?>

  <!-- KPI Strip -->
  <section class="kpi-strip">
    <div class="kpi-card">
      <div class="kpi-ico hub-icon orange"><?= icono_svg('credit-card') ?></div>
      <div>
        <div class="kpi-label">Para aprobar</div>
        <div class="kpi-valor"><?= $paraAprobar ?></div>
      </div>
    </div>
    <div class="kpi-card">
      <div class="kpi-ico hub-icon sky"><?= icono_svg('calendar') ?></div>
      <div>
        <div class="kpi-label">Listos para pagar</div>
        <div class="kpi-valor"><?= $paraPagar ?></div>
      </div>
    </div>
    <div class="kpi-card">
      <div class="kpi-ico hub-icon emerald"><?= icono_svg('shield') ?></div>
      <div>
        <div class="kpi-label">Total abonado</div>
        <div class="kpi-valor">$ <?= number_format($totalAbonado, 0, ',', '.') ?></div>
      </div>
    </div>
  </section>

  <div class="panel-grid">
    <div class="panel-principal">
      <?php if (empty($presupuestos)): ?>
        <div class="tarjeta">
          <p class="vacio" style="margin:0">Todavía no tenés presupuestos emitidos.</p>
        </div>
      <?php endif; ?>

      <?php foreach ($presupuestos as $p): ?>
        <?php $pendienteAccion = $p['estado'] === 'enviado' || ($p['estado'] === 'aprobado' && $p['estado_pago'] !== 'aprobado'); ?>
        <article class="tarjeta <?= $pendienteAccion ? 'destacada' : '' ?>">
          <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:14px; flex-wrap:wrap; margin-bottom:14px;">
            <div>
              <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <h3 style="margin:0; font-size:1.08rem;">Presupuesto #<?= (int) $p['id_presupuesto'] ?> · <?= htmlspecialchars($p['modelo']) ?></h3>
                <span class="patente-placa"><?= htmlspecialchars($p['patente']) ?></span>
              </div>
              <div class="detalle" style="margin-top:4px;">Emitido el <?= date('d/m/Y · H:i', strtotime($p['fecha_envio'])) ?> hs</div>
            </div>
            <?= badge_estado($p['estado']) ?>
          </div>

          <?php if (!empty($p['diagnostico'])): ?>
            <div class="meta-tile" style="margin-bottom:14px; border-left:3px solid var(--accent);">
              <span>Diagnóstico del taller</span>
              <strong style="font-weight:500;"><?= htmlspecialchars($p['diagnostico']) ?></strong>
            </div>
          <?php endif; ?>

          <div class="tabla-wrap" style="margin:14px 0;">
            <table class="tabla">
              <tr>
                <th>Descripción</th>
                <th>Categoría</th>
                <th class="num">Cant.</th>
                <th class="num">Unitario</th>
                <th class="num">Subtotal</th>
              </tr>
              <?php foreach ($p['items'] as $it): ?>
                <tr>
                  <td><?= htmlspecialchars($it['descripcion']) ?></td>
                  <td><span class="badge"><?= ($it['tipo'] ?? '') === 'repuesto' ? 'Repuesto' : 'Mano de obra' ?></span></td>
                  <td class="num"><?= (int) $it['cantidad'] ?></td>
                  <td class="num">$ <?= number_format($it['precio_unitario'], 2, ',', '.') ?></td>
                  <td class="num"><strong>$ <?= number_format($it['cantidad'] * $it['precio_unitario'], 2, ',', '.') ?></strong></td>
                </tr>
              <?php endforeach; ?>
            </table>
          </div>

          <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px; padding-top:6px;">
            <div>
              <?php if ($p['estado'] === 'aprobado' && $p['estado_pago'] === 'aprobado'): ?>
                <span class="badge badge-cerrado">Pagado</span>
                <span class="detalle" style="margin-left:8px;">
                  <?= $p['metodo_pago'] === 'mercadopago' ? 'Online con Mercado Pago' : 'En el taller (' . htmlspecialchars((string) $p['metodo_pago']) . ')' ?>
                </span>
              <?php elseif ($p['estado'] === 'aprobado'): ?>
                <span class="badge badge-activo">10% OFF pagando en efectivo en el taller</span>
              <?php endif; ?>
            </div>

            <div style="text-align:right;">
              <span class="subtitulo" style="font-size:0.72rem; text-transform:uppercase; font-weight:700;">Total ARS</span>
              <div class="mono" style="font-size:1.65rem; font-weight:800; color:#ffffff;">
                $ <?= number_format($p['total'], 2, ',', '.') ?>
              </div>
            </div>
          </div>

          <?php if ($p['estado'] === 'enviado'): ?>
            <div class="acciones" style="display:grid; grid-template-columns:1fr 1fr; margin-top:16px;">
              <form method="POST" action="presupuestos">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="id_presupuesto" value="<?= (int) $p['id_presupuesto'] ?>">
                <input type="hidden" name="accion" value="aprobar">
                <button type="submit" class="btn btn-grande">Aprobar y reservar stock</button>
              </form>
              <form method="POST" action="presupuestos">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="id_presupuesto" value="<?= (int) $p['id_presupuesto'] ?>">
                <input type="hidden" name="accion" value="rechazar">
                <button type="submit" class="btn btn-peligro btn-grande">Rechazar</button>
              </form>
            </div>
          <?php elseif ($p['estado'] === 'aprobado' && $p['estado_pago'] !== 'aprobado'): ?>
            <div class="acciones" style="justify-content:flex-end; margin-top:16px;">
              <form method="POST" action="pagar" style="min-width:260px;">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="id_presupuesto" value="<?= (int) $p['id_presupuesto'] ?>">
                <button type="submit" class="btn btn-grande">Pagar con Mercado Pago</button>
              </form>
            </div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>

    <aside class="panel-lateral" aria-label="Medios de pago">
      <div style="display:flex; align-items:center; gap:8px;">
        <?= icono_svg('credit-card') ?>
        <h2 style="margin:0; font-size:1.05rem;">Medios de Pago</h2>
      </div>

      <div class="transfer-box">
        <strong style="color:#fff; display:block; margin-bottom:4px;">Transferencia bancaria</strong>
        <span style="color:var(--text-secondary);">Podés transferir y avisarnos al retirar el vehículo:</span>
        <div class="alias-row">
          <span class="mono" style="color:#fb923c; font-weight:700;" id="alias-taller">taller.gomez.ar</span>
          <button type="button" class="quick-chip" id="btn-copiar-alias" style="padding:4px 9px;">Copiar</button>
        </div>
      </div>

      <div class="meta-tile">
        <span>Mercado Pago Online</span>
        <strong style="font-weight:500; font-size:0.8rem;">Tarjetas, débito y dinero en cuenta con acreditación instantánea.</strong>
      </div>

      <div class="meta-tile">
        <span>Efectivo en el taller</span>
        <strong style="font-weight:500; font-size:0.8rem; color:#6ee7b7;">Aboná al retirar y consultá por bonificación contado.</strong>
      </div>
    </aside>
  </div>

<script>
(() => {
  const btn = document.getElementById('btn-copiar-alias');
  const alias = document.getElementById('alias-taller');
  if (btn && alias && navigator.clipboard) {
    btn.addEventListener('click', () => {
      navigator.clipboard.writeText(alias.textContent.trim()).then(() => {
        btn.textContent = '¡Copiado!';
        btn.classList.add('activo');
        setTimeout(() => { btn.textContent = 'Copiar'; btn.classList.remove('activo'); }, 1800);
      });
    });
  }
})();
</script>
<?php fin_html(); ?>
