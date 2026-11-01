<?php
require __DIR__ . '/../src/auth/acceso.php';
require __DIR__ . '/../src/auth/flash.php';
require __DIR__ . '/../src/config/database.php';
require __DIR__ . '/../src/integraciones/mercadopago.php';

requerir_rol('cliente');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: presupuestos');
    exit;
}
csrf_verificar();

$pdo = conectar();
$idPresupuesto = filter_var($_POST['id_presupuesto'] ?? '', FILTER_VALIDATE_INT);

// Solo se cobra un presupuesto aprobado, del cliente logueado, que todavía no esté pagado
$stmt = $pdo->prepare(
    "SELECT p.id_presupuesto, p.total, v.modelo, v.patente
     FROM presupuestos p
     JOIN trabajos t ON t.id_trabajo = p.id_trabajo
     JOIN turnos tu ON tu.id_turno = t.id_turno
     JOIN vehiculos v ON v.id_vehiculo = tu.id_vehiculo
     JOIN usuarios u ON u.id_usuario = v.id_cliente
     WHERE p.id_presupuesto = ? AND v.id_cliente = ? AND p.estado = 'aprobado'
       AND NOT EXISTS (
           SELECT 1 FROM pagos pg WHERE pg.id_presupuesto = p.id_presupuesto AND pg.estado = 'aprobado'
       )"
);
$stmt->execute([$idPresupuesto, (int) $_SESSION['usuario_id']]);
$presupuesto = $stmt->fetch();

if (!$presupuesto) {
    flash_set('Este presupuesto no se puede pagar.');
    header('Location: presupuestos');
    exit;
}

// El pago queda registrado antes de ir a Mercado Pago
$pdo->prepare("INSERT INTO pagos (id_presupuesto, monto, estado) VALUES (?, ?, 'pendiente')")
    ->execute([$presupuesto['id_presupuesto'], $presupuesto['total']]);
$idPago = (int) $pdo->lastInsertId();

try {
    $preferencia = mp_crear_preferencia(
        $idPago,
        "Presupuesto #{$presupuesto['id_presupuesto']} · {$presupuesto['modelo']} {$presupuesto['patente']}",
        (float) $presupuesto['total']
    );
    $pdo->prepare('UPDATE pagos SET preference_id = ? WHERE id_pago = ?')
        ->execute([$preferencia['id'], $idPago]);

    header('Location: ' . mp_url_pago($preferencia));
    exit;
} catch (RuntimeException $e) {
    error_log('pagar: ' . $e->getMessage());
    flash_set('No pudimos iniciar el pago. Intentá de nuevo más tarde.');
    header('Location: presupuestos');
    exit;
}