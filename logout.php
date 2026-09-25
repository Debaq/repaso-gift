<?php
require __DIR__ . '/inc/bootstrap.php';

if (es_post() && hay_sesion()) {
    csrf_verificar();
    $_SESSION = [];
    session_destroy();
    setcookie(session_name(), '', ['expires' => 1, 'path' => base_url() . '/']);
}
redirigir('index.php');
