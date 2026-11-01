import 'dotenv/config';
import express from 'express';
import helmet from 'helmet';
import rateLimit from 'express-rate-limit';
import turnosRouter from './routes/turnos.js';
import trabajosRouter from './routes/trabajos.js';
import dashboardRouter from './routes/dashboard.js';
import presupuestosRouter from './routes/presupuestos.js';

const app = express();

// Detrás de un proxy (Traefik en Dokploy), la IP real llega en X-Forwarded-For
if (process.env.TRUST_PROXY === '1') {
  app.set('trust proxy', 1);
}

// Cabeceras de seguridad. CORP en "cross-origin" para que el frontend en otro puerto pueda leer las respuestas
app.use(
  helmet({
    crossOriginResourcePolicy: { policy: 'cross-origin' },
  })
);

// Límite general: 100 pedidos por IP cada 15 minutos
app.use(
  rateLimit({
    windowMs: 15 * 60 * 1000,
    limit: 100,
    standardHeaders: 'draft-7',
    legacyHeaders: false,
  })
);

// CORS: solo los orígenes listados en CORS_ORIGENES (separados por coma)
const origenesPermitidos = (process.env.CORS_ORIGENES ?? 'http://localhost:8000')
  .split(',')
  .map((o) => o.trim());

app.use((req, res, next) => {
  const origen = req.get('Origin');
  if (origenesPermitidos.includes(origen)) {
    res.set('Access-Control-Allow-Origin', origen);
    res.set('Access-Control-Allow-Headers', 'Authorization, Content-Type');
    res.set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE');
  }
  if (req.method === 'OPTIONS') {
    return res.sendStatus(204);
  }
  next();
});

// Límite de tamaño para el cuerpo de los pedidos JSON
app.use(express.json({ limit: '10kb' }));

app.use('/api/turnos', turnosRouter);
app.use('/api/trabajos', trabajosRouter);
app.use('/api/dashboard', dashboardRouter);
app.use('/api/presupuestos', presupuestosRouter);

// Ruta que no existe
app.use((req, res) => {
  res.status(404).json({ error: 'Ruta no encontrada' });
});

// Manejo de errores: el detalle queda en el log del servidor, no en la respuesta
app.use((err, req, res, next) => {
  console.error(err);
  res.status(500).json({ error: 'Error interno' });
});

const port = Number(process.env.PORT) || 3000;
app.listen(port, () => {
  console.log(`API escuchando en el puerto ${port}`);
});