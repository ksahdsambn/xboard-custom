#!/usr/bin/env bash
set -euo pipefail

CUSTOM_ROOT="${CUSTOM_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
OFFICIAL_ROOT="${OFFICIAL_ROOT:-}"
WEB_SERVICE="${WEB_SERVICE:-web}"
HORIZON_SERVICE="${HORIZON_SERVICE:-horizon}"
THEME_NAME="${THEME_NAME:-XboardCustom}"
DRY_RUN="${DRY_RUN:-0}"
COMPOSE_BIN="${COMPOSE_BIN:-docker compose}"

if [[ -z "${OFFICIAL_ROOT}" ]]; then
  echo "OFFICIAL_ROOT is required, for example: OFFICIAL_ROOT=/opt/xboard-official"
  exit 1
fi

if [[ ! -d "${CUSTOM_ROOT}" ]]; then
  echo "Custom repo directory does not exist: ${CUSTOM_ROOT}"
  exit 1
fi

if [[ ! -d "${OFFICIAL_ROOT}" ]]; then
  echo "Official Xboard directory does not exist: ${OFFICIAL_ROOT}"
  exit 1
fi

if ! command -v rsync >/dev/null 2>&1; then
  echo "rsync is required but not installed"
  exit 1
fi

read -r -a COMPOSE_CMD <<< "${COMPOSE_BIN}"

sync_dir() {
  local source_dir="$1"
  local target_dir="$2"

  if [[ ! -d "${source_dir}" ]]; then
    echo "Skip missing source directory: ${source_dir}"
    return 0
  fi

  mkdir -p "${target_dir}"

  local rsync_args=(-a --delete)
  if [[ "${DRY_RUN}" == "1" ]]; then
    rsync_args+=(--dry-run -v)
  fi

  echo "Sync ${source_dir} -> ${target_dir}"
  rsync "${rsync_args[@]}" "${source_dir}/" "${target_dir}/"
}

has_compose_service() {
  local service_name="$1"
  (
    cd "${OFFICIAL_ROOT}"
    "${COMPOSE_CMD[@]}" config --services 2>/dev/null | grep -qx "${service_name}"
  )
}

restart_service_if_exists() {
  local service_name="$1"
  if has_compose_service "${service_name}"; then
    echo "Restart compose service: ${service_name}"
    (
      cd "${OFFICIAL_ROOT}"
      "${COMPOSE_CMD[@]}" restart "${service_name}"
    )
  else
    echo "Skip missing compose service: ${service_name}"
  fi
}

refresh_theme_if_possible() {
  if [[ "${DRY_RUN}" == "1" ]]; then
    echo "Dry run enabled, skip theme refresh"
    return 0
  fi

  if ! has_compose_service "${WEB_SERVICE}"; then
    echo "Skip theme refresh because web service does not exist: ${WEB_SERVICE}"
    return 0
  fi

  echo "Refresh current theme public assets"
  (
    cd "${OFFICIAL_ROOT}"
    "${COMPOSE_CMD[@]}" exec -T "${WEB_SERVICE}" php artisan tinker --execute="app(\App\Services\ThemeService::class)->refreshCurrentTheme();"
  )
}

sync_dir "${CUSTOM_ROOT}/plugins/StripePayment" "${OFFICIAL_ROOT}/plugins/StripePayment"
sync_dir "${CUSTOM_ROOT}/plugins/BepusdtPayment" "${OFFICIAL_ROOT}/plugins/BepusdtPayment"
sync_dir "${CUSTOM_ROOT}/plugins/WalletCenter" "${OFFICIAL_ROOT}/plugins/WalletCenter"
sync_dir "${CUSTOM_ROOT}/theme/${THEME_NAME}" "${OFFICIAL_ROOT}/theme/${THEME_NAME}"

if [[ "${DRY_RUN}" == "1" ]]; then
  echo "Dry run completed"
  exit 0
fi

restart_service_if_exists "${WEB_SERVICE}"
restart_service_if_exists "${HORIZON_SERVICE}"
refresh_theme_if_possible

cat <<EOF
Overlay deploy completed.

Next checks:
1. Open admin plugin list and confirm stripe_payment, bepusdt_payment, wallet_center are installed and enabled.
2. If plugin config.json version changed, run plugin upgrade from the admin panel.
3. Open theme management and confirm ${THEME_NAME} is still the active theme.
EOF
