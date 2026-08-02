#!/usr/bin/env bash
set -euo pipefail

CERT_DIR=/www/server/panel/vhost/letsencrypt/boatops.ayany.com
CERT_FILE="$CERT_DIR/fullchain.pem"
KEY_FILE="$CERT_DIR/privkey.pem"
PFX_FILE="$CERT_DIR/fullchain.pfx"
ORDER_INDEX=2f20f61fdcda9eeae9d2bbe129c4ad97
PANEL=/www/server/panel

if [[ ! -s "$CERT_FILE" || ! -s "$KEY_FILE" ]]; then
    echo "BoatOps certificate files are missing" >&2
    exit 1
fi

if openssl x509 -checkend $((30 * 86400)) -noout -in "$CERT_FILE" >/dev/null; then
    echo "BoatOps certificate is not within the 30-day renewal window"
    exit 0
fi

cd "$PANEL"
PYTHONPATH="$PANEL" "$PANEL/pyenv/bin/python3" "$PANEL/class/acme_v2.py"     --renew=1 --index="$ORDER_INDEX" --cycle=30

chmod 600 "$KEY_FILE"
[[ -f "$PFX_FILE" ]] && chmod 600 "$PFX_FILE"
openssl x509 -checkhost boatops.ayany.com -noout -in "$CERT_FILE"
/www/server/nginx/sbin/nginx -t
/www/server/nginx/sbin/nginx -s reload
