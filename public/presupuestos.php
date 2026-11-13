<?php

require __DIR__ . '/../src/config/database.php';
require __DIR__ . '/../src/auth/acceso.php';
require __DIR__ . '/../src/auth/flash.php';
require __DIR__ . '/../src/views/layout.php';
require __DIR__ . '/../src/dominio/trabajos.php';

// Solo clientes ven esta página
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

        // El presupuesto tiene que ser de un vehículo de este cliente y estar pendiente
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

        // El nuevo estado del trabajo lo dicta el mapa de transiciones
        $nuevoEstado = $accion === 'aprobar' ? 'aprobado' : 'rechazado';
        if (!puede_pasar($presupuesto['estado'], $nuevoEstado)) {
            throw new DomainException('Ese cambio no está permitido para este trabajo.');
        }

        $pdo->beginTransaction();

        if ($accion === 'aprobar') {
            // Descontar el stock de cada repuesto del presupuesto
            $stmt = $pdo->prepare(
                "SELECT i.id_repuesto, i.cantidad, r.nombre
                 FROM items_presupuesto i
                 JOIN repuestos r ON r.id_repuesto = i.id_repuesto
                 WHERE i.id_presupuesto = ?"
            );
            $stmt->execute([$idPresupuesto]);
            $repuestos = $stmt->fetchAll();

            // "stock >= ?" evita dejar el stock en negativo; si falla, se revierte todo
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

    // PRG: si todo salió bien, redirigir para que recargar no repita la acción
    if ($mensaje !== '' && empty($errores)) {
        flash_set($mensaje);
        header('Location: presupuestos');
        exit;
    }
}

// Presupuestos de los vehículos de este cliente
$stmt = $pdo->prepare(
    "SELECT p.id_presupuesto, p.total, p.estado, p.fecha_envio, p.fecha_respuesta,
            v.patente, v.modelo,
            (SELECT pg.estado FROM pagos pg WHERE pg.id_presupuesto = p.id_presupuesto
             ORDER BY pg.id_pago DESC LIMIT 1) AS estado_pago,
            (SELECT pg.metodo FROM pagos pg WHERE pg.id_presupuesto = p.id_presupuesto AND pg.estado = 'aprobado'
             LIMIT 1) AS metodo_pago
     FROM presupuestos p
     JOIN trabajos t ON t.id_trabajo = p.id_trabajo
     JOIN turnos tu ON tu.id_turno = t.id_turno
     JOIN vehiculos v ON v.id_vehiculo = tu.id_vehiculo
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

inicio_html('Mis presupuestos');
?>
  <div class="encabezado-pagina">
    <div>
      <h1>Mis presupuestos</h1>
      <p class="subtitulo">Revisá cada ítem antes de aprobar y elegí pagar online o en el taller.</p>
    </div>
  </div>

  <?php mostrar_mensajes($mensaje, $errores); ?>

  <div class="panel-grid">
    <div class="panel-principal">
      <?php if (empty($presupuestos)): ?>
        <div class="tarjeta">
          <p class="vacio" style="margin:0">Todavía no tenés presupuestos emitidos.</p>
        </div>
      <?php endif; ?>

      <?php foreach ($presupuestos as $p): ?>
        <?php $pendienteAccion = $p['estado'] === 'enviado' || ($p['estado'] === 'aprobado' && $p['estado_pago'] !== 'aprobado'); ?>
        <div class="tarjeta <?= $pendienteAccion ? 'destacada' : '' ?>">
          <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; margin-bottom:14px;">
            <div>
              <h3 style="margin:0 0 4px;">
                Presupuesto #<?= (int) $p['id_presupuesto'] ?> · <?= htmlspecialchars($p['modelo']) ?>
                <span class="patente-tag" style="margin-left:6px;"><?= htmlspecialchars($p['patente']) ?></span>
              </h3>
              <div class="detalle">Emitido: <?= date('d/m/Y H:i', strtotime($p['fecha_envio'])) ?></div>
            </div>
            <?= badge_estado($p['estado']) ?>
          </div>

          <div class="tabla-wrap" style="margin:14px 0;">
            <table class="tabla">
              <tr>
                <th>Descripción</th>
                <th>Tipo</th>
                <th class="num">Cant.</th>
                <th class="num">Precio</th>
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
                <span class="detalle">Podés pagar online ahora o al retirar el vehículo.</span>
              <?php endif; ?>
            </div>

            <div style="text-align:right;">
              <span class="subtitulo" style="font-size:0.75rem; text-transform:uppercase;">Total presupuesto</span>
              <div class="mono" style="font-size:1.55rem; font-weight:800; color:#ffffff;">
                $ <?= number_format($p['total'], 2, ',', '.') ?>
              </div>
            </div>
          </div>

          <?php if ($p['estado'] === 'enviado'): ?>
            <div class="acciones" style="display:grid; grid-template-columns:1fr 1fr;">
              <form method="POST" action="presupuestos">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="id_presupuesto" value="<?= (int) $p['id_presupuesto'] ?>">
                <input type="hidden" name="accion" value="aprobar">
                <button type="submit" class="btn btn-grande">Aprobar presupuesto</button>
              </form>
              <form method="POST" action="presupuestos">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="id_presupuesto" value="<?= (int) $p['id_presupuesto'] ?>">
                <input type="hidden" name="accion" value="rechazar">
                <button type="submit" class="btn btn-peligro btn-grande">Rechazar</button>
              </form>
            </div>
          <?php elseif ($p['estado'] === 'aprobado' && $p['estado_pago'] !== 'aprobado'): ?>
            <div class="acciones" style="justify-content:flex-end;">
              <form method="POST" action="pagar" style="min-width:240px;">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="id_presupuesto" value="<?= (int) $p['id_presupuesto'] ?>">
                <button type="submit" class="btn btn-grande">Pagar con Mercado Pago</button>
              </form>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <aside class="panel-lateral" aria-label="Resumen">
      <h2 style="margin:0 0 4px; font-size:1.02rem;">Resumen de cuenta</h2>
      <div class="item" style="padding:12px 14px;">
        <span class="detalle" style="margin:0;">Para aprobar</span>
        <strong class="mono" style="font-size:1.1rem; color:<?= $paraAprobar > 0 ? '#fdba74' : '#ffffff' ?>;"><?= $paraAprobar ?></strong>
      </div>
      <div class="item" style="padding:12px 14px;">
        <span class="detalle" style="margin:0;">Para pagar</span>
        <strong class="mono" style="font-size:1.1rem; color:<?= $paraPagar > 0 ? '#fdba74' : '#ffffff' ?>;"><?= $paraPagar ?></strong>
      </div>
      <div style="font-size:0.82rem; color:var(--text-secondary); line-height:1.5; padding-top:4px;">
        <strong style="color:#ffffff; display:block; margin-bottom:4px;">Medios de pago aceptados</strong>
        • Mercado Pago (tarjeta, débito o dinero en cuenta)<br>
        • Efectivo o transferencia al retirar en el taller
      </div>
    </aside>
  </div>
<?php
link_panel();
fin_html();
