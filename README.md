# Repaso GIFT

Web en PHP para que los estudiantes practiquen con bancos de preguntas en formato **GIFT** (el de Moodle).
El docente organiza **temas**, sube los sets como archivos `.txt` y los estudiantes practican sin crear cuenta.

- PHP **7.4+** (probado en 7.4.33), sin frameworks ni Composer.
- Base de datos **SQLite** (un archivo, extensión `pdo_sqlite`) solo para el contenido.
- **No se guarda nada del estudiante en el servidor**: ni cuentas, ni cookies, ni respuestas.
  Su avance, historial y logros viven en el `localStorage` de su navegador.

## Instalación

1. Copia la carpeta al servidor (ej: `public_html/practica/`).
2. Dale permisos de escritura a `data/` para el usuario de PHP (`chmod 775 data`).
3. Revisa `config.php`.
4. Abre la URL: la primera vez te pide crear la cuenta de administrador.

Prueba local: `php -S localhost:8000` dentro de la carpeta.

> **Apache**: los `.htaccess` incluidos bloquean `inc/`, `data/` y `config.php`.
> **Nginx**: agrega `location ~ ^/(inc|data)/ { deny all; }` o mueve la base de datos fuera de la
> carpeta pública cambiando `'db'` en `config.php`.

## Cómo funciona

### Docente (único que inicia sesión — enlace "Acceso docente" al pie)
- **Temas**: crear, renombrar, ordenar (↑ ↓), ocultar o eliminar (sus sets pasan a «Otros»).
- **Sets**: subir el GIFT (archivo o texto pegado) eligiendo el tema. Antes de guardar hay una **vista previa**
  con errores por número de línea, advertencias y cada pregunta con su respuesta correcta.
- Por set: cambiar tema/título/orden, ocultar, mezclar preguntas/alternativas, **reemplazar** el archivo
  por una versión corregida, descargar el original, "probar como estudiante".
- Opcional (`'estadisticas_anonimas' => true`): un contador anónimo por pregunta (n.º de respuestas y
  % de acierto, sin IP ni identificador) para ver cuáles cuestan más. Por defecto **desactivado**.

### Estudiante (sin cuenta)
1. Elige un **tema** → un **set** → modo y opciones:
   - **Práctica**: corrige al instante con retroalimentación; botón "No sé / ver respuesta".
   - **Simulacro de examen**: corrige al final, con tiempo límite opcional.
   - Filtros: subtema (`$CATEGORY` del GIFT), cantidad, **solo nuevas**, **solo las que tengo malas**.
2. Resultado con revisión completa, nota referencial (1,0–7,0 al 60 %) y "repetir las que fallé".
3. **Mi progreso**: dominio por set, historial, racha de días, 15 **logros**, y
   **descargar/cargar copia (.json)** para pasar su avance a otro navegador o computador.
4. Si recarga o cierra la pestaña a mitad de intento, puede continuarlo.

Atajos: `1`–`9` eligen alternativa, `Enter` comprueba/avanza. Modo oscuro automático.

**Cómo se corrige sin guardar nada**: el navegador pide a `api.php` el HTML de las preguntas (sin
respuestas) y envía cada respuesta para corregirla; el servidor responde y olvida. Así las respuestas
correctas no quedan expuestas en la página antes de responder.

Si el docente **reemplaza** las preguntas de un set, su versión cambia y el dominio guardado en los
navegadores para ese set se reinicia solo (el historial de puntajes se mantiene).

## Formato GIFT soportado

| Tipo | Ejemplo |
|---|---|
| Opción múltiple | `¿Capital de Chile? {=Santiago ~Lima ~Quito}` |
| Varias correctas | `{~%50%A ~%50%B ~%-100%C}` |
| Verdadero/Falso | `El sol es una estrella. {T}` · `{F#feedback si falla#feedback si acierta}` |
| Respuesta corta | `¿Símbolo del oro? {=Au}` (sin distinguir mayúsculas, acepta comodín `*`) |
| Numérica | `{#3.14:0.01}` · `{#1..5}` · `{#=16:0 =%50%15:0}` |
| Emparejamiento | `{=Perro -> Ladra =Gato -> Maúlla =Vaca -> Muge}` |
| Palabra faltante | `El volcán {=Villarrica} está en Pucón.` |
| Desarrollo | `Explica X. {}` (no se califica; muestra la guía de `####`) |
| Descripción | Texto sin llaves |

También: títulos `::Título::`, `$CATEGORY:`, retroalimentación `#` y general `####`,
formatos `[html]`, `[markdown]`, `[plain]`, comentarios `//` y escapes `\~ \= \# \{ \} \:`.
Acepta archivos UTF-8 o Latin-1 (Bloc de notas de Windows).

Extras (no estándar en Moodle): `{V}`/`{VERDADERO}`/`{FALSO}`, coma decimal en numéricas y respuestas
cortas aceptadas sin tildes (con aviso). Ejemplo completo en `ejemplos/ejemplo.txt`.

## Estructura

```
config.php              configuración
index.php               portada: lista de temas
tema.php                sets de un tema
set.php                 opciones de práctica + progreso
practicar.php           intento (lo maneja assets/practica.js)
progreso.php            mi progreso, logros, copia de seguridad
api.php                 corrección sin estado (JSON)
login / logout / instalar / cuenta   acceso docente
admin/                  index (sets + subir), temas, set, subir
inc/                    bootstrap, db, gift (parser), calificar, render, layout
assets/                 style.css, app.js, progreso.js (localStorage + logros), practica.js
data/                   app.sqlite (se crea sola)
```

## Ideas para más adelante

- Imágenes en preguntas (subir ZIP con GIFT + imágenes, reemplazando `@@PLUGINFILE@@`).
- Repetición espaciada tipo Anki: priorizar preguntas falladas hace días.
- Código QR / enlace directo a un set para compartir en clases.
- Marcar preguntas como "dudosas" y que el estudiante pueda reportar un error al docente.
- Convertir la web en PWA para practicar sin conexión.

## Licencia

[MIT](LICENSE) © 2026 Nicolás Baier Quezada
