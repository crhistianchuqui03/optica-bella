-- Creación de la base de datos asegurando caracteres y zona horaria
CREATE DATABASE IF NOT EXISTS optica_bellavista 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

USE optica_bellavista;

-- ===================================================
-- 1. TABLA: usuarios
-- ===================================================
CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    nombre_completo VARCHAR(100) NOT NULL,
    rol ENUM('ADMIN', 'VENDEDOR') NOT NULL DEFAULT 'VENDEDOR',
    estado TINYINT(1) NOT NULL DEFAULT 1,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===================================================
-- 2. TABLA: pacientes
-- ===================================================
CREATE TABLE IF NOT EXISTS pacientes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombres_apellidos VARCHAR(150) NOT NULL,
    edad INT NULL,
    celular VARCHAR(20) NULL,
    ocupacion VARCHAR(100) NULL,
    genero ENUM('MASCULINO', 'FEMENINO', 'OTRO') NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FULLTEXT INDEX idx_busqueda_paciente (nombres_apellidos)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===================================================
-- 3. TABLA: historias_clinicas
-- ===================================================
CREATE TABLE IF NOT EXISTS historias_clinicas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    paciente_id INT NOT NULL,
    fecha_consulta DATE NOT NULL,
    -- Mediciones Lejos (VL)
    esf_od_vl VARCHAR(10) NULL,
    cyl_od_vl VARCHAR(10) NULL,
    eje_od_vl VARCHAR(10) NULL,
    dip_od_vl VARCHAR(10) NULL,
    av_od_vl  VARCHAR(10) NULL,
    esf_oi_vl VARCHAR(10) NULL,
    cyl_oi_vl VARCHAR(10) NULL,
    eje_oi_vl VARCHAR(10) NULL,
    dip_oi_vl VARCHAR(10) NULL,
    av_oi_vl  VARCHAR(10) NULL,
    -- Mediciones Cerca (VC) y ADD
    add_medicion VARCHAR(10) NULL,
    esf_od_vc VARCHAR(10) NULL,
    cyl_od_vc VARCHAR(10) NULL,
    eje_od_vc VARCHAR(10) NULL,
    dip_od_vc VARCHAR(10) NULL,
    av_od_vc  VARCHAR(10) NULL,
    esf_oi_vc VARCHAR(10) NULL,
    cyl_oi_vc VARCHAR(10) NULL,
    eje_oi_vc VARCHAR(10) NULL,
    dip_oi_vc VARCHAR(10) NULL,
    av_oi_vc  VARCHAR(10) NULL,
    -- Detalles de Luna y Receta
    material ENUM('POLICARBONATO', 'CRISTAL', 'RESINA', 'ALTO INDICE', 'OTRO') NULL,
    tratamiento VARCHAR(150) NULL,
    tipo_lente ENUM('MONOFOCAL', 'BIFOCAL', 'MULTIFOCAL') NULL,
    indicaciones TEXT NULL,
    proxima_cita DATE NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_historia_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===================================================
-- 4. TABLA: inventario_monturas
-- ===================================================
CREATE TABLE IF NOT EXISTS inventario_monturas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo_montura VARCHAR(50) NOT NULL UNIQUE,
    descripcion_material TEXT NOT NULL,
    genero ENUM('MASCULINO', 'FEMENINO', 'UNISEX', 'NIÑO') NOT NULL,
    marca VARCHAR(80) NOT NULL,
    tipo_montura ENUM('SOL', '5EN1', 'AMARILLO', 'LECTURA', 'SEGURIDAD', 'RECETADO', 'DESCANSO') NOT NULL DEFAULT 'RECETADO',
    stock INT NOT NULL DEFAULT 1,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_codigo_montura (codigo_montura)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===================================================
-- 5. TABLA: contratos
-- ===================================================
CREATE TABLE IF NOT EXISTS contratos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero_contrato VARCHAR(20) NOT NULL UNIQUE, -- Ej: 0001461
    paciente_id INT NOT NULL,
    montura_id INT NULL,
    detalle_contrato TEXT NULL,
    monto_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    estado ENUM('PENDIENTE', 'CANCELADO') NOT NULL DEFAULT 'PENDIENTE',
    fecha_entrega DATE NULL,
    comentarios TEXT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_contrato_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id),
    CONSTRAINT fk_contrato_montura FOREIGN KEY (montura_id) REFERENCES inventario_monturas(id) ON DELETE SET NULL,
    INDEX idx_numero_contrato (numero_contrato)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===================================================
-- 6. TABLA: movimientos_caja (Ingresos y Egresos)
-- ===================================================
CREATE TABLE IF NOT EXISTS movimientos_caja (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fecha_registro DATE NOT NULL,
    tipo_movimiento ENUM('INGRESO', 'EGRESO') NOT NULL,
    contrato_id INT NULL, -- NULL si es un gasto general no ligado a paciente
    monto DECIMAL(10,2) NOT NULL,
    concepto_descripcion TEXT NOT NULL,
    nro_comprobante_lab VARCHAR(50) NULL, -- Factura/Boleta de laboratorio en Egresos
    usuario_id INT NOT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mov_contrato FOREIGN KEY (contrato_id) REFERENCES contratos(id) ON DELETE SET NULL,
    CONSTRAINT fk_mov_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    INDEX idx_fecha_caja (fecha_registro),
    INDEX idx_comprobante_lab (nro_comprobante_lab)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===================================================
-- INSERCIÓN DE DATOS INICIALES (Usuario Admin)
-- Contraseña por defecto: Admin123! (se asume hash argon2id/bcrypt)
-- ===================================================
INSERT INTO usuarios (username, password_hash, nombre_completo, rol) 
VALUES ('admin', '$2y$10$zaUPU9xC8XA2/7Xcg70iVeG/iF0MGPoL7leXdchYVfdRUtI6nnzAO', 'Administrador Bellavista', 'ADMIN')
ON DUPLICATE KEY UPDATE id=id;
