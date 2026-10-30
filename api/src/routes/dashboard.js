import { Router } from 'express';
import { pool } from '../db.js';
import { requerirRol } from '../middleware/auth.js';

const router = Router();

// GET /api/dashboard/resumen: métricas de caja, facturación y trabajo por mecánico
router.get('/resumen', requerirRol('admin'), async (req, res, next) => {
  try {
    const [facturacion] = await pool.execute(
      `SELECT DATE_FORMAT(fecha_respuesta, '%Y-%m') AS mes, SUM(total) AS total
       FROM presupuestos
       WHERE estado = 'aprobado'
       GROUP BY mes
       ORDER BY mes`
    );

    const [porMecanico] = await pool.execute(
      `SELECT u.nombre, COUNT(*) AS trabajos
       FROM trabajos t
       JOIN usuarios u ON u.id_usuario = t.id_mecanico
       WHERE t.estado = 'listo'
       GROUP BY u.id_usuario, u.nombre
       ORDER BY trabajos DESC`
    );

    const [[caja]] = await pool.execute(
      "SELECT COALESCE(SUM(total), 0) AS total FROM presupuestos WHERE estado = 'aprobado'"
    );

    // mysql2 devuelve DECIMAL como texto: lo convertimos a número para el JSON
    res.json({
      caja: Number(caja.total),
      facturacion_mensual: facturacion.map((f) => ({
        mes: f.mes,
        total: Number(f.total),
      })),
      trabajos_por_mecanico: porMecanico.map((m) => ({
        nombre: m.nombre,
        trabajos: Number(m.trabajos),
      })),
    });
  } catch (err) {
    next(err);
  }
});

export default router;