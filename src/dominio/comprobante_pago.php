<?php

require_once __DIR__ . '/../integraciones/brevo.php';

// Mail al cliente con el detalle de un pago aprobado.
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
    $fecha = date('d/m/Y · H:i');
    $appUrl = rtrim(getenv('APP_URL') ?: 'http://localhost:8000', '/');

    $contenido = '
      <p style="margin:0 0 16px; color:#d1d5db;">
        Hola <strong style="color:#ffffff;">' . $e($d['nombre']) . '</strong>, acreditamos correctamente tu pago. Este es tu comprobante:
      </p>

      <div style="margin:0 0 18px;">
        <span style="display:inline-block; background-color:rgba(16,185,129,0.14); border:1px solid rgba(16,185,129,0.35); color:#6ee7b7; font-size:12px; font-weight:700; padding:5px 11px; border-radius:7px;">
          ✓ Pago aprobado
        </span>
      </div>

      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#0d0f13; border:1px solid #262b35; border-radius:12px; overflow:hidden; font-size:14px; margin:0 0 18px;">
        <tr>
          <td style="padding:12px 14px; border-bottom:1px solid #262b35; color:#9ca3af;">Vehículo</td>
          <td style="padding:12px 14px; border-bottom:1px solid #262b35; text-align:right; color:#ffffff; font-weight:700;">
            ' . $e($d['modelo']) . '
            <span style="display:inline-block; margin-left:6px; background-color:#f8fafc; color:#090d16; font-family:monospace; font-size:12px; font-weight:800; padding:2px 7px; border-radius:4px;">' . $e($d['patente']) . '</span>
          </td>
        </tr>
        <tr>
          <td style="padding:12px 14px; border-bottom:1px solid #262b35; color:#9ca3af;">Presupuesto</td>
          <td style="padding:12px 14px; border-bottom:1px solid #262b35; text-align:right; color:#ffffff; font-family:monospace;">#' . (int) $d['id_presupuesto'] . '</td>
        </tr>
        <tr>
          <td style="padding:12px 14px; border-bottom:1px solid #262b35; color:#9ca3af;">Operación Mercado Pago</td>
          <td style="padding:12px 14px; border-bottom:1px solid #262b35; text-align:right; color:#ffffff; font-family:monospace;">' . $e($numeroOperacion) . '</td>
        </tr>
        <tr>
          <td style="padding:12px 14px; color:#9ca3af;">Fecha y hora</td>
          <td style="padding:12px 14px; text-align:right; color:#ffffff;">' . $fecha . ' hs</td>
        </tr>
      </table>

      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#111317; border:1px solid #262b35; border-left:3px solid #10b981; border-radius:10px; margin:0 0 20px;">
        <tr>
          <td style="padding:14px 16px; color:#9ca3af; font-size:13px; text-transform:uppercase; font-weight:700; letter-spacing:0.05em;">Total abonado</td>
          <td style="padding:14px 16px; text-align:right; color:#ffffff; font-family:monospace; font-size:20px; font-weight:800;">' . $monto . '</td>
        </tr>
      </table>

      <a href="' . $e($appUrl . '/historial') . '"
         style="display:block; background-color:#1c2028; border:1px solid #374151; color:#ffffff; text-decoration:none; font-weight:700; font-size:14px; padding:12px 18px; border-radius:10px; text-align:center;">
        Ver mi historial de servicios
      </a>';

    $pie = 'Orden de trabajo #' . (int) $d['id_trabajo'] . ' · Guardá este email como comprobante de pago.';
    $html = plantilla_mail_taller('Comprobante', 'Pago acreditado', $contenido, $pie);

    return enviar_mail_brevo($d['email'], 'Comprobante de pago · Taller Gómez', $html);
}

// Aviso al cliente cuando aprueba un presupuesto: le dice que ya puede pagarlo, online o en el taller
function enviar_aviso_pago_pendiente(PDO $pdo, int $idPresupuesto): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT p.total, v.modelo, v.patente, u.nombre, u.email
             FROM presupuestos p
             JOIN trabajos t ON t.id_trabajo = p.id_trabajo
             JOIN turnos tu ON tu.id_turno = t.id_turno
             JOIN vehiculos v ON v.id_vehiculo = tu.id_vehiculo
             JOIN usuarios u ON u.id_usuario = v.id_cliente
             WHERE p.id_presupuesto = ?'
        );
        $stmt->execute([$idPresupuesto]);
        $d = $stmt->fetch();
        if (!$d) {
            return false;
        }

        $e = fn (string $texto): string => htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
        $monto = '$ ' . number_format((float) $d['total'], 2, ',', '.');
        $appUrl = rtrim(getenv('APP_URL') ?: 'http://localhost:8000', '/');

        $contenido = '
          <p style="margin:0 0 16px; color:#d1d5db;">
            Hola <strong style="color:#ffffff;">' . $e($d['nombre']) . '</strong>, recibimos tu aprobación para tu
            <strong style="color:#ffffff;">' . $e($d['modelo']) . '</strong>
            <span style="display:inline-block; margin-left:4px; background-color:#f8fafc; color:#090d16; font-family:monospace; font-size:12px; font-weight:800; padding:2px 7px; border-radius:4px;">' . $e($d['patente']) . '</span>.
            El mecánico ya puede avanzar con la reparación.
          </p>

          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#111317; border:1px solid rgba(234,88,12,0.45); border-left:3px solid #ea580c; border-radius:10px; margin:0 0 18px;">
            <tr>
              <td style="padding:14px 16px; color:#fdba74; font-size:13px; text-transform:uppercase; font-weight:700; letter-spacing:0.05em;">Total a abonar</td>
              <td style="padding:14px 16px; text-align:right; color:#ffffff; font-family:monospace; font-size:20px; font-weight:800;">' . $monto . '</td>
            </tr>
          </table>

          <div style="background-color:#0d0f13; border:1px solid #262b35; border-radius:10px; padding:14px 16px; margin:0 0 20px; font-size:13px; color:#9ca3af; line-height:1.6;">
            <strong style="color:#ffffff; display:block; margin-bottom:4px;">Formas de pago disponibles:</strong>
            • Online con <strong style="color:#ffffff;">Mercado Pago</strong> desde tu cuenta.<br>
            • Al retirar en el taller con <strong style="color:#ffffff;">Efectivo</strong> o <strong style="color:#ffffff;">Transferencia</strong> (Alias: <span style="color:#fb923c; font-family:monospace;">taller.gomez.ar</span>).
          </div>

          <a href="' . $e($appUrl . '/presupuestos') . '"
             style="display:block; background-color:#ea580c; color:#ffffff; text-decoration:none; font-weight:700; font-size:15px; padding:14px 20px; border-radius:10px; text-align:center;">
            Ir a Mis Presupuestos
          </a>';

        $pie = 'Presupuesto #' . (int) $idPresupuesto . ' · Podés seguir el avance en tiempo real desde tu panel.';
        $html = plantilla_mail_taller('Presupuesto aprobado', 'Aprobaste el presupuesto', $contenido, $pie);

        return enviar_mail_brevo($d['email'], 'Presupuesto aprobado · podés pagarlo · Taller Gómez', $html);
    } catch (PDOException $e) {
        error_log('aviso_pago_pendiente: ' . $e->getMessage());
        return false;
    }
}
