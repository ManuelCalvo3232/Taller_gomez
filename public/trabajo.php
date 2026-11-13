<?php

require __DIR__ . '/../src/config/database.php';
require __DIR__ . '/../src/auth/acceso.php';
require __DIR__ . '/../src/auth/flash.php';
require __DIR__ . '/../src/views/layout.php';
require __DIR__ . '/../src/dominio/trabajos.php';
require __DIR__ . '/../src/dominio/pagos.php';
require __DIR__ . '/../src/integraciones/api_node.php';

$config = require __DIR__ . '/../config/config.php';

requerir_rol('mecanico');
$idMecanico = (int) $_SESSION['usuario_id'];

$idTrabajo = filter_var($_GET['id'] ?? $_POST['id_trabajo'] ?? '', FILTER_VALIDATE_INT);
if ($idTrabajo === false) {
    http_response_code(400);
    exit('Trabajo inválido.');
}

$pdo = conectar();

// Traer el trabajo solo si está asignado a este mecánico (control de acceso por dato)
function cargar_trabajo(PDO $pdo, int $idTrabajo, int $idMecanico): array|false
{
    $stmt = $pdo->prepare(
        "SELECT t.id_trabajo, t.estado, tu.fecha_hora, tu.motivo,
                v.patente, v.modelo, v.anio, v.kilometros,
                u.nombre AS cliente, u.telefono
         FROM trabajos t
         JOIN turnos tu ON tu.id_turno = t.id_turno
         JOIN vehiculos v ON v.id_vehiculo = tu.id_vehiculo
         JOIN usuarios u ON u.id_usuario = v.id_cliente
         WHERE t.id_trabajo = ? AND t.id_mecanico = ?"
    );
    $stmt->execute([$idTrabajo, $idMecanico]);
    return $stmt->fetch();
}

$trabajo = cargar_trabajo($pdo, $idTrabajo, $idMecanico);
if (!$trabajo) {
    http_response_code(404);
    exit('Trabajo no encontrado o no asignado a vos.');
}

