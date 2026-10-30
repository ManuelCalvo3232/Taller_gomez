import 'dotenv/config';
import express from 'express';
import turnosRouter from './routes/turnos.js';
import trabajosRouter from './routes/trabajos.js';

const app = express();

// Lee el cuerpo de los pedidos en formato JSON
app.use(express.json());

app.use('/api/turnos', turnosRouter);
app.use('/api/trabajos', trabajosRouter);

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