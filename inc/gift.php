<?php
declare(strict_types=1);

/**
 * Parser del formato GIFT de Moodle.
 *
 * Soporta: opción múltiple (una o varias correctas, pesos %), verdadero/falso,
 * respuesta corta (con comodín *), numérica (tolerancia y rangos), emparejamiento,
 * ensayo, descripción, palabra faltante (texto después de las llaves),
 * títulos ::x::, $CATEGORY, formatos [html]/[markdown]/[plain]/[moodle],
 * retroalimentación #, retroalimentación general ####, comentarios // y escapes \~ \= \# \{ \} \: \n
 *
 * Cada pregunta resultante es un array:
 *   tipo: multichoice|truefalse|shortanswer|numerical|matching|essay|description
 *   titulo, texto, texto_post (palabra faltante), formato, fb_general, categoria, linea
 *   + campos propios del tipo (opciones, multiple, correcta, fb_correcta, fb_incorrecta, respuestas, pares)
 */
final class Gift
{
    private const ESCAPES = [
        '\\\\' => "\u{E000}", '\\~' => "\u{E001}", '\\=' => "\u{E002}", '\\#' => "\u{E003}",
        '\\{'  => "\u{E004}", '\\}' => "\u{E005}", '\\:' => "\u{E006}", '\\n' => "\u{E007}",
    ];
    private const RESTAURAR = [
        "\u{E000}" => '\\', "\u{E001}" => '~', "\u{E002}" => '=', "\u{E003}" => '#',
        "\u{E004}" => '{',  "\u{E005}" => '}', "\u{E006}" => ':', "\u{E007}" => "\n",
    ];

    /** @return array{preguntas: array, errores: string[], avisos: string[]} */
    public static function parsear(string $texto): array
    {
        $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto);
        if (!mb_check_encoding($texto, 'UTF-8')) {
            // Archivos guardados en Windows (Bloc de notas antiguo) suelen venir en Latin-1
            $texto = mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
        }
        $texto = str_replace(["\r\n", "\r"], "\n", $texto);

        $res = ['preguntas' => [], 'errores' => [], 'avisos' => []];
        $categoria = '';

