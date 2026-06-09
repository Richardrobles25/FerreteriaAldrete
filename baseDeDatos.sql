    -- ============================================
    -- BASE DE DATOS: FERRETERIA
    -- Motor: MySQL (Railway)
    -- ============================================

    -- 1. SUCURSALES
    CREATE TABLE sucursales (
        sucursal_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(100) NOT NULL,
        rfc VARCHAR(20),
        direccion VARCHAR(255),
        telefono VARCHAR(20),
        datos_ticket TEXT,
        ticket_logo      VARCHAR(255) NULL,
        ticket_font_size TINYINT      NOT NULL DEFAULT 12,
        ticket_ancho_mm  TINYINT      NOT NULL DEFAULT 58,
        ticket_pie       VARCHAR(500) NULL,
        logo_url VARCHAR(255) NULL DEFAULT NULL,
        activo boolean DEFAULT true,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        banco              VARCHAR(100)  NULL DEFAULT NULL,
        titular_cuenta     VARCHAR(150)  NULL DEFAULT NULL,
        numero_cuenta      VARCHAR(30)   NULL DEFAULT NULL,
        clabe_interbancaria CHAR(18)     NULL DEFAULT NULL,
        alias_tarjeta      VARCHAR(60)   NULL DEFAULT NULL,
        comision_terminal_pct DECIMAL(5,2) DEFAULT 0
    );

    -- 2. USUARIOS
    CREATE TABLE usuarios (
        usuario_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        sucursal_id INT UNSIGNED NOT NULL,
        nombre_completo VARCHAR(100) NOT NULL,
        telefono varchar(20),
        domicilio varchar(150),
        nombre_usuario varchar(25) not null unique,
        contrasena VARCHAR(255) NOT NULL,
        rol ENUM('Administrador', 'Inventario', 'Cajero', 'Inventario/Cajero') NOT NULL,
        activo boolean DEFAULT true,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (sucursal_id) REFERENCES sucursales(sucursal_id)
    );

    -- 3. CATEGORIAS Productos
    CREATE TABLE categorias (
    categoria_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre       VARCHAR(100) NOT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_nombre (nombre)
);

    -- 4. PROVEEDORES
    CREATE TABLE proveedores (
        proveedor_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(100) NOT NULL,
        telefono VARCHAR(20),
        correo VARCHAR(100),
        direccion VARCHAR(255),
        activo BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    );

    -- Tabla intermedia proveedor - categorias
    CREATE TABLE proveedor_categorias (
        proveedor_categoria_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        proveedor_id INT UNSIGNED NOT NULL,
        categoria_id INT UNSIGNED NOT NULL,
        FOREIGN KEY (proveedor_id) REFERENCES proveedores(proveedor_id),
        FOREIGN KEY (categoria_id) REFERENCES categorias(categoria_id)
    );

    -- 5. PRODUCTOS
    CREATE TABLE productos (
        producto_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        categoria_id INT UNSIGNED,
        codigo VARCHAR(50) NOT NULL, UNIQUE (sucursal_id, codigo),
        nombre_producto VARCHAR(150) NOT NULL,
        descripcion TEXT,
        precio_compra DECIMAL(10,2) NOT NULL DEFAULT 0,
        precio_venta DECIMAL(10,2) NOT NULL DEFAULT 0,
        precio_mayoreo DECIMAL(10,2) DEFAULT 0, 
        tipo_venta ENUM('Unidad', 'Suelto') DEFAULT 'Unidad',
        unidad_medida VARCHAR(30) NULL DEFAULT NULL,
        activo boolean DEFAULT true,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (sucursal_id) REFERENCES sucursales(sucursal_id),
        FOREIGN KEY (categoria_id) REFERENCES categorias(categoria_id)
    );

    -- 6. PRODUCTO_PROVEEDOR
    CREATE TABLE producto_proveedor (
        producto_proveedor_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        producto_id INT UNSIGNED NOT NULL,
        proveedor_id INT UNSIGNED NOT NULL,
        codigo_proveedor VARCHAR(50),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (producto_id) REFERENCES productos(producto_id),
        FOREIGN KEY (proveedor_id) REFERENCES proveedores(proveedor_id)
    );

    -- 7. PAQUETES
    CREATE TABLE paquetes (
        paquete_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        sucursal_id INT UNSIGNED NOT NULL,
        codigo VARCHAR(50) NOT NULL DEFAULT '',
        nombre VARCHAR(150) NOT NULL,
        descripcion TEXT,
        precio_paquete DECIMAL(10,2) NOT NULL DEFAULT 0,
        activo boolean DEFAULT true,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (sucursal_id) REFERENCES sucursales(sucursal_id)
    );

    -- Quitar sucursal_id de paquetes (ahora son globales)
    ALTER TABLE paquetes DROP FOREIGN KEY paquetes_ibfk_1;
    ALTER TABLE paquetes DROP COLUMN sucursal_id;

    -- Agregar código único al paquete
    ALTER TABLE paquetes ADD COLUMN codigo VARCHAR(50) NOT NULL DEFAULT '' AFTER paquete_id;
    ALTER TABLE paquetes ADD UNIQUE KEY uk_paquete_codigo (codigo);

    -- 8. PAQUETE_PRODUCTOS
    CREATE TABLE paquete_productos (
        paquete_productos_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        paquete_id INT UNSIGNED NOT NULL,
        producto_id INT UNSIGNED NOT NULL,
        cantidad DECIMAL(10,3) NOT NULL DEFAULT 1,
        FOREIGN KEY (paquete_id) REFERENCES paquetes(paquete_id),
        FOREIGN KEY (producto_id) REFERENCES productos(producto_id)
    );

    -- 9. CLIENTES
    CREATE TABLE clientes (
        cliente_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        nombre_completo VARCHAR(100) NOT NULL,
        telefono VARCHAR(20),
        direccion VARCHAR(255),
        correo VARCHAR(100),
        descuento_fijo DECIMAL(5,2) DEFAULT 0,
        notas varchar(255),
        activo boolean DEFAULT true,
        credito_autorizado boolean default false,
        limite_credito DECIMAL(10,2) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    );

    -- 10. CAJAS
    CREATE TABLE cajas (
        caja_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        sucursal_id INT UNSIGNED NOT NULL,
        usuario_id INT UNSIGNED NOT NULL,
        monto_apertura DECIMAL(10,2) NOT NULL DEFAULT 0,
        monto_cierre DECIMAL(10,2) DEFAULT NULL,
        monto_esperado DECIMAL(10,2) DEFAULT NULL,
        diferencia DECIMAL(10,2) DEFAULT NULL,
        observaciones TEXT,
        estado ENUM('Abierta', 'Cerrada') DEFAULT 'Abierta',
        abierta_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        cerrada_en TIMESTAMP NULL,
        numero_turno INT UNSIGNED DEFAULT 1,
        FOREIGN KEY (sucursal_id) REFERENCES sucursales(sucursal_id),
        FOREIGN KEY (usuario_id) REFERENCES usuarios(usuario_id)
    );

    -- 11. VENTAS
    CREATE TABLE ventas (
        venta_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        folio VARCHAR(20) DEFAULT NULL,
        caja_id INT UNSIGNED NOT NULL,
        cliente_id INT UNSIGNED,
        usuario_id INT UNSIGNED NOT NULL,
        subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
        descuento DECIMAL(10,2) DEFAULT 0,
        comision_terminal DECIMAL(10,2) DEFAULT 0,
        total DECIMAL(10,2) NOT NULL DEFAULT 0,
        metodo_pago ENUM('Efectivo', 'Terminal', 'Credito', 'Mixto', 'Transferencia') NOT NULL,
        referencia_transferencia VARCHAR(100) NULL DEFAULT NULL,
        monto_efectivo DECIMAL(10,2) DEFAULT 0,
        monto_terminal DECIMAL(10,2) DEFAULT 0,
        cambio DECIMAL(10,2) DEFAULT 0,
        estado ENUM('Pendiente','Completada','Cancelada','Devuelto','Modificado') NOT NULL DEFAULT 'Pendiente',
        notas TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        referencia_transferencia VARCHAR(100) NULL DEFAULT NULL,
        INDEX idx_folio (folio),
        INDEX idx_ventas_mes (created_at),
        FOREIGN KEY (caja_id) REFERENCES cajas(caja_id),
        FOREIGN KEY (cliente_id) REFERENCES clientes(cliente_id),
        FOREIGN KEY (usuario_id) REFERENCES usuarios(usuario_id)
    );

    -- 12. VENTA_PRODUCTOS
    CREATE TABLE venta_productos (
        venta_productos_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        venta_id INT UNSIGNED NOT NULL,
        producto_id INT UNSIGNED NOT NULL,
        paquete_id INT NULL DEFAULT NULL,
        cantidad DECIMAL(10,3) NOT NULL,
        precio_unitario DECIMAL(10,2) NOT NULL,
        precio_final DECIMAL(10,2) NULL DEFAULT NULL,
        precio_final DECIMAL(10,2) NULL DEFAULT NULL,
        descuento DECIMAL(10,2) DEFAULT 0,
        subtotal DECIMAL(10,2) NOT NULL,
        nota_ajuste VARCHAR(255) NULL DEFAULT NULL,
        FOREIGN KEY (venta_id) REFERENCES ventas(venta_id),
        FOREIGN KEY (producto_id) REFERENCES productos(producto_id)
    );

    -- 13. CREDITOS
    CREATE TABLE creditos (
        credito_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        cliente_id INT UNSIGNED NOT NULL,
        venta_id INT UNSIGNED NOT NULL,
        monto_total DECIMAL(10,2) NOT NULL,
        saldo_pendiente DECIMAL(10,2) NOT NULL,
        estado ENUM('Activo', 'Liquidado', 'Vencido') DEFAULT 'Activo',
        fecha_limite DATE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (cliente_id) REFERENCES clientes(cliente_id),
        FOREIGN KEY (venta_id) REFERENCES ventas(venta_id)
    );

    -- 14. ABONOS
    CREATE TABLE abonos (
        abono_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        credito_id INT UNSIGNED NOT NULL,
        usuario_id INT UNSIGNED NOT NULL,
        monto DECIMAL(10,2) NOT NULL,
        comision_terminal DECIMAL(10,2) DEFAULT 0,
        metodo_pago ENUM('Efectivo','Terminal','Credito','Mixto','Transferencia') NOT NULL,
        notas TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (credito_id) REFERENCES creditos(credito_id),
        FOREIGN KEY (usuario_id) REFERENCES usuarios(usuario_id)
    );

    -- 15. MOVIMIENTOS_INVENTARIO
    CREATE TABLE movimientos_inventario (
        movimientos_inventario_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        producto_id   INT UNSIGNED NOT NULL,
        usuario_id    INT UNSIGNED NOT NULL,
        sucursal_id   INT UNSIGNED NULL DEFAULT NULL,
        tipo ENUM('Entrada', 'Salida', 'Ajuste', 'Transferencia') NOT NULL,
        cantidad      DECIMAL(10,3) NOT NULL,
        stock_anterior DECIMAL(10,3) NOT NULL,
        stock_nuevo   DECIMAL(10,3) NOT NULL,
        paquete_id INT NULL,
        motivo        VARCHAR(255),
        proveedor_id  INT NULL DEFAULT NULL,
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        devolucion_id INT UNSIGNED NULL DEFAULT NULL,
        KEY idx_mi_sucursal (sucursal_id),
        FOREIGN KEY (producto_id) REFERENCES productos(producto_id),
        FOREIGN KEY (usuario_id)  REFERENCES usuarios(usuario_id)
    );

    -- 16. TRANSFERENCIAS
    CREATE TABLE transferencias (
        transferencias_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        producto_id INT UNSIGNED NOT NULL,
        sucursal_origen_id INT UNSIGNED NOT NULL,
        sucursal_destino_id INT UNSIGNED NOT NULL,
        usuario_solicita_id INT UNSIGNED NOT NULL,
        usuario_aprueba_id INT UNSIGNED,
        cantidad DECIMAL(10,3) NOT NULL,
        estado ENUM('Pendiente','Aprobada','Modificada','En tránsito','Entregada','Rechazada') DEFAULT 'Pendiente',
        notas TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (producto_id) REFERENCES productos(producto_id),
        FOREIGN KEY (sucursal_origen_id) REFERENCES sucursales(sucursal_id),
        FOREIGN KEY (sucursal_destino_id) REFERENCES sucursales(sucursal_id),
        FOREIGN KEY (usuario_solicita_id) REFERENCES usuarios(usuario_id),
        FOREIGN KEY (usuario_aprueba_id) REFERENCES usuarios(usuario_id)
    );

    -- 17. COMPRAS_PROVEEDOR
    CREATE TABLE compras_proveedor (
        compras_proveedor_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        proveedor_id INT UNSIGNED NOT NULL,
        usuario_id INT UNSIGNED NOT NULL,
        sucursal_id INT UNSIGNED NOT NULL,
        total DECIMAL(10,2) NOT NULL DEFAULT 0,
        notas TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (proveedor_id) REFERENCES proveedores(proveedor_id),
        FOREIGN KEY (usuario_id) REFERENCES usuarios(usuario_id),
        FOREIGN KEY (sucursal_id) REFERENCES sucursales(sucursal_id)
    );

    -- 19. COMPRA_PRODUCTOS
    CREATE TABLE compra_productos (
        compra_producto_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        compra_id INT UNSIGNED NOT NULL,
        producto_id INT UNSIGNED NOT NULL,
        cantidad DECIMAL(10,3) NOT NULL,
        precio_unitario DECIMAL(10,2) NOT NULL,
        subtotal DECIMAL(10,2) NOT NULL,
        FOREIGN KEY (compra_id) REFERENCES compras_proveedor(compras_proveedor_id),
        FOREIGN KEY (producto_id) REFERENCES productos(producto_id)
    );

    CREATE TABLE devoluciones (
    devolucion_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    venta_id        INT UNSIGNED NOT NULL,
    usuario_id      INT UNSIGNED NOT NULL,
    procesada_en    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cancelada_en    DATETIME NULL DEFAULT NULL,
    cancelada_por   INT UNSIGNED NULL DEFAULT NULL,
    nota_cancelacion TEXT NULL DEFAULT NULL,
    total_devuelto DECIMAL(10,2) NOT NULL DEFAULT 0,
    subtotal_bruto_devuelto DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    subtotal_final_devuelto DECIMAL(10,2) NOT NULL DEFAULT 0,
    comision_devuelta       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (venta_id)   REFERENCES ventas(venta_id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(usuario_id)
);




    -- ============================================
    -- UNIDADES DE MEDIDA
    -- ============================================

    CREATE TABLE unidades_medida (
    unidad_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sucursal_id INT UNSIGNED NOT NULL,
    nombre      VARCHAR(50) NOT NULL,
    UNIQUE KEY uq_sucursal_nombre (sucursal_id, nombre),
    FOREIGN KEY (sucursal_id) REFERENCES sucursales(sucursal_id) ON DELETE CASCADE
);

    -- ============================================
    -- DATOS INICIALES
    -- ============================================

CREATE TABLE promociones (
    promocion_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    producto_id        INT UNSIGNED NOT NULL,
    precio_promocional DECIMAL(10,2) NOT NULL,
    fecha_inicio       DATE NOT NULL,
    fecha_fin          DATE NOT NULL,
    descripcion        VARCHAR(255) NULL,
    activo             TINYINT(1) NOT NULL DEFAULT 1,
    usuario_id         INT UNSIGNED NOT NULL,
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (producto_id) REFERENCES productos(producto_id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id)  REFERENCES usuarios(usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE movimientos_caja (
  movimiento_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  caja_id       INT UNSIGNED NOT NULL,
  usuario_id    INT UNSIGNED NOT NULL,
  sucursal_id   INT UNSIGNED NOT NULL,
  tipo          ENUM('Retiro','Ingreso') NOT NULL,
  monto         DECIMAL(10,2) NOT NULL,
  nota          TEXT NOT NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  devolucion_id INT UNSIGNED NULL DEFAULT NULL,
  KEY idx_caja (caja_id),
  KEY idx_suc  (sucursal_id),
  KEY idx_fecha (created_at),
  CONSTRAINT fk_movcaja_dev FOREIGN KEY (devolucion_id) REFERENCES devoluciones(devolucion_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE stock_sucursal (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  producto_id   INT UNSIGNED NOT NULL,
  sucursal_id   INT UNSIGNED NOT NULL,
  stock_actual  DECIMAL(10,3) NOT NULL DEFAULT 0.000,
  stock_minimo  DECIMAL(10,3) NOT NULL DEFAULT 0.000,
  stock_maximo  DECIMAL(10,3) NOT NULL DEFAULT 0.000,
  activo        TINYINT(1)    NOT NULL DEFAULT 1,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_prod_suc (producto_id, sucursal_id),
  KEY idx_sucursal (sucursal_id),
  KEY idx_producto (producto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



CREATE TABLE categorias_gastos (
    categoria_gasto_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre             VARCHAR(100) NOT NULL,
    activo             TINYINT(1)  NOT NULL DEFAULT 1
);


INSERT INTO categorias_gastos (nombre) VALUES
('Vehiculos'),
('Mantenimiento'),
('Servicios basicos'),
('Herramientas y equipo'),
('Otros');

CREATE TABLE gastos (
    gasto_id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sucursal_id        INT UNSIGNED NOT NULL,
    usuario_id         INT UNSIGNED NOT NULL,
    categoria_gasto_id INT UNSIGNED NOT NULL,
    descripcion        VARCHAR(500) NOT NULL,
    monto              DECIMAL(10,2) NOT NULL,
    fecha              DATE NOT NULL,
    notas              TEXT NULL,
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sucursal_id)        REFERENCES sucursales(sucursal_id),
    FOREIGN KEY (usuario_id)         REFERENCES usuarios(usuario_id),
    FOREIGN KEY (categoria_gasto_id) REFERENCES categorias_gastos(categoria_gasto_id)
);

CREATE TABLE movimientos_mora (
    mora_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    credito_id  INT UNSIGNED NOT NULL,
    monto       DECIMAL(10,2) NOT NULL,
    saldo_base  DECIMAL(10,2) NOT NULL,
    porcentaje  DECIMAL(5,2)  NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (credito_id) REFERENCES creditos(credito_id)
);

ALTER TABLE sucursales ADD COLUMN porcentaje_mora DECIMAL(5,2) NOT NULL DEFAULT 0;
ALTER TABLE creditos   ADD COLUMN mora_acumulada  DECIMAL(10,2) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS empleados (
    empleado_id    INT AUTO_INCREMENT PRIMARY KEY,
    nombre         VARCHAR(100) NOT NULL,
    fecha_ingreso  DATE NOT NULL,
    sueldo_semanal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    activo         TINYINT(1) DEFAULT 1,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS asistencia (
    asistencia_id       INT AUTO_INCREMENT PRIMARY KEY,
    empleado_id         INT NOT NULL,
    fecha               DATE NOT NULL,
    tipo                ENUM('Tardanza','Falta','Salida temprana','Tiempo fuera','Horas extra') NOT NULL,
    hora_entrada        TIME NULL,
    hora_salida         TIME NULL,
    horas_no_trabajadas DECIMAL(5,2) DEFAULT 0.00,
    horas_extra         DECIMAL(5,2) DEFAULT 0.00,
    razon               TEXT,
    resolucion          ENUM('Pendiente','Deducido','Compensado','Justificado','Pagado integro') DEFAULT 'Pendiente',
    notas               TEXT,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (empleado_id) REFERENCES empleados(empleado_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS asistencia_tiempos_fuera (
    tiempo_id     INT AUTO_INCREMENT PRIMARY KEY,
    asistencia_id INT NOT NULL,
    hora_salida   TIME NOT NULL,
    hora_regreso  TIME NOT NULL,
    FOREIGN KEY (asistencia_id) REFERENCES asistencia(asistencia_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vacaciones (
    vacacion_id  INT AUTO_INCREMENT PRIMARY KEY,
    empleado_id  INT NOT NULL,
    fecha_inicio DATE NOT NULL,
    fecha_fin    DATE NOT NULL,
    dias_tomados INT NOT NULL DEFAULT 0,
    anio         INT NOT NULL,
    estado       ENUM('Solicitado','Aprobado','Rechazado') DEFAULT 'Solicitado',
    notas        TEXT,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (empleado_id) REFERENCES empleados(empleado_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE sucursales
  ADD COLUMN ticket_pie_efectivo TEXT NULL AFTER ticket_pie,
  ADD COLUMN ticket_pie_credito  TEXT NULL AFTER ticket_pie_efectivo,
  ADD COLUMN ticket_pie_terminal TEXT NULL AFTER ticket_pie_credito,
  ADD COLUMN ticket_nota_credito TEXT NULL AFTER ticket_pie_terminal;