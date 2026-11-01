<?php

const METODOS_PRESENCIALES = ['efectivo', 'transferencia'];

// Registra un cobro hecho en el taller. Solo se puede cobrar un presupuesto aprobado que todavía no esté pagado.
function registrar_pago_presencial(PDO $pdo, int $idPresupuesto, string $metodo, int $idUsuario): void
{
    if (!in_array($metodo, METODOS_PRESENCIALES, true)) {
        throw new DomainException('Elegí efectivo o transferencia.');
    }

    $stmt = $pdo->prepare("SELECT total, estado FROM presupuestos WHERE id_presupuesto = ? FOR UPDATE");
    $stmt->execute([$idPresupuesto]);
    $presupuesto = $stmt->fetch();

    if (!$presupuesto || $presupuesto['estado'] !== 'aprobado') {
        throw new DomainException('Solo se puede cobrar un presupuesto aprobado por el cliente.');
    }

    $stmt = $pdo->prepare(
        "SELECT 1 FROM pagos WHERE id_presupuesto = ? AND estado = 'aprobado'"
    );
    $stmt->execute([$idPresupuesto]);
    if ($stmt->fetch()) {
        throw new DomainException('Este presupuesto ya está pagado.');
    }

    $pdo->prepare(
        "INSERT INTO pagos (id_presupuesto, monto, estado, metodo, id_registrador)
         VALUES (?, ?, 'aprobado', ?, ?)"
    )->execute([$idPresupuesto, $presupuesto['total'], $metodo, $idUsuario]);
}