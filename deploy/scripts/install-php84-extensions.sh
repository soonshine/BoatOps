#!/usr/bin/env bash
set -euo pipefail

PHP_PREFIX=${PHP_PREFIX:-/www/server/php/84}
JOBS=${JOBS:-2}

build_extension() {
    local extension=$1
    local configure_flag=${2:-}
    local source_dir="$PHP_PREFIX/src/ext/$extension"
    local extension_dir
    extension_dir=$("$PHP_PREFIX/bin/php-config" --extension-dir)

    if [[ -f "$extension_dir/$extension.so" ]]; then
        printf 'skip %s: already installed\n' "$extension"
        return
    fi

    test -d "$source_dir"
    cd "$source_dir"
    "$PHP_PREFIX/bin/phpize" --clean >/dev/null 2>&1 || true
    "$PHP_PREFIX/bin/phpize"
    if [[ "$extension" == "pdo_pgsql" || "$extension" == "pgsql" ]]; then
        PGSQL_CFLAGS="-I/usr/include" PGSQL_LIBS="-L/usr/lib64 -lpq" \
            ./configure --with-php-config="$PHP_PREFIX/bin/php-config" "$configure_flag"
    elif [[ -n "$configure_flag" ]]; then
        ./configure --with-php-config="$PHP_PREFIX/bin/php-config" "$configure_flag"
    else
        ./configure --with-php-config="$PHP_PREFIX/bin/php-config"
    fi
    make -j"$JOBS"
    make install
    make clean
}

build_extension fileinfo
build_extension pdo_pgsql --with-pdo-pgsql
build_extension pgsql --with-pgsql

python3 - "$PHP_PREFIX" <<'PY'
from pathlib import Path
import sys

prefix = Path(sys.argv[1])
for ini_name in ("php.ini", "php-cli.ini"):
    ini = prefix / "etc" / ini_name
    if not ini.exists():
        continue
    text = ini.read_text(encoding="utf-8", errors="surrogateescape")
    additions = [
        line for line in ("extension=fileinfo.so", "extension=pdo_pgsql.so", "extension=pgsql.so")
        if line not in text
    ]
    if additions:
        ini.write_text(
            text.rstrip() + "\n\n; BoatOps required extensions\n" + "\n".join(additions) + "\n",
            encoding="utf-8",
            errors="surrogateescape",
        )
PY

"$PHP_PREFIX/bin/php" -m | grep -E '^(fileinfo|pdo_pgsql|pgsql)$'
"$PHP_PREFIX/sbin/php-fpm" -t
