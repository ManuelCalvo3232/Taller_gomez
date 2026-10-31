import { Router } from 'express';
import { pool } from '../db.js';
import { requerirRol } from '../middleware/auth.js';
import { enviarMail, plantillaListo } from '../lib/email.js';

const router = Router();

// GET /api/trabajos/mios: trabajos activos asignados al mecánico logueado
router.get('/mios', requerirRol('mecanico'), async (req, res, next) => {
  try {
    const [trabajos] = await pool.execute(
      `SELECT t.id_trabajo, t.estado, tu.fecha_hora, tu.motivo,
              v.patente, v.modelo, v.anio, v.kilometros
       FROM trabajos t
       JOIN turnos tu ON tu.id_turno = t.id_turno
       JOIN vehiculos v ON v.id_vehiculo = tu.id_vehiculo
       WHERE t.id_mecanico = ? AND t.estado NOT IN ('listo', 'rechazado')
       ORDER BY tu.fecha_hora`,
      [req.usuario.id_usuario]
    );
    res.json({ trabajos });
  } catch (err) {
    next(err);
  }
});

// POST /api/trabajos/:id/notificar-listo: manda el mail al cliente cuando el auto está listo
router.post('/:id/notificar-listo', requerirRol('mecanico'), async (req, res, next) => {
  try {
    const idTrabajo = Number(req.params.id);
    if (!Number.isInteger(idTrabajo) || idTrabajo <= 0) {
      return res.status(400).json({ error: 'Trabajo inválido' });
    }

    // Solo el mecánico asignado puede disparar el aviso de su trabajo
    const [filas] = await pool.execute(
      `SELECT t.estado, v.modelo, v.patente, u.nombre, u.email
       FROM trabajos t
       JOIN turnos tu ON tu.id_turno = t.id_turno
       JOIN vehiculos v ON v.id_vehiculo = tu.id_vehiculo
       JOIN usuarios u ON u.id_usuario = v.id_cliente
       WHERE t.id_trabajo = ? AND t.id_mecanico = ?`,
      [idTrabajo, req.usuario.id_usuario]
    );

    if (filas.length === 0) {
      return res.status(404).json({ error: 'Trabajo no encontrado o no asignado a vos' });
    }

    const trabajo = filas[0];
    if (trabajo.estado !== 'listo') {
      return res.status(409).json({ error: 'El trabajo todavía no está listo' });
    }

    const enviado = await enviarMail({
      idTrabajo,
      destino: trabajo.email,
      asunto: 'Tu vehículo está listo',
      html: plantillaListo({
        nombre: trabajo.nombre,
        modelo: trabajo.modelo,
        patente: trabajo.patente,
        idTrabajo,
      }),
    });

    res.json({ enviado });
  } catch (err) {
    next(err);
  }
});

export default router;