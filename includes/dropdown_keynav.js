/**
 * dropdown_keynav.js — Navegación por teclado (flecha abajo / flecha arriba /
 * Enter) para los dropdowns de búsqueda tipo "escribe y elige" del sistema
 * (buscar producto, proveedor, cliente, área, categoría, etc.).
 *
 * attachDropdownKeyNav(input, drop, hide)
 *   input : el <input> de búsqueda sobre el que se escuchan las flechas/Enter.
 *   drop  : el contenedor del dropdown. Sus resultados seleccionables son los
 *           <div onclick="..."> o <div onmousedown="..."> HIJOS DIRECTOS de
 *           este contenedor (admin/inventario_paquetes.php selecciona por
 *           onmousedown, no onclick) -- así ya filtra solo a las opciones
 *           reales, sin tocar nada: los mensajes de "Sin resultados" en cada
 *           archivo nunca llevan ninguno de los dos, así que quedan afuera solos.
 *   hide  : función que oculta el dropdown EXACTAMENTE como ya lo hace el
 *           archivo (unos usan drop.style.display='none', otros
 *           drop.classList.remove('visible')). Se recibe la propia función
 *           del archivo en vez de adivinar el mecanismo -- adivinar mal
 *           rompería el mostrar/ocultar ya existente (un style.display='none'
 *           puesto a mano sobre un dropdown que se muestra por clase se queda
 *           pegado para siempre y el dropdown ya no vuelve a abrir).
 *
 * Enter SOLO actúa si ya hay un elemento resaltado con las flechas -- si el
 * usuario nunca tocó una flecha, Enter no hace nada nuevo (se preserva el
 * comportamiento de siempre en ese caso).
 */
(function () {
    function attachDropdownKeyNav(input, drop, hide) {
        if (!input || !drop) return;
        var activeIndex = -1;
        var HILITE = '0 0 0 2px #14ace7 inset';

        function items() {
            return Array.prototype.slice.call(drop.querySelectorAll(':scope > div[onclick], :scope > div[onmousedown]'));
        }
        function activar(el) {
            // Simula exactamente la interacción real que ya maneja el archivo -- .click()
            // dispara el onclick tal cual, y para los pocos casos que seleccionan por
            // onmousedown (en vez de onclick) se dispara ese evento en su lugar.
            if (el.hasAttribute('onclick')) {
                el.click();
            } else if (el.hasAttribute('onmousedown')) {
                el.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }));
            }
        }
        function clearHighlight(list) {
            list.forEach(function (it) { it.style.boxShadow = ''; });
        }
        function setActive(list, idx) {
            clearHighlight(list);
            activeIndex = idx;
            if (idx >= 0 && idx < list.length) {
                list[idx].style.boxShadow = HILITE;
                if (typeof list[idx].scrollIntoView === 'function') {
                    list[idx].scrollIntoView({ block: 'nearest' });
                }
            }
        }

        // El dropdown se re-renderiza por completo (innerHTML nuevo) en cada
        // tecla escrita -- sin esto, activeIndex quedaría apuntando a un <div>
        // que ya no existe o a la posición equivocada de la lista nueva.
        new MutationObserver(function () { activeIndex = -1; }).observe(drop, { childList: true });

        input.addEventListener('keydown', function (e) {
            if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp' && e.key !== 'Enter' && e.key !== 'Escape') return;
            var list = items();

            if (e.key === 'ArrowDown') {
                if (!list.length) return;
                e.preventDefault();
                setActive(list, activeIndex < list.length - 1 ? activeIndex + 1 : list.length - 1);
            } else if (e.key === 'ArrowUp') {
                if (!list.length) return;
                e.preventDefault();
                setActive(list, activeIndex > 0 ? activeIndex - 1 : 0);
            } else if (e.key === 'Enter') {
                if (activeIndex >= 0 && activeIndex < list.length) {
                    e.preventDefault();
                    var el = list[activeIndex];
                    activeIndex = -1;
                    activar(el);
                }
            } else if (e.key === 'Escape') {
                if (activeIndex !== -1) {
                    activeIndex = -1;
                    clearHighlight(list);
                }
                if (typeof hide === 'function') hide();
            }
        });
    }

    window.attachDropdownKeyNav = attachDropdownKeyNav;
})();
