/**
 * seleccionar_cantidad.js — al entrar a una caja de cantidad (clic, tabulador o foco por codigo) se selecciona
 * TODO su contenido, para que lo que se escriba reemplace el numero que hubiera (el "1" por defecto, por
 * ejemplo) sin tener que borrarlo antes.
 *
 * Funciona por delegacion de eventos en todo el documento, asi que tambien cubre las cajas que se dibujan
 * despues con JavaScript (renglones del carrito, devoluciones, etc.) sin tocar cada pantalla.
 *
 * Aplica a TODO <input type="number"> (cantidades, montos, precios, descuentos, horas...) y a las cajas de
 * texto que son cantidades por su nombre (id / name / clase con "cant" o "qty": cantidad_inicial, qty-input...,
 * y el stock minimo / maximo). Telefonos, cuentas, CLABE, RFC y demas texto NO se tocan. Los campos de solo
 * lectura o deshabilitados se dejan en paz.
 */
(function () {
    var PATRON = /cant|qty|stock_minimo|stock_maximo/i;

    function esCajaCantidad(el) {
        if (!el || el.tagName !== 'INPUT') return false;
        var tipo = (el.type || 'text').toLowerCase();
        if (tipo !== 'number' && tipo !== 'text') return false;
        if (el.readOnly || el.disabled) return false;
        // Cualquier campo numerico (cantidades, montos, precios, descuentos, horas...) selecciona todo al entrar.
        if (tipo === 'number' || el.hasAttribute('data-restante')) return true;
        // Cajas de texto que son cantidades (por su nombre).
        return PATRON.test((el.id || '') + ' ' + (el.name || '') + ' ' + (typeof el.className === 'string' ? el.className : ''));
    }

    var recienEnfocado = null;

    document.addEventListener('focusin', function (e) {
        if (!esCajaCantidad(e.target)) return;
        recienEnfocado = e.target;
        try { e.target.select(); } catch (err) { /* algunos navegadores no permiten select() en ciertos tipos */ }
    }, true);

    // El clic que acaba de dar el foco suelta el boton DESPUES de seleccionar y el navegador colocaria el cursor,
    // deshaciendo la seleccion: se cancela solo ese primer "mouseup". Un segundo clic ya coloca el cursor normal.
    document.addEventListener('mouseup', function (e) {
        if (recienEnfocado && e.target === recienEnfocado) e.preventDefault();
        recienEnfocado = null;
    }, true);
})();
