import 'dotenv/config';
import mysql from 'mysql2/promise';

// Pool de conexiones: reutiliza conexiones en vez de abrir una por cada pedido
export const pool = mysql.createPool({
  host: process.env.DB_HOST ?? 'localhost',
  database: process.env.DB_NAME ?? 'taller_gomez',
  user: process.env.DB_USER,
  password: process.env.DB_PASS,
  charset: 'utf8mb4',
  waitForConnections: true,
  connectionLimit: 10,
  dateStrings: true
});