-- Catálogo de marcas y modelos de autos y motos.
-- Un vehículo apunta a la lista (id_marca / id_modelo) o, si no está, guarda texto libre
-- (marca_otra / modelo_otro). "modelo" queda como texto para mostrar: "Marca Modelo".
CREATE TABLE marcas (
  id_marca  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre    VARCHAR(60) NOT NULL UNIQUE
) ENGINE = InnoDB;

CREATE TABLE modelos (
  id_modelo INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_marca  INT UNSIGNED NOT NULL,
  tipo      ENUM('auto', 'moto') NOT NULL,
  nombre    VARCHAR(80) NOT NULL,
  UNIQUE KEY uq_modelo (id_marca, tipo, nombre),
  CONSTRAINT fk_modelos_marca FOREIGN KEY (id_marca) REFERENCES marcas (id_marca)
    ON DELETE CASCADE
) ENGINE = InnoDB;

ALTER TABLE vehiculos
  MODIFY modelo VARCHAR(150) NOT NULL,
  ADD COLUMN id_marca    INT UNSIGNED NULL AFTER id_cliente,
  ADD COLUMN id_modelo   INT UNSIGNED NULL AFTER id_marca,
  ADD COLUMN marca_otra  VARCHAR(60) NULL AFTER id_modelo,
  ADD COLUMN modelo_otro VARCHAR(80) NULL AFTER marca_otra,
  ADD CONSTRAINT fk_vehiculos_marca  FOREIGN KEY (id_marca)  REFERENCES marcas (id_marca)
    ON DELETE SET NULL,
  ADD CONSTRAINT fk_vehiculos_modelo FOREIGN KEY (id_modelo) REFERENCES modelos (id_modelo)
    ON DELETE SET NULL;

-- Datos iniciales: marcas y modelos más conocidos. El admin puede sumar más.
CREATE TEMPORARY TABLE seed_modelos (
  marca  VARCHAR(60) NOT NULL,
  tipo   ENUM('auto', 'moto') NOT NULL,
  nombre VARCHAR(80) NOT NULL
);

