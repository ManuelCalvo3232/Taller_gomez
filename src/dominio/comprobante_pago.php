<?php

require_once __DIR__ . '/../integraciones/brevo.php';

// Mail al cliente con el detalle de un pago aprobado.
// Mercado Pago avisa del cobro, pero el detalle del presupuesto lo manda el taller.
function enviar_comprobante_pago(PDO $pdo, int $idPago, string $numeroOperacion): bool
{
    $stmt = $pdo->prepare(
        'SELECT pg.monto, p.id_presupuesto, p.id_trabajo, v.modelo, v.patente, u.nombre, u.email
         FROM pagos pg
         JOIN presupuestos p ON p.id_presupuesto = pg.id_presupuesto
         JOIN trabajos t ON t.id_trabajo = p.id_trabajo
         JOIN turnos tu ON tu.id_turno = t.id_turno
         JOIN vehiculos v ON v.id_vehiculo = tu.id_vehiculo
         JOIN usuarios u ON u.id_usuario = v.id_cliente
         WHERE pg.id_pago = ?'
    );
    $stmt->execute([$idPago]);
    $d = $stmt->fetch();
    if (!$d) {
        return false;
    }

    $e = fn (string $texto): string => htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
    $monto = '$ ' . number_format((float) $d['monto'], 2, ',', '.');
    $fecha = date('d/m/Y H:i');

    $html = '
<div style="font-family:Inter,Arial,sans-serif; max-width:560px; margin:0 auto; background:#F2F2F2; padding:24px;">
  <div style="background:#171717; color:#FFFFFF; padding:16px 20px; border-left:4px solid #E63946; font-weight:800; letter-spacing:.08em;">
    TALLER GÓMEZ
  </div>
  <div style="background:#FFFFFF; padding:24px 20px; color:#171717;">
    <h2 style="margin:0 0 12px;">Comprobante de pago</h2>
    <p>Hola ' . $e($d['nombre']) . ', recibimos tu pago. Este es el detalle:</p>
    <table style="width:100%; border-collapse:collapse; font-size:14px; margin:16px 0;">
      <tr><td style="padding:8px; border-bottom:1px solid #EDEDED;">Vehículo</td>
          <td style="padding:8px; border-bottom:1px solid #EDEDED; text-align:right;">' . $e($d['modelo']) . ' (' . $e($d['patente']) . ')</td></tr>
      <tr><td style="padding:8px; border-bottom:1px solid #EDEDED;">Presupuesto</td>
          <td style="padding:8px; border-bottom:1px solid #EDEDED; text-align:right;">#' . (int) $d['id_presupuesto'] . '</td></tr>
      <tr><td style="padding:8px; border-bottom:1px solid #EDEDED;">Operación de Mercado Pago</td>
          <td style="padding:8px; border-bottom:1px solid #EDEDED; text-align:right;">' . $e($numeroOperacion) . '</td></tr>
      <tr><td style="padding:8px; border-bottom:1px solid #EDEDED;">Fecha</td>
          <td style="padding:8px; border-bottom:1px solid #EDEDED; text-align:right;">' . $fecha . '</td></tr>
    </table>
    <p style="text-align:right; font-size:18px; font-weight:800; margin:8px 0 20px;">Total pagado: ' . $monto . '</p>
    <p style="color:#8A8A8A; font-size:13px; margin:0;">Trabajo #' . (int) $d['id_trabajo'] . '. Guardá este mail como comprobante.</p>
  </div>
</div>';

    return enviar_mail_brevo($d['email'], 'Comprobante de pago - Taller Gómez', $html);
}