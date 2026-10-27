-- Transferencias: folio por pedido, envio directo y formulario de recepcion (recibida / merma / no enviado).
--
-- Agrega a `transferencias`:
--   folio                 numero del pedido (0001, 0002... reinicia cada mes, igual que el folio de ventas).
--                         Todas las filas (productos) de un mismo pedido comparten folio.
--   folio_periodo         anio*100+mes de created_at (generada, igual que ventas.folio_periodo): junto con
--                         `folio` identifica al pedido de forma unica.
--   tipo_pedido           'Solicitud' (la sucursal destino lo pide y el origen lo acepta; flujo de siempre) o
--                         'Directo' (el origen lo manda sin autorizacion del destino, nace "En transito").
--   cantidad_recibida     lo que el destino capturo como recibido en buen estado (NULL = no capturado / flujo viejo).
--   cantidad_merma        lo que llego danado: SUMA al stock del destino y el destino lo da de baja desde Salidas.
--   cantidad_no_enviada   lo que venia en la lista pero no llego: REGRESA al stock del origen.
--   observaciones         texto libre del destino al recibir (antes eran renglones a mano en el ticket).
--   usuario_recibe_id     quien confirmo la recepcion.
--   fecha_recepcion       cuando se confirmo la recepcion.
--
-- Las filas ya existentes quedan con NULL (no se rompe nada). Despues de este SQL, correr UNA VEZ el script
--   php migracion_transferencias_folios.php
-- para ponerle folio a los pedidos que ya existian (agrupa las filas por pedido y numera por mes).
--
-- IMPORTANTE: ejecutar esto en el servidor ANTES de subir el codigo nuevo (el codigo ya usa estas columnas).
-- Correr cada sentencia por separado. Si una marca "Duplicate column name", esa columna ya existe y se puede saltar.
-- Sin "AFTER": no depende de que existan otras columnas.

ALTER TABLE transferencias ADD COLUMN folio VARCHAR(20) DEFAULT NULL;

ALTER TABLE transferencias ADD COLUMN folio_periodo INT GENERATED ALWAYS AS (YEAR(created_at)*100 + MONTH(created_at)) STORED;

ALTER TABLE transferencias ADD COLUMN tipo_pedido ENUM('Solicitud','Directo') NOT NULL DEFAULT 'Solicitud';

ALTER TABLE transferencias ADD COLUMN cantidad_recibida DECIMAL(10,3) DEFAULT NULL;

ALTER TABLE transferencias ADD COLUMN cantidad_merma DECIMAL(10,3) DEFAULT NULL;

ALTER TABLE transferencias ADD COLUMN cantidad_no_enviada DECIMAL(10,3) DEFAULT NULL;

ALTER TABLE transferencias ADD COLUMN observaciones TEXT DEFAULT NULL;

ALTER TABLE transferencias ADD COLUMN usuario_recibe_id INT UNSIGNED DEFAULT NULL;

ALTER TABLE transferencias ADD COLUMN fecha_recepcion DATETIME DEFAULT NULL;

ALTER TABLE transferencias ADD KEY idx_transferencias_folio (folio_periodo, folio);
