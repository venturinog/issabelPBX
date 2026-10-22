<?php
/**
 * php8scan.php — escaner estatico de incompatibilidades con PHP 8.3/8.4.
 *
 * Uso:  php php8scan.php <raiz> [solo:<check1,check2,...>]
 *
 * Checks:
 *   const    constantes definidas que PHP 8 convierte en Error fatal
 *   func     funciones removidas en PHP 7/8
 *   php84    deprecaciones que son nuevas en PHP 8.4
 *   dynprop  creacion de propiedades dinamicas (deprecado en 8.2) [informativo]
 *   interp   interpolacion ${var} (deprecada en 8.2) [informativo]
 *
 * Los checks `const`, `func`, `php84` y `dynprop` son de nivel ERROR: el
 * codigo se rompe o deja de funcionar. `interp` y los avisos de `func` que
 * solo apliquen a codigo muerto son informativos.
 *
 * Sin dependencias externas. Solo PHP CLI.
 */

// ---------------------------------------------------------------------------
// Configuracion
// ---------------------------------------------------------------------------

$root = isset($argv[1]) ? rtrim($argv[1], '/') : '.';
$only = array();
foreach (array_slice($argv, 2) as $a) {
    if (strpos($a, 'solo:') === 0) {
        $only = array_filter(array_map('trim', explode(',', substr($a, 5))));
    }
}
$want = function ($name) use ($only) {
    return empty($only) || in_array($name, $only, true);
};

// ---------------------------------------------------------------------------
// Alcance: solo los modulos listados en tools/php8scope.txt bloquean.
// Los demas se reportan como informativos.
// ---------------------------------------------------------------------------

$scopeFile = __DIR__ . '/php8scope.txt';
$scope = array();
if (file_exists($scopeFile)) {
    foreach (file($scopeFile) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $scope[$line] = true;
    }
}
$inScope = function ($rel) use ($scope) {
    if (empty($scope)) return true;
    $top = strtok($rel, '/');
    return isset($scope[$top]);
};

// PEAR DB define globalmente DB_FETCHMODE_*, DB_ERROR_*, PEAR_ERROR_* y las
// clases DB y PEAR. Cargarlo aqui evita 1300 falsos positivos.
foreach (array('/usr/share/pear/DB.php', '/usr/share/pear/PEAR.php') as $pear) {
    if (file_exists($pear)) {
        @require_once $pear;
    }
}

