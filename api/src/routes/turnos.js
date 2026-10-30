import { Router } from 'express';
import { pool } from '../db.js';

const router = Router();

// Horarios de atención: de 08:00 a 17:30, cada 30 minutos
const HORARIOS = [];
for (let h = 8; h < 18; h++) {
  const hh = String(h).padStart(2, '0');
  HORARIOS.push(`${hh}:00`, `${hh}:30`);
}

// Validar que la fecha tenga formato AAAA-MM-DD y exista de verdad (no 2026-02-31)
function fechaValida(fecha) {
  if (typeof fecha !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(fecha)) {
    return false;
  }
  const d = new Date(`${fecha}T00:00:00Z`);
  return !isNaN(d) && d.toISOString().slice(0, 10) === fecha;
}

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