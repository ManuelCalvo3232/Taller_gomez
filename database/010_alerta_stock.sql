-- Registro de las alertas de stock bajo enviadas al administrador (sirve para no repetirlas seguido)
CREATE TABLE alertas_stock (
  id_alerta           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_usuario          INT UNSIGNED NOT NULL,
  cantidad_repuestos  INT UNSIGNED NOT NULL,
  creado_en           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_alertas_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios (id_usuario)
    ON DELETE RESTRICT,
  INDEX idx_alertas_fecha (creado_en)
) ENGINE = InnoDB;