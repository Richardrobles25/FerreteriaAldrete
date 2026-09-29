-- Migración: exención de mora por cliente + créditos sin venta (saldo importado)
-- Fecha: 2026-09-22
-- Aplicar en el servidor (ver project_servidor_conexion en memoria: usar PHP/PDO, no mysql.exe).

-- 1) Bandera permanente por cliente: si es 0, ese cliente NUNCA acumula mora en ninguno
--    de sus créditos, sin importar cuánto se atrase. Independiente del botón "Cancelar mora"
--    (que solo cancela la ya acumulada de un crédito puntual). Todo cliente ya existente
--    nace en 1 (comportamiento actual sin cambios); los que se importen después del
--    sistema anterior se crearán en 0 explícitamente desde el código de importación.
ALTER TABLE clientes
    ADD COLUMN cobrar_mora TINYINT(1) NOT NULL DEFAULT 1 AFTER credito_autorizado;

-- 2) Permite que un crédito exista sin una venta real detrás (saldo heredado del sistema
--    anterior al importar clientes). Se deja NULL en vez de crear una venta falsa para que
--    ese saldo NO aparezca como ingreso en historialVentas.php / reporteVentas.php / corteCaja.php
--    (todos consultan la tabla ventas directamente). El FK hacia ventas se conserva.
ALTER TABLE creditos
    MODIFY venta_id INT UNSIGNED NULL;
