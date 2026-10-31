import { Resend } from 'resend';
import { pool } from '../db.js';

// Sin RESEND_API_KEY no se envía nada, pero el intento queda registrado como fallido
const resend = process.env.RESEND_API_KEY ? new Resend(process.env.RESEND_API_KEY) : null;

// Remitente. Con onboarding@resend.dev solo llega a la dirección de la cuenta de Resend
const remitente = process.env.MAIL_FROM ?? 'Taller Gómez <onboarding@resend.dev>';

// Escapa los datos del usuario antes de meterlos en el HTML del mail
function escapar(texto) {
  return String(texto).replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  })[c]);
}

export function plantillaListo({ nombre, modelo, patente, idTrabajo }) {
  return `
  <div style="font-family:Inter,Arial,sans-serif; max-width:520px; margin:0 auto; background:#F2F2F2; padding:24px;">
    <div style="background:#171717; color:#FFFFFF; padding:16px 20px; border-left:4px solid #E63946; font-weight:800; letter-spacing:.08em;">
      TALLER GÓMEZ
    </div>
    <div style="background:#FFFFFF; padding:24px 20px;">
      <h2 style="margin:0 0 12px; color:#171717;">Tu vehículo está listo</h2>
      <p style="color:#171717;">Hola ${escapar(nombre)}, tu ${escapar(modelo)} (${escapar(patente)}) ya está listo para retirar.</p>
      <p style="color:#8A8A8A; font-size:13px; margin-bottom:0;">Trabajo #${Number(idTrabajo)}</p>
    </div>
  </div>`;
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