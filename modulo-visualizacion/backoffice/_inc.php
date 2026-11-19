<?php
/** Backoffice IO 2.0 — utilidades comunes: sesión de admin, CSRF, permisos y layout. */
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
require_once __DIR__ . '/../lib/IO.php';

session_name('io_admin');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS']), 'path' => '/']);
session_start();
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

// Cualquier error no controlado: página genérica, detalle solo en el log.
set_exception_handler(function (Throwable $e) {
    error_log('[IO backoffice] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo 'Ocurrió un error. Intenta de nuevo.';
});

const IO_PERMISOS = [
    'admin'    => ['ver', 'accesos', 'politicas', 'reportes', 'bitacora', 'pedidos', 'reembolsos'],
    'editor'   => ['ver', 'politicas', 'reportes'],
    'soporte'  => ['ver', 'accesos', 'pedidos'],
    'finanzas' => ['ver', 'reportes', 'bitacora', 'pedidos', 'reembolsos'],
];

function h($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

/**
 * Admin de la sesión, revalidado contra la base en cada petición:
 * si lo desactivan o le cambian el rol, aplica de inmediato.
 */
function admin(): ?array
{
    static $cache = false;
    if ($cache !== false) { return $cache; }
    $id = (int) ($_SESSION['io_admin']['id'] ?? 0);
    if ($id <= 0) { return $cache = null; }
    $u = IO::uno('SELECT id, email, nombre, rol FROM io_admins WHERE id = ? AND activo = 1', [$id]);
    if (!$u) { $_SESSION = []; session_regenerate_id(true); return $cache = null; }
    $u['id'] = (int) $u['id'];
    return $cache = $u;
}

function exigir(string $permiso = 'ver'): array
{
    $a = admin();
    if (!$a) { header('Location: login.php'); exit; }
    if (!in_array($permiso, IO_PERMISOS[$a['rol']] ?? [], true)) {
        http_response_code(403); pagina('Sin permiso', fn() => print '<div class="bo-vacio">Tu rol no tiene acceso a esta sección.</div>'); exit;
    }
    return $a;
}

function puede(string $permiso): bool { $a = admin(); return $a && in_array($permiso, IO_PERMISOS[$a['rol']] ?? [], true); }

function csrf(): string
{
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
    return $_SESSION['csrf'];
}
function csrf_input(): string { return '<input type="hidden" name="csrf" value="' . h(csrf()) . '">'; }
function csrf_ok(): void
{
    $s = (string) ($_SESSION['csrf'] ?? '');
    $p = (string) ($_POST['csrf'] ?? '');
    if ($s === '' || $p === '' || !hash_equals($s, $p)) { http_response_code(403); exit('Token CSRF inválido. Recarga la página.'); }
}

function flash(?string $msg = null, string $tipo = 'ok'): ?array
{
    if ($msg !== null) { $_SESSION['flash'] = [$msg, $tipo]; return null; }
    $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f;
}

const ESTADOS = [
    'por_empezar' => ['Por empezar', 'neutro'], 'activo' => ['Activo', 'ok'], 'en_gracia' => ['En gracia', 'aviso'],
    'visto' => ['Visto', 'info'], 'vencido' => ['Vencido', 'apagado'], 'revocado' => ['Revocado', 'peligro'],
];
const TIPOS = ['permanente' => 'Permanente', 'ventana' => 'Ventana de tiempo', 'unica' => 'Visualización única'];

function chip_estado(string $e): string { [$t, $c] = ESTADOS[$e] ?? [$e, 'neutro']; return '<span class="bo-chip ' . $c . '">' . h($t) . '</span>'; }
function chip_tipo(string $t): string { return '<span class="io-badge-acceso ' . ($t === 'unica' ? 'unica' : ($t === 'permanente' ? 'permanente' : '')) . '">' . h(TIPOS[$t] ?? $t) . '</span>'; }
function fecha(?string $f): string { return $f ? date('d/m/Y H:i', strtotime($f)) : '—'; }
function hace(?string $f): string
{
    if (!$f) return '—';
    $s = time() - strtotime($f);
    if ($s < 60) return 'hace ' . $s . ' s';
    if ($s < 3600) return 'hace ' . intdiv($s, 60) . ' min';
    if ($s < 86400) return 'hace ' . intdiv($s, 3600) . ' h';
    return fecha($f);
}
function mmss(int $s): string { return sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60); }

function pagina(string $titulo, callable $cuerpo, string $activo = ''): void
{
    $a = admin();
    $menu = [
        ['index.php', 'Tablero', 'ver', '<path d="M3 13h8V3H3zM13 21h8V11h-8zM3 21h8v-6H3zM13 3v6h8V3z"/>'],
        ['transmisiones.php', 'Transmisiones en vivo', 'ver', '<rect x="2" y="6" width="14" height="12" rx="2"/><path d="M16 10l6-3v10l-6-3"/>'],
        ['en-vivo.php', 'Reproducciones en curso', 'ver', '<circle cx="12" cy="12" r="3"/><path d="M5.6 5.6a9 9 0 0 0 0 12.8M18.4 5.6a9 9 0 0 1 0 12.8"/>'],
        ['accesos.php', 'Accesos', 'ver', '<rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>'],
        ['reglas.php', 'Contenido', 'ver', '<path d="M4 6h16M4 12h16M4 18h10"/>'],
        ['pedidos.php', 'Pedidos y devoluciones', 'pedidos', '<path d="M4 4h16v16H4zM8 8h8M8 12h8M8 16h4"/>'],
        ['descuentos.php', 'Cupones y 2×1', 'politicas', '<path d="M3 7h18v10H3zM9 7v10"/>'],
        ['alertas.php', 'Alertas', 'ver', '<path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>'],
        ['reportes.php', 'Reportes', 'reportes', '<path d="M3 3v18h18M7 15l4-4 3 3 5-6"/>'],
        ['bitacora.php', 'Bitácora', 'bitacora', '<path d="M12 8v4l3 2"/><circle cx="12" cy="12" r="9"/>'],
    ];
    $pendientes = $a ? (int) (IO::uno('SELECT COUNT(*) n FROM io_alertas WHERE revisada = 0')['n'] ?? 0) : 0;
    ?>
<!DOCTYPE html>
<html lang="es-MX" data-io-theme="light">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($titulo) ?> · Administración · Inteligencia Objetiva</title>
<meta name="robots" content="noindex, nofollow">
<script>(function(){try{var t=localStorage.getItem('io-theme-bo');if(t==='light'||t==='dark')document.documentElement.setAttribute('data-io-theme',t)}catch(e){}})();</script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500&family=Roboto:wght@300;400;500;700&display=swap">
<link rel="stylesheet" href="/tema/io-theme.css?v=2.0.0">
<link rel="stylesheet" href="/io/backoffice/backoffice.css?v=2.2.0">
</head>
<body class="bo">
<?php if ($a): ?>
<aside class="bo-lateral">
  <a class="bo-marca" href="index.php" aria-label="Inteligencia Objetiva · inicio de administración"><img src="/tema/img/io-logo.webp" alt="Inteligencia Objetiva · Martín Aparicio" width="400" height="400"><span>Administración</span></a>
  <nav aria-label="Administración de Inteligencia Objetiva">
  <?php $grupoAnterior = ''; foreach ($menu as [$href, $txt, $perm, $ico]): if (!puede($perm)) continue;
      $grupo = in_array($href, ['pedidos.php','descuentos.php'], true) ? 'Comercial' : (in_array($href, ['alertas.php','reportes.php','bitacora.php'], true) ? 'Seguimiento' : 'Operación'); ?>
    <?php if ($grupo !== $grupoAnterior): $grupoAnterior = $grupo; ?><span class="bo-nav-grupo"><?= h($grupo) ?></span><?php endif; ?>
    <a href="<?= $href ?>" class="<?= $activo === $href ? 'activo' : '' ?>"<?= $activo === $href ? ' aria-current="page"' : '' ?>>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $ico ?></svg>
      <?= h($txt) ?><?php if ($href === 'alertas.php' && $pendientes): ?><b class="bo-num"><?= $pendientes ?></b><?php endif; ?>
    </a>
  <?php endforeach; ?>
  </nav>
  <div class="bo-yo">
    <span class="bo-avatar" aria-hidden="true"><?= h(mb_strtoupper(mb_substr($a['nombre'], 0, 1))) ?></span>
    <div><b><?= h($a['nombre']) ?></b><span><?= h(ucfirst($a['rol'])) ?></span></div>
    <button type="button" class="io-theme-toggle" id="boTema" aria-label="Cambiar tema">
      <svg class="io-ico-luna" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
      <svg class="io-ico-sol" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
    </button>
    <a href="salir.php" class="bo-salir" title="Cerrar sesión">Salir</a>
  </div>
</aside>
<?php endif; ?>
<main class="bo-main<?= $a ? '' : ' solo' ?>">
  <?php if ($a): ?>
  <header class="bo-cabecera">
    <div><p class="bo-contexto">Inteligencia Objetiva <span>/</span> Administración</p><h1><?= h($titulo) ?></h1></div>
    <a class="bo-volver-sitio" href="/">Ver sitio <span aria-hidden="true">↗</span></a>
    <form class="bo-buscar" action="accesos.php" method="get">
      <input name="q" type="search" aria-label="Buscar alumno o contenido" placeholder="Buscar alumno o contenido" value="<?= h($_GET['q'] ?? '') ?>">
    </form>
  </header>
  <?php if ($f = flash()): ?><div class="bo-flash <?= h($f[1]) ?>"><?= h($f[0]) ?></div><?php endif; ?>
  <?php endif; ?>
  <?php $cuerpo(); ?>
</main>
<script>
var botonTema=document.getElementById('boTema');function nombrarTema(){if(botonTema)botonTema.setAttribute('aria-label',document.documentElement.getAttribute('data-io-theme')==='dark'?'Cambiar a modo claro':'Cambiar a modo oscuro')}nombrarTema();if(botonTema)botonTema.addEventListener('click',function(){var r=document.documentElement,t=r.getAttribute('data-io-theme')==='dark'?'light':'dark';r.setAttribute('data-io-theme',t);nombrarTema();try{localStorage.setItem('io-theme-bo',t)}catch(e){}});
// Formularios de acciones sensibles: confirmar en un diálogo propio (sin alert/confirm del navegador)
document.querySelectorAll('[data-accion]').forEach(function(b){b.addEventListener('click',function(){var d=document.getElementById('dlgAccion');if(!d)return;d.querySelector('[name=accion]').value=b.dataset.accion;d.querySelector('[name=acceso_id]').value=b.dataset.acceso;d.querySelector('.dlg-titulo').textContent=b.dataset.titulo||'Confirmar acción';d.querySelector('.dlg-horas').style.display=b.dataset.accion==='extender'?'block':'none';d.showModal();});});
</script>
</body>
</html>
<?php
}

/** Diálogo reutilizable para acciones con motivo obligatorio. */
function dialogo_accion(string $volver): void
{ ?>
<dialog id="dlgAccion" class="bo-dialogo">
  <form method="post" action="accion.php">
    <?= csrf_input() ?>
    <input type="hidden" name="accion"><input type="hidden" name="acceso_id"><input type="hidden" name="volver" value="<?= h($volver) ?>">
    <h3 class="dlg-titulo">Confirmar acción</h3>
    <div class="dlg-horas mb-3"><label class="form-label">Horas a extender</label>
      <select name="horas" class="form-select"><option value="24">24 horas</option><option value="48" selected>48 horas</option><option value="72">72 horas</option><option value="168">7 días</option></select></div>
    <label class="form-label">Motivo (obligatorio)</label>
    <select name="motivo" class="form-select" required>
      <option value="">Elige un motivo</option>
      <?php foreach (IOAdmin::MOTIVOS as $m): ?><option><?= h($m) ?></option><?php endforeach; ?>
    </select>
    <p class="bo-nota mt-2">Queda registrado en la bitácora y el alumno recibe un aviso.</p>
    <div class="d-flex gap-2 justify-content-end mt-3">
      <button type="button" class="bo-btn sec" onclick="this.closest('dialog').close()">Cancelar</button>
      <button class="bo-btn">Confirmar</button>
    </div>
  </form>
</dialog>
<?php }
