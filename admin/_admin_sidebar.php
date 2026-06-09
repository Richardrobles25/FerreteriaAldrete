<?php
function renderAdminSidebar(string $activeKey): void
{
    $inventarioOpen = str_starts_with($activeKey, 'inventario_');
    $cajeroOpen = str_starts_with($activeKey, 'cajero_');
    $rrhhOpen = str_starts_with($activeKey, 'rrhh_');

    $isActive = static fn(string $key): string => $activeKey === $key ? ' active' : '';
    $submenuStyle = static fn(bool $open): string => 'style="display:' . ($open ? 'block' : 'none') . ';"';
    ?>
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h3>Ferreteria Aldrete</h3>
            <p>Administrador</p>
        </div>
        <div class="sidebar-menu">
            <a class="menu-item<?= $isActive('inicio') ?>" href="inicioAdmin.php">Inicio</a>
            <a class="menu-item<?= $isActive('usuarios') . $isActive('form_usuario') ?>" href="usuarios.php">Usuarios</a>
            <a class="menu-item<?= $isActive('sucursales') . $isActive('form_sucursal') ?>" href="sucursales.php">Sucursales</a>
            <div class="divider"></div>
            <a class="menu-item<?= $isActive('reporte_ventas') ?>" href="reporteVentas.php">Ventas</a>
            <a class="menu-item<?= $isActive('reporte_productos') ?>" href="reporteProductos.php">Productos mas vendidos</a>
            <a class="menu-item<?= $isActive('historial_admin') ?>" href="historial.php">Historial de movimientos</a>
            <a class="menu-item<?= $isActive('cortes_admin') ?>" href="cortes.php">Cortes de caja</a>
            <div class="divider"></div>
            <a class="menu-item<?= $isActive('clientes_admin') ?>" href="clientes.php">Clientes</a>
            <a class="menu-item<?= $isActive('creditos_admin') ?>" href="creditos.php">Creditos</a>
            <a class="menu-item<?= $isActive('abonos_admin') ?>" href="abonos.php">Abonos</a>
            <div class="divider"></div>
            <button type="button" class="menu-item" onclick="toggleAdminSection('rrhhSubmenu')" style="width:100%;text-align:left;background:none;border:none;">
                Recursos Humanos
                <span style="float:right;opacity:.6;"><?= $rrhhOpen ? '-' : '+' ?></span>
            </button>
            <div id="rrhhSubmenu" <?= $submenuStyle($rrhhOpen) ?>>
                <a class="menu-item<?= $isActive('rrhh_empleados') . $isActive('rrhh_form_empleado') ?>" href="empleados.php" style="padding-left:28px;">Empleados</a>
                <a class="menu-item<?= $isActive('rrhh_asistencia') . $isActive('rrhh_form_asistencia') ?>" href="asistencia.php" style="padding-left:28px;">Asistencia</a>
                <a class="menu-item<?= $isActive('rrhh_semana') ?>" href="semanaLaboral.php" style="padding-left:28px;">Semana laboral</a>
                <a class="menu-item<?= $isActive('rrhh_vacaciones') . $isActive('rrhh_form_vacacion') ?>" href="vacaciones.php" style="padding-left:28px;">Vacaciones</a>
            </div>
            <div class="divider"></div>
            <a class="menu-item<?= $isActive('gastos') ?>" href="gastos.php">Bitacora de gastos</a>
            <div class="divider"></div>

            <button type="button" class="menu-item" onclick="toggleAdminSection('inventarioSubmenu')" style="width:100%;text-align:left;background:none;border:none;">
                Inventario
                <span style="float:right;opacity:.6;"><?= $inventarioOpen ? '-' : '+' ?></span>
            </button>
            <div id="inventarioSubmenu" <?= $submenuStyle($inventarioOpen) ?>>
                <a class="menu-item<?= $isActive('inventario_inicio') ?>" href="inventario_inicio.php" style="padding-left:28px;">Inicio inventario</a>
                <a class="menu-item<?= $isActive('inventario_productos') . $isActive('inventario_form_producto') ?>" href="inventario_productos.php" style="padding-left:28px;">Productos</a>
                <a class="menu-item<?= $isActive('inventario_categorias') ?>" href="inventario_categorias.php" style="padding-left:28px;">Categorias</a>
                <a class="menu-item<?= $isActive('inventario_unidades') ?>" href="inventario_unidades.php" style="padding-left:28px;">Unidades de medida</a>
                <a class="menu-item<?= $isActive('inventario_entradas') ?>" href="inventario_entradas.php" style="padding-left:28px;">Entradas</a>
                <a class="menu-item<?= $isActive('inventario_salidas') ?>" href="inventario_salidas.php" style="padding-left:28px;">Salidas</a>
                <a class="menu-item<?= $isActive('inventario_historial') ?>" href="inventario_historial.php" style="padding-left:28px;">Historial</a>
                <a class="menu-item<?= $isActive('inventario_proveedores') ?>" href="inventario_proveedores.php" style="padding-left:28px;">Proveedores</a>
                <a class="menu-item<?= $isActive('inventario_compras') ?>" href="inventario_compras.php" style="padding-left:28px;">Compras</a>
                <a class="menu-item<?= $isActive('inventario_paquetes') ?>" href="inventario_paquetes.php" style="padding-left:28px;">Paquetes</a>
                <a class="menu-item<?= $isActive('inventario_transferencias') ?>" href="inventario_transferencias.php" style="padding-left:28px;">Transferencias</a>
                <a class="menu-item<?= $isActive('inventario_mas_vendidos') ?>" href="inventario_masVendidos.php" style="padding-left:28px;">Mas vendidos</a>
                <a class="menu-item<?= $isActive('promociones') ?>" href="promociones.php" style="padding-left:28px;">Promociones</a>
            </div>

            <button type="button" class="menu-item" onclick="toggleAdminSection('cajeroSubmenu')" style="width:100%;text-align:left;background:none;border:none;">
                Cajero
                <span style="float:right;opacity:.6;"><?= $cajeroOpen ? '-' : '+' ?></span>
            </button>
            <div id="cajeroSubmenu" <?= $submenuStyle($cajeroOpen) ?>>
                <a class="menu-item<?= $isActive('cajero_inicio') ?>" href="cajero_inicio.php" style="padding-left:28px;">Inicio cajero</a>
                <a class="menu-item<?= $isActive('cajero_nueva_venta') ?>" href="cajero_nuevaVenta.php" style="padding-left:28px;">Nueva venta</a>
                <a class="menu-item<?= $isActive('cajero_historial_ventas') ?>" href="cajero_historialVentas.php" style="padding-left:28px;">Historial ventas</a>
                <a class="menu-item<?= $isActive('cajero_abrir_caja') ?>" href="cajero_abrirCaja.php" style="padding-left:28px;">Abrir caja</a>
                <a class="menu-item<?= $isActive('cajero_corte_caja') ?>" href="cajero_corteCaja.php" style="padding-left:28px;">Corte de caja</a>
                <a class="menu-item<?= $isActive('cajero_historial_cortes') ?>" href="cajero_historialCortes.php" style="padding-left:28px;">Historial cortes</a>
                <a class="menu-item<?= $isActive('cajero_clientes') ?>" href="cajero_clientes.php" style="padding-left:28px;">Clientes cajero</a>
                <a class="menu-item<?= $isActive('cajero_creditos') ?>" href="cajero_creditos.php" style="padding-left:28px;">Creditos cajero</a>
                <a class="menu-item<?= $isActive('cajero_abonos') ?>" href="cajero_abonos.php" style="padding-left:28px;">Abonos</a>
                <a class="menu-item<?= $isActive('cajero_ventas_pendientes') ?>" href="cajero_ventasPendientes.php" style="padding-left:28px;">Ventas pendientes</a>
                <a class="menu-item<?= $isActive('cajero_devoluciones') ?>" href="cajero_devoluciones.php" style="padding-left:28px;">Devoluciones</a>
            </div>
        </div>
        <div class="sidebar-footer">v1.0.0</div>
    </div>
    <script>
    function toggleAdminSection(id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.style.display = el.style.display === 'none' ? 'block' : 'none';
    }
    </script>
    <?php
}

