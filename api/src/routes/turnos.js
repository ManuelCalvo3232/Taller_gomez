import { Router } from 'express';
import { pool } from '../db.js';
import { HORARIOS, fechaValida } from '../lib/fechas.js';

const router = Router();

// GET /api/turnos/disponibles?fecha=2026-11-16
router.get('/disponibles', async (req, res, next) => {
  try {
    const { fecha } = req.query;

    if (!fechaValida(fecha)) {
      return res.status(400).json({ error: 'La fecha debe tener formato AAAA-MM-DD y ser válida' });
    }

    // Consulta parametrizada: la fecha nunca se concatena en el SQL
    const [ocupados] = await pool.execute(
      "SELECT DATE_FORMAT(fecha_hora, '%H:%i') AS hora FROM turnos WHERE DATE(fecha_hora) = ?",
      [fecha]
    );

    const ocupadosSet = new Set(ocupados.map((fila) => fila.hora));
    const libres = HORARIOS.filter((hora) => !ocupadosSet.has(hora));

    res.json({ fecha, horarios_libres: libres });
  } catch (err) {
    next(err);
  }
});

export default router;