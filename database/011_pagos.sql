-- Cobros de los presupuestos aprobados (Mercado Pago, sandbox)
CREATE TABLE pagos (
  id_pago         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_presupuesto  INT UNSIGNED  NOT NULL,
  monto           DECIMAL(12,2) NOT NULL,
  estado          ENUM('pendiente', 'aprobado', 'rechazado') NOT NULL DEFAULT 'pendiente',
  preference_id   VARCHAR(100)  NULL,
  mp_payment_id   VARCHAR(50)   NULL UNIQUE,
  creado_en       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en  TIMESTAMP     NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_pagos_monto CHECK (monto >= 0),
  INDEX idx_pagos_presupuesto (id_presupuesto),
  CONSTRAINT fk_pagos_presupuesto FOREIGN KEY (id_presupuesto) REFERENCES presupuestos (id_presupuesto)
    ON DELETE CASCADE
) ENGINE = InnoDB;