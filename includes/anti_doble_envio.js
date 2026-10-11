/*
 * anti_doble_envio.js -- evita que un formulario se envie dos (o muchas) veces por picar el boton varias veces
 * mientras la pagina todavia esta cargando (internet lento). Antes, cada clic extra volvia a registrar la accion
 * (por ejemplo, un movimiento de caja repetido muchas veces).
 *
 * Como funciona: al primer envio valido de un formulario POST, los envios siguientes de ESE formulario se cancelan
 * durante 15 segundos y sus botones se ven deshabilitados. Si la pagina regresa con "Atras" o pasan los 15 segundos
 * (por ejemplo, el servidor no respondio), el formulario se libera. No afecta a los formularios que se validan y
 * se cancelan (return false) ni a los que se mandan por JavaScript (fetch): esos ya manejan su propio boton.
 */
(function () {
    var VENTANA_MS = 15000;
    var marcados = [];

    function liberar(f) {
        delete f.__envioEn;
        var bs = f.querySelectorAll('[data-anti-doble]');
        for (var i = 0; i < bs.length; i++) { bs[i].disabled = false; bs[i].removeAttribute('data-anti-doble'); }
    }

    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (!f || f.tagName !== 'FORM') return;
        if ((f.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
        if (e.defaultPrevented) return;                       // otro codigo lo cancelo (validacion): no cuenta
        var ahora = Date.now();
        if (f.__envioEn && ahora - f.__envioEn < VENTANA_MS) { // ya se envio hace un momento: se ignora este
            e.preventDefault();
            e.stopImmediatePropagation();
            return;
        }
        f.__envioEn = ahora;
        marcados.push(f);
        // Los botones se deshabilitan DESPUES de que el navegador armo los datos del formulario
        // (si se hiciera antes, un boton con name/value no viajaria en el POST).
        setTimeout(function () {
            var bs = f.querySelectorAll('button[type=submit], input[type=submit], button:not([type])');
            for (var i = 0; i < bs.length; i++) {
                if (!bs[i].disabled) { bs[i].disabled = true; bs[i].setAttribute('data-anti-doble', '1'); }
            }
        }, 0);
        setTimeout(function () { liberar(f); }, VENTANA_MS);
    }, false);

    // Al volver con "Atras" el navegador puede restaurar la pagina tal como estaba (botones apagados): se liberan.
    window.addEventListener('pageshow', function () {
        for (var i = 0; i < marcados.length; i++) liberar(marcados[i]);
        marcados = [];
        var bs = document.querySelectorAll('[data-anti-doble]');
        for (var j = 0; j < bs.length; j++) { bs[j].disabled = false; bs[j].removeAttribute('data-anti-doble'); }
    });
})();
