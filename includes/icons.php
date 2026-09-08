<?php
/**
 * Devuelve el markup SVG (inline) de un icono Lucide guardado en assets/icons/.
 * El SVG usa stroke="currentColor", por lo que el color se hereda del CSS del contenedor.
 */
function icono(string $nombre, string $clase = '', int $size = 20): string
{
    static $cache = [];

    if (!isset($cache[$nombre])) {
        $ruta = __DIR__ . '/../assets/icons/' . basename($nombre) . '.svg';
        if (!file_exists($ruta)) {
            $cache[$nombre] = '';
        } else {
            $svg = file_get_contents($ruta);
            $svg = preg_replace('/<!--.*?-->\s*/s', '', $svg);
            $svg = preg_replace_callback('/<svg\b[^>]*>/', function ($m) {
                $tag = preg_replace('/\s(class|width|height)="[^"]*"/', '', $m[0]);
                return $tag;
            }, $svg, 1);
            $cache[$nombre] = trim($svg);
        }
    }

    $svg = $cache[$nombre];
    if ($svg === '') {
        return '';
    }

    $claseAttr = trim('icon-svg ' . $clase);
    $atributos = ' class="' . htmlspecialchars($claseAttr, ENT_QUOTES) . '" width="' . $size . '" height="' . $size . '" style="vertical-align:-0.2em;flex-shrink:0;"';

    return preg_replace('/<svg/', '<svg' . $atributos, $svg, 1);
}

function icon(string $nombre, string $clase = '', int $size = 20): void
{
    echo icono($nombre, $clase, $size);
}
