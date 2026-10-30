import 'dotenv/config';
import express from 'express';
import turnosRouter from './routes/turnos.js';
import trabajosRouter from './routes/trabajos.js';
import dashboardRouter from './routes/dashboard.js';

const app = express();

// Lee el cuerpo de los pedidos en formato JSON
app.use(express.json());

     const ORIGENES_PERMITIDOS = (process.env.CORS_ORIGENES ?? 'http://localhost:8000').split(',').map((o) => o.trim());

   app.use((req, res, next) => {
     const origen = req.get('Origin');
     if (ORIGENES_PERMITIDOS.includes(origen)) {
       res.set('Access-Control-Allow-Origin', origen);
       res.set('Access-Control-Allow-Headers', 'Authorization, Content-Type');
       res.set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE');
     }
     if (req.method === 'OPTIONS') return res.sendStatus(204);
     next();
   });

app.use('/api/turnos', turnosRouter);
app.use('/api/trabajos', trabajosRouter);
app.use('/api/dashboard', dashboardRouter);

// Ruta que no existe
app.use((req, res) => {
  res.status(404).json({ error: 'Ruta no encontrada' });
});

// Manejo de errores: nunca mostramos detalles internos al cliente
app.use((err, req, res, next) => {
  console.error(err);
  res.status(500).json({ error: 'Error interno' });
});

const port = Number(process.env.PORT) || 3000;
app.listen(port, () => {
  console.log(`La api esta escuchando en http://localhost:${port}`);
});