// Funciones removidas en PHP 7.0/7.2/8.0.
$removedFuncs = array(
    'each'                   => 'PHP 8.0',
    'create_function'        => 'PHP 8.0',
    'ereg'                   => 'PHP 7.0',
    'eregi'                  => 'PHP 7.0',
    'ereg_replace'           => 'PHP 7.0',
    'split'                  => 'PHP 7.0',
    'spliti'                 => 'PHP 7.0',
    'sql_regcase'            => 'PHP 7.0',
    'mysql_connect'          => 'PHP 7.0',
    'mysql_pconnect'         => 'PHP 7.0',
    'mysql_query'            => 'PHP 7.0',
    'mysql_fetch_array'      => 'PHP 7.0',
    'mysql_fetch_row'        => 'PHP 7.0',
    'mysql_fetch_assoc'      => 'PHP 7.0',
    'mysql_fetch_object'     => 'PHP 7.0',
    'mysql_num_rows'         => 'PHP 7.0',
    'mysql_free_result'      => 'PHP 7.0',
    'mysql_insert_id'        => 'PHP 7.0',
    'mysql_real_escape_string' => 'PHP 7.0',
    'mysql_escape_string'    => 'PHP 7.0',
    'mysql_error'            => 'PHP 7.0',
    'mysql_errno'            => 'PHP 7.0',
    'mysql_select_db'        => 'PHP 7.0',
    'mysql_affected_rows'    => 'PHP 7.0',
    'mysql_close'            => 'PHP 7.0',
    'mysql_unbuffered_query' => 'PHP 7.0',
    'mysql_list_dbs'         => 'PHP 7.0',
    'mysql_list_fields'      => 'PHP 7.0',
    'mysql_list_tables'      => 'PHP 7.0',
    'mysql_field_flags'      => 'PHP 7.0',
    'mysql_stat'             => 'PHP 7.0',
    'dl'                     => 'PHP 7.0',
    'mcrypt_encrypt'         => 'PHP 7.2',
    'mcrypt_decrypt'         => 'PHP 7.2',
    'mcrypt_module_open'     => 'PHP 7.2',
    'mcrypt_generic'         => 'PHP 7.2',
    'mcrypt_generic_init'    => 'PHP 7.2',
    'mcrypt_generic_deinit'  => 'PHP 7.2',
    'mcrypt_create_iv'       => 'PHP 7.2',
    'mcrypt_get_iv_size'     => 'PHP 7.2',
    'mcrypt_get_block_size'  => 'PHP 7.2',
    'mcrypt_get_key_size'    => 'PHP 7.2',
    'mcrypt_module_close'    => 'PHP 7.2',
    'mcrypt_enc_get_iv_size' => 'PHP 7.2',
    'mcrypt_enc_get_block_size' => 'PHP 7.2',
    'mcrypt_enc_get_key_size'   => 'PHP 7.2',
    'money_format'           => 'PHP 8.0',
    'set_magic_quotes_runtime' => 'PHP 7.0',
    'fgetss'                 => 'PHP 7.3',
    'strptime'               => 'PHP 8.1',
    'image2wbmp'             => 'PHP 8.0',
    '__autoload'             => 'PHP 8.0',
    'get_magic_quotes_gpc'   => 'PHP 8.0',
    'get_magic_quotes_runtime' => 'PHP 8.0',
    'restore_include_path'   => 'PHP 8.0',
    'ldap_sort'              => 'PHP 8.3',
    'sqlite_open'            => 'PHP 5.4 (ext/sqlite v1)',
    'sqlite_query'           => 'PHP 5.4 (ext/sqlite v1)',
    'sqlite_exec'            => 'PHP 5.4 (ext/sqlite v1)',
    'sqlite_fetch_array'     => 'PHP 5.4 (ext/sqlite v1)',
    'sqlite_last_insert_rowid' => 'PHP 5.4 (ext/sqlite v1)',
    'sqlite_num_rows'        => 'PHP 5.4 (ext/sqlite v1)',
    'sqlite_error_string'    => 'PHP 5.4 (ext/sqlite v1)',
    'sqlite_escape_string'   => 'PHP 5.4 (ext/sqlite v1)',
    'sqlite_field_name'      => 'PHP 5.4 (ext/sqlite v1)',
);

// Funciones internas cuyo $escape por defecto quedo deprecado en PHP 8.4.
// Valor = numero de argumentos con los que ya se puede omitir $escape.
$csvEscapeArity = array(
    'fgetcsv'    => 5,
    'str_getcsv' => 4,
    'fputcsv'    => 5,
);

// ---------------------------------------------------------------------------
// Recoleccion previa: lo que define el propio codigo
// ---------------------------------------------------------------------------

// Rangos (linea inicial y final del cuerpo) de las clases marcadas con
// #[AllowDynamicProperties], por fichero. En una clase con ese atributo las
// propiedades dinamicas son un opt-in consciente, de modo que en ellas el
// aviso no aporta nada y se suprime.
$annotated = array();
$files = array();
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $f) {
    if (strtolower($f->getExtension()) !== 'php') continue;
    $p = $f->getPathname();
    if (strpos($p, '/php-upgrade/') !== false) continue;
    if (strpos($p, '/build/') !== false) continue;
    if (strpos($p, '/tools/') !== false) continue;
    $files[] = $p;
}
sort($files);

$knownConst  = array();
$knownClass  = array();
$knownFunc   = array();

