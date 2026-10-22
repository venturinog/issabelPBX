#!/bin/bash
#
# php8check.sh — puerta de calidad de compatibilidad con PHP 8.3 / 8.4.
#
#   Uso:  tools/php8check.sh [raiz]
#
# Ejecuta tres comprobaciones sobre el arbol:
#   1. php -l sobre todos los ficheros .php  -> 0 errores de parseo
#   2. php8scan.php                          -> constantes indefinidas,
#                                               funciones eliminadas y
#                                               deprecaciones nuevas de 8.4
#   3. php8scan.php (informativo)            -> propiedades dinamicas e
#                                               interpolacion ${var}
#
# Devuelve 1 si hay cualquier hallazgo de nivel ERROR.
#
# Sin dependencias externas: solo php-cli.

set -u

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT="${1:-${SCRIPT_DIR}/..}"
ROOT="$(cd "${ROOT}" && pwd)" || exit 2
cd "$ROOT" || exit 2

PHP="${PHP:-php}"
SCAN="${SCRIPT_DIR}/php8scan.php"
STATUS=0

echo "=== php8check: $ROOT (PHP $("$PHP" -r 'echo PHP_VERSION;')) ==="

# --- 1. Parseo -------------------------------------------------------------
echo
echo "--- 1. php -l ---"
LINT_OUT=$(find . -name '*.php' -not -path '*/php-upgrade/*' -not -path './build/*' \
    -print0 | xargs -0 -P 8 -n 1 "$PHP" -l 2>&1 | grep -v '^No syntax errors' | grep -v '^$')
if [ -n "$LINT_OUT" ]; then
    echo "$LINT_OUT"
    echo "FALLO: errores de parseo"
    STATUS=1
else
    echo "OK: sin errores de parseo"
fi

# --- 2. Comprobaciones de nivel ERROR --------------------------------------
echo
echo "--- 2. constantes, funciones y deprecaciones 8.4 ---"
"$PHP" "$SCAN" "$ROOT" solo:const,func,php84
[ $? -ne 0 ] && STATUS=1

# --- 3. Informativos --------------------------------------------------------
echo
echo "--- 3. avisos (propiedades dinamicas, interpolacion \${var}) ---"
"$PHP" "$SCAN" "$ROOT" solo:dynprop,interp | tail -20

echo
if [ "$STATUS" -eq 0 ]; then
    echo "=== php8check: OK ==="
else
    echo "=== php8check: HAY FALLOS ==="
fi
exit $STATUS
