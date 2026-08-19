#!/usr/bin/env bash
# Start a local Chroma HTTP server that persists files on this host.
# WordPress talks to Chroma at http://127.0.0.1:8000 — it does not open these files itself.
set -euo pipefail

HOST="${CHATHEARTH_CHROMA_HOST:-127.0.0.1}"
PORT="${CHATHEARTH_CHROMA_PORT:-8000}"
PERSIST="${CHATHEARTH_CHROMA_PATH:-}"

if [ -z "$PERSIST" ]; then
	if [ -n "${WP_DIR:-}" ]; then
		PERSIST="${WP_DIR}/wp-content/uploads/chathearth/chroma"
	else
		PERSIST="/var/www/wordpress/wp-content/uploads/chathearth/chroma"
	fi
fi

mkdir -p "$PERSIST"

CHROMA_BIN=""
if command -v chroma >/dev/null 2>&1; then
	CHROMA_BIN="$(command -v chroma)"
elif [ -x "${HOME}/chathearth-chroma/bin/chroma" ]; then
	CHROMA_BIN="${HOME}/chathearth-chroma/bin/chroma"
elif [ -x /home/ubuntu/chathearth-chroma/bin/chroma ]; then
	CHROMA_BIN=/home/ubuntu/chathearth-chroma/bin/chroma
fi

if [ -z "$CHROMA_BIN" ]; then
	echo "chroma CLI not found. Install with:" >&2
	echo "  python3 -m venv \"\$HOME/chathearth-chroma\"" >&2
	echo "  \"\$HOME/chathearth-chroma/bin/pip\" install chromadb" >&2
	exit 1
fi

echo "Chroma persist: $PERSIST"
echo "Listening on http://${HOST}:${PORT}"
exec "$CHROMA_BIN" run --path "$PERSIST" --host "$HOST" --port "$PORT"
