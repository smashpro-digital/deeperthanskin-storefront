#!/usr/bin/env bash
set -euo pipefail

export PATH="/usr/bin:/bin:$PATH"

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [ -f "$ROOT_DIR/.env.ftp" ]; then
  while IFS='=' read -r key value || [ -n "$key" ]; do
    key="${key//$'\r'/}"
    value="${value//$'\r'/}"
    key="${key#"${key%%[![:space:]]*}"}"
    key="${key%"${key##*[![:space:]]}"}"
    value="${value#"${value%%[![:space:]]*}"}"
    value="${value%"${value##*[![:space:]]}"}"
    case "$key" in
      ""|\#*) continue ;;
    esac
    case "$key" in
      FTP_HOST|FTP_USER|FTP_PASS|FTP_PORT|FTP_REMOTE_ROOT)
        if [[ "$value" == \"*\" && "$value" == *\" ]]; then
          value="${value:1:${#value}-2}"
        elif [[ "$value" == \'*\' && "$value" == *\' ]]; then
          value="${value:1:${#value}-2}"
        fi
        export "$key=$value"
        ;;
    esac
  done < "$ROOT_DIR/.env.ftp"
fi

: "${FTP_HOST:?FTP_HOST is required}"
: "${FTP_USER:?FTP_USER is required}"
: "${FTP_PASS:?FTP_PASS is required}"
: "${FTP_REMOTE_ROOT:=/public_html/smashpro.app}"
: "${FTP_PORT:=21}"

if ! command -v lftp >/dev/null 2>&1; then
  echo "lftp is required. Install it with Git Bash/MSYS2, WSL, or your package manager." >&2
  exit 127
fi

DELETE_FLAG=""
if [ "${1:-}" = "--delete" ]; then
  DELETE_FLAG="--delete"
elif [ "${1:-}" != "" ]; then
  echo "Unknown option: $1" >&2
  echo "Usage: $0 [--delete]" >&2
  exit 2
fi

LOCAL_DIR="$ROOT_DIR/api/v1/routes"
REMOTE_DIR="$FTP_REMOTE_ROOT/api/v1/routes"

lftp -u "$FTP_USER","$FTP_PASS" -p "$FTP_PORT" "$FTP_HOST" <<EOF
set ftp:ssl-allow true
set ssl:verify-certificate no
mirror -R -v $DELETE_FLAG \
  --exclude-glob .git* \
  --exclude-glob "*.log" \
  --exclude-glob ".env*" \
  --exclude-glob "*debug*.log" \
  --exclude config.php \
  --exclude square.config.php \
  "$LOCAL_DIR" "$REMOTE_DIR"
bye
EOF
