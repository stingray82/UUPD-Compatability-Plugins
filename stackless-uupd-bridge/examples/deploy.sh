#!/usr/bin/env bash
set -euo pipefail
script_dir="$(cd -- "$(dirname -- "$0")" && pwd -P)"
cd "$script_dir"
[[ -f deploy.cfg ]] || { echo 'Missing deploy.cfg' >&2; exit 1; }
# Simple KEY=VALUE parser; no eval or shell execution from the config.
while IFS= read -r line || [[ -n "$line" ]]; do
  line="${line%$'\r'}"
  [[ "$line" =~ ^[[:space:]]*($|#|\;) ]] && continue
  [[ "$line" == *=* ]] || continue
  key="${line%%=*}"; value="${line#*=}"
  key="$(printf '%s' "$key" | xargs)"
  [[ "$key" =~ ^[A-Z][A-Z0-9_]*$ ]] || continue
  case "$key" in
    PLUGIN_NAME|PLUGIN_SLUG|ZIP_NAME|PLUGIN_TAGS|CHANGELOG_FILE|STATIC_FILE|DEPLOY_TARGET|GITHUB_REPO|TOKEN_FILE|DEST_DIR|HEADER_SCRIPT|GENERATOR_SCRIPT|WOO_STORE_URL|WOO_SOFTWARE_SLUG)
      value="${value#\"}"; value="${value%\"}"
      printf -v "$key" '%s' "$value" ;;
  esac
done < deploy.cfg
: "${PLUGIN_SLUG:?Set PLUGIN_SLUG}"
DEPLOY_TARGET="${DEPLOY_TARGET:-woo}"
ZIP_NAME="${ZIP_NAME:-${PLUGIN_SLUG}.zip}"
CHANGELOG_FILE="${CHANGELOG_FILE:-changelog.txt}"
STATIC_FILE="${STATIC_FILE:-static.txt}"
PLUGIN_NAME="${PLUGIN_NAME:-$PLUGIN_SLUG}"
plugin_file="$script_dir/$PLUGIN_SLUG/$PLUGIN_SLUG.php"
[[ -f "$plugin_file" ]] || { echo "Missing $plugin_file" >&2; exit 1; }
[[ -f "$CHANGELOG_FILE" ]] || { echo "Missing $CHANGELOG_FILE" >&2; exit 1; }
case "$DEPLOY_TARGET" in github|local|woo|github+woo|local+woo) ;; *) echo 'Invalid DEPLOY_TARGET' >&2; exit 1;; esac
if [[ -n "${HEADER_SCRIPT:-}" ]]; then
  [[ -f "$HEADER_SCRIPT" ]] || { echo "Missing header script $HEADER_SCRIPT" >&2; exit 1; }
  if [[ -t 0 ]]; then php "$HEADER_SCRIPT" "$plugin_file"; else echo '[INFO] Non-interactive: skipping header prompts. Edit plugin headers before deploying.'; fi
fi
version="$(sed -nE 's/^[[:space:]]*\*?[[:space:]]*Version:[[:space:]]*([^[:space:]*]+).*/\1/p' "$plugin_file" | head -1)"
[[ -n "$version" ]] || { echo 'Plugin header Version: is required' >&2; exit 1; }
requires="$(sed -nE 's/^[[:space:]]*\*?[[:space:]]*Requires at least:[[:space:]]*(.*)/\1/p' "$plugin_file" | head -1)"
tested="$(sed -nE 's/^[[:space:]]*\*?[[:space:]]*Tested up to:[[:space:]]*(.*)/\1/p' "$plugin_file" | head -1)"
requires_php="$(sed -nE 's/^[[:space:]]*\*?[[:space:]]*Requires PHP:[[:space:]]*(.*)/\1/p' "$plugin_file" | head -1)"
if [[ -f "$STATIC_FILE" ]]; then
  { printf '=== %s ===\nTags: %s\nRequires at least: %s\nTested up to: %s\nStable tag: %s\nRequires PHP: %s\n\n' "$PLUGIN_NAME" "${PLUGIN_TAGS:-}" "$requires" "$tested" "$version" "$requires_php"
    cat "$STATIC_FILE"; printf '\n== Changelog ==\n'; cat "$CHANGELOG_FILE"; } > "$script_dir/$PLUGIN_SLUG/readme.txt"
fi
# Index metadata is needed for the GitHub/static updater workflow, not Woo bridge.
if [[ "$DEPLOY_TARGET" == *github* && -n "${GENERATOR_SCRIPT:-}" ]]; then
  : "${GITHUB_REPO:?Set GITHUB_REPO}"
  owner="${GITHUB_REPO%%/*}"; repo="${GITHUB_REPO#*/}"
  mkdir -p "$script_dir/uupd"
  php "$GENERATOR_SCRIPT" "$plugin_file" "$CHANGELOG_FILE" "$script_dir/uupd" "$owner" "https://raw.githubusercontent.com/$GITHUB_REPO/main/uupd" "$PLUGIN_SLUG" "$repo" "$STATIC_FILE" "$ZIP_NAME"
