-- Proveedores de repuestos
CREATE TABLE proveedores (
  id_proveedor  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre        VARCHAR(120) NOT NULL,
  telefono      VARCHAR(30)  NULL,
  email         VARCHAR(150) NULL,
  cuit          VARCHAR(13)  NULL,
  creado_en     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB;

-- "precio" pasa a ser el precio de venta. "costo" es el de la última compra.
ALTER TABLE repuestos
  ADD COLUMN marca_vehiculo VARCHAR(50) NULL AFTER nombre,
  ADD COLUMN categoria      VARCHAR(50) NULL AFTER marca_vehiculo,
  ADD COLUMN costo          DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER precio,
  ADD COLUMN stock_minimo   INT UNSIGNED NOT NULL DEFAULT 0 AFTER stock,
  ADD CONSTRAINT chk_repuestos_costo CHECK (costo >= 0);

-- Cada entrada o salida de stock queda registrada
CREATE TABLE movimientos_stock (
  id_movimiento   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_repuesto     INT UNSIGNED  NOT NULL,
  tipo            ENUM('entrada', 'salida') NOT NULL,
  cantidad        INT UNSIGNED  NOT NULL,
  costo_unitario  DECIMAL(12,2) NULL,
  id_proveedor    INT UNSIGNED  NULL,
  motivo          VARCHAR(255)  NOT NULL,
  id_usuario      INT UNSIGNED  NOT NULL,
  creado_en       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_mov_cantidad CHECK (cantidad > 0),
  CONSTRAINT fk_mov_repuesto FOREIGN KEY (id_repuesto) REFERENCES repuestos (id_repuesto)
    ON DELETE RESTRICT,
  CONSTRAINT fk_mov_proveedor FOREIGN KEY (id_proveedor) REFERENCES proveedores (id_proveedor)
    ON DELETE SET NULL,
  CONSTRAINT fk_mov_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios (id_usuario)
    ON DELETE RESTRICT,
  INDEX idx_mov_repuesto (id_repuesto)
) ENGINE = InnoDB;