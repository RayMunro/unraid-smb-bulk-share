#!/bin/bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "$0")" && pwd)"
VERSION="2026.09.14c"
PACKAGE="smb-bulk-share-${VERSION}-noarch-1.txz"
STAGE="$(mktemp -d)"
PAYLOAD="$(mktemp)"
trap 'rm -rf "$STAGE"; rm -f "$PAYLOAD"' EXIT

cp -a "$PROJECT_ROOT/source/." "$STAGE/"
mkdir -p "$STAGE/install"
cp "$PROJECT_ROOT/package/install/slack-desc" "$STAGE/install/slack-desc"
tar -C "$STAGE" -cJf "$PROJECT_ROOT/$PACKAGE" .
if base64 --help 2>&1 | grep -q -- '-w'; then
  base64 -w 76 "$PROJECT_ROOT/$PACKAGE" > "$PAYLOAD"
else
  base64 -b 76 -i "$PROJECT_ROOT/$PACKAGE" -o "$PAYLOAD"
fi

awk -v payload="$PAYLOAD" '
  $0 == "@PAYLOAD@" {
    while ((getline line < payload) > 0) print line
    close(payload)
    next
  }
  { print }
' "$PROJECT_ROOT/smb-bulk-share.plg.in" > "$PROJECT_ROOT/smb-bulk-share.plg"

echo "Built $PACKAGE and smb-bulk-share.plg"