        foreach (self::bloques($texto) as [$linea, $bloque]) {
            if (preg_match('/^\s*\$CATEGORY:\s*(.*)$/mi', $bloque, $m, PREG_OFFSET_CAPTURE) && $m[0][1] === 0) {
                $categoria = self::limpiar_categoria($m[1][0]);
                $bloque = trim(substr($bloque, strlen($m[0][0])));
                if ($bloque === '') continue;
                $linea++;
            }
            try {
                $q = self::pregunta($bloque);
                $q['categoria'] = $categoria;
                $q['linea'] = $linea;
                foreach (self::validar($q) as $aviso) {
                    $res['avisos'][] = "Línea $linea: $aviso";
                }
                $res['preguntas'][] = $q;
            } catch (InvalidArgumentException $e) {
                $res['errores'][] = "Línea $linea: " . $e->getMessage() . ' → «' . mb_strimwidth(trim($bloque), 0, 80, '…') . '»';
            }
        }
        return $res;
    }

    /** Divide el texto en bloques separados por líneas en blanco, ignorando comentarios //. */
    private static function bloques(string $texto): array
    {
        $bloques = [];
        $actual = [];
        $inicio = 0;
        foreach (explode("\n", $texto) as $i => $linea) {
            $t = trim($linea);
            if (strpos($t, '//') === 0) continue;
            if ($t === '') {
                if ($actual) $bloques[] = [$inicio, implode("\n", $actual)];
                $actual = [];
                continue;
            }
            if (!$actual) $inicio = $i + 1;
            $actual[] = $linea;
        }
        if ($actual) $bloques[] = [$inicio, implode("\n", $actual)];
        return $bloques;
    }

    private static function limpiar_categoria(string $c): string
    {
        $c = trim($c);
        $c = preg_replace('#^\$(course|cat\d|system|module)\$/#', '', $c);
        $c = preg_replace('#^top/#', '', $c);
        return trim($c, '/ ');
    }

    private static function u(string $s): string
    {
        return trim(strtr($s, self::RESTAURAR));
    }

    private static function pregunta(string $bloque): array
    {
        $s = trim(strtr($bloque, self::ESCAPES));

        $titulo = '';
        if (substr($s, 0, 2) === '::') {
            $fin = strpos($s, '::', 2);
            if ($fin === false) throw new InvalidArgumentException('Título sin cerrar (falta «::»)');
            $titulo = self::u(substr($s, 2, $fin - 2));
            $s = trim(substr($s, $fin + 2));
        }

        $formato = 'moodle';
        if (preg_match('/^\[(html|moodle|plain|markdown)\]\s*/i', $s, $m)) {
            $formato = strtolower($m[1]);
            $s = substr($s, strlen($m[0]));
        }

        $base = ['tipo' => '', 'titulo' => $titulo, 'texto' => '', 'texto_post' => '', 'formato' => $formato, 'fb_general' => ''];

        $abre = strpos($s, '{');
        if ($abre === false) {
            if (strpos($s, '}') !== false) throw new InvalidArgumentException('Hay «}» sin «{»');
            $base['tipo'] = 'description';
            $base['texto'] = self::u($s);
            return $base;
        }
        $cierra = strpos($s, '}', $abre);
        if ($cierra === false) throw new InvalidArgumentException('Falta cerrar las llaves «}»');

        $antes = self::u(substr($s, 0, $abre));
        $despues = self::u(substr($s, $cierra + 1));
        if (strpos(substr($s, $cierra + 1), '{') !== false) {
            throw new InvalidArgumentException('Solo se permite un bloque {…} por pregunta');
        }
        $resp = trim(substr($s, $abre + 1, $cierra - $abre - 1));

        $base['texto'] = $antes !== '' ? $antes : $titulo;
        $base['texto_post'] = $despues;
        if ($base['texto'] === '' && $despues === '') throw new InvalidArgumentException('Pregunta sin texto');

        $p = strpos($resp, '####');
        if ($p !== false) {
            $base['fb_general'] = self::u(substr($resp, $p + 4));
            $resp = trim(substr($resp, 0, $p));
        }

        if ($resp === '') {
            $base['tipo'] = 'essay';
            return $base;
        }
        if (preg_match('/^(T|TRUE|F|FALSE|V|VERDADERO|FALSO)\s*(#|$)/i', $resp)) {
            return self::verdadero_falso($base, $resp);
        }
        if ($resp[0] === '#') {
            return self::numerica($base, substr($resp, 1));
        }
        if (strpos($resp, '->') !== false && strpos($resp, '=') !== false && strpos($resp, '~') === false) {
            return self::emparejamiento($base, $resp);
        }
        if (strpos($resp, '~') !== false) {
            return self::opcion_multiple($base, $resp);
        }
        if ($resp[0] === '=') {
            return self::respuesta_corta($base, $resp);
        }
        throw new InvalidArgumentException('No se reconoce el tipo de pregunta (las respuestas deben empezar con =, ~, # o T/F)');
    }

    /** Separa «=a#fb ~%50%b» en elementos [marca, fraccion|null, texto, fb]. */
    private static function elementos(string $resp): array
    {
        $out = [];
        foreach (preg_split('/(?=[=~])/', $resp, -1, PREG_SPLIT_NO_EMPTY) as $parte) {
            $parte = trim($parte);
            if ($parte === '') continue;
            $marca = $parte[0];
            if ($marca !== '=' && $marca !== '~') {
                throw new InvalidArgumentException('Texto suelto «' . self::u($parte) . '» entre las respuestas');
            }
            $parte = ltrim(substr($parte, 1));
            $fraccion = null;
            if (preg_match('/^%(-?\d+(?:[.,]\d+)?)%/', $parte, $m)) {
                $fraccion = (float)str_replace(',', '.', $m[1]) / 100;
                $parte = substr($parte, strlen($m[0]));
            }
            $fb = '';
            $p = strpos($parte, '#');
            if ($p !== false) {
                $fb = self::u(substr($parte, $p + 1));
                $parte = substr($parte, 0, $p);
            }
            $parte = preg_replace('/^\[(html|moodle|plain|markdown)\]/i', '', trim($parte));
            $out[] = [$marca, $fraccion, $parte, $fb];
        }
        return $out;
    }

    private static function verdadero_falso(array $q, string $resp): array
    {
        $partes = explode('#', $resp);
        $v = strtoupper(trim($partes[0]));
        $q['tipo'] = 'truefalse';
        $q['correcta'] = in_array($v, ['T', 'TRUE', 'V', 'VERDADERO'], true);
        $q['fb_incorrecta'] = self::u($partes[1] ?? '');
        $q['fb_correcta'] = self::u($partes[2] ?? '');
        return $q;
    }

    private static function opcion_multiple(array $q, string $resp): array
    {
        $q['tipo'] = 'multichoice';
        $q['opciones'] = [];
        foreach (self::elementos($resp) as [$marca, $fraccion, $texto, $fb]) {
            if ($fraccion === null) $fraccion = $marca === '=' ? 1.0 : 0.0;
            $q['opciones'][] = ['texto' => self::u($texto), 'fraccion' => $fraccion, 'fb' => $fb];
        }
        if (count($q['opciones']) < 2) throw new InvalidArgumentException('Opción múltiple necesita al menos 2 opciones');
        $positivas = array_filter($q['opciones'], function ($o) { return $o['fraccion'] > 0; });
        $max = max(array_column($q['opciones'], 'fraccion'));
        // Varias correctas parciales (~%50%a ~%50%b) => casillas; si no => radio
        $q['multiple'] = count($positivas) > 1 && $max < 1;
        return $q;
    }

    private static function respuesta_corta(array $q, string $resp): array
    {
        $q['tipo'] = 'shortanswer';
        $q['respuestas'] = [];
        foreach (self::elementos($resp) as [$marca, $fraccion, $texto, $fb]) {
            $q['respuestas'][] = ['texto' => self::u($texto), 'fraccion' => $fraccion ?? 1.0, 'fb' => $fb];
        }
        return $q;
    }

    private static function emparejamiento(array $q, string $resp): array
    {
        $q['tipo'] = 'matching';
        $q['pares'] = [];
        foreach (self::elementos($resp) as [$marca, $fraccion, $texto]) {
            $p = strpos($texto, '->');
            if ($marca !== '=' || $p === false) throw new InvalidArgumentException('Cada par debe tener la forma «=izquierda -> derecha»');
            $q['pares'][] = ['izq' => self::u(substr($texto, 0, $p)), 'der' => self::u(substr($texto, $p + 2))];
        }
        return $q;
    }

    private static function numerica(array $q, string $resp): array
    {
        $q['tipo'] = 'numerical';
        $q['respuestas'] = [];
        $resp = trim($resp);
        if ($resp !== '' && ($resp[0] === '=' || $resp[0] === '~')) {
            $elementos = self::elementos($resp);
        } else {
            // Forma corta: {#3.14:0.01#feedback}
            $partes = explode('#', $resp, 2);
            $elementos = [['=', null, $partes[0], self::u($partes[1] ?? '')]];
        }

        foreach ($elementos as [$marca, $fraccion, $texto, $fb]) {
            $texto = self::u($texto);
            if ($fraccion === null) $fraccion = $marca === '=' ? 1.0 : 0.0;
            if (strpos($texto, '..') !== false) {
                [$a, $b] = array_map('trim', explode('..', $texto, 2));
                if (!self::es_num($a) || !self::es_num($b)) throw new InvalidArgumentException("Rango numérico inválido «{$texto}»");
                $min = self::num($a);
                $max = self::num($b);
                $valor = ($min + $max) / 2;
                $tol = ($max - $min) / 2;
            } else {
                $partes = explode(':', $texto, 2);
                if (!self::es_num($partes[0]) || (isset($partes[1]) && !self::es_num($partes[1]))) {
                    throw new InvalidArgumentException("Respuesta numérica inválida «{$texto}»");
                }
                $valor = self::num($partes[0]);
                $tol = isset($partes[1]) ? abs(self::num($partes[1])) : 0.0;
            }
            $q['respuestas'][] = ['valor' => $valor, 'tol' => $tol, 'fraccion' => $fraccion, 'fb' => $fb];
        }
        return $q;
    }

    public static function es_num(string $s): bool
    {
        return is_numeric(str_replace(',', '.', trim($s)));
    }

    public static function num(string $s): float
    {
        return (float)str_replace(',', '.', trim($s));
    }

    /** Advertencias no fatales (la pregunta se importa igual). */
    private static function validar(array $q): array
    {
        $a = [];
        switch ($q['tipo']) {
            case 'multichoice':
                $max = max(array_column($q['opciones'], 'fraccion'));
                if ($max <= 0) $a[] = 'la pregunta de opción múltiple no tiene ninguna opción correcta';
                if ($q['multiple']) {
                    $suma = array_sum(array_filter(array_column($q['opciones'], 'fraccion'), function ($f) { return $f > 0; }));
                    if (abs($suma - 1) > 0.02) $a[] = 'los porcentajes positivos suman ' . round($suma * 100) . '% (deberían sumar 100%)';
                }
                break;
            case 'shortanswer':
                if (max(array_column($q['respuestas'], 'fraccion')) < 1) $a[] = 'ninguna respuesta corta vale 100%';
                break;
            case 'matching':
                $conIzq = array_filter($q['pares'], function ($p) { return $p['izq'] !== ''; });
                if (count($conIzq) < 2) $a[] = 'emparejamiento con menos de 2 pares';
                break;
        }
        return $a;
    }
}