INSERT INTO seed_modelos (marca, tipo, nombre) VALUES
  ('Volkswagen', 'auto', 'Gol'), ('Volkswagen', 'auto', 'Gol Trend'), ('Volkswagen', 'auto', 'Polo'),
  ('Volkswagen', 'auto', 'Virtus'), ('Volkswagen', 'auto', 'Voyage'), ('Volkswagen', 'auto', 'Saveiro'),
  ('Volkswagen', 'auto', 'Amarok'), ('Volkswagen', 'auto', 'Taos'), ('Volkswagen', 'auto', 'Nivus'),
  ('Volkswagen', 'auto', 'Fox'), ('Volkswagen', 'auto', 'Up!'),
  ('Fiat', 'auto', 'Cronos'), ('Fiat', 'auto', 'Argo'), ('Fiat', 'auto', 'Uno'), ('Fiat', 'auto', 'Palio'),
  ('Fiat', 'auto', 'Siena'), ('Fiat', 'auto', 'Strada'), ('Fiat', 'auto', 'Toro'), ('Fiat', 'auto', 'Mobi'),
  ('Fiat', 'auto', 'Punto'), ('Fiat', 'auto', 'Fiorino'), ('Fiat', 'auto', '500'),
  ('Chevrolet', 'auto', 'Onix'), ('Chevrolet', 'auto', 'Prisma'), ('Chevrolet', 'auto', 'Cruze'),
  ('Chevrolet', 'auto', 'Tracker'), ('Chevrolet', 'auto', 'S10'), ('Chevrolet', 'auto', 'Corsa'),
  ('Chevrolet', 'auto', 'Classic'), ('Chevrolet', 'auto', 'Spin'),
  ('Renault', 'auto', 'Clio'), ('Renault', 'auto', 'Logan'), ('Renault', 'auto', 'Sandero'),
  ('Renault', 'auto', 'Kangoo'), ('Renault', 'auto', 'Kwid'), ('Renault', 'auto', 'Duster'),
  ('Renault', 'auto', 'Oroch'), ('Renault', 'auto', 'Symbol'), ('Renault', 'auto', 'Captur'),
  ('Ford', 'auto', 'Ka'), ('Ford', 'auto', 'Fiesta'), ('Ford', 'auto', 'Focus'), ('Ford', 'auto', 'Ranger'),
  ('Ford', 'auto', 'EcoSport'), ('Ford', 'auto', 'Territory'),
  ('Toyota', 'auto', 'Etios'), ('Toyota', 'auto', 'Corolla'), ('Toyota', 'auto', 'Corolla Cross'),
  ('Toyota', 'auto', 'Yaris'), ('Toyota', 'auto', 'Hilux'), ('Toyota', 'auto', 'SW4'), ('Toyota', 'auto', 'RAV4'),
  ('Peugeot', 'auto', '208'), ('Peugeot', 'auto', '2008'), ('Peugeot', 'auto', '207'), ('Peugeot', 'auto', '308'),
  ('Peugeot', 'auto', '3008'), ('Peugeot', 'auto', 'Partner'),
  ('Citroën', 'auto', 'C3'), ('Citroën', 'auto', 'C3 Aircross'), ('Citroën', 'auto', 'C4 Cactus'),
  ('Citroën', 'auto', 'Berlingo'), ('Citroën', 'auto', 'C5 Aircross'),
  ('Nissan', 'auto', 'March'), ('Nissan', 'auto', 'Versa'), ('Nissan', 'auto', 'Sentra'),
  ('Nissan', 'auto', 'Frontier'), ('Nissan', 'auto', 'Kicks'), ('Nissan', 'auto', 'Tiida'),
  ('Honda', 'auto', 'Fit'), ('Honda', 'auto', 'City'), ('Honda', 'auto', 'Civic'), ('Honda', 'auto', 'HR-V'),
  ('Honda', 'auto', 'CR-V'), ('Honda', 'auto', 'WR-V'),
  ('Hyundai', 'auto', 'HB20'), ('Hyundai', 'auto', 'Creta'), ('Hyundai', 'auto', 'Accent'),
  ('Hyundai', 'auto', 'Elantra'), ('Hyundai', 'auto', 'Tucson'), ('Hyundai', 'auto', 'i10'),
  ('Hyundai', 'auto', 'Santa Fe'),
  ('Jeep', 'auto', 'Renegade'), ('Jeep', 'auto', 'Compass'), ('Jeep', 'auto', 'Wrangler'), ('Jeep', 'auto', 'Cherokee'),
  ('Kia', 'auto', 'Sportage'), ('Kia', 'auto', 'Cerato'), ('Kia', 'auto', 'Rio'), ('Kia', 'auto', 'Picanto'),
  ('Kia', 'auto', 'Sorento'),
  ('Suzuki', 'auto', 'Swift'), ('Suzuki', 'auto', 'Vitara'), ('Suzuki', 'auto', 'Jimny'), ('Suzuki', 'auto', 'S-Presso'),
  ('Mitsubishi', 'auto', 'L200'), ('Mitsubishi', 'auto', 'Outlander'), ('Mitsubishi', 'auto', 'ASX'),
  ('Mitsubishi', 'auto', 'Montero Sport'),
  ('Honda', 'moto', 'Wave 110'), ('Honda', 'moto', 'Biz 125'), ('Honda', 'moto', 'CG 150 Titan'),
  ('Honda', 'moto', 'XR 150 L'), ('Honda', 'moto', 'Tornado 250'),
  ('Yamaha', 'moto', 'YBR 125'), ('Yamaha', 'moto', 'FZ 150'), ('Yamaha', 'moto', 'XTZ 125'),
  ('Yamaha', 'moto', 'MT-03'), ('Yamaha', 'moto', 'Crypton 110'),
  ('Motomel', 'moto', 'Blitz 110'), ('Motomel', 'moto', 'Skua 150'),
  ('Zanella', 'moto', 'ZB 110'), ('Zanella', 'moto', 'RX 150'),
  ('Gilera', 'moto', 'Smash 110'),
  ('Corven', 'moto', 'Energy 110'), ('Corven', 'moto', 'Triax 150'),
  ('Suzuki', 'moto', 'GN 125');

INSERT IGNORE INTO marcas (nombre) SELECT DISTINCT marca FROM seed_modelos;

INSERT IGNORE INTO modelos (id_marca, tipo, nombre)
  SELECT m.id_marca, s.tipo, s.nombre FROM seed_modelos s JOIN marcas m ON m.nombre = s.marca;

DROP TEMPORARY TABLE seed_modelos;