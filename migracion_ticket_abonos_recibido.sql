-- Migración adicional para el ticket de abonos: agrega la columna "monto_recibido"
-- (cantidad en efectivo entregada por el cliente) para poder mostrar "Recibido" y
-- "Cambio" en el comprobante de pago, igual que ya se hace en el ticket de ventas.
--
-- Requiere que ya se haya aplicado antes migracion_ticket_abonos.sql (folio, saldo_despues,
-- caja_id, sucursal_id, monto_efectivo, monto_terminal, referencia_transferencia).
--
-- Ejecutar en el servidor de producción (146.190.40.160) sobre la base de datos real.

ALTER TABLE abonos
    ADD COLUMN monto_recibido DECIMAL(10,2) DEFAULT NULL AFTER referencia_transferencia;
