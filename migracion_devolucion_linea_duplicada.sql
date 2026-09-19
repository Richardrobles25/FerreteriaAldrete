-- [FIX-DEVOLUCION-LINEA-DUPLICADA 2026-09-19] Cuando un ajuste por daño parcial partia una
-- linea de venta_productos en "sana" + "dañada" (mismo producto_id, ambas paquete_id NULL),
-- devolver la linea dañada marcaba tambien como devuelta la linea sana nunca tocada -- la
-- venta terminaba "sin productos disponibles para devolucion" aunque quedaran unidades sanas
-- legitimas sin devolver. La causa: obtenerTotalesDevueltos() (cajeroInventario/devoluciones.php
-- y admin/cajero_devoluciones.php) solo distinguia por producto_id:paquete_id, sin poder
-- diferenciar dos renglones sueltos del mismo producto entre si.
--
-- venta_productos_id identifica a que renglon especifico de venta_productos apunto cada
-- devolucion, para poder distinguir esas dos lineas. Se usa como clave precisa "pid:Lvid" en
-- todos los puntos donde antes se agrupaba solo por producto_id (lectura de restante, precio a
-- reembolsar, comision proporcional).
--
-- Seguro de correr en una base de datos con filas existentes: columna nueva, NULL por default,
-- los movimientos ya registrados quedan en NULL y el codigo los trata igual que antes (clave
-- legacy "pid:", el mismo comportamiento previo a este fix, sin romper devoluciones ya hechas).
--
-- Correr ANTES de desplegar el codigo nuevo (commit 4df07a7).

ALTER TABLE movimientos_inventario
  ADD COLUMN venta_productos_id INT UNSIGNED NULL DEFAULT NULL AFTER paquete_id;
