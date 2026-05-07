/**
 * auto_filter.js — Oculta el botón "Filtrar" y envía el formulario
 * automáticamente al cambiar cualquier campo del bloque .filtros.
 * - Selects / dates / checkboxes: envío inmediato al cambiar.
 * - Inputs de texto: envío con 600 ms de debounce (permite escribir sin recargar en cada tecla).
 */
(function () {
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.filtros').forEach(function (filtros) {
            var form = filtros.closest('form');
            if (!form) return;

            // Ocultar botón Filtrar
            filtros.querySelectorAll('.btn-filtrar').forEach(function (btn) {
                btn.style.display = 'none';
            });

            // Envío inmediato al cambiar selects, fechas, checkboxes y radios
            filtros.querySelectorAll('select, input[type="date"], input[type="checkbox"], input[type="radio"]')
                .forEach(function (el) {
                    el.addEventListener('change', function () { form.submit(); });
                });

            // Envío con debounce para inputs de texto (omite los que tengan data-no-auto)
            var timer;
            filtros.querySelectorAll('input[type="text"], input[type="search"], input[type="number"]')
                .forEach(function (el) {
                    if (el.hasAttribute('data-no-auto')) return;
                    el.addEventListener('input', function () {
                        clearTimeout(timer);
                        timer = setTimeout(function () { form.submit(); }, 600);
                    });
                });
        });
    });
})();
