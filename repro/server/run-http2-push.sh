#!/bin/sh
set -eu
cd "$(dirname "$0")"
command -v nghttpd >/dev/null 2>&1 || {
  echo "ERROR: nghttpd not found (install nghttp2)." >&2
  exit 127
}
# Bind to loopback only; this fixture is for local testing.
exec nghttpd --no-tls --addr=127.0.0.1 -d www -p /index.html=/push.txt 8080
