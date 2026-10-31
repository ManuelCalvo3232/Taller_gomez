-- Tokens de un solo uso para aprobar o rechazar un presupuesto desde el mail.
-- Solo se guarda el hash SHA-256 del token, nunca el token en claro.
CREATE TABLE tokens_presupuesto (
  token_hash     CHAR(64) PRIMARY KEY,
  id_presupuesto INT NOT NULL,
  creado_en      DATETIME NOT NULL,
  expira_en      DATETIME NOT NULL,
  usado_en       DATETIME NULL,
  INDEX idx_tokens_presupuesto (id_presupuesto)
);