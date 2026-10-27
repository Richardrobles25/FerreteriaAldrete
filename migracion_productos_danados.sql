-- =====================================================================================
-- MIGRACION: productos dañados (devoluciones de clientes y salidas por daño)
-- Correr UNA VEZ en el servidor ANTES de subir el codigo nuevo de devoluciones/salidas.
-- Es una tabla nueva: no modifica ninguna tabla existente.
-- =====================================================================================

CREATE TABLE IF NOT EXISTS productos_danados (
    danado_id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    producto_id       INT UNSIGNED NOT NULL,
    sucursal_id       INT UNSIGNED NOT NULL,
    usuario_id        INT UNSIGNED NOT NULL,
    cantidad          DECIMAL(10,3) NOT NULL,
    origen            ENUM('Devolucion','Salida') NOT NULL,
    devolucion_id     INT UNSIGNED NULL,
    venta_id          INT UNSIGNED NULL,
    movimiento_id     INT UNSIGNED NULL,
    motivo            VARCHAR(255) NULL,
    estado            ENUM('Pendiente','Regresado','Cancelado') NOT NULL DEFAULT 'Pendiente',
    regresado_en      DATETIME NULL,
    regresado_por     INT UNSIGNED NULL,
    nota_regreso      VARCHAR(255) NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (danado_id),
    KEY idx_danados_estado_sucursal (estado, sucursal_id),
    KEY idx_danados_producto (producto_id),
    KEY idx_danados_devolucion (devolucion_id),
    KEY idx_danados_movimiento (movimiento_id),
    CONSTRAINT fk_danados_producto FOREIGN KEY (producto_id) REFERENCES productos (producto_id),
    CONSTRAINT fk_danados_usuario  FOREIGN KEY (usuario_id)  REFERENCES usuarios (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
