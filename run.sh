#!/bin/bash
# Usage: ./run.sh [release-2.28.4|release-2.29.0]
set -e
export MANTIS_VERSION="${1:-release-2.28.4}"
cd "$(dirname "$0")"

docker compose down -v >/dev/null 2>&1 || true
docker compose up -d
echo "[*] Waiting for MantisBT $MANTIS_VERSION, a few minutes on first run"
until docker compose logs mantis 2>/dev/null | grep -q "is ready"; do
    if docker compose logs mantis 2>/dev/null | grep -qE "Fatal error|SEED FAILED"; then
        docker compose logs mantis | tail -20; exit 1
    fi
    sleep 5; printf .
done
echo
python3 exploit.py
