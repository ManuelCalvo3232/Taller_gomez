<?php

require __DIR__ . '/../src/auth/acceso.php';
require __DIR__ . '/../src/auth/flash.php';
require __DIR__ . '/../src/config/database.php';
require __DIR__ . '/../src/dominio/stock.php';
require __DIR__ . '/../src/integraciones/brevo.php';
require __DIR__ . '/../src/views/layout.php';

requerir_rol('admin', 'mecanico');

$esAdmin = $_SESSION['rol'] === 'admin';
$pdo = conectar();
$idUsuario = (int) $_SESSION['usuario_id'];
$errores = [];
$mensaje = flash_get();

function html_alerta_stock(array $bajos, string $quien): string
{
    $celda = 'padding:11px 12px; border-bottom:1px solid #262b35; color:#f9fafb;';
    $filas = '';
    foreach ($bajos as $r) {
        $filas .= '<tr>'
            . '<td style="' . $celda . 'font-weight:700;">' . htmlspecialchars($r['nombre']) . '</td>'
            . '<td style="' . $celda . 'color:#9ca3af;">' . htmlspecialchars($r['marca_vehiculo'] ?? '—') . '</td>'
            . '<td style="' . $celda . 'text-align:right; font-family:monospace; color:#fca5a5; font-weight:700;">' . (int) $r['stock'] . '</td>'
            . '<td style="' . $celda . 'text-align:right; font-family:monospace; color:#9ca3af;">' . (int) $r['stock_minimo'] . '</td>'
            . '</tr>';
    }

    $appUrl = rtrim(getenv('APP_URL') ?: 'http://localhost:8000', '/');
    $contenido = '
      <p style="margin:0 0 18px; color:#d1d5db;">
        <strong style="color:#ffffff;">' . htmlspecialchars($quien) . '</strong> informó que los siguientes repuestos están en el mínimo o por debajo:
      </p>
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#0d0f13; border:1px solid #262b35; border-radius:12px; overflow:hidden; margin:0 0 20px; font-size:13px;">
        <tr style="background-color:#111317;">
          <th style="padding:10px 12px; text-align:left; color:#9ca3af; font-size:11px; text-transform:uppercase; letter-spacing:0.05em; border-bottom:1px solid #262b35;">Repuesto</th>
          <th style="padding:10px 12px; text-align:left; color:#9ca3af; font-size:11px; text-transform:uppercase; letter-spacing:0.05em; border-bottom:1px solid #262b35;">Vehículo</th>
          <th style="padding:10px 12px; text-align:right; color:#9ca3af; font-size:11px; text-transform:uppercase; letter-spacing:0.05em; border-bottom:1px solid #262b35;">Stock</th>
          <th style="padding:10px 12px; text-align:right; color:#9ca3af; font-size:11px; text-transform:uppercase; letter-spacing:0.05em; border-bottom:1px solid #262b35;">Mínimo</th>
        </tr>' . $filas . '
      </table>
      <a href="' . htmlspecialchars($appUrl . '/stock', ENT_QUOTES, 'UTF-8') . '"
         style="display:block; background-color:#ea580c; color:#ffffff; text-decoration:none; font-weight:700; font-size:14px; padding:13px 18px; border-radius:10px; text-align:center;">
        Gestionar inventario y reposición
      </a>';

    return plantilla_mail_taller('Stock crítico', 'Alerta de stock bajo en el taller', $contenido);
}

