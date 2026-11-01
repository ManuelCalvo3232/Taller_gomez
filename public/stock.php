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

// Mail con los repuestos que están por debajo del mínimo
function html_alerta_stock(array $bajos, string $quien): string
{
    $celda = 'padding:8px;border-bottom:1px solid #EDEDED;';
    $filas = '';
    foreach ($bajos as $r) {
        $filas .= '<tr>'
            . '<td style="' . $celda . '">' . htmlspecialchars($r['nombre']) . '</td>'
            . '<td style="' . $celda . '">' . htmlspecialchars($r['marca_vehiculo'] ?? '—') . '</td>'
            . '<td style="' . $celda . 'text-align:right;">' . (int) $r['stock'] . '</td>'
            . '<td style="' . $celda . 'text-align:right;">' . (int) $r['stock_minimo'] . '</td>'
            . '</tr>';
    }

    return '<div style="font-family:Inter,Arial,sans-serif;max-width:560px;margin:0 auto;background:#F2F2F2;padding:24px;">'
        . '<div style="background:#171717;color:#FFFFFF;padding:16px 20px;border-left:4px solid #E63946;font-weight:800;letter-spacing:.08em;">TALLER GÓMEZ</div>'
        . '<div style="background:#FFFFFF;padding:24px 20px;color:#171717;">'
        . '<h2 style="margin:0 0 12px;">Alerta de stock bajo</h2>'
        . '<p>' . htmlspecialchars($quien) . ' avisó que estos repuestos están en el mínimo o por debajo:</p>'
        . '<table style="width:100%;border-collapse:collapse;font-size:14px;">'
        . '<tr style="background:#171717;color:#FFFFFF;">'
        . '<th style="padding:10px 8px;text-align:left;">Repuesto</th>'
        . '<th style="padding:10px 8px;text-align:left;">Vehículo</th>'
        . '<th style="padding:10px 8px;text-align:right;">Stock</th>'
        . '<th style="padding:10px 8px;text-align:right;">Mínimo</th></tr>'
        . $filas . '</table>'
        . '</div></div>';
}

// Valida los campos comunes de un repuesto (alta o edición)
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

    // Solo el admin modifica stock y proveedores. El mecánico solo puede pedir la alerta.
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
                // Con compras registradas no se borra, para no perder el historial
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

// Detalle de un repuesto (si se eligió uno)
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

// Listado con búsqueda por nombre o marca
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

// Ganancia por unidad y margen sobre el precio de venta
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

inicio_html('Stock de repuestos');
?>
<h1>Stock de repuestos</h1>
<?php mostrar_mensajes($mensaje, $errores); ?>

<p style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:20px">
  <a class="btn <?= $vista === 'repuestos' ? 'btn-oscuro' : 'btn-outline' ?>" href="stock">Repuestos</a>
  <?php if ($esAdmin): ?>
    <a class="btn <?= $vista === 'proveedores' ? 'btn-oscuro' : 'btn-outline' ?>" href="stock?vista=proveedores">Proveedores</a>
  <?php endif; ?>
</p>

<?php if ($vista === 'proveedores'): ?>
  <div class="lista">
    <?php if (!$proveedores): ?>
      <p class="vacio">Todavía no cargaste proveedores.</p>
    <?php endif; ?>
    <?php foreach ($proveedores as $pr): ?>
      <div class="item">
        <div>
          <div class="titulo"><?= htmlspecialchars($pr['nombre']) ?></div>
          <div class="detalle">
            <?= htmlspecialchars($pr['telefono'] ?? 'Sin teléfono') ?>
            · <?= htmlspecialchars($pr['email'] ?? 'Sin email') ?>
            · CUIT <?= htmlspecialchars($pr['cuit'] ?? '—') ?>
          </div>
        </div>
        <form method="POST" action="stock" onsubmit="return confirm('¿Eliminar este proveedor?');">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
          <input type="hidden" name="accion" value="eliminar_proveedor">
          <input type="hidden" name="id_proveedor" value="<?= (int) $pr['id_proveedor'] ?>">
          <button type="submit" class="btn btn-outline">Eliminar</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>

  <section class="tarjeta" style="margin-top:24px">
    <h2>Agregar proveedor</h2>
    <form method="POST" action="stock" class="formulario">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
      <input type="hidden" name="accion" value="alta_proveedor">

      <label class="campo completo">Nombre
        <input type="text" name="nombre" maxlength="120" required>
      </label>
      <label class="campo">Teléfono
        <input type="tel" name="telefono" maxlength="30">
      </label>
      <label class="campo">Email
        <input type="email" name="email" maxlength="150">
      </label>
      <label class="campo completo">CUIT (opcional)
        <input type="text" name="cuit" maxlength="13" placeholder="30-12345678-9">
      </label>
      <div class="completo">
        <button type="submit" class="btn btn-grande">Agregar proveedor</button>
      </div>
    </form>
  </section>

