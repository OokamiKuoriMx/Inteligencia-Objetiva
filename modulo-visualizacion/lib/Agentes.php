<?php
/**
 * Acceso para buscadores y agentes de IA: robots.txt, llms.txt, llms-full.txt, sitemap.xml y datos estructurados.
 *
 * Las funciones de texto son puras (reciben arreglos) para poder probarlas sin base de datos.
 * Solo se publica lo que ya es público en el sitio: fichas del catálogo activas, temas, la página
 * Nosotros y las transmisiones con acceso público. Nada del alumno, del backoffice ni de los videos completos.
 */
declare(strict_types=1);
require_once __DIR__ . '/IO.php';
require_once __DIR__ . '/Tienda.php';

final class IOAgentes
{
    /** Rastreadores de buscadores y asistentes de IA a los que se da la bienvenida de forma explícita. */
    public const RASTREADORES = [
        'Googlebot', 'Google-Extended', 'Bingbot', 'Applebot', 'Applebot-Extended', 'DuckDuckBot', 'DuckAssistBot',
        'GPTBot', 'OAI-SearchBot', 'ChatGPT-User',
        'ClaudeBot', 'Claude-SearchBot', 'Claude-User',
        'PerplexityBot', 'Perplexity-User',
        'Meta-ExternalAgent', 'Amazonbot', 'MistralAI-User', 'CCBot',
    ];

    /** Rutas privadas o transaccionales que no aportan nada a un buscador. */
    public const PRIVADAS = [
        '/io/backoffice/', '/io/api/', '/io/public/', '/carrito', '/gracias',
        '/io/tienda/carrito.php', '/io/tienda/gracias.php', '/io/tienda/mis-pedidos.php', '/io/tienda/facturacion.php',
    ];

    /** Páginas de categoría con URL propia en el sitio (las demás se ven desde el catálogo). */
    public const RUTAS_CATEGORIA = [
        'conferencias' => '/conferencias-en-linea', 'cursos' => '/cursos-en-linea',
        'documentales' => '/documentales', 'audiolibro' => '/audiolibro',
    ];

    public const FUNDADOR = [
        'nombre' => 'Martín Aparicio',
        'rol' => 'Filósofo, conferencista, comunicador, periodista, investigador y escritor',
        'resumen' => 'Lleva más de veinte años estudiando la mente y la conciencia de uno mismo. Su trabajo atiende problemas como la desintegración familiar, las adicciones, la pobreza, el estrés laboral, la delincuencia y las dificultades en la pareja, que en buena parte nacen de perder el control de las emociones.',
    ];

