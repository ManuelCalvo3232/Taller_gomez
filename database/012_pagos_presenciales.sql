-- Pagos cobrados en el taller (efectivo o transferencia), registrados por el mecánico.
-- Los pagos anteriores quedan como 'mercadopago'.
ALTER TABLE pagos
  ADD COLUMN metodo ENUM('mercadopago', 'efectivo', 'transferencia') NOT NULL DEFAULT 'mercadopago' AFTER monto,
  ADD COLUMN id_registrador INT UNSIGNED NULL AFTER metodo,
  ADD CONSTRAINT fk_pagos_registrador FOREIGN KEY (id_registrador) REFERENCES usuarios (id_usuario)
    ON DELETE SET NULL;