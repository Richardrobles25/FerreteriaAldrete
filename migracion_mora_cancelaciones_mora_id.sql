-- =====================================================================================
-- MIGRACION: cancelar moras una por una (Administrador > Abonos)
-- Correr UNA VEZ en el servidor ANTES de subir el codigo nuevo de admin/abonos.php.
-- Agrega a mora_cancelaciones la columna que dice QUE mora (movimientos_mora.mora_id)
-- se cancelo. Las cancelaciones anteriores quedan con mora_id NULL y el sistema las
-- empata solo con la mora de mismo monto mas reciente.
-- =====================================================================================

ALTER TABLE mora_cancelaciones
    ADD COLUMN mora_id INT UNSIGNED NULL AFTER credito_id,
    ADD KEY idx_mora_canc_mora (mora_id);