$errores = [];
$mensaje = flash_get();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar();
    $accion = $_POST['accion'] ?? '';

    try {
        if ($accion === 'diagnostico') {
            $descripcion = trim($_POST['descripcion'] ?? '');
            if ($descripcion === '' || strlen($descripcion) > 2000) {
                throw new DomainException('El diagnóstico es obligatorio (máximo 2000 caracteres).');
            }
            $stmt = $pdo->prepare(
                "INSERT INTO diagnosticos (id_trabajo, descripcion) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion)"
            );
            $stmt->execute([$idTrabajo, $descripcion]);
            $mensaje = 'Diagnóstico guardado.';
        } elseif ($accion === 'presupuesto') {
            if (!admite_presupuesto($trabajo['estado'])) {
                throw new DomainException('Este trabajo ya no admite presupuestos nuevos.');
            }
            $stmt = $pdo->prepare("SELECT 1 FROM diagnosticos WHERE id_trabajo = ?");
            $stmt->execute([$idTrabajo]);
            if (!$stmt->fetch()) {
                throw new DomainException('Primero cargá el diagnóstico.');
            }

            $idsRepuesto = $_POST['id_repuesto'] ?? [];
            $descripciones = $_POST['descripcion'] ?? [];
            $cantidades = $_POST['cantidad'] ?? [];
            $precios = $_POST['precio'] ?? [];

            $items = [];
            $total = 0.0;

            foreach ($descripciones as $i => $descripcionItem) {
                $descripcionItem = trim($descripcionItem);
                $idRepuesto = filter_var($idsRepuesto[$i] ?? '', FILTER_VALIDATE_INT);

                if ($descripcionItem === '' && $idRepuesto === false) {
                    continue;
                }

                $cantidad = filter_var($cantidades[$i] ?? '', FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1],
                ]);
                if ($cantidad === false) {
                    throw new DomainException('La cantidad de cada ítem tiene que ser al menos 1.');
                }

                if ($idRepuesto !== false) {
                    // Repuesto: el precio sale de la base, nunca del formulario
                    $stmt = $pdo->prepare("SELECT nombre, precio FROM repuestos WHERE id_repuesto = ?");
                    $stmt->execute([$idRepuesto]);
                    $repuesto = $stmt->fetch();
                    if (!$repuesto) {
                        throw new DomainException('Un repuesto seleccionado no existe.');
                    }
                    $tipo = 'repuesto';
                    $descripcionFinal = $descripcionItem !== '' ? $descripcionItem : $repuesto['nombre'];
                    $precio = (float) $repuesto['precio'];
                } else {
                    $precio = filter_var($precios[$i] ?? '', FILTER_VALIDATE_FLOAT);
                    if ($precio === false || $precio < 0) {
                        throw new DomainException('El precio de mano de obra no es válido.');
                    }
                    $tipo = 'mano_obra';
                    $descripcionFinal = $descripcionItem;
                    $idRepuesto = null;
                }

                if ($descripcionFinal === '') {
                    throw new DomainException('Cada ítem necesita una descripción.');
                }
                if (strlen($descripcionFinal) > 150) {
                    throw new DomainException('Una descripción supera los 150 caracteres.');
                }

                $items[] = [
                    'id_repuesto' => $idRepuesto,
                    'tipo' => $tipo,
                    'descripcion' => $descripcionFinal,
                    'cantidad' => $cantidad,
                    'precio' => $precio,
                ];
                $total += $cantidad * $precio;
            }

            if (empty($items)) {
                throw new DomainException('Agregá al menos un ítem al presupuesto.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "INSERT INTO presupuestos (id_trabajo, total, estado) VALUES (?, ?, 'enviado')"
            );
            $stmt->execute([$idTrabajo, round($total, 2)]);
            $idPresupuesto = (int) $pdo->lastInsertId();

            $stmtItem = $pdo->prepare(
                "INSERT INTO items_presupuesto
                 (id_presupuesto, id_repuesto, tipo, descripcion, cantidad, precio_unitario)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            foreach ($items as $it) {
                $stmtItem->execute([
                    $idPresupuesto,
                    $it['id_repuesto'],
                    $it['tipo'],
                    $it['descripcion'],
                    $it['cantidad'],
                    $it['precio'],
                ]);
            }

            if (puede_pasar($trabajo['estado'], 'presupuestado')) {
                $pdo->prepare("UPDATE trabajos SET estado = 'presupuestado' WHERE id_trabajo = ?")
                    ->execute([$idTrabajo]);
            }

            $pdo->commit();

            $enviado = notificar_presupuesto_api($idPresupuesto, $_SESSION['api_token'] ?? '', $config['api_url']);
            $mensaje = 'Presupuesto cargado por $ ' . number_format($total, 2, ',', '.') . '. '
                     . ($enviado ? 'Se envió el aviso al cliente.' : 'No se pudo enviar el aviso por mail.');
        } elseif ($accion === 'en_reparacion') {
            if (!puede_pasar($trabajo['estado'], 'en_reparacion')) {
                throw new DomainException('Solo se puede pasar a En reparación un trabajo aprobado.');
            }
            $pdo->prepare("UPDATE trabajos SET estado = 'en_reparacion' WHERE id_trabajo = ?")
                ->execute([$idTrabajo]);
            $mensaje = 'Estado actualizado a En reparación.';
        } elseif ($accion === 'pago_presencial') {
            $idPresupuestoCobro = filter_var($_POST['id_presupuesto'] ?? '', FILTER_VALIDATE_INT);
            if ($idPresupuestoCobro === false) {
                throw new DomainException('Presupuesto inválido.');
            }
            $pdo->beginTransaction();
            registrar_pago_presencial($pdo, $idPresupuestoCobro, $_POST['metodo'] ?? '', $idMecanico);
            $pdo->commit();
            $mensaje = 'Pago registrado. Queda reflejado en el dashboard del administrador.';
        } elseif ($accion === 'listo') {
            if (!puede_pasar($trabajo['estado'], 'listo')) {
                throw new DomainException('Solo se puede marcar listo un trabajo aprobado por el cliente.');
            }
            $pdo->prepare("UPDATE trabajos SET estado = 'listo' WHERE id_trabajo = ?")
                ->execute([$idTrabajo]);

            $avisado = notificar_listo_api($idTrabajo, $_SESSION['api_token'] ?? '', $config['api_url']);
            $mensaje = $avisado
                ? 'Vehículo marcado como listo. Se envió el aviso al cliente.'
                : 'Vehículo marcado como listo. No se pudo enviar el aviso por mail.';
        } else {
            throw new DomainException('Acción no válida.');
        }
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
        header('Location: trabajo?id=' . $idTrabajo);
        exit;
    }

    $trabajo = cargar_trabajo($pdo, $idTrabajo, $idMecanico);
}

