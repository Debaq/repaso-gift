<?php
// Primera ejecución: crea la cuenta de administrador. Se desactiva sola cuando ya existe un admin.
require __DIR__ . '/inc/bootstrap.php';

if (hay_admin()) redirigir('login.php');

$error = '';
if (es_post()) {
    csrf_verificar();
    $user = post_str('username');
    $nombre = post_str('nombre');
    $pass = (string)($_POST['pass'] ?? '');
    if (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', $user)) $error = 'Usuario: 3 a 40 caracteres (letras, números, . _ -).';
    elseif ($nombre === '') $error = 'Ingresa tu nombre.';
    elseif (strlen($pass) < 8) $error = 'La contraseña debe tener al menos 8 caracteres.';
    else {
        db()->prepare('INSERT INTO usuarios (username, nombre, pass, creado) VALUES (?, ?, ?, ?)')
            ->execute([$user, $nombre, password_hash($pass, PASSWORD_DEFAULT), time()]);
        iniciar_sesion((int)db()->lastInsertId());
        flash('Administrador creado. Crea tus temas y sube el primer set de preguntas.');
        redirigir('admin/index.php');
    }
}

cabecera('Instalación', '', 'admin');
?>
<section class="tarjeta angosta">
  <h1>Crear administrador</h1>
  <p class="muted">Primera vez que se abre la app. Esta cuenta podrá crear temas y subir sets GIFT. Los estudiantes no necesitan cuenta.</p>
  <?php if ($error): ?><div class="flash error"><?= h($error) ?></div><?php endif; ?>
  <form method="post" class="form">
    <?= csrf_campo() ?>
    <label>Usuario <input name="username" required value="<?= h(post_str('username')) ?>"></label>
    <label>Nombre <input name="nombre" required value="<?= h(post_str('nombre')) ?>"></label>
    <label>Contraseña <input type="password" name="pass" required minlength="8"></label>
    <button class="btn">Crear</button>
  </form>
</section>
<?php pie('admin');
