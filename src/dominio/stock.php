<?php

const TIPOS_MOVIMIENTO = ['entrada', 'salida'];

// Registra una entrada o salida de stock.
// Entrada: suma stock y guarda el costo de esta compra como costo del repuesto.
// Salida: resta stock, pero nunca deja el stock en negativo.
// Si ya hay una transacción abierta, se usa esa; si no, esta función abre y cierra la suya.
function registrar_movimiento(
    PDO $pdo,
    int $idRepuesto,
    string $tipo,
    int $cantidad,
    ?float $costoUnitario,
    ?int $idProveedor,
    string $motivo,
    int $idUsuario
): void {
    if (!in_array($tipo, TIPOS_MOVIMIENTO, true)) {
        throw new DomainException('Tipo de movimiento inválido.');
    }
    if ($cantidad <= 0) {
        throw new DomainException('La cantidad tiene que ser mayor a 0.');
    }

    $propia = !$pdo->inTransaction();
    if ($propia) {
        $pdo->beginTransaction();
    }

    try {
        // Bloquea la fila del repuesto para que dos movimientos no se pisen
        $stmt = $pdo->prepare('SELECT stock FROM repuestos WHERE id_repuesto = ? FOR UPDATE');
        $stmt->execute([$idRepuesto]);
        $stock = $stmt->fetchColumn();
        if ($stock === false) {
            throw new DomainException('Repuesto no encontrado.');
        }

        if ($tipo === 'entrada') {
            if ($costoUnitario === null || $costoUnitario < 0) {
                throw new DomainException('Indicá el costo unitario de la compra.');
            }
            $pdo->prepare('UPDATE repuestos SET stock = stock + ?, costo = ? WHERE id_repuesto = ?')
                ->execute([$cantidad, $costoUnitario, $idRepuesto]);
        } else {
            if ((int) $stock < $cantidad) {
                throw new DomainException("No hay stock suficiente. Hay $stock unidades.");
            }
            $pdo->prepare('UPDATE repuestos SET stock = stock - ? WHERE id_repuesto = ?')
                ->execute([$cantidad, $idRepuesto]);
        }

        $pdo->prepare(
            'INSERT INTO movimientos_stock (id_repuesto, tipo, cantidad, costo_unitario, id_proveedor, motivo, id_usuario)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $idRepuesto,
            $tipo,
            $cantidad,
            $tipo === 'entrada' ? $costoUnitario : null,
            $tipo === 'entrada' ? $idProveedor : null,
            $motivo,
            $idUsuario,
        ]);

        if ($propia) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($propia && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}