$stmt = $pdo->prepare("SELECT descripcion FROM diagnosticos WHERE id_trabajo = ?");
$stmt->execute([$idTrabajo]);
$diagnostico = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT id_presupuesto, total, estado, fecha_envio FROM presupuestos WHERE id_trabajo = ? ORDER BY fecha_envio DESC");
$stmt->execute([$idTrabajo]);
$presupuestos = $stmt->fetchAll();

// Presupuesto aprobado del trabajo y si ya fue cobrado (online o en el taller)
$stmt = $pdo->prepare(
    "SELECT p.id_presupuesto, p.total,
            (SELECT pg.metodo FROM pagos pg WHERE pg.id_presupuesto = p.id_presupuesto AND pg.estado = 'aprobado'
             LIMIT 1) AS metodo_cobro
     FROM presupuestos p
     WHERE p.id_trabajo = ? AND p.estado = 'aprobado'
     ORDER BY p.fecha_respuesta DESC
     LIMIT 1"
);
$stmt->execute([$idTrabajo]);
$presupuestoAprobado = $stmt->fetch();

$repuestos = $pdo->query("SELECT id_repuesto, nombre, precio, stock FROM repuestos ORDER BY nombre")->fetchAll();

// Etapas completas (alineadas con panel del cliente y ESTADOS_TRABAJO)
$etapas = ['Recibido', 'Diagnóstico', 'Presupuesto', 'Aprobado', 'En reparación', 'Listo'];
$indiceEstado = match ($trabajo['estado']) {
    'recibido' => 0,
    'en_diagnostico' => 1,
    'presupuestado' => 2,
    'aprobado' => 3,
    'en_reparacion' => 4,
    'listo' => 5,
    default => -1,
};

