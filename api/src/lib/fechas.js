// Horarios de atención: de 08:00 a 17:30, cada 30 minutos
export const HORARIOS = [];
for (let h = 8; h < 18; h++) {
  const hh = String(h).padStart(2, '0');
  HORARIOS.push(`${hh}:00`, `${hh}:30`);
}

// Formato AAAA-MM-DD y que la fecha exista de verdad (no 2026-02-31)
export function fechaValida(fecha) {
  if (typeof fecha !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(fecha)) {
    return false;
  }
  const d = new Date(`${fecha}T00:00:00Z`);
  return !isNaN(d) && d.toISOString().slice(0, 10) === fecha;
}