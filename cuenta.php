<?php
require __DIR__ . '/inc/bootstrap.php';

$u = requiere_admin();
$error = '';
if (es_post()) {
    $st = db()->prepare('SELECT pass FROM usuarios WHERE id = ?');
    $st->execute([$u['id']]);
    $nueva = (string)($_POST['nueva'] ?? '');
    if (!password_verify((string)($_POST['actual'] ?? ''), (string)$st->fetchColumn())) $error = 'La contraseña actual no es correcta.';
    elseif (strlen($nueva) < 6) $error = 'La nueva contraseña debe tener al menos 6 caracteres.';
    elseif ($nueva !== (string)($_POST['nueva2'] ?? '')) $error = 'Las contraseñas no coinciden.';
    else {
        db()->prepare('UPDATE usuarios SET pass = ? WHERE id = ?')->execute([password_hash($nueva, PASSWORD_DEFAULT), $u['id']]);
        flash('Contraseña actualizada.');
        redirigir('cuenta.php');
    }
}

cabecera('Mi cuenta', '', 'admin');
?>
<section class="tarjeta angosta">
  <h1>Mi cuenta</h1>
  <p class="muted">Usuario: <strong><?= h($u['username']) ?></strong></p>
  <?php if ($error): ?><div class="flash error"><?= h($error) ?></div><?php endif; ?>
  <form method="post" class="form">
    <?= csrf_campo() ?>
    <label>Contraseña actual <input type="password" name="actual" required autocomplete="current-password"></label>
    <label>Nueva contraseña <input type="password" name="nueva" required minlength="6" autocomplete="new-password"></label>
    <label>Repetir nueva <input type="password" name="nueva2" required minlength="6" autocomplete="new-password"></label>
    <button class="btn">Cambiar contraseña</button>
  </form>
</section>
<?php pie('admin');
