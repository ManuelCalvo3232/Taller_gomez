import { Router } from 'express';
import { createHash, randomBytes } from 'node:crypto';
import { pool } from '../db.js';
import { requerirRol } from '../middleware/auth.js';
import { enviarMail, plantillaPresupuesto } from '../lib/email.js';

const router = Router();

const appUrl = (process.env.APP_URL ?? 'http://localhost:8000').replace(/\/$/, '');
const DIAS_VALIDEZ = 7;

// POST /api/presupuestos/:id/notificar: manda al cliente el detalle y un link para aprobar o rechazar
router.post('/:id/notificar', requerirRol('mecanico'), async (req, res, next) => {
  try {
    const idPresupuesto = Number(req.params.id);
    if (!Number.isInteger(idPresupuesto) || idPresupuesto <= 0) {
      return res.status(400).json({ error: 'Presupuesto inválido' });
    }

    // Solo el mecánico asignado al trabajo puede avisar del presupuesto
    const [filas] = await pool.execute(
      `SELECT p.id_presupuesto, p.id_trabajo, p.total, p.estado,
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

    if (presupuesto.estado !== 'enviado') {
      return res.status(409).json({ error: 'El presupuesto ya fue respondido por el cliente' });
    }

    const [items] = await pool.execute(
      `SELECT descripcion, cantidad, precio_unitario
       FROM items_presupuesto
       WHERE id_presupuesto = ?
       ORDER BY id_item`,
      [idPresupuesto]
    );

    // Token de un solo uso. En la base solo se guarda su hash.
    // Al reenviar, se invalidan los links anteriores que no se usaron.
    const token = randomBytes(32).toString('hex');
    const hash = createHash('sha256').update(token).digest('hex');

    await pool.execute(
      'DELETE FROM tokens_presupuesto WHERE id_presupuesto = ? AND usado_en IS NULL',
      [idPresupuesto]
    );
    await pool.execute(
      'INSERT INTO tokens_presupuesto (token_hash, id_presupuesto, creado_en, expira_en) VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? DAY))',
      [hash, idPresupuesto, DIAS_VALIDEZ]
    );

    const urlAprobar = `${appUrl}/presupuesto_aprobar.php?token=${token}`;

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
        urlAprobar,
      }),
    });

    res.json({ enviado });
  } catch (err) {
    next(err);
  }
});

export default router;