<?php
/** Crea la base io_demo con datos ficticios para revisar el backoffice en local. Solo CLI. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/IO.php';

$pdo = new PDO('mysql:host=127.0.0.1;port=3307;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
if ($pdo->query("SHOW DATABASES LIKE 'io_demo'")->fetchColumn() && !in_array('--reiniciar', $argv, true)) {
    $pdo->exec('USE io_demo');
    foreach (glob(__DIR__ . '/../sql/*.sql') as $f) { foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', file_get_contents($f))))) as $sql) { $pdo->exec($sql); } }
    echo "La base io_demo ya existe; se conserva y se aplican tablas nuevas. Usa --reiniciar únicamente para borrar y recrear datos ficticios.\n";
    exit(0);
}
$pdo->exec('DROP DATABASE IF EXISTS io_demo; CREATE DATABASE io_demo CHARACTER SET utf8mb4; USE io_demo');
foreach (glob(__DIR__ . '/../sql/*.sql') as $f) { foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', file_get_contents($f))))) as $sql) { $pdo->exec($sql); } }
date_default_timezone_set('America/Mexico_City');
$pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
IO::usarConexion($pdo, ['zona_horaria' => 'America/Mexico_City']);
$adm = ['id' => 1, 'email' => 'demo-admin@ejemplo.test'];

$base = ['vimeo_hash' => null, 'horas_ventana' => 48, 'cuenta_desde' => 'primera_reproduccion', 'dias_para_empezar' => 30, 'umbral_pct' => 95, 'retroceso_seg' => 30,
         'permitir_adelantar' => 0, 'pausa_max_min' => 60, 'reanudaciones_max' => 2, 'gracia_min' => 120];
$productos = [
    // Videos PÚBLICOS de Vimeo que permiten insertarse (solo para la demo local).
    [410, '3 errores básicos en una relación de pareja', 'ventana', '1084537', 597],     // Big Buck Bunny (Blender, CC BY)
    [411, '5 elementos para incrementar tu economía', 'ventana', '22439234', 185],       // The Mountain
    [412, 'Ansiedad cero: respira, suelta y avanza', 'unica', '76979871', 62],          // Vimeo: The New Vimeo Player
    [413, 'El gran sistema: cómo funciona la existencia', 'unica', '22439234', 185],
    [600, 'Documental: Introspección I', 'permanente', '1084537', 597],
];
foreach ($productos as [$id, $t, $tipo, $vimeo, $dur]) {
    IOAdmin::guardarPolitica($adm, $base + ['producto_id' => $id, 'titulo' => $t, 'vimeo_id' => $vimeo, 'duracion_seg' => $dur, 'tipo' => $tipo], 'Configuración');
}

// ---------- Catálogo de la tienda (títulos reales del sitio; textos y precios de ejemplo) ----------
$img = fn(string $n) => "/portada/img/$n-800.webp";
$apr = fn(string ...$l) => implode("\n", $l);
$catalogo = [
  // id, slug, título, categoría, tema, resumen, aprenderás, precio, antes, rating, reseñas, ventas, imagen, tipo, vimeo, duración, incluye, destacado
  [410, '3-errores-basicos-en-una-relacion-de-pareja', '3 errores básicos en una relación de pareja', 'conferencias', 'pareja',
   'Por qué el intento de sentirnos seguros del otro termina deteriorando la relación, y cómo construir una hermandad que resista cualquier obstáculo.',
   $apr('Los tres errores que fracturan una relación sin que lo notemos', 'Cómo la necesidad de seguridad se vuelve control', 'Qué sostiene a una pareja a largo plazo'), 579, null, 4.9, 5, 320, $img('conferencias'), 'ventana', '1084537', 8520, 'Cuaderno de trabajo', 1],
  [411, '5-elementos-para-incrementar-tu-economia', '5 elementos para incrementar tu economía', 'conferencias', 'economia',
   'Los cinco elementos de los que depende tu desarrollo económico y qué hacer con cada uno para dejar de estancarte.',
   $apr('De qué depende realmente tu economía', 'Los hábitos mentales que te frenan', 'Un plan para ordenar tus decisiones'), 579, null, 4.8, 5, 275, $img('cursos'), 'ventana', '22439234', 7900, 'Cuaderno de trabajo', 0],
  [412, 'ansiedad-cero-respira-suelta-y-avanza', 'Ansiedad cero: respira, suelta y avanza', 'conferencias', 'desarrollo',
   'Recupera el control cuando la ansiedad te roba la paz. Una sesión práctica para entender de dónde viene y cómo soltarla.',
   $apr('Qué es la ansiedad desde la mente objetiva', 'Ejercicios para soltar la tensión', 'Cómo avanzar sin esperar a sentirte listo'), 579, null, 0, 0, 190, $img('introspecciones'), 'unica', '76979871', 62, 'Ejercicios guiados', 1],
  [413, 'el-gran-sistema-como-funciona-la-existencia', 'El gran sistema: cómo funciona la existencia', 'cursos', 'desarrollo',
   'Un curso para entender las reglas del sistema en el que vivimos y cómo tu frecuencia mental interactúa con él.',
   $apr('Las reglas del sistema social y por qué nos atrapan', 'Cómo interactúa tu frecuencia mental con lo que te sucede', 'Decisiones que cambian la dirección de tu vida'), 1855, null, 5.0, 5, 140, $img('hero'), 'unica', '22439234', 6600, 'Cuaderno de trabajo y audio', 1],
  [414, 'amar-sin-perderte', 'Amar sin perderte: confianza, límites y libertad', 'conferencias', 'pareja',
   '¿Es posible amar profundamente sin perder tu esencia? Confianza, límites sanos y libertad dentro de la pareja.',
   $apr('La diferencia entre amar y depender', 'Cómo poner límites sin romper el vínculo'), 579, null, 0, 0, 98, $img('membresias'), 'ventana', '1084537', 5400, null, 0],
  [415, 'aprende-a-priorizar', 'Aprende a priorizar', 'conferencias', 'laboral',
   'Para invertir tu esfuerzo de forma inteligente primero hay que saber qué va primero. Un método claro para decidir.',
   $apr('Por qué todo parece urgente', 'Un criterio simple para ordenar tus prioridades'), 579, null, 4.6, 3, 120, $img('cursos'), 'ventana', '22439234', 4800, null, 0],
  [416, 'educacion-a-los-hijos-limites-con-amor', 'Educación a los hijos: límites con amor', 'conferencias', 'hijos',
   'Cómo educar con firmeza sin dañar el vínculo, y por qué los hijos aprenden más de lo que ven que de lo que se les dice.',
   $apr('Qué necesita un hijo para formarse estable', 'Límites que enseñan en lugar de castigar'), 579, null, 4.9, 8, 210, $img('introspecciones'), 'ventana', '1084537', 6100, 'Cuaderno de trabajo', 0],
  [417, 'supera-los-obstaculos-psicologicos-de-tu-prosperidad', 'Supera los obstáculos psicológicos que evitan tu prosperidad', 'cursos', 'economia',
   'Las emociones que sabotean tu crecimiento económico y cómo desactivarlas, paso a paso.',
   $apr('Las creencias que te alejan del dinero', 'Cómo decidir sin miedo ni culpa', 'Un plan de 30 días'), 1855, null, 4.7, 6, 160, $img('conferencias'), 'ventana', '22439234', 7200, 'Cuaderno de trabajo', 0],
  [418, 'pareja-inteligente-el-dialogo', 'Pareja inteligente: el diálogo', 'cursos', 'pareja',
   'La comunicación es el problema más común en las parejas. Aprende a dialogar sin herir y a escuchar sin defenderte.',
   $apr('Por qué discutimos de lo mismo una y otra vez', 'La estructura de un diálogo que resuelve'), 1855, null, 5.0, 5, 180, $img('membresias'), 'ventana', '1084537', 7800, 'Cuaderno de trabajo', 0],
  [419, 'presupuesto-de-vida', 'Presupuesto de vida', 'cursos', 'desarrollo',
   '¿Cuánto vale tu tiempo? Un curso para invertir tu vida con la misma precisión con la que cuidas tu dinero.',
   $apr('El valor real de tu tiempo', 'Cómo dejar de gastar tu vida en lo que no importa'), 1855, null, 4.8, 5, 110, $img('hero'), 'ventana', '22439234', 6400, null, 0],
  [600, 'introspeccion-el-silencio', 'Introspección I: el silencio', 'introspecciones', 'desarrollo',
   'Una práctica guiada para mirar hacia dentro, observar el pensamiento y encontrar estabilidad.',
   $apr('Cómo observar tus pensamientos sin pelear con ellos', 'Un espacio diario de quietud'), 464, 928, 4.9, 12, 400, $img('introspecciones'), 'permanente', '1084537', 3600, null, 1],
  [601, 'documental-la-mente-objetiva', 'Documental: la mente objetiva', 'documentales', 'desarrollo',
   'Un recorrido por los principios de la Inteligencia Objetiva y lo que cambia cuando una persona aprende a ver la vida tal como es.',
   $apr('Los principios del sistema de pensamiento', 'Historias reales de transformación'), 696, 1392, 4.7, 9, 260, $img('hero'), 'permanente', '22439234', 4200, null, 0],
  [602, 'introspeccion-ii-el-perdon', 'Introspección II: el perdón', 'introspecciones', 'desarrollo',
   'Soltar lo que pasó no es justificarlo. Una introspección guiada para liberarte del peso del rencor.',
   $apr('La diferencia entre perdonar y olvidar', 'Cómo soltar sin traicionarte'), 464, 928, 0, 0, 85, $img('membresias'), 'permanente', '1084537', 3300, null, 0],
  [603, 'audiolibro-conciencia-de-si', 'Audiolibro: Conciencia de sí', 'audiolibro', 'desarrollo',
   'El audiolibro que reúne las bases del sistema de pensamiento para escucharlo donde estés.',
   $apr('Las bases del autoconocimiento', 'Ejercicios para aplicar cada capítulo'), 580, 1160, 4.9, 7, 150, $img('cursos'), 'permanente', '22439234', 18000, 'Audio para escuchar donde estés', 0],
  [420, 'el-engano-de-tu-mente', 'El engaño de tu mente: paranoias, prejuicios y conjeturas', 'cursos', 'desarrollo',
   'Sin darnos cuenta distorsionamos la realidad. Aprende a detectar cuándo tu mente te engaña.',
   $apr('Los tres engaños más comunes de la mente', 'Cómo volver a los hechos'), 1855, null, 5.0, 5, 130, $img('introspecciones'), 'unica', '76979871', 62, 'Cuaderno de trabajo', 0],
  [421, 'detonantes-del-fracaso', 'Detonantes del fracaso: la pereza y la pérdida de interés', 'cursos', 'superacion',
   'Por qué abandonamos los buenos proyectos y cómo sostener el interés hasta terminar lo que empiezas.',
   $apr('De dónde viene la pereza', 'Cómo recuperar el interés perdido'), 1855, null, 4.8, 5, 115, $img('conferencias'), 'ventana', '1084537', 6800, null, 0],
];
foreach ($catalogo as [$id, $slug, $tit, $cat, $tema, $res, $aprende, $precio, $antes, $rat, $nres, $ventas, $im, $tipo, $vimeo, $dur, $incl, $dest]) {
    IOAdmin::guardarPolitica($adm, $base + ['producto_id' => $id, 'titulo' => $tit, 'vimeo_id' => $vimeo, 'duracion_seg' => $dur, 'tipo' => $tipo,
        'horas_ventana' => $cat === 'cursos' ? 240 : 48], 'Configuración');
    IO::ejecutar('INSERT INTO io_productos (producto_id, slug, titulo, categoria, tema, resumen, descripcion, aprenderas, imagen, precio_mxn, precio_anterior, incluye, trailer_vimeo, rating, resenas, ventas, destacado)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$id, $slug, $tit, $cat, $tema, $res, $res, $aprende, $im, $precio, $antes, $incl, '76979871', $rat, $nres, $ventas, $dest]);
}
$alumnos = [['Laura Méndez', 'laura@ejemplo.test'], ['Jorge Ruiz', 'jorge@ejemplo.test'], ['Sofía Pardo', 'sofia@ejemplo.test'], ['Andrés Lima', 'andres@ejemplo.test'],
            ['Paula Ortega', 'paula@ejemplo.test'], ['Diego Salas', 'diego@ejemplo.test'], ['Marta Gil', 'marta@ejemplo.test'], ['Iván Rojas', 'ivan@ejemplo.test']];
$n = 0;
foreach ($alumnos as $i => [$nombre, $email]) {
    $c = ['id' => 1000 + $i, 'email' => $email, 'nombre' => $nombre];
    if ($i === 0) { continue; } // Laura (la alumna con sesión en la demo) recibe un set limpio más abajo
    foreach ([410, 412, 600, 411, 413] as $j => $prod) {
        if (($i + $j) % 3 === 2) continue;
        $id = IOAccesos::otorgar($c['id'], $email, $nombre, $prod, $j % 4 === 3 ? 'membresia' : 'compra', 'PED-' . (2400 + ++$n));
        $estado = ($i + $j) % 5;
        if ($estado === 0) continue; // por empezar
        $pase = IOAccesos::iniciar($id, $c, $i % 2 ? 'Mozilla/5.0 (iPhone) Safari/604.1' : 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0', '189.203.' . $i . '.' . $j);
        $dur = (int) IO::uno('SELECT duracion_seg FROM io_politicas WHERE producto_id = ?', [$prod])['duracion_seg'];
        $pos = (int) ($dur * [0, .35, .62, .97, .18][$estado]);
        IO::ejecutar('UPDATE io_accesos SET max_seg = ?, seg_vistos = ? WHERE id = ?', [$pos, $pos, $id]);
        IO::ejecutar('UPDATE io_sesiones SET posicion_seg = ?, reproduciendo = 1 WHERE token = ?', [$pos, $pase['token']]);
        if ($estado === 3) { IO::ejecutar('UPDATE io_accesos SET estado = "visto", visto_en = NOW() WHERE id = ?', [$id]); IO::ejecutar('UPDATE io_sesiones SET activa = 0 WHERE token = ?', [$pase['token']]); }
        if ($estado === 4) { IOAccesos::cerrar($pase["token"], $c); }
        if ($estado === 1 && $prod === 410) { IO::ejecutar('UPDATE io_accesos SET vence = DATE_ADD(NOW(), INTERVAL 9 HOUR) WHERE id = ?', [$id]); }
    }
}
// Laura: un acceso de cada tipo listo para probar desde "Mi biblioteca".
foreach ([[412, 'PED-LAURA-1'], [410, 'PED-LAURA-2'], [600, 'PED-LAURA-3'], [413, 'PED-LAURA-4']] as [$prod, $ref]) {
    IOAccesos::otorgar(1000, 'laura@ejemplo.test', 'Laura Méndez', $prod, 'compra', $ref);
}
IOAccesos::alerta(3, 1001, 'doble_dispositivo', 'Se abrió en: Mozilla/5.0 (iPhone) Safari/604.1');
IOAccesos::alerta(7, 1003, 'adelanto_bloqueado', 'posición 3900, máximo 1210');
$pdo->prepare('INSERT INTO io_admins (email, nombre, hash, rol) VALUES (?,?,?,?)')->execute(['demo-admin@ejemplo.test', 'Admin Demo', password_hash('demo-local-12345', PASSWORD_DEFAULT), 'admin']);
echo "Base io_demo lista: " . IO::uno('SELECT COUNT(*) n FROM io_accesos')['n'] . " accesos. Usuario demo-admin@ejemplo.test; contraseña demo-local-12345 (solo demo local).\n";
