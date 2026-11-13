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

$telLimpio = preg_replace('/\D+/', '', (string) ($trabajo['telefono'] ?? ''));

inicio_html('Trabajo #' . $idTrabajo);
?>
  <div class="encabezado-pagina">
    <div>
      <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:4px;">
        <h1 style="margin:0;">Trabajo #<?= (int) $idTrabajo ?> · <?= htmlspecialchars($trabajo['modelo']) ?></h1>
        <span class="patente-placa"><?= htmlspecialchars($trabajo['patente']) ?></span>
      </div>
      <p class="subtitulo">Turno <?= date('d/m/Y · H:i', strtotime($trabajo['fecha_hora'])) ?> hs · <?= htmlspecialchars($trabajo['motivo']) ?></p>
    </div>
    <div style="display:flex; align-items:center; gap:10px;">
      <?= badge_estado($trabajo['estado']) ?>
      <a href="trabajos" class="btn btn-outline" style="min-height:38px;">← Volver</a>
    </div>
  </div>

  <?php mostrar_mensajes($mensaje, $errores); ?>

  <?php if ($indiceEstado >= 0): ?>
    <ol class="etapas" style="margin-bottom:24px;">
      <?php foreach ($etapas as $i => $etapa): ?>
        <li class="<?= $i < $indiceEstado ? 'hecha' : ($i === $indiceEstado ? 'activa' : '') ?>"><?= $etapa ?></li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>

  <div class="panel-grid">
    <div class="panel-principal">
      <section class="tarjeta <?= $diagnostico ? '' : 'destacada' ?>">
        <div style="display:flex; align-items:center; gap:10px; margin-bottom:14px;">
          <div class="hub-icon orange" style="width:36px; height:36px;"><?= icono_svg('wrench') ?></div>
          <h2 style="margin:0;">Diagnóstico técnico</h2>
        </div>

        <?php if ($diagnostico): ?>
          <div class="meta-tile" style="margin-bottom:14px; border-left:3px solid var(--accent);">
            <span>Diagnóstico registrado</span>
            <strong style="font-weight:500;"><?= nl2br(htmlspecialchars($diagnostico)) ?></strong>
          </div>
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
      </section>

      <?php if ($diagnostico && admite_presupuesto($trabajo['estado'])): ?>
        <section class="tarjeta destacada">
          <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:12px;">
            <div style="display:flex; align-items:center; gap:10px;">
              <div class="hub-icon sky" style="width:36px; height:36px;"><?= icono_svg('credit-card') ?></div>
              <div>
                <h2 style="margin:0;">Armar presupuesto (hasta 5 ítems)</h2>
                <p class="subtitulo">Al elegir repuesto, el precio unitario se toma directo del stock.</p>
              </div>
            </div>
            <div style="text-align:right;">
              <span class="subtitulo" style="font-size:0.7rem; text-transform:uppercase;">Total estimado</span>
              <div class="mono" id="total-presupuesto-vivo" style="font-size:1.35rem; font-weight:800; color:#fb923c;">$ 0,00</div>
            </div>
          </div>

          <form method="POST" action="trabajo?id=<?= (int) $idTrabajo ?>" id="form-presupuesto">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="accion" value="presupuesto">
            <input type="hidden" name="id_trabajo" value="<?= (int) $idTrabajo ?>">

            <?php for ($i = 0; $i < 5; $i++): ?>
              <div class="fila-item-presupuesto" style="display:grid; grid-template-columns: 1.3fr 1.3fr 78px 130px; gap:8px; margin-bottom:10px;">
                <select name="id_repuesto[]" class="sel-repuesto" style="padding:10px; border-radius:9px; border:1px solid var(--border); background:var(--bg-input); color:#fff;">
                  <option value="">Mano de obra</option>
                  <?php foreach ($repuestos as $r): ?>
                    <option value="<?= (int) $r['id_repuesto'] ?>" data-precio="<?= htmlspecialchars((string) $r['precio']) ?>">
                      <?= htmlspecialchars($r['nombre']) ?> (Stock: <?= (int) $r['stock'] ?> · $<?= number_format($r['precio'], 0, ',', '.') ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
                <input type="text" name="descripcion[]" maxlength="150" placeholder="Descripción del ítem" style="padding:10px; border-radius:9px; border:1px solid var(--border); background:var(--bg-input); color:#fff;">
                <input type="number" name="cantidad[]" min="1" value="1" class="inp-cantidad" style="padding:10px; border-radius:9px; border:1px solid var(--border); background:var(--bg-input); color:#fff;">
                <input type="number" name="precio[]" min="0" step="0.01" placeholder="Precio ($)" class="inp-precio" style="padding:10px; border-radius:9px; border:1px solid var(--border); background:var(--bg-input); color:#fff;">
              </div>
            <?php endfor; ?>

            <button type="submit" class="btn btn-grande" style="margin-top:8px;">Enviar presupuesto al cliente</button>
          </form>
        </section>
      <?php endif; ?>

      <?php if (!empty($presupuestos)): ?>
        <section class="tarjeta">
          <h3 style="margin-bottom:12px;">Presupuestos enviados</h3>
          <div class="lista">
            <?php foreach ($presupuestos as $p): ?>
              <div class="item">
                <div>
                  <div class="titulo mono">Presupuesto #<?= (int) $p['id_presupuesto'] ?> · $ <?= number_format($p['total'], 2, ',', '.') ?></div>
                  <div class="detalle">Enviado: <?= date('d/m/Y · H:i', strtotime($p['fecha_envio'])) ?> hs</div>
                </div>
                <?= badge_estado($p['estado']) ?>
              </div>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>
    </div>

    <aside class="panel-lateral" aria-label="Ficha y acciones del trabajo">
      <div>
        <h3 style="margin-bottom:10px;">Vehículo y Cliente</h3>
        <div class="meta-tile" style="margin-bottom:8px;">
          <span>Propietario</span>
          <strong><?= htmlspecialchars($trabajo['cliente']) ?></strong>
        </div>
        <div class="meta-tile" style="margin-bottom:8px;">
          <span>Teléfono</span>
          <strong><?= htmlspecialchars($trabajo['telefono'] ?? 'No cargado') ?></strong>
        </div>
        <div class="meta-tile" style="margin-bottom:10px;">
          <span>Ficha técnica</span>
          <strong>Año <?= (int) $trabajo['anio'] ?> · <span class="mono"><?= number_format((int) $trabajo['kilometros'], 0, ',', '.') ?> km</span></strong>
        </div>
        <?php if ($telLimpio !== ''): ?>
          <a href="https://wa.me/<?= htmlspecialchars($telLimpio) ?>" target="_blank" rel="noreferrer" class="btn btn-whatsapp btn-grande" style="min-height:38px;">
            <?= icono_svg('whatsapp') ?><span>Escribir por WhatsApp</span>
          </a>
        <?php endif; ?>
      </div>

      <?php if ($presupuestoAprobado): ?>
        <div style="border-top:1px solid var(--border); padding-top:14px;">
          <h3 style="margin-bottom:8px;">Cobro del servicio</h3>
          <?php if ($presupuestoAprobado['metodo_cobro']): ?>
            <span class="badge badge-cerrado">Pagado</span>
            <p class="subtitulo" style="margin-top:6px;">
              <?= $presupuestoAprobado['metodo_cobro'] === 'mercadopago' ? 'Online con Mercado Pago' : 'En el taller (' . htmlspecialchars($presupuestoAprobado['metodo_cobro']) . ')' ?>
            </p>
          <?php else: ?>
            <p class="subtitulo" style="margin-bottom:10px;">
              Total a cobrar: <strong class="mono" style="color:#fff;">$ <?= number_format($presupuestoAprobado['total'], 2, ',', '.') ?></strong>
            </p>
            <form method="POST" action="trabajo?id=<?= (int) $idTrabajo ?>" class="formulario" style="grid-template-columns:1fr;">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
              <input type="hidden" name="accion" value="pago_presencial">
              <input type="hidden" name="id_trabajo" value="<?= (int) $idTrabajo ?>">
              <input type="hidden" name="id_presupuesto" value="<?= (int) $presupuestoAprobado['id_presupuesto'] ?>">
              <label class="campo">Forma de pago en taller
                <select name="metodo" required>
                  <option value="efectivo">Efectivo</option>
                  <option value="transferencia">Transferencia</option>
                </select>
              </label>
              <button type="submit" class="btn btn-grande">Registrar pago en taller</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if (puede_pasar($trabajo['estado'], 'en_reparacion')): ?>
        <div style="border-top:1px solid var(--border); padding-top:14px;">
          <h3 style="margin-bottom:6px;">Iniciar reparación</h3>
          <p class="subtitulo" style="margin-bottom:10px;">El cliente aprobó el presupuesto. Pasalo a reparación para actualizar su tracking.</p>
          <form method="POST" action="trabajo?id=<?= (int) $idTrabajo ?>">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="accion" value="en_reparacion">
            <input type="hidden" name="id_trabajo" value="<?= (int) $idTrabajo ?>">
            <button type="submit" class="btn btn-outline btn-grande">Pasar a En reparación</button>
          </form>
        </div>
      <?php endif; ?>

      <?php if (puede_pasar($trabajo['estado'], 'listo')): ?>
        <div style="border-top:1px solid var(--border); padding-top:14px;">
          <h3 style="margin-bottom:6px;">Finalizar y entregar</h3>
          <p class="subtitulo" style="margin-bottom:10px;">Cuando el vehículo esté probado y listo, notificá al cliente por mail.</p>
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
    (() => {
      const totalEl = document.getElementById('total-presupuesto-vivo');
      const filas = document.querySelectorAll('.fila-item-presupuesto');

      function recalcularTotal() {
        if (!totalEl) return;
        let suma = 0;
        filas.forEach((fila) => {
          const sel = fila.querySelector('.sel-repuesto');
          const cant = Number(fila.querySelector('.inp-cantidad')?.value || 0);
          const precioInp = Number(fila.querySelector('.inp-precio')?.value || 0);
          const desc = (fila.querySelector('input[type="text"]')?.value || '').trim();
          if (sel?.value || desc !== '') {
            suma += Math.max(1, cant) * Math.max(0, precioInp);
          }
        });
        totalEl.textContent = '$ ' + suma.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      }

      filas.forEach((fila) => {
        const sel = fila.querySelector('.sel-repuesto');
        const inpPrecio = fila.querySelector('.inp-precio');
        const inpCant = fila.querySelector('.inp-cantidad');
        const inpDesc = fila.querySelector('input[type="text"]');
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
          recalcularTotal();
        });

        [inpPrecio, inpCant, inpDesc].forEach((el) => el && el.addEventListener('input', recalcularTotal));
      });
    })();
  </script>
<?php fin_html(); ?>
