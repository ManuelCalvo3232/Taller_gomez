import crypto from 'node:crypto';
import { pool } from '../db.js';

// Verifica el token del header "Authorization: Bearer <token>" y, opcionalmente, el rol.
// Uso: router.get('/ruta', requerirRol('mecanico'), handler)
export function requerirRol(...roles) {
  return async (req, res, next) => {
    try {
      const header = req.get('Authorization') ?? '';
      const token = header.startsWith('Bearer ') ? header.slice(7) : '';

      if (!token) {
        return res.status(401).json({ error: 'Falta el token' });
      }

      // Mismo hash que en PHP: sha256 en hexadecimal
      const hash = crypto.createHash('sha256').update(token).digest('hex');

      // El rol se lee de usuarios, no del token: si cambia, rige al instante
      const [filas] = await pool.execute(
        `SELECT u.id_usuario, u.nombre, u.rol
         FROM tokens_api t
         JOIN usuarios u ON u.id_usuario = t.id_usuario
         WHERE t.token_hash = ? AND t.expira_en > NOW()`,
        [hash]
      );

      if (filas.length === 0) {
        return res.status(401).json({ error: 'Token inválido o vencido' });
      }

      const usuario = filas[0];

      if (roles.length > 0 && !roles.includes(usuario.rol)) {
        return res.status(403).json({ error: 'No tenés permiso para esta acción' });
      }

      req.usuario = usuario;
      next();
    } catch (err) {
      next(err);
    }
  };
}