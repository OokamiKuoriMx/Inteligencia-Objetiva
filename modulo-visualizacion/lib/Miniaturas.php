<?php
/** Miniaturas subidas desde el backoffice: valida que sea una imagen real y la guarda con nombre aleatorio. */
declare(strict_types=1);
require_once __DIR__ . '/IO.php';

final class IOMiniaturas
{
    public const MAX_BYTES = 4 * 1024 * 1024;
    public const MAX_LADO = 4096;
    public const ANCHO_FINAL = 1280;
    /** Subidas permitidas por sesión de administrador en una hora. */
    public const MAX_POR_HORA = 60;
    private const TIPOS = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];

    public static function directorio(): string
    {
        return rtrim((string) (IO::config()['miniaturas_dir'] ?? dirname(__DIR__) . '/subidas/miniaturas'), '/\\');
    }

    public static function urlBase(): string
    {
        return rtrim((string) (IO::config()['miniaturas_url'] ?? '/io/subidas/miniaturas'), '/') . '/';
    }

    private static function bytes(string $v): int
    {
        $v = trim($v); if ($v === '' || $v === '-1') { return -1; }
        $n = (int) $v; $u = strtolower(substr($v, -1));
        return $u === 'g' ? $n * 1073741824 : ($u === 'm' ? $n * 1048576 : ($u === 'k' ? $n * 1024 : $n));
    }

    /** Límite simple por sesión para que nadie llene el disco con subidas en serie. */
    public static function registrarIntento(array &$sesion): void
    {
        $ahora = time();
        $sesion['io_subidas'] = array_values(array_filter((array) ($sesion['io_subidas'] ?? []), static fn($t) => is_int($t) && $t > $ahora - 3600));
        if (count($sesion['io_subidas']) >= self::MAX_POR_HORA) { throw new IOError('limite', 'Llegaste al límite de subidas por hora. Inténtalo más tarde.', 429); }
        $sesion['io_subidas'][] = $ahora;
    }

    /** Rutas locales aceptadas como imagen: solo archivos de imagen (evita que un <img> dispare otras páginas del sitio). */
    public static function rutaLocalValida(string $ruta): bool
    {
        return (bool) preg_match('#^/(?!/)[A-Za-z0-9/_.%\-]+\.(?:webp|jpe?g|png|gif|avif)(?:\?[A-Za-z0-9=&._\-]*)?$#Di', $ruta);
    }

    /**
     * @param array $archivo Entrada de $_FILES.
     * @param bool $subida false solo en pruebas de CLI (omite is_uploaded_file).
     * @return array{url:string,ancho:int,alto:int}
     */
    public static function guardar(array $archivo, bool $subida = true): array
    {
        $error = $archivo['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) { throw new IOError('tamano', 'La imagen pesa demasiado. Usa una de hasta 4 MB.'); }
        if ($error !== UPLOAD_ERR_OK || !is_string($archivo['tmp_name'] ?? null)) { throw new IOError('archivo', 'No se recibió la imagen. Vuelve a intentarlo.'); }
        $tmp = $archivo['tmp_name'];
        if ($subida && !is_uploaded_file($tmp)) { throw new IOError('archivo', 'No se recibió la imagen. Vuelve a intentarlo.'); }
        $bytes = (int) filesize($tmp);
        if ($bytes < 1 || $bytes > self::MAX_BYTES) { throw new IOError('tamano', 'La imagen pesa demasiado. Usa una de hasta 4 MB.'); }
        $info = @getimagesize($tmp);
        if (!$info || !isset(self::TIPOS[$info[2]])) { throw new IOError('formato', 'Usa una imagen JPG, PNG o WebP.'); }
        [$ancho, $alto] = $info;
        if ($ancho < 320 || $alto < 180) { throw new IOError('medidas', 'La imagen debe medir al menos 320 × 180 px (recomendado 1280 × 720).'); }
        if ($ancho > self::MAX_LADO || $alto > self::MAX_LADO) { throw new IOError('medidas', 'La imagen mide más de 4096 px por lado. Redúcela e inténtalo de nuevo.'); }
        $cabecera = (string) file_get_contents($tmp, false, null, 0, 16);
        $firmas = ['jpg' => "\xFF\xD8\xFF", 'png' => "\x89PNG", 'webp' => 'RIFF'];
        $ext = self::TIPOS[$info[2]];
        if (strpos($cabecera, $firmas[$ext]) !== 0 || ($ext === 'webp' && substr($cabecera, 8, 4) !== 'WEBP')) { throw new IOError('formato', 'Usa una imagen JPG, PNG o WebP.'); }

        $dir = self::directorio();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) { throw new IOError('almacen', 'No se pudo guardar la imagen en el servidor.', 500); }
        $nombre = bin2hex(random_bytes(12));
        // Con GD se vuelve a codificar (descarta metadatos y cualquier contenido ajeno a la imagen).
        if (function_exists('imagecreatefromstring') && function_exists('imagewebp')) {
            // Decodificar reserva ~5 bytes por píxel: se comprueba antes para no agotar la memoria del proceso.
            $limite = self::bytes((string) ini_get('memory_limit'));
            if ($limite > 0 && memory_get_usage() + $ancho * $alto * 5 + 8 * 1024 * 1024 > $limite) { throw new IOError('medidas', 'La imagen es demasiado grande para procesarla. Usa una de 1280 × 720.'); }
            $img = @imagecreatefromstring((string) file_get_contents($tmp));
            if (!$img) { throw new IOError('formato', 'La imagen está dañada. Prueba con otra.'); }
            if ($ancho > self::ANCHO_FINAL) {
                $reducida = imagescale($img, self::ANCHO_FINAL, (int) round($alto * self::ANCHO_FINAL / $ancho));
                imagedestroy($img);
                if (!$reducida) { throw new IOError('almacen', 'No se pudo procesar la imagen.', 500); }
                $img = $reducida;
            }
            $destino = $dir . '/' . $nombre . '.webp';
            $ok = imagewebp($img, $destino, 86); imagedestroy($img);
            if (!$ok) { throw new IOError('almacen', 'No se pudo guardar la imagen en el servidor.', 500); }
            $ext = 'webp';
        } else {
            $destino = $dir . '/' . $nombre . '.' . $ext;
            if (!($subida ? move_uploaded_file($tmp, $destino) : copy($tmp, $destino))) { throw new IOError('almacen', 'No se pudo guardar la imagen en el servidor.', 500); }
        }
        @chmod($destino, 0644);
        return ['url' => self::urlBase() . $nombre . '.' . $ext, 'ancho' => $ancho, 'alto' => $alto];
    }
}
