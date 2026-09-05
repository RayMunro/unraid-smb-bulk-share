#!/bin/bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TEST_ROOT="$(mktemp -d)"
trap 'rm -rf "$TEST_ROOT"' EXIT

mkdir -p "$TEST_ROOT/config" "$TEST_ROOT/user/Movies" "$TEST_ROOT/user/Music" "$TEST_ROOT/user/appdata" "$TEST_ROOT/backups" "$TEST_ROOT/plugin"

printf '%s\n' 'shareExport="-"' 'shareSecurity="private"' > "$TEST_ROOT/config/Movies.cfg"
printf '%s\n' 'shareExport="eh"' 'shareSecurity="secure"' > "$TEST_ROOT/config/Music.cfg"
printf '%s\n' 'shareExport="-"' 'shareSecurity="private"' > "$TEST_ROOT/config/appdata.cfg"
printf '%s\n' 'AUTO_ENABLE="no"' 'EXCLUSIONS="appdata"' > "$TEST_ROOT/plugin/settings.cfg"

export SMB_BULK_SHARE_CONFIG_DIR="$TEST_ROOT/config"
export SMB_BULK_SHARE_USER_DIR="$TEST_ROOT/user"
export SMB_BULK_SHARE_SEC_INI="$TEST_ROOT/missing-sec.ini"
export SMB_BULK_SHARE_SHARES_INI="$TEST_ROOT/missing-shares.ini"
export SMB_BULK_SHARE_SETTINGS="$TEST_ROOT/plugin/settings.cfg"
export SMB_BULK_SHARE_BACKUPS="$TEST_ROOT/backups"
export SMB_BULK_SHARE_VAR_INI="$TEST_ROOT/missing-var.ini"
export SMB_BULK_SHARE_SOCKET="$TEST_ROOT/missing.sock"
export SMB_BULK_SHARE_TEST_MODE=1

SCRIPT="$PROJECT_ROOT/source/usr/local/emhttp/plugins/smb-bulk-share/scripts/smb-bulk-share.php"

php "$SCRIPT" --enable >/dev/null
grep -q 'shareExport="e"' "$TEST_ROOT/config/Movies.cfg"
grep -q 'shareSecurity="private"' "$TEST_ROOT/config/Movies.cfg"
grep -q 'shareExport="eh"' "$TEST_ROOT/config/Music.cfg"
grep -q 'shareExport="-"' "$TEST_ROOT/config/appdata.cfg"

php "$SCRIPT" --disable >/dev/null
grep -q 'shareExport="-"' "$TEST_ROOT/config/Movies.cfg"
grep -q 'shareExport="-"' "$TEST_ROOT/config/Music.cfg"

php "$SCRIPT" --restore >/dev/null
grep -q 'shareExport="e"' "$TEST_ROOT/config/Movies.cfg"
grep -q 'shareExport="eh"' "$TEST_ROOT/config/Music.cfg"

mkdir -p "$TEST_ROOT/user/Books"
printf '%s\n' 'shareExport="-"' 'shareSecurity="secure"' > "$TEST_ROOT/config/Books.cfg"
printf '%s\n' 'AUTO_ENABLE="yes"' 'EXCLUSIONS="appdata"' > "$TEST_ROOT/plugin/settings.cfg"
php "$SCRIPT" --auto >/dev/null
grep -q 'shareExport="e"' "$TEST_ROOT/config/Books.cfg"
grep -q 'shareSecurity="secure"' "$TEST_ROOT/config/Books.cfg"

php "$SCRIPT" --status | php -r '$d=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); if ($d["counts"]["total"] !== 4) exit(1);'

echo "All SMB Bulk Share Control tests passed."
