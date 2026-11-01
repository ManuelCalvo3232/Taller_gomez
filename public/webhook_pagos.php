<?php
require __DIR__ . '/../src/config/database.php';
require __DIR__ . '/../src/integraciones/mercadopago.php';
require __DIR__ . '/../src/dominio/comprobante_pago.php';

// Mercado Pago avisa acá cuando cambia un pago. El aviso solo dice qué pago cambió:
// el estado real se consulta a la API, así que nadie puede marcar un pago como aprobado a mano.

$tipo = $_GET['type'] ?? $_GET['topic'] ?? null;
$idPago = $_GET['data_id'] ?? $_GET['id'] ?? null;

$cuerpo = json_decode(file_get_contents('php://input') ?: '', true);
if (is_array($cuerpo)) {
    $tipo = $tipo ?? ($cuerpo['type'] ?? $cuerpo['topic'] ?? null);
    $idPago = $idPago ?? ($cuerpo['data']['id'] ?? null);
}

// Avisos que no son de pagos se ignoran
if ($tipo !== 'payment' || !$idPago) {
    http_response_code(200);
    exit;
}

try {
    $pago = mp_obtener_pago((string) $idPago);
    $idLocal = (int) ($pago['external_reference'] ?? 0);

    $nuevoEstado = match ($pago['status'] ?? '') {
        'approved' => 'aprobado',
        'rejected', 'cancelled' => 'rechazado',
        default => 'pendiente',
    };

    if ($idLocal > 0) {
        $pdo = conectar();
        $stmt = $pdo->prepare('SELECT monto FROM pagos WHERE id_pago = ?');
        $stmt->execute([$idLocal]);
        $monto = $stmt->fetchColumn();

        // Si el monto cobrado no coincide con el del presupuesto, no se marca como aprobado
        $montoCoincide = $monto !== false && abs((float) ($pago['transaction_amount'] ?? 0) - (float) $monto) < 0.01;
        if ($nuevoEstado === 'aprobado' && !$montoCoincide) {
            error_log("webhook_pagos: monto no coincide para pago $idLocal");
            $nuevoEstado = 'pendiente';
        }

        // Un pago aprobado no vuelve a cambiar. Si Mercado Pago reenvía el aviso, no se cuenta de nuevo
        $stmt = $pdo->prepare(
            "UPDATE pagos SET estado = ?, mp_payment_id = ? WHERE id_pago = ? AND estado <> 'aprobado'"
        );
        $stmt->execute([$nuevoEstado, (string) $idPago, $idLocal]);

        // El mail solo sale en el paso a aprobado (rowCount = 1 significa que la fila cambió)
        if ($nuevoEstado === 'aprobado' && $stmt->rowCount() === 1) {
            enviar_comprobante_pago($pdo, $idLocal, (string) $idPago);
        }
    }

    http_response_code(200);
} catch (Throwable $e) {
    error_log('webhook_pagos: ' . $e->getMessage());
    // 500 hace que Mercado Pago vuelva a intentar el aviso más tarde
    http_response_code(500);
}