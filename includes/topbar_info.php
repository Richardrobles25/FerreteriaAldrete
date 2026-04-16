<?php
// Obtener nombre de la sucursal del usuario actual
if (!isset($nombreSucursal)) {
    $stmtSuc = $pdo->prepare("SELECT nombre FROM sucursales WHERE sucursal_id = ?");
    $stmtSuc->execute([$_SESSION['sucursal_id']]);
    $nombreSucursal = $stmtSuc->fetchColumn() ?: 'Sin sucursal';
}
