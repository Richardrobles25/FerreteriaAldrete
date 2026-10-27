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

    // Tope por defecto: una caja numerica SIN max dejaba que la flechita (mantenida) subiera el valor sin parar.
    // Al interactuar con ella se le pone el maximo de las columnas DECIMAL(10,x) de la base (99,999,999); donde la
    // pantalla conoce un limite real (stock, restante, enviado...) ya trae su propio max y no se toca.
    var MAX_POR_DEFECTO = '99999999';
    function ponerTopeSiFalta(e) {
        var el = e.target;
        if (!el || el.tagName !== 'INPUT' || (el.type || '').toLowerCase() !== 'number') return;
        if (el.readOnly || el.disabled || el.hasAttribute('max')) return;
        el.setAttribute('max', MAX_POR_DEFECTO);
    }
    ['mouseover', 'pointerdown', 'focusin'].forEach(function (ev) { document.addEventListener(ev, ponerTopeSiFalta, true); });

    // Las flechitas nativas, al mantenerlas apretadas, repiten el paso sin parar (de 1 en 1 a toda velocidad hasta el
    // tope). Aqui cada clic en la flechita da UN solo paso (arriba suma, abajo resta) y mantenerla no repite; el teclado
    // (flecha arriba / abajo) tambien da un paso por pulsacion aunque se deje apretada. Respeta min, max y step.
    var ANCHO_FLECHITAS = 22;   // px del borde derecho donde Chrome/Edge dibuja la flechita
    function darPaso(el, arriba) {
        try { arriba ? el.stepUp() : el.stepDown(); } catch (err) { return; }   // stepUp falla si no hay paso valido
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
    }
    document.addEventListener('mousedown', function (e) {
        var el = e.target;
        if (e.button !== 0 || !el || el.tagName !== 'INPUT' || (el.type || '').toLowerCase() !== 'number') return;
        if (el.readOnly || el.disabled) return;
        var r = el.getBoundingClientRect();
        if (e.clientX < r.right - ANCHO_FLECHITAS || e.clientX > r.right) return;
        e.preventDefault();                       // sin esto el navegador arranca su repeticion automatica
        if (document.activeElement !== el) el.focus();
        darPaso(el, e.clientY < r.top + r.height / 2);
    }, true);
    document.addEventListener('keydown', function (e) {
        var el = e.target;
        if (!e.repeat || (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')) return;
        if (el && el.tagName === 'INPUT' && (el.type || '').toLowerCase() === 'number') e.preventDefault();
    }, true);

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
