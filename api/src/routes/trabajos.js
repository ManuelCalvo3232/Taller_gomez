import { Router } from 'express';
import { pool } from '../db.js';
import { requerirRol } from '../middleware/auth.js';

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

export default router;