fi
zip_path="$script_dir/$ZIP_NAME"
rm -f "$zip_path"
if command -v zip >/dev/null; then zip -qr "$zip_path" "$PLUGIN_SLUG"; elif command -v 7z >/dev/null; then 7z a -tzip "$zip_path" "$PLUGIN_SLUG" >/dev/null; else echo 'Need zip or 7z' >&2; exit 1; fi
[[ -s "$zip_path" ]] || { echo 'ZIP packaging failed' >&2; exit 1; }
case "$DEPLOY_TARGET" in
  local|local+woo)
    DEST_DIR="${DEST_DIR:-$script_dir/dist}"; mkdir -p "$DEST_DIR"; cp "$zip_path" "$DEST_DIR/"; echo "[OK] Local: $DEST_DIR" ;;
esac
if [[ "$DEPLOY_TARGET" == *github* ]]; then
  : "${GITHUB_REPO:?Set GITHUB_REPO}"
  if [[ -z "${GITHUB_TOKEN:-}" && -f "${TOKEN_FILE:-./github_token.txt}" ]]; then GITHUB_TOKEN="$(tr -d '\r\n' < "${TOKEN_FILE:-./github_token.txt}")"; fi
  : "${GITHUB_TOKEN:?Set GITHUB_TOKEN or TOKEN_FILE for GitHub deployment}"
  echo '[INFO] Creating/replacing GitHub release...'
  tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
  python3 - "$version" "$CHANGELOG_FILE" "$tmp/body.json" <<'PY'
import json,sys
version,changelog,out=sys.argv[1:]
with open(changelog,encoding='utf8') as f: body=f.read()
with open(out,'w',encoding='utf8') as f: json.dump({'tag_name':'v'+version,'name':version,'body':body,'draft':False,'prerelease':False},f)
PY
  api="https://api.github.com/repos/$GITHUB_REPO/releases"
  code="$(curl -sS -o "$tmp/existing.json" -w '%{http_code}' -H "Authorization: Bearer $GITHUB_TOKEN" -H 'Accept: application/vnd.github+json' "$api/tags/v$version")"
  if [[ "$code" == 200 ]]; then
    id="$(python3 -c 'import json,sys;print(json.load(open(sys.argv[1]))["id"])' "$tmp/existing.json")"
    curl -fsS -X PATCH -H "Authorization: Bearer $GITHUB_TOKEN" -H 'Accept: application/vnd.github+json' -H 'Content-Type: application/json' --data-binary @"$tmp/body.json" "$api/$id" > "$tmp/release.json"
  else
    curl -fsS -X POST -H "Authorization: Bearer $GITHUB_TOKEN" -H 'Accept: application/vnd.github+json' -H 'Content-Type: application/json' --data-binary @"$tmp/body.json" "$api" > "$tmp/release.json"
  fi
  id="$(python3 -c 'import json,sys;print(json.load(open(sys.argv[1]))["id"])' "$tmp/release.json")"
  # Delete matching old asset if release already existed (GitHub rejects duplicate names).
  curl -fsS -H "Authorization: Bearer $GITHUB_TOKEN" -H 'Accept: application/vnd.github+json' "$api/$id/assets" > "$tmp/assets.json"
  python3 - "$tmp/assets.json" "$(basename "$zip_path")" > "$tmp/delete-ids" <<'PY'
import json,sys
for asset in json.load(open(sys.argv[1])):
 if asset.get('name')==sys.argv[2]:print(asset['id'])
PY
  while IFS= read -r oldid; do curl -fsS -X DELETE -H "Authorization: Bearer $GITHUB_TOKEN" "https://api.github.com/repos/$GITHUB_REPO/releases/assets/$oldid" >/dev/null; done < "$tmp/delete-ids"
  curl -fsS -X POST -H "Authorization: Bearer $GITHUB_TOKEN" -H 'Content-Type: application/zip' --data-binary @"$zip_path" "https://uploads.github.com/repos/$GITHUB_REPO/releases/$id/assets?name=$(basename "$zip_path")" > "$tmp/asset.json"
  echo '[OK] GitHub release published.'
fi
if [[ "$DEPLOY_TARGET" == *woo* ]]; then
  : "${WOO_STORE_URL:?Set WOO_STORE_URL}"
  WOO_SOFTWARE_SLUG="${WOO_SOFTWARE_SLUG:-$PLUGIN_SLUG}"
  : "${WOO_USER:?Export WOO_USER in environment}"
  : "${WOO_APP_PASSWORD:?Export WOO_APP_PASSWORD in environment}"
  echo "[INFO] Publishing Stackless release $WOO_SOFTWARE_SLUG $version..."
  # --form-string is important: changelog contents are not interpreted as curl form filename syntax.
  curl --fail-with-body -sS -u "$WOO_USER:$WOO_APP_PASSWORD" \
    -F "version=$version" -F "package=@$zip_path;type=application/zip" \
    -F "requires_wp=$requires" -F "tested_wp=$tested" -F "requires_php=$requires_php" \
    --form-string "changelog=$(cat "$CHANGELOG_FILE")" \
    "${WOO_STORE_URL%/}/wp-json/uupd/v1/products/$WOO_SOFTWARE_SLUG/release"
  echo; echo '[OK] Stackless release published.'
fi
echo "[OK] Deployment complete: $DEPLOY_TARGET ($version)"
