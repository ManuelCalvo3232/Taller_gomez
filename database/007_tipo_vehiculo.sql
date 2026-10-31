-- Los vehículos pueden ser autos o motos.
-- Los registros existentes quedan como 'auto'.
ALTER TABLE vehiculos
  ADD COLUMN tipo ENUM('auto', 'moto') NOT NULL DEFAULT 'auto' AFTER patente,
  ADD COLUMN cilindrada SMALLINT UNSIGNED NULL AFTER tipo;