<?php elseif ($detalle): ?>
  <p><a href="stock">← Volver al listado</a></p>

  <?php [$gananciaPesos, $gananciaMargen] = ganancia_texto($detalle); ?>
  <section class="tarjeta destacada">
    <h2><?= htmlspecialchars($detalle['nombre']) ?></h2>
    <p class="detalle">
      Stock: <strong><?= (int) $detalle['stock'] ?></strong> ·
      Mínimo: <?= (int) $detalle['stock_minimo'] ?> ·
      Venta: <?= pesos_fmt((float) $detalle['precio']) ?>
      <?php if ($esAdmin): ?>
        · Costo: <?= pesos_fmt((float) $detalle['costo']) ?>
        · Ganancia: <?= pesos_fmt($gananciaPesos) ?> (<?= number_format($gananciaMargen, 1, ',', '.') ?>%)
      <?php endif; ?>
    </p>
  </section>

  <?php if ($esAdmin): ?>
  <section class="tarjeta" style="margin-top:20px">
    <h2>Registrar movimiento</h2>
    <form method="POST" action="stock" class="formulario" id="form-mov">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
      <input type="hidden" name="accion" value="movimiento">
      <input type="hidden" name="id_repuesto" value="<?= (int) $detalle['id_repuesto'] ?>">

      <fieldset class="tipo completo">
        <legend>Tipo</legend>
        <label><input type="radio" name="tipo" value="entrada" checked> Entrada (compra)</label>
        <label><input type="radio" name="tipo" value="salida"> Salida (uso o descarte)</label>
      </fieldset>

      <label class="campo">Cantidad
        <input type="number" name="cantidad" min="1" required>
      </label>
      <label class="campo" data-entrada>Costo unitario
        <input type="number" name="costo_unitario" min="0" step="0.01">
      </label>
      <label class="campo" data-entrada>Proveedor
        <select name="id_proveedor">
          <option value="">Sin proveedor</option>
          <?php foreach ($proveedores as $pr): ?>
            <option value="<?= (int) $pr['id_proveedor'] ?>"><?= htmlspecialchars($pr['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (!$proveedores): ?><small>Todavía no hay proveedores. Cargalos en la pestaña Proveedores.</small><?php endif; ?>
      </label>
      <label class="campo completo">Motivo
        <input type="text" name="motivo" maxlength="255" required placeholder="Compra factura 0001-123">
      </label>
      <div class="completo">
        <button type="submit" class="btn btn-grande">Registrar</button>
      </div>
    </form>
  </section>

  <section class="tarjeta" style="margin-top:20px">
    <h2>Editar datos</h2>
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
      <div class="completo">
        <button type="submit" class="btn btn-outline">Guardar cambios</button>
      </div>
    </form>
  </section>

  <section style="margin-top:20px">
    <h2>Movimientos</h2>
    <div class="tabla-wrap">
      <table class="tabla">
        <thead>
          <tr><th>Fecha</th><th>Tipo</th><th class="num">Cant.</th><th class="num">Costo</th><th>Proveedor</th><th>Motivo</th><th>Usuario</th></tr>
        </thead>
        <tbody>
          <?php foreach ($movimientos as $m): ?>
            <tr>
              <td><?= date('d/m/Y H:i', strtotime($m['creado_en'])) ?></td>
              <td><?= htmlspecialchars($m['tipo']) ?></td>
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
  <form method="GET" action="stock" class="formulario" style="margin-bottom:16px">
    <label class="campo completo">Buscar
      <input type="search" name="q" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Nombre o marca de vehículo">
    </label>
  </form>

  <?php $cantBajos = count(array_filter($listado, fn ($r) => (int) $r['stock'] <= (int) $r['stock_minimo'])); ?>

  <form method="POST" action="stock" style="margin-bottom:16px">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
    <input type="hidden" name="accion" value="alerta_stock">
    <button type="submit" class="btn btn-outline" <?= $cantBajos ? '' : 'disabled' ?>>
      Avisar al administrador<?= $cantBajos ? " ($cantBajos con stock bajo)" : ' (no hay stock bajo)' ?>
    </button>
  </form>

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
            <td><?= htmlspecialchars($r['nombre']) ?></td>
            <td><?= htmlspecialchars($r['marca_vehiculo'] ?? '—') ?></td>
            <td class="num">
              <?= (int) $r['stock'] ?>
              <?= (int) $r['stock'] <= (int) $r['stock_minimo']
                  ? '<span class="badge badge-rechazado">Bajo</span>'
                  : '' ?>
            </td>
            <?php if ($esAdmin): ?><td class="num"><?= pesos_fmt((float) $r['costo']) ?></td><?php endif; ?>
            <td class="num"><?= pesos_fmt((float) $r['precio']) ?></td>
            <?php if ($esAdmin): ?>
              <td class="num"><?= pesos_fmt($gPesos) ?> <span class="detalle">(<?= number_format($gMargen, 0, ',', '.') ?>%)</span></td>
            <?php endif; ?>
            <td><a href="stock?id=<?= (int) $r['id_repuesto'] ?>">Ver</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$listado): ?>
          <tr><td colspan="<?= $esAdmin ? 7 : 5 ?>" class="vacio">No hay repuestos para mostrar.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($esAdmin): ?>
  <section class="tarjeta" style="margin-top:24px">
    <h2>Agregar repuesto</h2>
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
        <?php if (!$proveedores): ?><small>Todavía no hay proveedores. Cargalos en la pestaña Proveedores.</small><?php endif; ?>
      </label>
      <label class="campo">Costo unitario del stock inicial
        <input type="number" name="costo_inicial" min="0" step="0.01">
        <small>Obligatorio si el stock inicial es mayor a 0.</small>
      </label>
      <div class="completo">
        <button type="submit" class="btn btn-grande">Agregar repuesto</button>
      </div>
    </form>
  </section>
  <?php endif; ?>
<?php endif; ?>
<?php
fin_html();