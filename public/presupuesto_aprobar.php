<?php

require __DIR__ . '/../src/auth/csrf.php';
require __DIR__ . '/../src/config/database.php';
require __DIR__ . '/../src/dominio/trabajos.php';
require __DIR__ . '/../src/views/layout.php';

// Página pública: no pide login. El acceso lo da el token del link del mail.

$pdo = conectar();
$token = (string) ($_REQUEST['token'] ?? '');
$error = null;
$resultado = $_GET['resultado'] ?? null;

// El token llega en claro; en la base solo está su hash
$hash = preg_match('/^[a-f0-9]{64}$/', $token) ? hash('sha256', $token) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar();
    $accion = $_POST['accion'] ?? '';

    try {
        if ($hash === null || !in_array($accion, ['aprobar', 'rechazar'], true)) {
            throw new DomainException('El link no es válido.');
        }
        $nuevo = $accion === 'aprobar' ? 'aprobado' : 'rechazado';

        $pdo->beginTransaction();

        // Bloquea el token para que no se pueda usar dos veces al mismo tiempo
        $stmt = $pdo->prepare(
            'SELECT id_presupuesto FROM tokens_presupuesto
             WHERE token_hash = ? AND usado_en IS NULL AND expira_en > NOW()
             FOR UPDATE'
        );
        $stmt->execute([$hash]);
        $filaToken = $stmt->fetch();
        if (!$filaToken) {
            throw new DomainException('Este link ya no es válido. Consultá con el taller.');
        }
        $idPresupuesto = (int) $filaToken['id_presupuesto'];

        $stmt = $pdo->prepare(
            'SELECT p.id_trabajo, p.estado, t.estado AS estado_trabajo
             FROM presupuestos p
             JOIN trabajos t ON t.id_trabajo = p.id_trabajo
             WHERE p.id_presupuesto = ?
             FOR UPDATE'
        );
        $stmt->execute([$idPresupuesto]);
        $presupuesto = $stmt->fetch();

        if ($presupuesto['estado'] !== 'enviado') {
            throw new DomainException('Este presupuesto ya fue respondido.');
        }
        if (!puede_pasar($presupuesto['estado_trabajo'], $nuevo)) {
            throw new DomainException('No se puede cambiar el estado del trabajo. Consultá con el taller.');
        }

        // Al aprobar, se descuenta el stock de cada repuesto. Si alguno no alcanza, se cancela todo.
        if ($nuevo === 'aprobado') {
            $stmt = $pdo->prepare(
                'SELECT id_repuesto, cantidad, descripcion
                 FROM items_presupuesto
                 WHERE id_presupuesto = ? AND id_repuesto IS NOT NULL'
            );
            $stmt->execute([$idPresupuesto]);
            $descuento = $pdo->prepare(
                'UPDATE repuestos SET stock = stock - ? WHERE id_repuesto = ? AND stock >= ?'
            );
            foreach ($stmt->fetchAll() as $item) {
                $descuento->execute([$item['cantidad'], $item['id_repuesto'], $item['cantidad']]);
                if ($descuento->rowCount() === 0) {
                    throw new DomainException("No hay stock suficiente de {$item['descripcion']}. Consultá con el taller.");
                }
            }
        }

        $pdo->prepare('UPDATE presupuestos SET estado = ?, fecha_respuesta = NOW() WHERE id_presupuesto = ?')
            ->execute([$nuevo, $idPresupuesto]);
        $pdo->prepare('UPDATE trabajos SET estado = ? WHERE id_trabajo = ?')
            ->execute([$nuevo, $presupuesto['id_trabajo']]);
        $pdo->prepare('UPDATE tokens_presupuesto SET usado_en = NOW() WHERE token_hash = ?')
            ->execute([$hash]);

        $pdo->commit();

        // PRG: después de decidir, el token deja de estar en la URL
        header('Location: presupuesto_aprobar.php?resultado=' . $nuevo);
        exit;
    } catch (DomainException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('presupuesto_aprobar: ' . $e->getMessage());
        $error = 'Ocurrió un error. Intentá de nuevo o consultá con el taller.';
    }
}

// Datos para mostrar el presupuesto, solo si el token sigue vigente
$datos = null;
$items = [];
if (!in_array($resultado, ['aprobado', 'rechazado'], true) && $hash !== null) {
    $stmt = $pdo->prepare(
        'SELECT p.id_presupuesto, p.total, t.id_trabajo, v.modelo, v.patente, u.nombre
         FROM tokens_presupuesto tp
         JOIN presupuestos p ON p.id_presupuesto = tp.id_presupuesto
         JOIN trabajos t ON t.id_trabajo = p.id_trabajo
         JOIN turnos tu ON tu.id_turno = t.id_turno
         JOIN vehiculos v ON v.id_vehiculo = tu.id_vehiculo
         JOIN usuarios u ON u.id_usuario = v.id_cliente
         WHERE tp.token_hash = ? AND tp.usado_en IS NULL AND tp.expira_en > NOW()'
    );
    $stmt->execute([$hash]);
    $datos = $stmt->fetch() ?: null;

    if ($datos) {
        $stmt = $pdo->prepare(
            'SELECT descripcion, cantidad, precio_unitario
             FROM items_presupuesto
             WHERE id_presupuesto = ?
             ORDER BY id_item'
        );
        $stmt->execute([$datos['id_presupuesto']]);
        $items = $stmt->fetchAll();
    }
}

inicio_html('Presupuesto');
mostrar_mensajes('', $error ? [$error] : []);
?>

<?php if ($resultado === 'aprobado'): ?>
    <section class="tarjeta destacada">
        <h1>¡Presupuesto aprobado!</h1>
        <p>Gracias. El taller va a seguir con tu vehículo.</p>
    </section>

<?php elseif ($resultado === 'rechazado'): ?>
    <section class="tarjeta destacada">
        <h1>Presupuesto rechazado</h1>
        <p>Recibimos tu respuesta. Si querés, comunicate con el taller.</p>
    </section>

<?php elseif ($datos === null): ?>
    <section class="tarjeta destacada">
        <h1>Link no disponible</h1>
        <p>El link venció, ya se usó o no es válido. Consultá con el taller.</p>
    </section>

<?php else: ?>
    <section class="tarjeta destacada">
        <h1>Presupuesto para tu vehículo</h1>
        <p>Hola <?= htmlspecialchars($datos['nombre']) ?>. Este es el detalle para tu
            <?= htmlspecialchars($datos['modelo']) ?> (<?= htmlspecialchars($datos['patente']) ?>).</p>

        <table>
            <thead>
                <tr>
                    <th>Descripción</th>
                    <th>Cant.</th>
                    <th>Precio</th>
                    <th>Subtotal</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['descripcion']) ?></td>
                        <td><?= (int) $item['cantidad'] ?></td>
                        <td>$ <?= number_format((float) $item['precio_unitario'], 2, ',', '.') ?></td>
                        <td>$ <?= number_format($item['cantidad'] * (float) $item['precio_unitario'], 2, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p class="total">Total: $ <?= number_format((float) $datos['total'], 2, ',', '.') ?></p>

        <form method="post" action="presupuesto_aprobar.php" class="acciones">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
            <button type="submit" name="accion" value="aprobar" class="btn btn-grande">Aprobar</button>
            <button type="submit" name="accion" value="rechazar" class="btn btn-outline">Rechazar</button>
        </form>
    </section>
<?php endif; ?>

<?php fin_html(); ?>