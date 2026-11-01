-- Tokens que PHP entrega al navegador para llamar a la API de Node.
-- Se guarda solo el hash SHA-256 del token, nunca el token en texto plano.
USE taller_gomez;

CREATE TABLE tokens_api (
  token_hash  CHAR(64) NOT NULL PRIMARY KEY,
  id_usuario  INT UNSIGNED NOT NULL,
  creado_en   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_en   DATETIME NOT NULL,
  CONSTRAINT fk_tokens_usuario
    FOREIGN KEY (id_usuario) REFERENCES usuarios (id_usuario)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE = InnoDB;

CREATE INDEX idx_tokens_expira ON tokens_api (expira_en);