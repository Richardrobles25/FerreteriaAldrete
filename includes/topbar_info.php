<?php
// Obtener nombre de la sucursal del usuario actual
// El Administrador no está ligado a una sucursal específica
if (!isset($nombreSucursal)) {
    if (($_SESSION['rol'] ?? '') === 'Administrador') {
        $nombreSucursal = 'Administrador';
    } else {
        $stmtSuc = $pdo->prepare("SELECT nombre FROM sucursales WHERE sucursal_id = ?");
        $stmtSuc->execute([$_SESSION['sucursal_id']]);
        $nombreSucursal = $stmtSuc->fetchColumn() ?: 'Sin sucursal';
    }
}
