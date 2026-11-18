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
            $_SESSION = ['io_admin' => ['id' => (int) $u['id']]];
            header('Location: index.php'); exit;
        }
        $error = 'Correo o contraseña incorrectos.';
    }
}

pagina('Entrar', function () use ($error) { ?>
  <form method="post" class="bo-login">
    <?= csrf_input() ?>
    <h1>iO</h1>
    <p class="bo-nota mb-4">Backoffice de Inteligencia Objetiva</p>
    <?php if ($error): ?><div class="bo-flash error"><?= h($error) ?></div><?php endif; ?>
    <label class="form-label">Correo</label>
    <input class="form-control mb-3" type="email" name="email" required autocomplete="username">
    <label class="form-label">Contraseña</label>
    <input class="form-control mb-4" type="password" name="clave" required autocomplete="current-password">
    <button class="bo-btn w-100 justify-content-center">Entrar</button>
  </form>
<?php });
