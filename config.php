<?php
// Configuración general. Editar según el servidor.
return [
    'nombre_sitio'  => 'Repaso GIFT',
    // Ruta del archivo SQLite (la carpeta debe tener permisos de escritura para PHP).
    // Recomendado: fuera de la carpeta pública, ej: __DIR__ . '/../gift-datos/app.sqlite'
    'db'            => __DIR__ . '/data/app.sqlite',
    // Tamaño máximo de archivo GIFT en MB
    'max_upload_mb' => 2,
    'zona_horaria'  => 'America/Santiago',
    // Contador anónimo de aciertos por pregunta (solo n.º de respuestas y % de acierto, sin identificar a nadie).
    // Sirve para ver en el admin qué preguntas cuestan más. false = no se guarda absolutamente nada.
    'estadisticas_anonimas' => false,
    // Normalmente se detecta sola. Forzar si la app queda detrás de un proxy, ej: '/practica'
    'base_url'      => null,
];
