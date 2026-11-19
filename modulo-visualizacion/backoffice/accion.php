<?php
/** Procesa las acciones del backoffice (solo POST, con CSRF, permiso y motivo). */
declare(strict_types=1);
require __DIR__ . '/_inc.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_ok();
$accion = (string) ($_POST['accion'] ?? '');
$admin = exigir($accion === 'politica' ? 'politicas' : ($accion === 'alerta_revisada' ? 'ver' : 'accesos'));

// Solo redirecciones internas (evita open redirect).
$volver = (string) ($_POST['volver'] ?? 'index.php');
if (!preg_match('#^[a-z\-]+\.php(\?[^\r\n]*)?$#', $volver)) { $volver = 'index.php'; }

$motivo = (string) ($_POST['motivo'] ?? '');
$id = (int) ($_POST['acceso_id'] ?? 0);

try {
    switch ($accion) {
        case 'extender':
            $horas = (int) ($_POST['horas'] ?? 48);
            if (!in_array($horas, [24, 48, 72, 168], true)) { throw new IOError('horas', 'Duración no válida.'); }
            IOAdmin::extender($admin, $id, $horas, $motivo);
            flash("Acceso extendido $horas horas.");
            break;
        case 'reiniciar':
            IOAdmin::reiniciar($admin, $id, $motivo);
            flash('Visualización reiniciada. El alumno puede verla de nuevo desde el inicio.');
            break;
        case 'revocar':
            IOAdmin::revocar($admin, $id, $motivo);
            flash('Acceso revocado.');
            break;
        case 'cerrar_sesion':
            IOAdmin::cerrarSesiones($admin, $id, $motivo);
            flash('Reproducción cerrada.');
            break;
        case 'otorgar':
            require_once __DIR__ . '/../lib/Alumnos.php';
            $email = filter_var(trim((string) ($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
            if (!$email) { throw new IOError('email', 'Elige al alumno en la búsqueda: el correo no es válido.'); }
            // No se confía en los campos ocultos: el alumno debe existir en el módulo o en el gancho del sitio.
            $alumno = IOAlumnos::verificar((int) ($_POST['cliente_id'] ?? 0), $email);
            if (!$alumno) { throw new IOError('alumno', 'No encontramos a ese alumno. Debe tener cuenta en el sitio; búscalo por nombre o correo.'); }
            $productoId = (int) ($_POST['producto_id'] ?? 0);
            $pol = IO::uno('SELECT titulo FROM io_politicas WHERE producto_id = ?', [$productoId]);
            if (!$pol) { throw new IOError('producto', 'Elige un contenido de la lista.'); }
            if (!in_array($motivo, IOAdmin::MOTIVOS, true)) { throw new IOError('motivo', 'Elige un motivo de la lista.'); }
            $nota = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['nota'] ?? '')));
            if ($nota !== '') {
                $motivo .= ': ' . $nota;
                if (mb_strlen($motivo, 'UTF-8') > 120) { throw new IOError('nota', 'La nota es demasiado larga: motivo y nota suman máximo 120 caracteres.'); }
            }
            IOAdmin::cortesia($admin, $alumno['id'], $alumno['email'], $alumno['nombre'], $productoId, $motivo);
            $volver = 'accesos.php?cliente=' . $alumno['id'];
            flash('Acceso otorgado a '. ($alumno['nombre'] ?: $alumno['email']) . ': «' . $pol['titulo'] . '».');
            break;
        case 'politica':
            $d = [
                'producto_id' => (int) $_POST['producto_id'],
                'titulo' => trim((string) $_POST['titulo']),
                'vimeo_id' => preg_replace('/\D/', '', (string) $_POST['vimeo_id']),
                'vimeo_hash' => preg_replace('/[^a-zA-Z0-9]/', '', (string) ($_POST['vimeo_hash'] ?? '')) ?: null,
                'duracion_seg' => max(0, (int) $_POST['duracion_seg']),
                'tipo' => in_array($_POST['tipo'] ?? '', ['permanente', 'ventana', 'unica'], true) ? $_POST['tipo'] : 'ventana',
                'horas_ventana' => max(1, (int) $_POST['horas_ventana']),
                'cuenta_desde' => ($_POST['cuenta_desde'] ?? '') === 'compra' ? 'compra' : 'primera_reproduccion',
                'dias_para_empezar' => max(1, (int) $_POST['dias_para_empezar']),
                'umbral_pct' => min(100, max(50, (int) $_POST['umbral_pct'])),
                'retroceso_seg' => max(0, (int) $_POST['retroceso_seg']),
                'permitir_adelantar' => !empty($_POST['permitir_adelantar']) ? 1 : 0,
                'pausa_max_min' => max(1, (int) $_POST['pausa_max_min']),
                'reanudaciones_max' => min(5, max(0, (int) $_POST['reanudaciones_max'])),
                'gracia_min' => max(5, (int) $_POST['gracia_min']),
            ];
            if ($d['producto_id'] <= 0 || $d['titulo'] === '' || $d['vimeo_id'] === '') { throw new IOError('datos', 'Producto, título e ID de Vimeo son obligatorios.'); }
            if (!empty($_POST['sincronizar']) && ($info = IOVimeo::info($d['vimeo_id']))) { $d['duracion_seg'] = $info['duracion_seg'] ?: $d['duracion_seg']; }
            if ($d['tipo'] === 'unica' && $d['duracion_seg'] <= 0) { throw new IOError('duracion', 'La visualización única necesita la duración del video (sincroniza con Vimeo o escríbela).'); }
            IOAdmin::guardarPolitica($admin, $d, $motivo ?: 'Configuración');
            flash('Reglas guardadas.');
            break;
        case 'alerta_revisada':
            IO::ejecutar('UPDATE io_alertas SET revisada = 1 WHERE id = ?', [(int) ($_POST['alerta_id'] ?? 0)]);
            flash('Alerta marcada como revisada.');
            break;
        default:
            throw new IOError('accion', 'Acción no válida.');
    }
} catch (IOError $e) {
    flash($e->getMessage(), 'error');
} catch (Throwable $e) {
    error_log('[IO backoffice] ' . $e->getMessage());
    flash('No se pudo completar la acción.', 'error');
}
header('Location: ' . $volver);
