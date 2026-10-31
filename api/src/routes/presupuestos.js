import { Router } from 'express';
import { pool } from '../db.js';
import { requerirRol } from '../middleware/auth.js';
import { enviarMail, plantillaPresupuesto } from '../lib/email.js';

const router = Router();

// POST /api/presupuestos/:id/notificar: manda al cliente el detalle del presupuesto
router.post('/:id/notificar', requerirRol('mecanico'), async (req, res, next) => {
  try {
    const idPresupuesto = Number(req.params.id);
    if (!Number.isInteger(idPresupuesto) || idPresupuesto <= 0) {
      return res.status(400).json({ error: 'Presupuesto inválido' });
    }

    // Solo el mecánico asignado al trabajo puede avisar del presupuesto
    const [filas] = await pool.execute(
      `SELECT p.id_presupuesto, p.id_trabajo, p.total,
              v.modelo, v.patente, u.nombre, u.email
       FROM presupuestos p
       JOIN trabajos t ON t.id_trabajo = p.id_trabajo
       JOIN turnos tu ON tu.id_turno = t.id_turno
       JOIN vehiculos v ON v.id_vehiculo = tu.id_vehiculo
       JOIN usuarios u ON u.id_usuario = v.id_cliente
       WHERE p.id_presupuesto = ? AND t.id_mecanico = ?`,
      [idPresupuesto, req.usuario.id_usuario]
    );

    if (filas.length === 0) {
      return res.status(404).json({ error: 'Presupuesto no encontrado o no asignado a vos' });
    }
    const presupuesto = filas[0];

    const [items] = await pool.execute(
      `SELECT descripcion, cantidad, precio_unitario
       FROM items_presupuesto
       WHERE id_presupuesto = ?
       ORDER BY id_item`,
      [idPresupuesto]
    );

    const enviado = await enviarMail({
      idTrabajo: presupuesto.id_trabajo,
      destino: presupuesto.email,
      asunto: `Presupuesto para tu ${presupuesto.modelo}`,
      html: plantillaPresupuesto({
        nombre: presupuesto.nombre,
        modelo: presupuesto.modelo,
        patente: presupuesto.patente,
        idTrabajo: presupuesto.id_trabajo,
        idPresupuesto,
        total: presupuesto.total,
        items,
      }),
    });

    res.json({ enviado });
  } catch (err) {
    next(err);
  }
});

export default router;