inicio_html('Trabajo #' . $idTrabajo);
?>
  <div class="encabezado-pagina">
    <div>
      <h1>
        Trabajo #<?= (int) $idTrabajo ?> · <?= htmlspecialchars($trabajo['modelo']) ?>
        <span class="patente-tag" style="margin-left:8px; vertical-align:middle;"><?= htmlspecialchars($trabajo['patente']) ?></span>
      </h1>
      <p class="subtitulo">Turno <?= date('d/m/Y H:i', strtotime($trabajo['fecha_hora'])) ?> hs · <?= htmlspecialchars($trabajo['motivo']) ?></p>
    </div>
    <?= badge_estado($trabajo['estado']) ?>
  </div>

  <?php mostrar_mensajes($mensaje, $errores); ?>

  <?php if ($indiceEstado >= 0): ?>
    <ol class="etapas">
      <?php foreach ($etapas as $i => $etapa): ?>
        <li class="<?= $i < $indiceEstado ? 'hecha' : ($i === $indiceEstado ? 'activa' : '') ?>"><?= $etapa ?></li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>

  <div class="panel-grid">
    <div class="panel-principal">
      <div class="tarjeta <?= $diagnostico ? '' : 'destacada' ?>">
        <h3>Diagnóstico técnico</h3>
        <?php if ($diagnostico): ?>
          <p style="color:var(--text-primary); background:var(--bg-input); padding:12px 14px; border-radius:8px; border:1px solid var(--border);"><?= nl2br(htmlspecialchars($diagnostico)) ?></p>
        <?php endif; ?>
        <form method="POST" action="trabajo?id=<?= (int) $idTrabajo ?>" class="formulario">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
          <input type="hidden" name="accion" value="diagnostico">
          <input type="hidden" name="id_trabajo" value="<?= (int) $idTrabajo ?>">
          <label class="campo completo">Descripción del problema / hallazgos
            <textarea name="descripcion" rows="3" maxlength="2000" required placeholder="Ej: Desgaste crítico en pastillas delanteras, requiere rectificación de discos..."><?= htmlspecialchars($diagnostico ?: '') ?></textarea>
          </label>
          <div class="completo"><button type="submit" class="btn">Guardar diagnóstico</button></div>
        </form>
      </div>

      <?php if ($diagnostico && admite_presupuesto($trabajo['estado'])): ?>
        <div class="tarjeta destacada">
          <h3>Armar presupuesto</h3>
          <p class="subtitulo" style="margin-bottom:14px;">Completá hasta 5 ítems. Al elegir un repuesto del stock, el precio se toma automáticamente del inventario.</p>
          <form method="POST" action="trabajo?id=<?= (int) $idTrabajo ?>" id="form-presupuesto">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="accion" value="presupuesto">
            <input type="hidden" name="id_trabajo" value="<?= (int) $idTrabajo ?>">
            <?php for ($i = 0; $i < 5; $i++): ?>
              <div class="fila-item-presupuesto" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(145px, 1fr)); gap:8px; margin-bottom:10px;">
                <select name="id_repuesto[]" class="sel-repuesto" style="padding:10px; border-radius:8px; border:1px solid var(--border); background:var(--bg-input); color:#fff;">
                  <option value="">Mano de obra</option>
                  <?php foreach ($repuestos as $r): ?>
                    <option value="<?= (int) $r['id_repuesto'] ?>" data-precio="<?= htmlspecialchars((string) $r['precio']) ?>">
                      <?= htmlspecialchars($r['nombre']) ?> (Stock: <?= (int) $r['stock'] ?> · $<?= number_format($r['precio'], 0, ',', '.') ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
                <input type="text" name="descripcion[]" maxlength="150" placeholder="Descripción" style="padding:10px; border-radius:8px; border:1px solid var(--border); background:var(--bg-input); color:#fff;">
                <input type="number" name="cantidad[]" min="1" value="1" style="padding:10px; border-radius:8px; border:1px solid var(--border); background:var(--bg-input); color:#fff;">
                <input type="number" name="precio[]" min="0" step="0.01" placeholder="Precio (mano de obra)" class="inp-precio" style="padding:10px; border-radius:8px; border:1px solid var(--border); background:var(--bg-input); color:#fff;">
              </div>
            <?php endfor; ?>
            <button type="submit" class="btn btn-grande" style="margin-top:8px;">Enviar presupuesto al cliente</button>
          </form>
        </div>
      <?php endif; ?>

      <?php if (!empty($presupuestos)): ?>
        <div class="tarjeta">
          <h3>Historial de presupuestos</h3>
          <div class="lista">
            <?php foreach ($presupuestos as $p): ?>
              <div class="item">
                <div>
                  <div class="titulo mono">Presupuesto #<?= (int) $p['id_presupuesto'] ?> · $ <?= number_format($p['total'], 2, ',', '.') ?></div>
                  <div class="detalle">Enviado: <?= date('d/m/Y H:i', strtotime($p['fecha_envio'])) ?></div>
                </div>
                <?= badge_estado($p['estado']) ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <aside class="panel-lateral" aria-label="Estado del trabajo">
      <div class="tarjeta" style="margin-bottom:0; padding:16px;">
        <h3>Vehículo y cliente</h3>
        <div class="detalle" style="line-height:1.7;">
          Dueño: <strong style="color:#fff;"><?= htmlspecialchars($trabajo['cliente']) ?></strong><br>
          Tel: <strong style="color:#fff;"><?= htmlspecialchars($trabajo['telefono'] ?? '-') ?></strong><br>
          Año <?= (int) $trabajo['anio'] ?> · <span class="mono"><?= number_format((int) $trabajo['kilometros'], 0, ',', '.') ?> km</span><br>
          Motivo: <?= htmlspecialchars($trabajo['motivo']) ?>
        </div>
      </div>

      <?php if ($presupuestoAprobado): ?>
        <div class="tarjeta <?= $presupuestoAprobado['metodo_cobro'] ? '' : 'destacada' ?>" style="margin-bottom:0; padding:16px;">
          <h3>Cobro</h3>
          <?php if ($presupuestoAprobado['metodo_cobro']): ?>
            <p style="margin:0;">
              <span class="badge badge-cerrado">Pagado</span>
              <span class="detalle" style="display:block; margin-top:6px;">
                <?= $presupuestoAprobado['metodo_cobro'] === 'mercadopago' ? 'Online con Mercado Pago' : 'En el taller (' . htmlspecialchars($presupuestoAprobado['metodo_cobro']) . ')' ?>
              </span>
            </p>
          <?php else: ?>
            <p class="detalle">
              Total a cobrar: <strong class="mono" style="color:#fff;">$ <?= number_format($presupuestoAprobado['total'], 2, ',', '.') ?></strong>.
              Si abona en el taller, registralo acá.
            </p>
            <form method="POST" action="trabajo?id=<?= (int) $idTrabajo ?>" class="formulario">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
              <input type="hidden" name="accion" value="pago_presencial">
              <input type="hidden" name="id_trabajo" value="<?= (int) $idTrabajo ?>">
              <input type="hidden" name="id_presupuesto" value="<?= (int) $presupuestoAprobado['id_presupuesto'] ?>">
              <label class="campo completo">Forma de pago
                <select name="metodo" required>
                  <option value="efectivo">Efectivo</option>
                  <option value="transferencia">Transferencia</option>
                </select>
              </label>
              <div class="completo"><button type="submit" class="btn btn-grande">Registrar pago en el taller</button></div>
            </form>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if (puede_pasar($trabajo['estado'], 'en_reparacion')): ?>
        <div class="tarjeta destacada" style="margin-bottom:0; padding:16px;">
          <h3>Iniciar reparación</h3>
          <p class="detalle">El cliente aprobó el presupuesto. Pasalo a reparación para que vea el avance en vivo.</p>
          <form method="POST" action="trabajo?id=<?= (int) $idTrabajo ?>">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="accion" value="en_reparacion">
            <input type="hidden" name="id_trabajo" value="<?= (int) $idTrabajo ?>">
            <button type="submit" class="btn btn-outline btn-grande">Pasar a En reparación</button>
          </form>
        </div>
      <?php endif; ?>

      <?php if (puede_pasar($trabajo['estado'], 'listo')): ?>
        <div class="tarjeta destacada" style="margin-bottom:0; padding:16px;">
          <h3>Entrega</h3>
          <p class="detalle">Cuando el vehículo esté terminado y probado, marcalo como listo para avisar al cliente.</p>
          <form method="POST" action="trabajo?id=<?= (int) $idTrabajo ?>">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="accion" value="listo">
            <input type="hidden" name="id_trabajo" value="<?= (int) $idTrabajo ?>">
            <button type="submit" class="btn btn-grande">Marcar como Listo</button>
          </form>
        </div>
      <?php endif; ?>
    </aside>
  </div>

  <script>
    document.querySelectorAll('.fila-item-presupuesto').forEach((fila) => {
      const sel = fila.querySelector('.sel-repuesto');
      const inpPrecio = fila.querySelector('.inp-precio');
      if (!sel || !inpPrecio) return;
      sel.addEventListener('change', () => {
        const opt = sel.selectedOptions[0];
        const precio = opt ? opt.dataset.precio : '';
        if (precio) {
          inpPrecio.value = precio;
          inpPrecio.readOnly = true;
          inpPrecio.style.opacity = '0.7';
        } else {
          inpPrecio.value = '';
          inpPrecio.readOnly = false;
          inpPrecio.style.opacity = '1';
        }
      });
    });
  </script>

  <p style="margin-top:24px"><a href="trabajos">← Volver a trabajos</a></p>
<?php
fin_html();