    // ---------- Dominio ----------
    public static function dominio(): string
    {
        try { $d = (string) (IO::config()['dominio'] ?? ''); } catch (Throwable $e) { $d = ''; }
        if ($d === '') {
            $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            $d = ($https ? 'https://' : 'http://') . preg_replace('/[^a-z0-9.\-:\[\]]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        }
        return rtrim($d, '/');
    }

    private static function abs(string $dominio, string $ruta): string
    {
        return preg_match('#^https?://#i', $ruta) ? $ruta : rtrim($dominio, '/') . '/' . ltrim($ruta, '/');
    }

    // ---------- Datos públicos desde la base ----------
    public static function productosPublicos(): array
    {
        return IO::todos('SELECT producto_id, slug, titulo, categoria, tema, resumen, descripcion, aprenderas, imagen, precio_mxn, incluye, creado
            FROM io_productos WHERE activo = 1 ORDER BY categoria, titulo');
    }

    /** Transmisiones públicas próximas y grabaciones pasadas; si la tabla aún no existe, devuelve una lista vacía. */
    public static function transmisionesPublicas(): array
    {
        try {
            return IO::todos("SELECT slug, titulo, descripcion, imagen, inicio, duracion_min, estado, acceso, actualizado
                FROM io_transmisiones WHERE acceso = 'publico' AND estado IN ('programada','en_vivo','finalizada')
                ORDER BY inicio DESC LIMIT 100");
        } catch (Throwable $e) { return []; }
    }

    // ---------- robots.txt ----------
    public static function robots(string $dominio, array $extra = []): string
    {
        $l = ['# Inteligencia Objetiva: el contenido público puede leerse y citarse por buscadores y asistentes de IA.', ''];
        foreach (self::RASTREADORES as $bot) { $l[] = 'User-agent: ' . $bot; }
        $l[] = 'User-agent: *';
        $l[] = 'Allow: /';
        foreach (self::PRIVADAS as $r) { $l[] = 'Disallow: ' . $r; }
        foreach ($extra as $x) {
            $x = trim(str_replace(["\r", "\n"], '', (string) $x));
            if ($x !== '') { $l[] = $x; }
        }
        $l[] = '';
        $l[] = 'Sitemap: ' . self::abs($dominio, '/sitemap.xml');
        return implode("\n", $l) . "\n";
    }

    // ---------- sitemap.xml ----------
    public static function sitemap(string $dominio, array $productos, array $transmisiones): string
    {
        $urls = [['/', null], ['/nosotros', null], ['/catalogo', null], ['/en-vivo', null], ['/io/tienda/membresias.php', null]];
        foreach (self::RUTAS_CATEGORIA as $r) { $urls[] = [$r, null]; }
        foreach (array_keys(IOTienda::TEMAS) as $tema) { $urls[] = ['/catalogo?tema=' . rawurlencode($tema), null]; }
        foreach ($productos as $p) { $urls[] = ['/producto/' . rawurlencode((string) $p['slug']), $p['creado'] ?? null]; }
        foreach ($transmisiones as $t) { $urls[] = ['/en-vivo?t=' . rawurlencode((string) $t['slug']), $t['actualizado'] ?? null]; }

        $x = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];
        $vistos = [];
        foreach ($urls as [$ruta, $fecha]) {
            $loc = self::abs($dominio, $ruta);
            if (isset($vistos[$loc])) { continue; }
            $vistos[$loc] = true;
            $x[] = '  <url><loc>' . htmlspecialchars($loc, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>'
                . ($fecha && strtotime((string) $fecha) ? '<lastmod>' . date('Y-m-d', strtotime((string) $fecha)) . '</lastmod>' : '') . '</url>';
        }
        $x[] = '</urlset>';
        return implode("\n", $x) . "\n";
    }

    // ---------- llms.txt y llms-full.txt ----------
    private static function md(string $s): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
        return str_replace(['[', ']'], ['(', ')'], $s);
    }

    public static function llms(string $dominio, array $productos, array $transmisiones, bool $completo = false): string
    {
        $f = self::FUNDADOR;
        $l = [
            '# Inteligencia Objetiva',
            '',
            '> La primera escuela del pensamiento, fundada por ' . $f['nombre'] . '. Conferencias, cursos, documentales, introspecciones, audiolibros y transmisiones en vivo para aprender a ver la vida tal como es, conocerse a uno mismo y tomar decisiones desde la conciencia y no desde la emoción.',
            '',
            'Sitio en español de México. Las fichas del catálogo, la página Nosotros y las transmisiones públicas pueden leerse y citarse. Los videos completos son de pago y solo los ve quien los compró.',
            '',
            '## Fundador',
            '',
            '- [' . $f['nombre'] . '](' . self::abs($dominio, '/nosotros') . '): ' . $f['rol'] . '. ' . $f['resumen'],
            '',
            '## Temas',
            '',
            'Entre otros, de manera enunciativa y no limitativa: autoconocimiento, la mente y la conciencia, el control de las emociones, espiritualidad, filosofía de vida, ' . implode(', ', array_map('mb_strtolower', IOTienda::TEMAS)) . '.',
            '',
        ];
        foreach (IOTienda::TEMAS as $clave => $nombre) {
            $l[] = '- [' . $nombre . '](' . self::abs($dominio, '/catalogo?tema=' . rawurlencode($clave)) . ')';
        }
        $l[] = '';

        $porCategoria = [];
        foreach ($productos as $p) { $porCategoria[(string) $p['categoria']][] = $p; }
        foreach (IOTienda::CATEGORIAS as $cat => $nombre) {
            if (empty($porCategoria[$cat])) { continue; }
            $l[] = '## ' . $nombre;
            $l[] = '';
            foreach ($porCategoria[$cat] as $p) {
                $url = self::abs($dominio, '/producto/' . rawurlencode((string) $p['slug']));
                if (!$completo) {
                    $l[] = '- [' . self::md((string) $p['titulo']) . '](' . $url . ')' . (trim((string) $p['resumen']) !== '' ? ': ' . self::md((string) $p['resumen']) : '');
                    continue;
                }
                $l[] = '### ' . self::md((string) $p['titulo']);
                $l[] = '';
                $l[] = '- Ficha: ' . $url;
                if (!empty($p['tema']) && isset(IOTienda::TEMAS[$p['tema']])) { $l[] = '- Tema: ' . IOTienda::TEMAS[$p['tema']]; }
                if ((float) ($p['precio_mxn'] ?? 0) > 0) { $l[] = '- Precio: $' . number_format((float) $p['precio_mxn'], 2, '.', ',') . ' MXN con IVA'; }
                if (trim((string) ($p['incluye'] ?? '')) !== '') { $l[] = '- Incluye: ' . self::md((string) $p['incluye']); }
                $l[] = '';
                $texto = trim((string) ($p['descripcion'] ?: $p['resumen']));
                if ($texto !== '') { $l[] = trim(preg_replace("/[ \t]+/u", ' ', str_replace("\r", '', $texto)) ?? ''); $l[] = ''; }
                $ideas = array_values(array_filter(array_map('trim', explode("\n", (string) ($p['aprenderas'] ?? '')))));
                if ($ideas) {
                    $l[] = 'Lo que vas a comprender:';
                    foreach ($ideas as $i) { $l[] = '- ' . self::md($i); }
                    $l[] = '';
                }
            }
            if (!$completo) { $l[] = ''; }
        }

        if ($transmisiones) {
            $l[] = '## Transmisiones en vivo';
            $l[] = '';
            foreach ($transmisiones as $t) {
                $l[] = '- [' . self::md((string) $t['titulo']) . '](' . self::abs($dominio, '/en-vivo?t=' . rawurlencode((string) $t['slug'])) . '): '
                    . date('Y-m-d H:i', strtotime((string) $t['inicio'])) . ' (hora del centro de México)'
                    . ($completo && trim((string) $t['descripcion']) !== '' ? '. ' . self::md((string) $t['descripcion']) : '');
            }
            $l[] = '';
        }

        $l[] = '## Más información';
        $l[] = '';
        $l[] = '- [Catálogo completo](' . self::abs($dominio, '/catalogo') . ')';
        $l[] = '- [Membresías](' . self::abs($dominio, '/io/tienda/membresias.php') . ')';
        $l[] = '- [Calendario de transmisiones](' . self::abs($dominio, '/en-vivo') . ')';
        if (!$completo) { $l[] = '- [Versión completa de este archivo, con descripciones](' . self::abs($dominio, '/llms-full.txt') . ')'; }
        $l[] = '- Contacto: contacto@iobjetiva.com · WhatsApp +52 55 1951 4412';
        return implode("\n", $l) . "\n";
    }

    // ---------- Datos estructurados (schema.org) ----------
    public static function grafoSitio(string $dominio): array
    {
        $org = self::abs($dominio, '/#organizacion');
        $persona = self::abs($dominio, '/nosotros#martin-aparicio');
        return ['@context' => 'https://schema.org', '@graph' => [
            ['@type' => 'Organization', '@id' => $org, 'name' => 'Inteligencia Objetiva', 'url' => self::abs($dominio, '/'),
                'logo' => self::abs($dominio, '/img/icons/logo.png'), 'slogan' => 'La primera escuela del pensamiento',
                'email' => 'contacto@iobjetiva.com', 'telephone' => '+52 55 1951 4412', 'founder' => ['@id' => $persona],
                'knowsAbout' => array_merge(['Autoconocimiento', 'Conciencia', 'Control de las emociones', 'Espiritualidad', 'Filosofía'], array_values(IOTienda::TEMAS))],
            ['@type' => 'Person', '@id' => $persona, 'name' => self::FUNDADOR['nombre'], 'jobTitle' => self::FUNDADOR['rol'],
                'description' => self::FUNDADOR['resumen'], 'url' => self::abs($dominio, '/nosotros'), 'worksFor' => ['@id' => $org]],
            ['@type' => 'WebSite', '@id' => self::abs($dominio, '/#sitio'), 'name' => 'Inteligencia Objetiva', 'url' => self::abs($dominio, '/'),
                'inLanguage' => 'es-MX', 'publisher' => ['@id' => $org],
                'potentialAction' => ['@type' => 'SearchAction', 'target' => self::abs($dominio, '/catalogo') . '?q={search_term_string}', 'query-input' => 'required name=search_term_string']],
        ]];
    }

    public static function curso(string $dominio, array $p): array
    {
        $ideas = array_values(array_filter(array_map('trim', explode("\n", (string) ($p['aprenderas'] ?? '')))));
        $url = self::abs($dominio, '/producto/' . rawurlencode((string) $p['slug']));
        $d = [
            '@context' => 'https://schema.org', '@type' => 'Course', '@id' => $url . '#curso', 'url' => $url,
            'name' => (string) $p['titulo'], 'description' => (string) ($p['resumen'] ?: $p['descripcion']), 'inLanguage' => 'es-MX',
            'provider' => ['@type' => 'Organization', '@id' => self::abs($dominio, '/#organizacion'), 'name' => 'Inteligencia Objetiva', 'url' => self::abs($dominio, '/')],
            'creator' => ['@type' => 'Person', '@id' => self::abs($dominio, '/nosotros#martin-aparicio'), 'name' => self::FUNDADOR['nombre']],
            'hasCourseInstance' => ['@type' => 'CourseInstance', 'courseMode' => 'online'],
            'offers' => ['@type' => 'Offer', 'url' => $url, 'category' => 'Paid', 'price' => number_format((float) $p['precio_mxn'], 2, '.', ''),
                'priceCurrency' => 'MXN', 'availability' => 'https://schema.org/InStock'],
        ];
        if (!empty($p['imagen'])) { $d['image'] = self::abs($dominio, (string) $p['imagen']); }
        if (!empty($p['tema']) && isset(IOTienda::TEMAS[$p['tema']])) { $d['about'] = IOTienda::TEMAS[$p['tema']]; }
        if (isset(IOTienda::CATEGORIAS[$p['categoria'] ?? ''])) { $d['learningResourceType'] = IOTienda::CATEGORIAS[$p['categoria']]; }
        if ($ideas) { $d['teaches'] = $ideas; }
        if ((int) ($p['resenas'] ?? 0) > 0) { $d['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => (float) $p['rating'], 'reviewCount' => (int) $p['resenas']]; }
        return $d;
    }

    /** Evento en línea de una transmisión; solo para transmisiones con acceso público. */
    public static function evento(string $dominio, array $t): ?array
    {
        if (($t['acceso'] ?? '') !== 'publico' || ($t['estado'] ?? '') === 'cancelada') { return null; }
        $ini = strtotime((string) $t['inicio']);
        if (!$ini) { return null; }
        $url = self::abs($dominio, '/en-vivo?t=' . rawurlencode((string) $t['slug']));
        $d = [
            '@context' => 'https://schema.org', '@type' => 'Event', 'name' => (string) $t['titulo'], 'url' => $url,
            'startDate' => date('c', $ini), 'endDate' => date('c', $ini + 60 * (int) ($t['duracion_min'] ?? 90)),
            'eventAttendanceMode' => 'https://schema.org/OnlineEventAttendanceMode', 'eventStatus' => 'https://schema.org/EventScheduled',
            'location' => ['@type' => 'VirtualLocation', 'url' => $url], 'isAccessibleForFree' => true, 'inLanguage' => 'es-MX',
            'organizer' => ['@type' => 'Organization', 'name' => 'Inteligencia Objetiva', 'url' => self::abs($dominio, '/')],
            'performer' => ['@type' => 'Person', 'name' => self::FUNDADOR['nombre']],
        ];
        if (trim((string) ($t['descripcion'] ?? '')) !== '') { $d['description'] = (string) $t['descripcion']; }
        if (!empty($t['imagen'])) { $d['image'] = self::abs($dominio, (string) $t['imagen']); }
        return $d;
    }

    public static function jsonLd(array $datos): string
    {
        return '<script type="application/ld+json">' . json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
    }
}
