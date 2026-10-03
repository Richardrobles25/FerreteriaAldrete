-- Folio del voucher de la terminal bancaria: se captura al cobrar con Terminal o Mixto
-- (la parte de terminal) como evidencia del cargo, igual que la referencia de Transferencia.
-- Aplica a ventas (Nueva venta) y a abonos de credito (Creditos / Abonos).
-- Las filas ya existentes quedan con NULL (no se rompe nada).
-- Sin "AFTER": no depende de que existan otras columnas (el orden no afecta al sistema).
--
-- Ejecutar en el servidor de produccion (146.190.40.160) sobre la base de datos real.
-- Correr cada sentencia por separado. Si una marca "Duplicate column name 'folio_terminal'"
-- esa tabla ya quedo lista y se puede saltar.

ALTER TABLE ventas ADD COLUMN folio_terminal VARCHAR(100) DEFAULT NULL;

ALTER TABLE abonos ADD COLUMN folio_terminal VARCHAR(100) DEFAULT NULL;
