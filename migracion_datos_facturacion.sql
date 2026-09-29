-- Migración: datos bancarios de facturación (separados de los datos bancarios normales)
-- Fecha: 2026-09-21
-- Aplicar en LOCAL y en el servidor (ver project_servidor_conexion en memoria).

ALTER TABLE sucursales
    ADD COLUMN banco_fact              VARCHAR(100) NULL DEFAULT NULL AFTER alias_tarjeta,
    ADD COLUMN titular_cuenta_fact     VARCHAR(150) NULL DEFAULT NULL AFTER banco_fact,
    ADD COLUMN numero_cuenta_fact      VARCHAR(30)  NULL DEFAULT NULL AFTER titular_cuenta_fact,
    ADD COLUMN clabe_interbancaria_fact CHAR(18)    NULL DEFAULT NULL AFTER numero_cuenta_fact,
    ADD COLUMN alias_tarjeta_fact      VARCHAR(60)  NULL DEFAULT NULL AFTER clabe_interbancaria_fact;