function datos_repuesto(array $p, array &$errores): array
{
    $nombre = trim($p['nombre'] ?? '');
    $marca = trim($p['marca_vehiculo'] ?? '');
    $categoria = trim($p['categoria'] ?? '');
    $precio = filter_var($p['precio'] ?? '', FILTER_VALIDATE_FLOAT);
    $minimo = filter_var($p['stock_minimo'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

    if ($nombre === '' || mb_strlen($nombre) > 120) {
        $errores[] = 'El nombre es obligatorio (hasta 120 caracteres).';
    }
    if (mb_strlen($marca) > 50 || mb_strlen($categoria) > 50) {
        $errores[] = 'La marca y la categoría pueden tener hasta 50 caracteres.';
    }
    if ($precio === false || $precio < 0) {
        $errores[] = 'El precio de venta tiene que ser un número mayor o igual a 0.';
    }
    if ($minimo === false) {
        $errores[] = 'El stock mínimo tiene que ser un número mayor o igual a 0.';
    }

    return [
        'nombre' => $nombre,
        'marca_vehiculo' => $marca !== '' ? $marca : null,
        'categoria' => $categoria !== '' ? $categoria : null,
        'precio' => $precio,
        'stock_minimo' => $minimo,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar();
    $accion = $_POST['accion'] ?? '';

    if (!$esAdmin && $accion !== 'alerta_stock') {
        http_response_code(403);
        exit('No tenés permiso para esta acción.');
    }

    try {
        if ($accion === 'alta') {
            $datos = datos_repuesto($_POST, $errores);
            $stockInicial = filter_var($_POST['stock_inicial'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($stockInicial === false) {
                $errores[] = 'El stock inicial tiene que ser un número mayor o igual a 0.';
            }

            if (!$errores) {
                $pdo->beginTransaction();
                $pdo->prepare(
                    'INSERT INTO repuestos (nombre, marca_vehiculo, categoria, precio, costo, stock, stock_minimo)
                     VALUES (?, ?, ?, ?, 0, 0, ?)'
                )->execute([$datos['nombre'], $datos['marca_vehiculo'], $datos['categoria'], $datos['precio'], $datos['stock_minimo']]);
                $idNuevo = (int) $pdo->lastInsertId();

                if ($stockInicial > 0) {
                    $costoInicial = filter_var($_POST['costo_inicial'] ?? '', FILTER_VALIDATE_FLOAT);
                    if ($costoInicial === false || $costoInicial < 0) {
                        throw new DomainException('Indicá el costo unitario del stock inicial.');
                    }
                    registrar_movimiento($pdo, $idNuevo, 'entrada', $stockInicial, $costoInicial,
                        ($_POST['id_proveedor_inicial'] ?? '') !== '' ? (int) $_POST['id_proveedor_inicial'] : null,
                        'Stock inicial', $idUsuario);
                }
                $pdo->commit();
                flash_set('Repuesto agregado.');
                header('Location: stock?id=' . $idNuevo);
                exit;
            }
        }

        if ($accion === 'editar') {
            $id = (int) ($_POST['id_repuesto'] ?? 0);
            $datos = datos_repuesto($_POST, $errores);
            if (!$errores) {
                $pdo->prepare(
                    'UPDATE repuestos SET nombre = ?, marca_vehiculo = ?, categoria = ?, precio = ?, stock_minimo = ?
                     WHERE id_repuesto = ?'
                )->execute([$datos['nombre'], $datos['marca_vehiculo'], $datos['categoria'], $datos['precio'], $datos['stock_minimo'], $id]);
                flash_set('Repuesto actualizado.');
                header('Location: stock?id=' . $id);
                exit;
            }
        }

        if ($accion === 'alerta_stock') {
            $bajos = $pdo->query('SELECT nombre, marca_vehiculo, stock, stock_minimo FROM repuestos WHERE stock <= stock_minimo ORDER BY nombre')->fetchAll();
            if (!$bajos) {
                flash_set('No hay repuestos con stock bajo.');
                header('Location: stock');
                exit;
            }

            $reciente = $pdo->query('SELECT COUNT(*) FROM alertas_stock WHERE creado_en > NOW() - INTERVAL 6 HOUR')->fetchColumn();
            if ((int) $reciente > 0) {
                $errores[] = 'Ya se envió una alerta en las últimas 6 horas. Esperá un poco antes de volver a enviarla.';
            } else {
                $admins = $pdo->query("SELECT email FROM usuarios WHERE rol = 'admin' AND email_verificado_en IS NOT NULL")
                    ->fetchAll(PDO::FETCH_COLUMN);
                if (!$admins) {
                    throw new DomainException('No hay administradores con email verificado.');
                }
                $html = html_alerta_stock($bajos, $_SESSION['nombre']);
                $enviados = 0;
                foreach ($admins as $mailAdmin) {
                    if (enviar_mail_brevo($mailAdmin, 'Alerta de stock bajo en el taller', $html)) {
                        $enviados++;
                    }
                }
                if ($enviados === 0) {
                    throw new DomainException('No pudimos enviar la alerta. Revisá la configuración de mails.');
                }
                $pdo->prepare('INSERT INTO alertas_stock (id_usuario, cantidad_repuestos) VALUES (?, ?)')
                    ->execute([$idUsuario, count($bajos)]);
                flash_set('Alerta enviada al administrador.');
                header('Location: stock');
                exit;
            }
        }

        if ($accion === 'alta_proveedor') {
            $nombre = trim($_POST['nombre'] ?? '');
            $telefono = trim($_POST['telefono'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $cuit = trim($_POST['cuit'] ?? '');

            if ($nombre === '' || mb_strlen($nombre) > 120) {
                $errores[] = 'El nombre del proveedor es obligatorio (hasta 120 caracteres).';
            }
            if ($email !== '' && (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 150)) {
                $errores[] = 'El email no es válido.';
            }
            if ($cuit !== '' && preg_match('/^\d{2}-?\d{8}-?\d$/', $cuit) !== 1) {
                $errores[] = 'El CUIT no es válido. Ejemplo: 30-12345678-9.';
            }
            if (!$errores) {
                $pdo->prepare('INSERT INTO proveedores (nombre, telefono, email, cuit) VALUES (?, ?, ?, ?)')
                    ->execute([$nombre, $telefono ?: null, $email ?: null, $cuit ?: null]);
                flash_set('Proveedor agregado.');
                header('Location: stock?vista=proveedores');
                exit;
            }
        }

        if ($accion === 'eliminar_proveedor') {
            try {
                $pdo->prepare('DELETE FROM proveedores WHERE id_proveedor = ?')
                    ->execute([(int) ($_POST['id_proveedor'] ?? 0)]);
                flash_set('Proveedor eliminado.');
                header('Location: stock?vista=proveedores');
                exit;
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
                $errores[] = 'No podés eliminar un proveedor con compras registradas.';
            }
        }

        if ($accion === 'movimiento') {
            $id = (int) ($_POST['id_repuesto'] ?? 0);
            $tipo = $_POST['tipo'] ?? '';
            $cantidad = filter_var($_POST['cantidad'] ?? '', FILTER_VALIDATE_INT);
            $costo = $tipo === 'entrada' ? filter_var($_POST['costo_unitario'] ?? '', FILTER_VALIDATE_FLOAT) : null;
            $idProveedor = ($tipo === 'entrada' && ($_POST['id_proveedor'] ?? '') !== '') ? (int) $_POST['id_proveedor'] : null;
            $motivo = trim($_POST['motivo'] ?? '');

            if ($cantidad === false || $cantidad <= 0) {
                $errores[] = 'La cantidad tiene que ser mayor a 0.';
            }
            if ($motivo === '' || mb_strlen($motivo) > 255) {
                $errores[] = 'El motivo es obligatorio (hasta 255 caracteres).';
            }
            if (!$errores) {
                registrar_movimiento($pdo, $id, $tipo, (int) $cantidad, $costo === false ? null : $costo, $idProveedor, $motivo, $idUsuario);
                flash_set('Movimiento registrado.');
                header('Location: stock?id=' . $id);
                exit;
            }
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
        error_log('stock: ' . $e->getMessage());
        $errores[] = 'Error interno. Intentá de nuevo más tarde.';
    }
}

$idDetalle = (int) ($_GET['id'] ?? ($_POST['id_repuesto'] ?? 0));
$detalle = null;
$movimientos = [];

if ($idDetalle > 0) {
    $stmt = $pdo->prepare('SELECT * FROM repuestos WHERE id_repuesto = ?');
    $stmt->execute([$idDetalle]);
    $detalle = $stmt->fetch() ?: null;
    if ($detalle) {
        $stmt = $pdo->prepare(
            'SELECT m.tipo, m.cantidad, m.costo_unitario, m.motivo, m.creado_en,
                    p.nombre AS proveedor, u.nombre AS usuario
             FROM movimientos_stock m
             LEFT JOIN proveedores p ON p.id_proveedor = m.id_proveedor
             JOIN usuarios u ON u.id_usuario = m.id_usuario
             WHERE m.id_repuesto = ?
             ORDER BY m.creado_en DESC, m.id_movimiento DESC
             LIMIT 50'
        );
        $stmt->execute([$idDetalle]);
        $movimientos = $stmt->fetchAll();
    }
}

$busqueda = trim($_GET['q'] ?? '');
$stmt = $pdo->prepare(
    "SELECT * FROM repuestos
     WHERE ? = '' OR nombre LIKE ? OR marca_vehiculo LIKE ?
     ORDER BY nombre"
);
$like = '%' . $busqueda . '%';
$stmt->execute([$busqueda, $like, $like]);
$listado = $stmt->fetchAll();

$proveedores = $pdo->query('SELECT * FROM proveedores ORDER BY nombre')->fetchAll();

function ganancia_texto(array $r): array
{
    $venta = (float) $r['precio'];
    $costo = (float) $r['costo'];
    $pesos = $venta - $costo;
    $margen = $venta > 0 ? ($pesos / $venta) * 100 : 0;
    return [$pesos, $margen];
}

function pesos_fmt(float $v): string
{
    return '$ ' . number_format($v, 2, ',', '.');
}

$vista = $esAdmin && (($_GET['vista'] ?? '') === 'proveedores' || in_array($_POST['accion'] ?? '', ['alta_proveedor', 'eliminar_proveedor'], true))
    ? 'proveedores'
    : 'repuestos';

$cantBajos = count(array_filter($listado, fn($r) => (int) $r['stock'] <= (int) $r['stock_minimo']));

inicio_html('Stock de repuestos');
?>
  <div class="encabezado-pagina">
    <div>
      <h1><?= $esAdmin ? 'Stock y Proveedores' : 'Repuestos del Taller' ?></h1>
      <p class="subtitulo">
        <?= $esAdmin ? 'Inventario valorizado, costos, margen de ganancia, movimientos y proveedores.' : 'Consultá stock disponible y avisá al administrador si hay faltantes.' ?>
      </p>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
      <a class="btn <?= $vista === 'repuestos' ? '' : 'btn-outline' ?>" href="stock">Repuestos</a>
      <?php if ($esAdmin): ?>
        <a class="btn <?= $vista === 'proveedores' ? '' : 'btn-outline' ?>" href="stock?vista=proveedores">Proveedores</a>
      <?php endif; ?>
    </div>
  </div>

  <?php mostrar_mensajes($mensaje, $errores); ?>

  <?php if ($vista === 'proveedores'): ?>
    <div class="panel-grid">
      <div class="panel-principal">
        <?php if (!$proveedores): ?>
          <div class="tarjeta"><p class="vacio" style="margin:0;">Todavía no cargaste proveedores.</p></div>
        <?php else: ?>
          <div class="garage-grid">
            <?php foreach ($proveedores as $pr): ?>
              <article class="tarjeta" style="margin-bottom:0; display:flex; flex-direction:column; gap:10px;">
                <h3 style="margin:0; font-size:1.05rem;"><?= htmlspecialchars($pr['nombre']) ?></h3>
                <div class="meta-tile">
                  <span>Contacto</span>
                  <strong style="font-weight:500;">
                    <?= htmlspecialchars($pr['telefono'] ?? 'Sin teléfono') ?> · <?= htmlspecialchars($pr['email'] ?? 'Sin email') ?>
                  </strong>
                </div>
                <div class="meta-tile">
                  <span>CUIT</span>
                  <strong class="mono"><?= htmlspecialchars($pr['cuit'] ?? '—') ?></strong>
                </div>
                <form method="POST" action="stock" onsubmit="return confirm('¿Eliminar este proveedor?');" style="margin-top:auto;">
                  <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                  <input type="hidden" name="accion" value="eliminar_proveedor">
                  <input type="hidden" name="id_proveedor" value="<?= (int) $pr['id_proveedor'] ?>">
                  <button type="submit" class="btn btn-peligro btn-grande" style="min-height:38px;">Eliminar proveedor</button>
                </form>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <aside class="panel-lateral">
        <h2 style="margin:0 0 6px; font-size:1.08rem;">Agregar proveedor</h2>
        <form method="POST" action="stock" class="formulario" style="grid-template-columns:1fr;">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
          <input type="hidden" name="accion" value="alta_proveedor">
          <label class="campo">Nombre / Razón social
            <input type="text" name="nombre" maxlength="120" required>
          </label>
          <label class="campo">Teléfono
            <input type="tel" name="telefono" maxlength="30">
          </label>
          <label class="campo">Email
            <input type="email" name="email" maxlength="150">
          </label>
          <label class="campo">CUIT (opcional)
            <input type="text" name="cuit" maxlength="13" placeholder="30-12345678-9">
          </label>
          <button type="submit" class="btn btn-grande">Guardar proveedor</button>
        </form>
      </aside>
    </div>

  <?php elseif ($detalle): ?>
    <p><a href="stock">← Volver al listado completo</a></p>
    <?php [$gananciaPesos, $gananciaMargen] = ganancia_texto($detalle); ?>

    <section class="tarjeta destacada">
      <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:14px;">
        <div>
          <h2 style="margin:0;"><?= htmlspecialchars($detalle['nombre']) ?></h2>
          <p class="subtitulo"><?= htmlspecialchars($detalle['marca_vehiculo'] ?? 'Multimarca') ?> · <?= htmlspecialchars($detalle['categoria'] ?? 'General') ?></p>
        </div>
        <?= (int) $detalle['stock'] <= (int) $detalle['stock_minimo'] ? '<span class="badge badge-rechazado">Stock bajo</span>' : '<span class="badge badge-cerrado">Stock OK</span>' ?>
      </div>

      <div class="kpi-strip" style="margin-bottom:0;">
        <div class="meta-tile"><span>Stock actual</span><strong class="mono"><?= (int) $detalle['stock'] ?> u.</strong></div>
        <div class="meta-tile"><span>Mínimo</span><strong class="mono"><?= (int) $detalle['stock_minimo'] ?> u.</strong></div>
        <div class="meta-tile"><span>Precio venta</span><strong class="mono"><?= pesos_fmt((float) $detalle['precio']) ?></strong></div>
        <?php if ($esAdmin): ?>
          <div class="meta-tile"><span>Costo</span><strong class="mono"><?= pesos_fmt((float) $detalle['costo']) ?></strong></div>
          <div class="meta-tile"><span>Ganancia</span><strong class="mono" style="color:#34d399;"><?= pesos_fmt($gananciaPesos) ?> (<?= number_format($gananciaMargen, 1, ',', '.') ?>%)</strong></div>
        <?php endif; ?>
      </div>
    </section>

    <?php if ($esAdmin): ?>
      <div class="dos-columnas">
        <section class="tarjeta">
          <h2 style="margin-top:0;">Registrar movimiento</h2>
          <form method="POST" action="stock" class="formulario" id="form-mov">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="accion" value="movimiento">
            <input type="hidden" name="id_repuesto" value="<?= (int) $detalle['id_repuesto'] ?>">
            <fieldset class="tipo completo">
              <legend>Tipo de movimiento</legend>
              <label><input type="radio" name="tipo" value="entrada" checked> Entrada (compra)</label>
              <label><input type="radio" name="tipo" value="salida"> Salida (uso o descarte)</label>
            </fieldset>
            <label class="campo">Cantidad
              <input type="number" name="cantidad" min="1" required>
            </label>
            <label class="campo" data-entrada>Costo unitario
              <input type="number" name="costo_unitario" min="0" step="0.01">
            </label>
            <label class="campo completo" data-entrada>Proveedor
              <select name="id_proveedor">
                <option value="">Sin proveedor</option>
                <?php foreach ($proveedores as $pr): ?>
                  <option value="<?= (int) $pr['id_proveedor'] ?>"><?= htmlspecialchars($pr['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="campo completo">Motivo
              <input type="text" name="motivo" maxlength="255" required placeholder="Compra factura 0001-123">
            </label>
            <div class="completo"><button type="submit" class="btn btn-grande">Registrar movimiento</button></div>
          </form>
        </section>

        <section class="tarjeta">
          <h2 style="margin-top:0;">Editar repuesto</h2>
          <form method="POST" action="stock" class="formulario">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="accion" value="editar">
            <input type="hidden" name="id_repuesto" value="<?= (int) $detalle['id_repuesto'] ?>">
            <label class="campo completo">Nombre
              <input type="text" name="nombre" maxlength="120" value="<?= htmlspecialchars($detalle['nombre']) ?>" required>
            </label>
            <label class="campo">Marca de vehículo
              <input type="text" name="marca_vehiculo" maxlength="50" value="<?= htmlspecialchars($detalle['marca_vehiculo'] ?? '') ?>" placeholder="Volkswagen">
            </label>
            <label class="campo">Categoría
              <input type="text" name="categoria" maxlength="50" value="<?= htmlspecialchars($detalle['categoria'] ?? '') ?>" placeholder="Filtros">
            </label>
            <label class="campo">Precio de venta
              <input type="number" name="precio" min="0" step="0.01" value="<?= htmlspecialchars((string) $detalle['precio']) ?>" required>
            </label>
            <label class="campo">Stock mínimo
              <input type="number" name="stock_minimo" min="0" value="<?= (int) $detalle['stock_minimo'] ?>" required>
            </label>
            <div class="completo"><button type="submit" class="btn btn-outline btn-grande">Guardar cambios</button></div>
          </form>
        </section>
      </div>

      <section class="tarjeta">
        <h2 style="margin-top:0;">Historial de movimientos</h2>
        <div class="tabla-wrap" style="margin-bottom:0;">
          <table class="tabla">
            <thead>
              <tr><th>Fecha</th><th>Tipo</th><th class="num">Cant.</th><th class="num">Costo</th><th>Proveedor</th><th>Motivo</th><th>Usuario</th></tr>
            </thead>
            <tbody>
              <?php foreach ($movimientos as $m): ?>
                <tr>
                  <td><?= date('d/m/Y H:i', strtotime($m['creado_en'])) ?></td>
                  <td><span class="badge <?= $m['tipo'] === 'entrada' ? 'badge-cerrado' : 'badge-activo' ?>"><?= htmlspecialchars($m['tipo']) ?></span></td>
                  <td class="num"><?= (int) $m['cantidad'] ?></td>
                  <td class="num"><?= $m['costo_unitario'] !== null ? pesos_fmt((float) $m['costo_unitario']) : '—' ?></td>
                  <td><?= htmlspecialchars($m['proveedor'] ?? '—') ?></td>
                  <td><?= htmlspecialchars($m['motivo']) ?></td>
                  <td><?= htmlspecialchars($m['usuario']) ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$movimientos): ?>
                <tr><td colspan="7" class="vacio">Sin movimientos todavía.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>

      <script>
      (() => {
          const form = document.getElementById('form-mov');
          function mostrarCamposEntrada() {
              const entrada = form.querySelector('input[name="tipo"]:checked').value === 'entrada';
              form.querySelectorAll('[data-entrada]').forEach((campo) => {
                  campo.hidden = !entrada;
                  campo.querySelectorAll('input, select').forEach((i) => { i.disabled = !entrada; });
              });
          }
          form.querySelectorAll('input[name="tipo"]').forEach((r) => r.addEventListener('change', mostrarCamposEntrada));
          mostrarCamposEntrada();
      })();
      </script>
    <?php endif; ?>

  <?php else: ?>
    <!-- Vista listado general -->
    <section class="kpi-strip">
      <div class="kpi-card">
        <div class="kpi-ico hub-icon sky"><?= icono_svg('box') ?></div>
        <div>
          <div class="kpi-label">Repuestos listados</div>
          <div class="kpi-valor"><?= count($listado) ?></div>
        </div>
      </div>
      <div class="kpi-card">
        <div class="kpi-ico hub-icon <?= $cantBajos > 0 ? 'orange' : 'emerald' ?>"><?= icono_svg('shield') ?></div>
        <div>
          <div class="kpi-label">Con stock bajo</div>
          <div class="kpi-valor" style="color:<?= $cantBajos > 0 ? '#fca5a5' : '#6ee7b7' ?>;"><?= $cantBajos ?></div>
        </div>
      </div>
    </section>

    <div class="tarjeta" style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:14px;">
      <form method="GET" action="stock" class="formulario" style="flex:1; min-width:240px;">
        <label class="campo completo">Buscar por nombre o marca de vehículo
          <input type="search" name="q" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Ej: Pastillas Bosch, Volkswagen, Filtro...">
        </label>
      </form>
      <form method="POST" action="stock" style="margin:0;">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="accion" value="alerta_stock">
        <button type="submit" class="btn btn-outline" <?= $cantBajos ? '' : 'disabled' ?>>
          Avisar al administrador<?= $cantBajos ? " ($cantBajos con stock bajo)" : ' (todo OK)' ?>
        </button>
      </form>
    </div>

    <div class="tabla-wrap">
      <table class="tabla">
        <thead>
          <tr>
            <th>Repuesto</th><th>Vehículo</th><th class="num">Stock</th>
            <?php if ($esAdmin): ?><th class="num">Costo</th><?php endif; ?>
            <th class="num">Venta</th>
            <?php if ($esAdmin): ?><th class="num">Ganancia</th><?php endif; ?>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($listado as $r): ?>
            <?php [$gPesos, $gMargen] = ganancia_texto($r); ?>
            <tr>
              <td><strong><?= htmlspecialchars($r['nombre']) ?></strong></td>
              <td><?= htmlspecialchars($r['marca_vehiculo'] ?? '—') ?></td>
              <td class="num">
                <strong><?= (int) $r['stock'] ?></strong>
                <?= (int) $r['stock'] <= (int) $r['stock_minimo']
                    ? ' <span class="badge badge-rechazado">Bajo</span>'
                    : '' ?>
              </td>
              <?php if ($esAdmin): ?><td class="num"><?= pesos_fmt((float) $r['costo']) ?></td><?php endif; ?>
              <td class="num"><strong><?= pesos_fmt((float) $r['precio']) ?></strong></td>
              <?php if ($esAdmin): ?>
                <td class="num"><?= pesos_fmt($gPesos) ?> <span class="badge badge-cerrado"><?= number_format($gMargen, 0, ',', '.') ?>%</span></td>
              <?php endif; ?>
              <td><a href="stock?id=<?= (int) $r['id_repuesto'] ?>" class="btn btn-outline" style="min-height:32px; padding:4px 12px; font-size:0.78rem;">Ver</a></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$listado): ?>
            <tr><td colspan="<?= $esAdmin ? 7 : 5 ?>" class="vacio">No hay repuestos para mostrar.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($esAdmin): ?>
      <section class="tarjeta">
        <h2 style="margin-top:0;">Agregar nuevo repuesto</h2>
        <form method="POST" action="stock" class="formulario">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
          <input type="hidden" name="accion" value="alta">
          <label class="campo completo">Nombre
            <input type="text" name="nombre" maxlength="120" required placeholder="Filtro de aceite">
          </label>
          <label class="campo">Marca de vehículo
            <input type="text" name="marca_vehiculo" maxlength="50" placeholder="Volkswagen">
          </label>
          <label class="campo">Categoría
            <input type="text" name="categoria" maxlength="50" placeholder="Filtros">
          </label>
          <label class="campo">Precio de venta
            <input type="number" name="precio" min="0" step="0.01" required>
          </label>
          <label class="campo">Stock mínimo
            <input type="number" name="stock_minimo" min="0" value="0" required>
          </label>
          <label class="campo">Stock inicial
            <input type="number" name="stock_inicial" min="0" value="0">
          </label>
          <label class="campo">Proveedor del stock inicial
            <select name="id_proveedor_inicial">
              <option value="">Sin proveedor</option>
              <?php foreach ($proveedores as $pr): ?>
                <option value="<?= (int) $pr['id_proveedor'] ?>"><?= htmlspecialchars($pr['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="campo">Costo unitario del stock inicial
            <input type="number" name="costo_inicial" min="0" step="0.01">
            <small>Obligatorio si el stock inicial es mayor a 0.</small>
          </label>
          <div class="completo"><button type="submit" class="btn btn-grande">Agregar repuesto</button></div>
        </form>
      </section>
    <?php endif; ?>
  <?php endif; ?>
<?php
link_panel();
fin_html();
