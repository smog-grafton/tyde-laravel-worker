#!/bin/sh
set -eu

if [ "${APP_RUNTIME_ROLE:-web}" = "web" ]; then
    curl -fsS "http://127.0.0.1:${APP_PORT:-8080}/up" >/dev/null
else
    php -v >/dev/null
fi
