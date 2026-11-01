-- Apellido del usuario y fecha de verificación del email
ALTER TABLE usuarios
  ADD COLUMN apellido VARCHAR(100) NOT NULL DEFAULT '' AFTER nombre,
  ADD COLUMN email_verificado_en DATETIME NULL AFTER rol;

-- Las cuentas que ya existían se dan por verificadas, para no bloquear a nadie
UPDATE usuarios SET email_verificado_en = NOW() WHERE email_verificado_en IS NULL;

-- Tokens de un solo uso para confirmar el email (solo se guarda el hash)
CREATE TABLE tokens_verificacion (
  token_hash  CHAR(64) PRIMARY KEY,
  id_usuario  INT UNSIGNED NOT NULL,
  creado_en   DATETIME NOT NULL,
  expira_en   DATETIME NOT NULL,
  usado_en    DATETIME NULL,
  INDEX idx_tokens_verif_usuario (id_usuario),
  CONSTRAINT fk_tokens_verif_usuario
    FOREIGN KEY (id_usuario) REFERENCES usuarios (id_usuario)
    ON DELETE CASCADE
) ENGINE = InnoDB;