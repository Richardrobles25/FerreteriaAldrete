-- [FEATURE-TICKET-ABONO 2026-09-11] Comprobante de pago (ticket) para abonos de credito.
-- Un solo pago puede tocar varios creditos a la vez (FIFO del mas antiguo al mas reciente),
-- por eso cada fila de "abonos" que ese pago genere comparte el mismo "folio" -- asi se puede
-- reconstruir el comprobante completo de un pago que abono a mas de un credito.
--
-- Seguro de correr en una base de datos con filas existentes: todas las columnas nuevas son
-- NULL/0.00 por default, asi que los abonos ya registrados quedan intactos (sin folio, por lo
-- tanto sin boton de "Ticket" en su historial -- nunca tuvieron uno, no se les puede inventar
-- un comprobante retroactivo con datos reales).

ALTER TABLE abonos
    ADD COLUMN folio VARCHAR(20) DEFAULT NULL AFTER abono_id,
    ADD COLUMN saldo_despues DECIMAL(10,2) DEFAULT NULL AFTER monto,
    ADD COLUMN caja_id INT UNSIGNED DEFAULT NULL AFTER credito_id,
    ADD COLUMN sucursal_id INT UNSIGNED DEFAULT NULL AFTER caja_id,
    ADD COLUMN monto_efectivo DECIMAL(10,2) DEFAULT 0.00 AFTER metodo_pago,
    ADD COLUMN monto_terminal DECIMAL(10,2) DEFAULT 0.00 AFTER monto_efectivo,
    ADD COLUMN referencia_transferencia VARCHAR(100) DEFAULT NULL AFTER monto_terminal;

ALTER TABLE abonos
    ADD KEY idx_abonos_folio (folio),
    ADD KEY idx_abonos_caja (caja_id),
    ADD CONSTRAINT fk_abonos_caja FOREIGN KEY (caja_id) REFERENCES cajas(caja_id),
    ADD CONSTRAINT fk_abonos_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(sucursal_id);
