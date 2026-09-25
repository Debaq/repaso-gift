<?php
require __DIR__ . '/inc/bootstrap.php';

if (!hay_admin()) redirigir('instalar.php');
if (admin()) redirigir('admin/index.php');

$error = '';
if (es_post()) {
    csrf_verificar();
    if (login_bloqueado()) {
        $error = 'Demasiados intentos fallidos. Espera 10 minutos.';
    } else {
        $st = db()->prepare('SELECT id, pass FROM usuarios WHERE username = ?');
        $st->execute([post_str('username')]);
        $f = $st->fetch();
        if ($f && password_verify((string)($_POST['pass'] ?? ''), $f['pass'])) {
            if (password_needs_rehash($f['pass'], PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE usuarios SET pass = ? WHERE id = ?')->execute([password_hash((string)$_POST['pass'], PASSWORD_DEFAULT), $f['id']]);
            }
            iniciar_sesion((int)$f['id']);
            redirigir('admin/index.php');
        }
        registrar_fallo_login();
        $error = 'Usuario o contraseña incorrectos.';
    }
}

cabecera('Acceso docente');
?>
<section class="tarjeta angosta">
  <h1>Acceso docente</h1>
  <p class="muted">Solo para administrar temas y sets. Para practicar no necesitas cuenta: <a href="<?= h(url('index.php')) ?>">ir a los temas</a>.</p>
  <?php if ($error): ?><div class="flash error"><?= h($error) ?></div><?php endif; ?>
  <form method="post" class="form">
    <?= csrf_campo() ?>
    <label>Usuario <input name="username" required autofocus autocomplete="username" value="<?= h(post_str('username')) ?>"></label>
    <label>Contraseña <input type="password" name="pass" required autocomplete="current-password"></label>
    <button class="btn">Entrar</button>
  </form>
</section>
<?php pie();
