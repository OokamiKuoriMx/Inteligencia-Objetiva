<?php
/**
 * Migas de pan reutilizables de la tienda 2.0.
 *
 *   require_once __DIR__ . '/_migas.php';
 *   echo migas([
 *       ['texto' => 'Inicio', 'url' => ruta('inicio')],
 *       ['texto' => 'Catálogo', 'url' => ruta('catalogo')],
 *       ['texto' => 'Título del tema'],            // último: página actual, sin URL
 *   ]);
 *
 * Genera un <nav> accesible con <ol>, separadores SVG y aria-current en el último paso,
 * más un bloque JSON-LD BreadcrumbList con URL absolutas. En celular se reduce a
 * "‹ Volver a {paso anterior}". Los estilos viven en migas.css y se enlazan una sola vez.
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/IO.php';

if (!function_exists('migas')) {
    function migas(array $pasos): string
    {
        static $cssEmitido = false;
        $pasos = array_values(array_filter($pasos, fn($p) => is_array($p) && trim((string) ($p['texto'] ?? '')) !== ''));
        if (!$pasos) { return ''; }

        $esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $base = migas_dominio();
        $total = count($pasos);
        $chevron = '<svg class="io-migas-sep" viewBox="0 0 16 16" width="12" height="12" aria-hidden="true" focusable="false"><path d="M6 3.5 10.5 8 6 12.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        $atras = '<svg class="io-migas-atras" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" focusable="false"><path d="M10 3.5 5.5 8 10 12.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';

        $items = '';
        $ld = [];
        foreach ($pasos as $i => $p) {
            $texto = trim((string) $p['texto']);
            $corto = mb_strimwidth($texto, 0, 60, '…', 'UTF-8');
            $titulo = $corto !== $texto ? ' title="' . $esc($texto) . '"' : '';
            $ultimo = $i === $total - 1;
            $url = $ultimo ? '' : (string) ($p['url'] ?? '');

            if ($ultimo) {
                $items .= '<li class="io-migas-actual"><span aria-current="page"' . $titulo . '>' . $esc($corto) . '</span></li>';
            } elseif ($url !== '') {
                $items .= '<li><a href="' . $esc($url) . '"' . $titulo . '>' . $atras
                    . '<span class="io-migas-volver">Volver a </span><span class="io-migas-txt">' . $esc($corto) . '</span></a>' . $chevron . '</li>';
            } else {
                $items .= '<li><span class="io-migas-txt"' . $titulo . '>' . $esc($corto) . '</span>' . $chevron . '</li>';
            }

            $elem = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $texto];
            if ($url !== '') { $elem['item'] = migas_absoluta($url, $base); }
            $ld[] = $elem;
        }

        $html = '';
        if (!$cssEmitido) {
            $cssEmitido = true;
            $html .= '<link rel="stylesheet" href="/io/tienda/migas.css?v=2.0.0">';
        }
        $html .= '<nav class="io-migas" aria-label="Ruta de navegación"><ol>' . $items . '</ol></nav>';
        $html .= '<script type="application/ld+json">' . json_encode(
            ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $ld],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
        ) . '</script>';
        return $html;
    }

    /** Dominio público sin barra final (config 'dominio'; si falta, el host de la petición). */
    function migas_dominio(): string
    {
        try { $d = (string) (IO::config()['dominio'] ?? ''); } catch (Throwable $e) { $d = ''; }
        if ($d === '') {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            $d = ($https ? 'https://' : 'http://') . preg_replace('/[^a-z0-9.\-:\[\]]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        }
        return rtrim($d, '/');
    }

    function migas_absoluta(string $url, string $base): string
    {
        if (preg_match('#^https?://#i', $url)) { return $url; }
        return $base . '/' . ltrim($url, '/');
    }
}