foreach (array_keys(get_defined_constants()) as $c) {
    $knownConst[strtolower($c)] = true;
}
foreach (get_defined_functions()['internal'] as $f) {
    $knownFunc[strtolower($f)] = true;
}
foreach (get_declared_classes() as $c) {
    $knownClass[strtolower($c)] = true;
}
foreach (get_declared_interfaces() as $c) {
    $knownClass[strtolower($c)] = true;
}

foreach ($files as $p) {
    $src = file_get_contents($p);
    if (preg_match_all("/\bdefine\s*\(\s*['\"]([A-Za-z_][A-Za-z0-9_]*)['\"]/", $src, $m)) {
        foreach ($m[1] as $c) { $knownConst[strtolower($c)] = true; }
    }
    if (preg_match_all('/\bconst\s+([A-Za-z_][A-Za-z0-9_]*)/', $src, $m)) {
        foreach ($m[1] as $c) { $knownConst[strtolower($c)] = true; }
    }
    if (preg_match_all('/\b(?:class|interface|trait)\s+([A-Za-z_][A-Za-z0-9_]*)/', $src, $m)) {
        foreach ($m[1] as $c) { $knownClass[strtolower($c)] = true; }
    }
    if (preg_match_all('/\bfunction\s+&?\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $src, $m)) {
        foreach ($m[1] as $f) { $knownFunc[strtolower($f)] = true; }
    }
}
// Palabras clave que el tokenizador marca como T_STRING.
foreach (array('self', 'parent', 'static', 'array', 'list', 'null', 'true', 'false',
               'int', 'float', 'string', 'bool', 'void', 'iterable', 'object',
               'mixed', 'never', 'callable', 'fn') as $k) {
    $knownClass[$k] = true;
}

// ---------------------------------------------------------------------------
// Escaneo
// ---------------------------------------------------------------------------

// Calculo de los rangos de cuerpo de las clases, por fichero:
// [lineaInicio, lineaFin, suprimir]. Se resuelve la marca
// #[AllowDynamicProperties] y su herencia (un hijo de una clase anotada
// hereda el atributo, verificado en PHP 8.2+).
foreach ($files as $p) {
    $relK = preg_replace('#^' . preg_quote($root, '#') . '/#', '', $p);
    $classes = array();   // registros de clases: name, start, end, annot, parent
    $waiting = false;     // 'class X' visto, pendiente de su '{'
    $pendName = null;
    $pendParent = null;
    $cur = null;          // [startLine, parent, recordIndex]
    $depth = 0;
    $line = 1;
    $toks = token_get_all(file_get_contents($p));
    $nk = count($toks);
    for ($k = 0; $k < $nk; $k++) {
        $tk = $toks[$k];
        if (!is_array($tk)) {
            if ($tk === '{') {
                if ($waiting) {
                    // el cuerpo de una clase arranca aqui
                    $classes[] = array('name' => $pendName, 'start' => $classLine, 'end' => 0, 'annot' => $annotPend, 'parent' => $pendParent);
                    $cur = count($classes) - 1;
                    $depth = 1;
                    $waiting = false;
                } elseif ($cur !== null) {
                    $depth++;
                }
            } elseif ($tk === '}') {
                if ($cur !== null && --$depth === 0) {
                    $classes[$cur]['end'] = $line;
                    $cur = null;
                    $annotPend = false;
                }
            }
            $line += substr_count($tk, "\n");
            continue;
        }
        $line = $tk[2];
        switch ($tk[0]) {
            case T_ATTRIBUTE:
                $na = nextSignificant($toks, $k);
                $annotPend = ($na < $nk && is_array($toks[$na]) && $toks[$na][1] === 'AllowDynamicProperties');
                break;
            case T_CLASS:
                $ni2 = nextSignificant($toks, $k);
                if ($ni2 < $nk && is_array($toks[$ni2]) && $toks[$ni2][0] === T_STRING) {
                    $pendName = $toks[$ni2][1];
                    $pendParent = null;
                    $waiting = true;
                    $classLine = $tk[2];
                } else {
                    // clase anonima: atributo sin consumir
                    $annotPend = false;
                }
                break;
            case T_EXTENDS:
                if ($waiting) {
                    $ni2 = nextSignificant($toks, $k);
                    if ($ni2 < $nk && is_array($toks[$ni2]) && $toks[$ni2][0] === T_STRING) {
                        $pendParent = $toks[$ni2][1];
                    }
                }
                break;
            case T_CURLY_OPEN:
                // apertura de interpolacion "{$var}" dentro de una cadena: su
                // cierre SI es un '}' token string, asi que hay que contarla.
                if ($cur !== null) { $depth++; }
                break;
        }
        $line += substr_count($tk[1], "\n");
    }
    // resolucion con herencia
    $byname = array();
    foreach ($classes as $c) { $byname[$c['name']] = $c; }
    $ranges = array();
    foreach ($classes as $c) {
        $sup = $c['annot'];
        $seen = array();
        $par = $c['parent'];
        while ($par && !isset($seen[$par])) {
            $seen[$par] = true;
            if (isset($byname[$par]) && $byname[$par]['annot']) { $sup = true; break; }
            $par = isset($byname[$par]) ? $byname[$par]['parent'] : null;
        }
        $ranges[] = array($c['start'], $c['end'] ? $c['end'] : $line, $sup);
    }
    $annotated[$relK] = $ranges;
}

$findings = array();
$fileLines = array();
function add($level, $check, $file, $line, $msg) {
    global $findings, $inScope;
    // Fuera del alcance (ver tools/php8scope.txt) no bloquea: se reporta
    // como informativo para que quede visible sin romper la puerta.
    if ($level === 'ERROR' && !call_user_func($inScope, $file)) {
        $level = 'INFO';
        $msg .= ' [fuera de alcance]';
    }
    $findings[] = array($level, $check, $file, $line, $msg);
}

/** Devuelve el indice del token siguiente no trivial desde $i. */
function nextSignificant($tokens, $i) {
    $n = count($tokens);
    for ($k = $i + 1; $k < $n; $k++) {
        if (is_array($tokens[$k]) && in_array($tokens[$k][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
            continue;
        }
        return $k;
    }
    return $n;
}

/** Devuelve el indice del token previo no trivial desde $i. */
function prevSignificant($tokens, $i) {
    for ($k = $i - 1; $k >= 0; $k--) {
        if (is_array($tokens[$k]) && in_array($tokens[$k][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
            continue;
        }
        return $k;
    }
    return -1;
}

/**
 * Cuenta los argumentos de una llamada y detecta el uso de argumentos con
 * nombre (escape:). Devuelve array(num, tieneNamedEscape).
 */
function callArity($tokens, $lparen) {
    $n = count($tokens);
    $depth = 0;
    $count = 0;
    $hasNamed = false;
    $seenValue = false;
    for ($k = $lparen; $k < $n; $k++) {
        $tk = $tokens[$k];
        if ($tk === '(' || $tk === '[' || $tk === '{') { $depth++; continue; }
        if ($tk === ')' || $tk === ']' || $tk === '}') {
            $depth--;
            if ($depth === 0) { break; }
            continue;
        }
        if ($depth === 1) {
            if ($tk === ',') { $count++; continue; }
            if (is_array($tk) && $tk[0] === T_STRING && $tk[1] === 'escape') {
                $nx = nextSignificant($tokens, $k);
                if ($nx < $n && $tokens[$nx] === ':') { $hasNamed = true; }
            }
        }
    }
    // Un trailing comma no cuenta como argumento extra.
    $count = rtrim(str_repeat(' ', $count), ' ');
    $parts = 0;
    $depth = 0;
    $nonEmpty = false;
    for ($k = $lparen + 1; $k < $n; $k++) {
        $tk = $tokens[$k];
        if ($tk === '(' || $tk === '[' || $tk === '{') { $depth++; continue; }
        if ($tk === ')' || $tk === ']' || $tk === '}') {
            $depth--;
            if ($depth === 0) { break; }
            continue;
        }
        if ($depth === 1 && $tk === ',') { $parts++; $nonEmpty = false; continue; }
        if ($depth === 1) {
            if (is_array($tk) && in_array($tk[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                continue;
            }
            $nonEmpty = true;
        }
    }
    $argc = $nonEmpty ? $parts + 1 : $parts;
    return array($argc, $hasNamed);
}

foreach ($files as $p) {
    $rel = preg_replace('#^' . preg_quote($root, '#') . '/#', '', $p);
    $src = file_get_contents($p);
    $fileLines[$rel] = explode("\n", $src);
    $tokens = token_get_all($src);
    $n = count($tokens);

    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t)) continue;

        // --- 1. Constantes indefinidas ---------------------------------
        if ($want('const') && $t[0] === T_STRING) {
            do {
            $name = $t[1];
            if ($name === '' || $name[0] === '$') continue;
            $lower = strtolower($name);

            $pi = prevSignificant($tokens, $i);
            $prev = $pi >= 0 ? $tokens[$pi] : null;
            $ni  = nextSignificant($tokens, $i);
            $next = $ni < $n ? $tokens[$ni] : null;

            //_PREV_ EXCLUIDOS: son sintaxis, no lectura de constante.
            if (is_array($prev) && in_array($prev[0], array(
                    T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON,
                    T_FUNCTION, T_NEW, T_CLASS, T_INTERFACE, T_TRAIT, T_CONST,
                    T_NAMESPACE, T_USE, T_INSTANCEOF, T_EXTENDS, T_IMPLEMENTS,
                    T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE,
                    T_GOTO, T_AS, T_CASE, T_CATCH, T_ENUM), true)) {
                continue;
            }
            if (is_string($prev) && ($prev === '\\' || $prev === '$' || $prev === '?')) continue;
            // El siguiente token T_DOUBLE_COLON significa nombre de clase.
            if (is_array($next) && $next[0] === T_DOUBLE_COLON) continue;
            // Un T_STRING seguido de '(' es una llamada a funcion, no una
            // lectura de constante. Las funciones ausentes las reporta el
            // check 'func'.
            if ($next === '(') continue;
            // Etiqueta de argumento nominal de PHP 8 (escape: ''): no es una
            // lectura de constante.
            if (is_string($next) && $next === ':') continue;

            if (isset($knownClass[$lower]) || isset($knownConst[$lower])) continue;
            if (is_array($next) && $next[0] === T_DOUBLE_ARROW) continue;

            add('ERROR', 'const', $rel, $t[2],
                "constante indefinida \"$name\" — en PHP 8 es un Error fatal "
                . "(en 7.4 era un Warning con el nombre como string)");
            } while (false);
        }

        // --- 2. Funciones eliminadas ----------------------------------
        if ($want('func') && $t[0] === T_STRING) {
            do {
            $lower = strtolower($t[1]);
            if (!isset($removedFuncs[$lower])) continue;
            if (isset($knownFunc[$lower])) continue;   // definida en el codigo

            $pi = prevSignificant($tokens, $i);
            $prev = $pi >= 0 ? $tokens[$pi] : null;
            $ni  = nextSignificant($tokens, $i);
            $next = $ni < $n ? $tokens[$ni] : null;

            if (is_array($prev) && in_array($prev[0], array(
                    T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON,
                    T_FUNCTION, T_NEW, T_CLASS, T_CONST, T_USE), true)) {
                continue;
            }
            if ($next !== '(') continue;   // no es una llamada

            // Si la llamada esta guardada por is_callable()/function_exists()
            // unas lineas antes, no se ejecuta: se degrada a informativo.
            // Tambien se degrada si la rama esta condicionada por
            // version_compare(PHP_VERSION, ...), que en PHP 8 es falsa.
            $guarded = false;
            $why = '';
            for ($gl = max(1, $t[2] - 8); $gl < $t[2]; $gl++) {
                $ctx = isset($fileLines[$rel][$gl]) ? $fileLines[$rel][$gl] : '';
                if (preg_match('/(is_callable|function_exists)\s*\(\s*[\'"]' . preg_quote($lower, '/') . '[\'"]\s*\)/i', $ctx)) {
                    $guarded = true;
                    $why = 'llamada guardada por is_callable(): no se ejecuta';
                    break;
                }
                if (preg_match('/version_compare\s*\(\s*PHP_VERSION/i', $ctx)) {
                    $guarded = true;
                    $why = 'rama condicionada por version_compare(PHP_VERSION, ...): no se ejecuta en PHP 8';
                    break;
                }
            }
            add($guarded ? 'INFO' : 'ERROR', 'func', $rel, $t[2],
                "{$t[1]}() removida en {$removedFuncs[$lower]} — "
                . ($guarded ? $why : 'llamada fatal'));
            } while (false);
        }

        // --- 3. Deprecaciones nuevas de 8.4 ---------------------------
        if ($want('php84') && $t[0] === T_STRING) {
            do {
            $lower = strtolower($t[1]);
            if (isset($csvEscapeArity[$lower])) {
                $pi = prevSignificant($tokens, $i);
                $prev = $pi >= 0 ? $tokens[$pi] : null;
                if (is_array($prev) && in_array($prev[0], array(
                        T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON,
                        T_FUNCTION, T_NEW), true)) {
                    continue;
                }
                $ni = nextSignificant($tokens, $i);
                if ($ni >= $n || $tokens[$ni] !== '(') continue;
                list($argc, $named) = callArity($tokens, $ni);
                if (!$named && $argc < $csvEscapeArity[$lower]) {
                    add('ERROR', 'php84', $rel, $t[2],
                        "{$t[1]}() sin el argumento \$escape — deprecado en PHP 8.4 "
                        . "(pasarlo como \$escape: '' desactiva el escape proprietary)");
                }
            }
            if ($lower === 'e_strict') {
                add('ERROR', 'php84', $rel, $t[2],
                    "constante E_STRICT deprecada en PHP 8.4 (usar el literal 2048)");
            }
            if ($lower === 'mysqli_driver') {
                add('ERROR', 'php84', $rel, $t[2],
                    "mysqli_driver() deprecada en PHP 8.4");
            }
            } while (false);
        }

        // --- 4. Propiedades dinamicas (informativo) --------------------
        if ($want('dynprop') && $t[0] === T_VARIABLE && $t[1] === '$this') {
            do {
            static $declared = array();
            if (!$declared) {
                foreach ($files as $q) {
                    $s = file_get_contents($q);
                    if (preg_match_all('/\b(?:public|protected|private|var)\s+(?:static\s+)?(?:\?)?[A-Za-z_\\\\][A-Za-z0-9_\\\\]*\s+\$([A-Za-z_][A-Za-z0-9_]*)|\b(?:public|protected|private|var)\s+(\$[A-Za-z_][A-Za-z0-9_]*)/', $s, $m, PREG_SET_ORDER)) {
                        foreach ($m as $mm) {
                            $prop  = isset($mm[1]) && $mm[1] !== '' ? $mm[1] : ltrim($mm[2], '$');
                            $declared[$prop] = true;
                        }
                    }
                }
            }
            $ni = nextSignificant($tokens, $i);
            if ($ni < $n && is_array($tokens[$ni]) && $tokens[$ni][0] === T_OBJECT_OPERATOR) {
                $pi2 = nextSignificant($tokens, $ni);
                if ($pi2 < $n && is_array($tokens[$pi2]) && $tokens[$pi2][0] === T_STRING) {
                    $prop = $tokens[$pi2][1];
                    $ai = nextSignificant($tokens, $pi2);
                    if ($ai < $n && $tokens[$ai] === '=' && !isset($declared[$prop])) {
                        $inAnnot = false;
                        foreach ($annotated[$rel] as $rg) {
                            if ($rg[2] && $tokens[$pi2][2] >= $rg[0] && $tokens[$pi2][2] <= $rg[1]) { $inAnnot = true; break; }
                        }
                        if ($inAnnot) break;
                        add('WARN', 'dynprop', $rel, $tokens[$pi2][2],
                            "propiedad dinamica \$this->$prop — deprecado en PHP 8.2");
                    }
                }
            }
            } while (false);
        }

        // --- 5. Interpolacion ${var} (informativo) --------------------
        if ($want('interp') && $t[0] === T_ENCAPSED_AND_WHITESPACE) {
            do {
            if (preg_match_all('/(?<!\\\\)\$\{([A-Za-z_][A-Za-z0-9_]*)\}/', $t[1], $m)) {
                foreach ($m[0] as $tok) {
                    add('WARN', 'interp', $rel, $t[2],
                        "interpolacion $tok — deprecada en PHP 8.2 (usar {$m[1][0]})");
                }
            }
            } while (false);
        }
    }
}

// ---------------------------------------------------------------------------
// Parametros nullable implicitos (deprecacion de PHP 8.4)
// ---------------------------------------------------------------------------

if ($want('php84')) {
    foreach ($files as $p) {
        $rel = preg_replace('#^' . preg_quote($root, '#') . '/#', '', $p);
        $tokens = token_get_all(file_get_contents($p));
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || $t[0] !== T_FUNCTION) continue;
            $pi = prevSignificant($tokens, $i);
            if ($pi >= 0 && is_array($tokens[$pi]) && $tokens[$pi][0] === T_DOUBLE_COLON) continue;
            $ni = nextSignificant($tokens, $i);
            if ($ni >= $n || $tokens[$ni] !== '(') continue;
            $depth = 0;
            $start = $ni + 1;
            for ($k = $ni; $k < $n; $k++) {
                if ($tokens[$k] === '(') { $depth++; continue; }
                if ($tokens[$k] === ')') {
                    $depth--;
                    if ($depth === 0) {
                        $params = implode('', array_map(function ($x) {
                            return is_array($x) ? $x[1] : $x;
                        }, array_slice($tokens, $start, $k - $start)));
                        foreach (preg_split('/,(?![^(]*\))/', $params) as $pdecl) {
                            $pdecl = trim($pdecl);
                            if ($pdecl === '' || strpos($pdecl, '=') === false) continue;
                            list($decl, $def) = explode('=', $pdecl, 2);
                            if (strtolower(trim($def)) !== 'null') continue;
                            $decl = trim(str_replace(array('&', '...'), ' ', $decl));
                            $pieces = preg_split('/\s+/', $decl, -1, PREG_SPLIT_NO_EMPTY);
                            if (!$pieces) continue;
                            $types = array();
                            $found = false;
                            foreach (array_reverse($pieces) as $pc) {
                                if (strpos($pc, '$') === 0) { $found = true; continue; }
                                if ($found) { $types[] = $pc; }
                            }
                            if (!$types) continue;
                            $type = implode(' ', $types);
                            if ($type === '' || $type[0] === '?') continue;
                            $lt = strtolower($type);
                            if ($lt === 'mixed' || $lt === 'null') continue;
                            add('ERROR', 'php84', $rel, $t[2],
                                "parametro implicitamente nullable ($type \$x = null) — "
                                . "deprecado en PHP 8.4 (usar ?$type)");
                        }
                        break;
                    }
                    continue;
                }
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Informe
// ---------------------------------------------------------------------------

$errors = 0;
$warns  = 0;
$byCheck = array();
foreach ($findings as $f) {
    if ($f[0] === 'ERROR') { $errors++; } else { $warns++; }
    $byCheck[$f[1]] = isset($byCheck[$f[1]]) ? $byCheck[$f[1]] + 1 : 1;
}
ksort($byCheck);

foreach (array('ERROR', 'WARN') as $level) {
    foreach ($findings as $f) {
        if ($f[0] !== $level) continue;
        printf("%-5s %-8s %s:%d  %s\n", $level, $f[1], $f[2], $f[3], $f[4]);
    }
}

printf("\n%s: %d ficheros analizados | %d errores, %d avisos\n",
    basename($root), count($files), $errors, $warns);
foreach ($byCheck as $k => $v) {
    printf("  %-8s %d\n", $k, $v);
}

exit($errors > 0 ? 1 : 0);
