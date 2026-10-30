-- Registro de intentos fallidos de login, para limitar la fuerza bruta.
USE taller_gomez;

CREATE TABLE intentos_login (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip         VARCHAR(45) NOT NULL,
  creado_en  DATETIME    NOT NULL
) ENGINE = InnoDB;

CREATE INDEX idx_intentos_ip_fecha ON intentos_login (ip, creado_en);