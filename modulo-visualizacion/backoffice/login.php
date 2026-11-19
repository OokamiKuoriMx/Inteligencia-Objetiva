<?php
declare(strict_types=1);
require __DIR__ . '/_inc.php';

const MAX_POR_IP = 10;      // intentos fallidos por IP en 15 min
const MAX_POR_CORREO = 5;   // intentos fallidos por correo en 15 min

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_ok();
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    // Freno persistente (no depende de la cookie): por IP y por correo.
    $f = IO::uno("SELECT
        (SELECT COUNT(*) FROM io_login_intentos WHERE ip = ? AND exito = 0 AND creado > DATE_SUB(NOW(), INTERVAL 15 MINUTE)) AS por_ip,
        (SELECT COUNT(*) FROM io_login_intentos WHERE email = ? AND exito = 0 AND creado > DATE_SUB(NOW(), INTERVAL 15 MINUTE)) AS por_correo", [$ip, $email]);
    if ((int) $f['por_ip'] >= MAX_POR_IP || (int) $f['por_correo'] >= MAX_POR_CORREO) {
        $error = 'Demasiados intentos. Espera 15 minutos.';
    } else {
        $u = IO::uno('SELECT * FROM io_admins WHERE email = ? AND activo = 1', [$email]);
        // Verificación con costo constante aunque el correo no exista.
        $ok = password_verify((string) ($_POST['clave'] ?? ''), $u['hash'] ?? password_hash(random_bytes(8), PASSWORD_DEFAULT));
        IO::ejecutar('INSERT INTO io_login_intentos (ip, email, exito) VALUES (?,?,?)', [$ip, mb_substr($email, 0, 190), $u && $ok ? 1 : 0]);
        if ($u && $ok) {
            session_regenerate_id(true);
            $_SESSION = ['io_admin' => ['id' => (int) $u['id'], 'v' => IOEquipo::registrarAcceso((int) $u['id'])]];
            header('Location: index.php'); exit;
        }
        $error = 'Correo o contraseña incorrectos.';
    }
}

pagina('Entrar', function () use ($error) { ?>
  <section class="bo-acceso" aria-label="Acceso al equipo de Inteligencia Objetiva">
  <div class="bo-acceso-identidad">
    <a class="bo-acceso-logo" href="/"><img src="/tema/img/io-logo.webp" alt="Inteligencia Objetiva · Martín Aparicio" width="400" height="400"></a>
    <span class="bo-contexto">La primera escuela del pensamiento</span>
    <h2>El espacio de <br>nuestro equipo.</h2>
    <p>Administra los contenidos, acompaña a los alumnos y da seguimiento a cada aprendizaje.</p>
    <a class="bo-volver-sitio" href="/">Volver al sitio <span aria-hidden="true">↗</span></a>
  </div>
  <form method="post" class="bo-login">
    <?= csrf_input() ?>
    <p class="bo-contexto">Administración</p>
    <h1>Bienvenido al equipo</h1>
    <p class="bo-login-intro">Ingresa con tu cuenta de Inteligencia Objetiva.</p>
    <?php if ($error): ?><div class="bo-flash error" role="alert"><?= h($error) ?></div><?php endif; ?>
    <label class="form-label" for="bo-correo">Correo electrónico</label>
    <input class="form-control mb-3" id="bo-correo" type="email" name="email" required autocomplete="username">
    <label class="form-label" for="bo-clave">Contraseña</label>
    <input class="form-control mb-4" id="bo-clave" type="password" name="clave" required autocomplete="current-password">
    <button class="bo-btn w-100 justify-content-center">Entrar al backoffice</button>
    <p class="bo-login-pie">Uso exclusivo del equipo de Inteligencia Objetiva.</p>
  </form>
  </section>
<?php });
