-- Taller Mecánico Gómez - esquema de base de datos
-- MySQL 8.0 o superior (CHECK y ENUM), motor InnoDB, charset utf8mb4

CREATE DATABASE IF NOT EXISTS taller_gomez
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE taller_gomez;

-- Usuarios del sistema: clientes, mecánicos y administrador
CREATE TABLE usuarios (
  id_usuario     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email          VARCHAR(150) NOT NULL UNIQUE,
  password_hash  VARCHAR(255) NOT NULL,
  nombre         VARCHAR(100) NOT NULL,
  telefono       VARCHAR(30)  NULL,
  rol            ENUM('cliente', 'mecanico', 'admin') NOT NULL,
  creado_en      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB;

-- Vehículos registrados por cada cliente
CREATE TABLE vehiculos (
  id_vehiculo   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_cliente    INT UNSIGNED NOT NULL,
  patente       VARCHAR(10)  NOT NULL UNIQUE,
  modelo        VARCHAR(80)  NOT NULL,
  anio          SMALLINT UNSIGNED NOT NULL,
  kilometros    INT UNSIGNED NOT NULL,
  CONSTRAINT chk_vehiculos_anio CHECK (anio BETWEEN 1950 AND 2100),
  CONSTRAINT fk_vehiculos_cliente
    FOREIGN KEY (id_cliente) REFERENCES usuarios (id_usuario)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE = InnoDB;

-- Turnos pedidos por el cliente. fecha_hora UNIQUE evita turnos superpuestos
CREATE TABLE turnos (
  id_turno      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_vehiculo   INT UNSIGNED NOT NULL,
  fecha_hora    DATETIME     NOT NULL UNIQUE,
  motivo        VARCHAR(255) NOT NULL,
  creado_en     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_turnos_vehiculo
    FOREIGN KEY (id_vehiculo) REFERENCES vehiculos (id_vehiculo)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE = InnoDB;

-- Trabajo que nace de un turno. Cada turno genera como máximo un trabajo
CREATE TABLE trabajos (
  id_trabajo     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_turno       INT UNSIGNED NOT NULL UNIQUE,
  id_mecanico    INT UNSIGNED NULL,
  estado         ENUM('recibido', 'en_diagnostico', 'presupuestado',
                      'aprobado', 'rechazado', 'en_reparacion', 'listo')
                 NOT NULL DEFAULT 'recibido',
  fecha_ingreso  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_trabajos_turno
    FOREIGN KEY (id_turno) REFERENCES turnos (id_turno)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_trabajos_mecanico
    FOREIGN KEY (id_mecanico) REFERENCES usuarios (id_usuario)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE = InnoDB;

-- Diagnóstico técnico del trabajo (uno por trabajo)
CREATE TABLE diagnosticos (
  id_diagnostico  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_trabajo      INT UNSIGNED NOT NULL UNIQUE,
  descripcion     TEXT         NOT NULL,
  creado_en       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_diagnosticos_trabajo
    FOREIGN KEY (id_trabajo) REFERENCES trabajos (id_trabajo)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE = InnoDB;

-- Stock de repuestos del taller
CREATE TABLE repuestos (
  id_repuesto  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre       VARCHAR(120)   NOT NULL,
  stock        INT UNSIGNED   NOT NULL DEFAULT 0,
  precio       DECIMAL(12,2)  NOT NULL,
  CONSTRAINT chk_repuestos_precio CHECK (precio >= 0)
) ENGINE = InnoDB;

-- Presupuesto enviado al cliente. El total se recalcula en el servidor
CREATE TABLE presupuestos (
  id_presupuesto   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_trabajo       INT UNSIGNED NOT NULL,
  total            DECIMAL(12,2) NOT NULL,
  estado           ENUM('enviado', 'aprobado', 'rechazado') NOT NULL DEFAULT 'enviado',
  fecha_envio      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fecha_respuesta  TIMESTAMP NULL,
  CONSTRAINT chk_presupuestos_total CHECK (total >= 0),
  CONSTRAINT fk_presupuestos_trabajo
    FOREIGN KEY (id_trabajo) REFERENCES trabajos (id_trabajo)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE = InnoDB;

-- Ítems de cada presupuesto: repuestos y mano de obra
CREATE TABLE items_presupuesto (
  id_item          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_presupuesto   INT UNSIGNED NOT NULL,
  id_repuesto      INT UNSIGNED NULL,
  tipo             ENUM('repuesto', 'mano_obra') NOT NULL,
  descripcion      VARCHAR(150)  NOT NULL,
  cantidad         INT UNSIGNED  NOT NULL,
  precio_unitario  DECIMAL(12,2) NOT NULL,
  CONSTRAINT chk_items_cantidad CHECK (cantidad > 0),
  CONSTRAINT chk_items_precio CHECK (precio_unitario >= 0),
  CONSTRAINT fk_items_presupuesto
    FOREIGN KEY (id_presupuesto) REFERENCES presupuestos (id_presupuesto)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_items_repuesto
    FOREIGN KEY (id_repuesto) REFERENCES repuestos (id_repuesto)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE = InnoDB;

-- Índices para las consultas más frecuentes del mecánico y del dashboard
CREATE INDEX idx_trabajos_estado ON trabajos (estado);
CREATE INDEX idx_trabajos_mecanico ON trabajos (id_mecanico);
CREATE INDEX idx_presupuestos_estado ON presupuestos (estado);