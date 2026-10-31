import { Resend } from 'resend';
import { pool } from '../db.js';

// Sin RESEND_API_KEY no se envía nada, pero el intento queda registrado como fallido
const resend = process.env.RESEND_API_KEY ? new Resend(process.env.RESEND_API_KEY) : null;

// Remitente. Con onboarding@resend.dev solo llega a la dirección de la cuenta de Resend
const remitente = process.env.MAIL_FROM ?? 'Taller Gómez <onboarding@resend.dev>';

// URL pública de la app (la que el cliente abre desde el mail)
const appUrl = (process.env.APP_URL ?? 'http://localhost:8000').replace(/\/$/, '');

// Escapa los datos del usuario antes de meterlos en el HTML del mail
function escapar(texto) {
  return String(texto).replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  })[c]);
}

function pesos(valor) {
  return '$ ' + Number(valor).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// Encabezado y pie comunes a todos los mails del taller
function envoltorio(contenido) {
  return `
  <div style="font-family:Inter,Arial,sans-serif; max-width:560px; margin:0 auto; background:#F2F2F2; padding:24px;">
    <div style="background:#171717; color:#FFFFFF; padding:16px 20px; border-left:4px solid #E63946; font-weight:800; letter-spacing:.08em;">
      TALLER GÓMEZ
    </div>
    <div style="background:#FFFFFF; padding:24px 20px;">
      ${contenido}
    </div>
  </div>`;
}

export function plantillaListo({ nombre, modelo, patente, idTrabajo }) {
  return envoltorio(`
      <h2 style="margin:0 0 12px; color:#171717;">Tu vehículo está listo</h2>
      <p style="color:#171717;">Hola ${escapar(nombre)}, tu ${escapar(modelo)} (${escapar(patente)}) ya está listo para retirar.</p>
      <p style="color:#8A8A8A; font-size:13px; margin-bottom:0;">Trabajo #${Number(idTrabajo)}</p>
  `);
}

export function plantillaPresupuesto({ nombre, modelo, patente, idTrabajo, idPresupuesto, total, items }) {
  const filas = items.map((it) => `
        <tr>
          <td style="padding:8px; border-bottom:1px solid #EDEDED;">${escapar(it.descripcion)}</td>
          <td style="padding:8px; border-bottom:1px solid #EDEDED; text-align:right;">${Number(it.cantidad)}</td>
          <td style="padding:8px; border-bottom:1px solid #EDEDED; text-align:right;">${pesos(it.precio_unitario)}</td>
          <td style="padding:8px; border-bottom:1px solid #EDEDED; text-align:right;">${pesos(it.cantidad * it.precio_unitario)}</td>
        </tr>`).join('');

  return envoltorio(`
      <h2 style="margin:0 0 12px; color:#171717;">Presupuesto para tu vehículo</h2>
      <p style="color:#171717;">Hola ${escapar(nombre)}, te enviamos el presupuesto para tu ${escapar(modelo)} (${escapar(patente)}).</p>

      <table style="width:100%; border-collapse:collapse; margin:16px 0; font-size:14px; color:#171717;">
        <tr style="background:#171717; color:#FFFFFF;">
          <th style="padding:10px 8px; text-align:left;">Descripción</th>
          <th style="padding:10px 8px; text-align:right;">Cant.</th>
          <th style="padding:10px 8px; text-align:right;">Precio</th>
          <th style="padding:10px 8px; text-align:right;">Subtotal</th>
        </tr>${filas}
      </table>

      <p style="text-align:right; font-size:18px; font-weight:800; color:#171717; margin:8px 0 20px;">
        Total: ${pesos(total)}
      </p>

      <a href="${escapar(appUrl)}/presupuestos.php"
         style="display:block; text-align:center; background:#E63946; color:#FFFFFF; text-decoration:none; font-weight:700; padding:14px; border-radius:8px;">
        Revisar y aprobar
      </a>

      <p style="color:#8A8A8A; font-size:13px; margin:16px 0 0;">
        Para aprobar o rechazar el presupuesto, ingresá con tu cuenta. Trabajo #${Number(idTrabajo)} · Presupuesto #${Number(idPresupuesto)}
      </p>
  `);
}

// Envía el mail y deja registro en la tabla notificaciones. Devuelve true si salió bien
export async function enviarMail({ idTrabajo, destino, asunto, html }) {
  let estado = 'enviado';
  let detalle = null;

  try {
    if (!resend) {
      throw new Error('RESEND_API_KEY no configurada');
    }
    const { error } = await resend.emails.send({
      from: remitente,
      to: destino,
      subject: asunto,
      html,
    });
    if (error) {
      throw new Error(error.message);
    }
  } catch (err) {
    estado = 'fallido';
    detalle = err.message.slice(0, 255);
    console.error('Mail no enviado:', err.message);
  }

  await pool.execute(
    "INSERT INTO notificaciones (id_trabajo, destino, asunto, estado, detalle, creado_en) VALUES (?, ?, ?, ?, ?, NOW())",
    [idTrabajo, destino, asunto.slice(0, 150), estado, detalle]
  );

  return estado === 'enviado';
}