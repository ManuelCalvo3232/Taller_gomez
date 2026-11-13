import { pool } from '../db.js';

// Envío de mails con la API transaccional de Brevo
const BREVO_URL = 'https://api.brevo.com/v3/smtp/email';
const apiKey = process.env.BREVO_API_KEY;

// Remitente. Su dirección tiene que estar verificada en Brevo (Senders)
const remitente = {
  email: process.env.MAIL_FROM_EMAIL,
  name: process.env.MAIL_FROM_NAME ?? 'Taller Gómez',
};

function escapar(texto) {
  return String(texto).replace(/[&<>"']/g, (c) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
  })[c]);
}

function pesos(valor) {
  return '$ ' + Number(valor).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// Encabezado y pie comunes en sintonía con la web Dark Graphite & Orange de Taller Gómez
function envoltorio({ kicker, titulo, cuerpo, pie = 'Taller Gómez · Tu vehículo, con presupuesto antes de tocar nada.' }) {
  return `<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>
<body style="margin:0; padding:0; background-color:#0a0a0c; font-family:'Plus Jakarta Sans','Inter',Arial,sans-serif; color:#f9fafb;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#0a0a0c; padding:28px 14px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:580px; background-color:#16191f; border:1px solid #262b35; border-radius:16px; overflow:hidden;">
          <tr>
            <td style="background-color:#111317; padding:18px 24px; border-bottom:2px solid #ea580c;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td style="vertical-align:middle;">
                    <span style="display:inline-block; background-color:#ea580c; color:#ffffff; font-weight:800; font-size:13px; padding:6px 9px; border-radius:7px; letter-spacing:0.02em; vertical-align:middle;">TG</span>
                    <span style="display:inline-block; color:#ffffff; font-weight:800; font-size:15px; letter-spacing:0.12em; margin-left:10px; vertical-align:middle;">TALLER GÓMEZ</span>
                  </td>
                  <td align="right" style="vertical-align:middle;">
                    <span style="display:inline-block; background-color:#1c2028; border:1px solid #374151; color:#fb923c; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.06em; padding:4px 10px; border-radius:999px;">${escapar(kicker)}</span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:28px 24px; color:#f9fafb; font-size:15px; line-height:1.6;">
              <h1 style="margin:0 0 14px; font-size:22px; line-height:1.25; font-weight:800; color:#ffffff; letter-spacing:-0.02em;">${escapar(titulo)}</h1>
              ${cuerpo}
            </td>
          </tr>
          <tr>
            <td style="background-color:#111317; padding:16px 24px; border-top:1px solid #262b35; font-size:12px; color:#6b7280; line-height:1.5;">
              ${pie}
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>`;
}

export function plantillaListo({ nombre, modelo, patente, idTrabajo }) {
  const appUrl = (process.env.APP_URL ?? 'http://localhost:8000').replace(/\/$/, '');
  return envoltorio({
    kicker: 'Listo para retirar',
    titulo: '¡Tu vehículo está listo!',
    cuerpo: `
      <p style="margin:0 0 16px; color:#d1d5db;">
        Hola <strong style="color:#ffffff;">${escapar(nombre)}</strong>, terminamos los trabajos sobre tu
        <strong style="color:#ffffff;">${escapar(modelo)}</strong>
        <span style="display:inline-block; margin-left:4px; background-color:#f8fafc; color:#090d16; font-family:monospace; font-size:12px; font-weight:800; padding:2px 7px; border-radius:4px;">${escapar(patente)}</span>.
      </p>

      <div style="margin:0 0 18px;">
        <span style="display:inline-block; background-color:rgba(16,185,129,0.14); border:1px solid rgba(16,185,129,0.35); color:#6ee7b7; font-size:12px; font-weight:700; padding:6px 12px; border-radius:8px;">
          ✓ Control final aprobado · Listo para entrega
        </span>
      </div>

      <div style="background-color:#0d0f13; border:1px solid #262b35; border-radius:10px; padding:14px 16px; margin:0 0 20px; font-size:13px; color:#9ca3af; line-height:1.6;">
        Podés pasar a retirarlo por el taller. Si todavía tenés saldo pendiente, podés abonarlo online desde tu cuenta o presencialmente al retirar.
      </div>

      <a href="${escapar(appUrl + '/panel')}"
         style="display:block; background-color:#ea580c; color:#ffffff; text-decoration:none; font-weight:700; font-size:15px; padding:14px 20px; border-radius:10px; text-align:center;">
        Ver mi portal de cliente
      </a>`,
    pie: `Orden de trabajo #${Number(idTrabajo)} · Taller Gómez`,
  });
}

export function plantillaPresupuesto({ nombre, modelo, patente, idTrabajo, idPresupuesto, total, items, urlAprobar }) {
  const filas = items.map((it) => `
    <tr>
      <td style="padding:11px 12px; border-bottom:1px solid #262b35; color:#f9fafb;">${escapar(it.descripcion)}</td>
      <td style="padding:11px 12px; border-bottom:1px solid #262b35; text-align:right; color:#d1d5db; font-family:monospace;">${Number(it.cantidad)}</td>
      <td style="padding:11px 12px; border-bottom:1px solid #262b35; text-align:right; color:#d1d5db; font-family:monospace;">${pesos(it.precio_unitario)}</td>
      <td style="padding:11px 12px; border-bottom:1px solid #262b35; text-align:right; color:#ffffff; font-weight:700; font-family:monospace;">${pesos(it.cantidad * it.precio_unitario)}</td>
    </tr>`).join('');

  return envoltorio({
    kicker: `Presupuesto #${Number(idPresupuesto)}`,
    titulo: 'Presupuesto para tu vehículo',
    cuerpo: `
      <p style="margin:0 0 18px; color:#d1d5db;">
        Hola <strong style="color:#ffffff;">${escapar(nombre)}</strong>, ya revisamos tu
        <strong style="color:#ffffff;">${escapar(modelo)}</strong>
        <span style="display:inline-block; margin-left:4px; background-color:#f8fafc; color:#090d16; font-family:monospace; font-size:12px; font-weight:800; padding:2px 7px; border-radius:4px;">${escapar(patente)}</span>.
        Necesitamos tu aprobación antes de comenzar:
      </p>

      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#0d0f13; border:1px solid #262b35; border-radius:12px; overflow:hidden; margin:0 0 18px; font-size:13px;">
        <tr style="background-color:#111317;">
          <th style="padding:10px 12px; text-align:left; color:#9ca3af; font-size:11px; text-transform:uppercase; letter-spacing:0.05em; border-bottom:1px solid #262b35;">Descripción</th>
          <th style="padding:10px 12px; text-align:right; color:#9ca3af; font-size:11px; text-transform:uppercase; letter-spacing:0.05em; border-bottom:1px solid #262b35;">Cant.</th>
          <th style="padding:10px 12px; text-align:right; color:#9ca3af; font-size:11px; text-transform:uppercase; letter-spacing:0.05em; border-bottom:1px solid #262b35;">Precio</th>
          <th style="padding:10px 12px; text-align:right; color:#9ca3af; font-size:11px; text-transform:uppercase; letter-spacing:0.05em; border-bottom:1px solid #262b35;">Subtotal</th>
        </tr>
        ${filas}
      </table>

      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#111317; border:1px solid rgba(234,88,12,0.45); border-left:3px solid #ea580c; border-radius:10px; margin:0 0 22px;">
        <tr>
          <td style="padding:14px 16px; color:#fdba74; font-size:13px; text-transform:uppercase; font-weight:700; letter-spacing:0.05em;">Total presupuesto</td>
          <td style="padding:14px 16px; text-align:right; color:#ffffff; font-family:monospace; font-size:21px; font-weight:800;">${pesos(total)}</td>
        </tr>
      </table>

      <a href="${escapar(urlAprobar)}"
         style="display:block; background-color:#ea580c; color:#ffffff; text-decoration:none; font-weight:700; font-size:15px; padding:14px 20px; border-radius:10px; text-align:center;">
        Revisar y aprobar presupuesto
      </a>`,
    pie: `El enlace vence en 7 días y es de un solo uso. Trabajo #${Number(idTrabajo)} · Presupuesto #${Number(idPresupuesto)}.`,
  });
}

// Envía el mail con Brevo y deja registro en la tabla notificaciones. Devuelve true si salió bien
export async function enviarMail({ idTrabajo, destino, asunto, html }) {
  let estado = 'enviado';
  let detalle = null;

  try {
    if (!apiKey || !remitente.email) {
      throw new Error('Falta BREVO_API_KEY o MAIL_FROM_EMAIL');
    }

    const respuesta = await fetch(BREVO_URL, {
      method: 'POST',
      headers: {
        'api-key': apiKey,
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify({
        sender: remitente,
        to: [{ email: destino }],
        subject: asunto,
        htmlContent: html,
      }),
      signal: AbortSignal.timeout(10000),
    });

    if (!respuesta.ok) {
      const cuerpo = await respuesta.json().catch(() => ({}));
      throw new Error(`Brevo HTTP ${respuesta.status}: ${cuerpo.message ?? 'sin detalle'}`);
    }
  } catch (err) {
    estado = 'fallido';
    detalle = err.message.slice(0, 255);
    console.error('Mail no enviado:', err.message);
  }

  await pool.execute(
    'INSERT INTO notificaciones (id_trabajo, destino, asunto, estado, detalle, creado_en) VALUES (?, ?, ?, ?, ?, NOW())',
    [idTrabajo, destino, asunto.slice(0, 150), estado, detalle]
  );

  return estado === 'enviado';
}
