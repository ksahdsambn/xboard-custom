#!/usr/bin/env bash
set -euo pipefail

CUSTOM_ROOT="${CUSTOM_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
OFFICIAL_ROOT="${OFFICIAL_ROOT:-}"
WEB_SERVICE="${WEB_SERVICE:-}"
HORIZON_SERVICE="${HORIZON_SERVICE-horizon}"
THEME_NAME="${THEME_NAME:-XboardCustom}"
THEME_TARGET_ROOT="${THEME_TARGET_ROOT:-storage/theme}"
DRY_RUN="${DRY_RUN:-0}"
SKIP_COMPOSE="${SKIP_COMPOSE:-0}"
SKIP_POST_DEPLOY="${SKIP_POST_DEPLOY:-0}"
COMPOSE_BIN="${COMPOSE_BIN:-docker compose}"
ALLOWED_PLUGINS=(StripePayment BepusdtPayment WalletCenter MobileApp)

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
  if [[ "${DRY_RUN}" != "1" && "${SKIP_COMPOSE}" != "1" ]]; then
    echo "rsync is required for overlay deploy so extra files are deleted"
    exit 1
  fi
  echo "rsync not found; test/dry-run overlay will copy with cp -a"
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
  if command -v rsync >/dev/null 2>&1; then
    rsync "${rsync_args[@]}" "${source_dir}/" "${target_dir}/"
  else
    if [[ "${DRY_RUN}" == "1" ]]; then
      echo "Dry run without rsync: would copy ${source_dir} -> ${target_dir}"
      return 0
    fi
    mkdir -p "${target_dir}"
    cp -a "${source_dir}/." "${target_dir}/"
  fi
}

assert_overlay_allowlist() {
  local plugin
  for plugin in "${ALLOWED_PLUGINS[@]}"; do
    if [[ ! -d "${CUSTOM_ROOT}/plugins/${plugin}" ]]; then
      echo "Required overlay plugin missing in custom repo: ${plugin}"
      exit 1
    fi
  done
}

compose_services() {
  (
    cd "${OFFICIAL_ROOT}"
    "${COMPOSE_CMD[@]}" config --services 2>/dev/null
  )
}

has_compose_service() {
  local service_name="$1"
  compose_services | grep -qx "${service_name}"
}

resolve_web_service() {
  if [[ -n "${WEB_SERVICE}" ]] && has_compose_service "${WEB_SERVICE}"; then
    return 0
  fi

  local candidate
  for candidate in xboard web app laravel php; do
    if has_compose_service "${candidate}"; then
      WEB_SERVICE="${candidate}"
      echo "Using compose web service: ${WEB_SERVICE}"
      return 0
    fi
  done

  WEB_SERVICE="$(compose_services | head -n 1 || true)"
  if [[ -n "${WEB_SERVICE}" ]]; then
    echo "Using compose web service: ${WEB_SERVICE}"
  fi
}

restart_service_if_exists() {
  local service_name="$1"
  if [[ -z "${service_name}" ]]; then
    return 0
  fi

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

cleanup_legacy_theme_path() {
  local legacy_theme_path="${OFFICIAL_ROOT}/theme/${THEME_NAME}"

  if [[ ! -d "${legacy_theme_path}" ]]; then
    return 0
  fi

  if [[ "${DRY_RUN}" == "1" ]]; then
    echo "Dry run: remove legacy theme path ${legacy_theme_path}"
    return 0
  fi

  echo "Remove legacy theme path to avoid theme precedence conflict: ${legacy_theme_path}"
  rm -rf "${legacy_theme_path}"
}

refresh_theme_if_possible() {
  if [[ "${DRY_RUN}" == "1" ]]; then
    echo "Dry run enabled, skip theme refresh"
    return 0
  fi

  if [[ -z "${WEB_SERVICE}" ]] || ! has_compose_service "${WEB_SERVICE}"; then
    echo "Skip theme refresh because web service does not exist: ${WEB_SERVICE:-unset}"
    return 0
  fi

  echo "Refresh current theme public assets"
  (
    cd "${OFFICIAL_ROOT}"
    "${COMPOSE_CMD[@]}" exec -T "${WEB_SERVICE}" php artisan tinker --execute="app(\App\Services\ThemeService::class)->refreshCurrentTheme();" || true
    echo "Copy ${THEME_NAME} from storage/theme into public/theme"
    "${COMPOSE_CMD[@]}" exec -T "${WEB_SERVICE}" sh -c "rm -rf public/theme/${THEME_NAME} && mkdir -p public/theme && cp -a storage/theme/${THEME_NAME} public/theme/${THEME_NAME}"
  )
}

assert_overlay_allowlist
resolve_web_service

for plugin in "${ALLOWED_PLUGINS[@]}"; do
  sync_dir "${CUSTOM_ROOT}/plugins/${plugin}" "${OFFICIAL_ROOT}/plugins/${plugin}"
done
sync_dir "${CUSTOM_ROOT}/theme/${THEME_NAME}" "${OFFICIAL_ROOT}/${THEME_TARGET_ROOT}/${THEME_NAME}"
cleanup_legacy_theme_path

if [[ -d "${OFFICIAL_ROOT}/.git" ]]; then
  core_diff="$(git -C "${OFFICIAL_ROOT}" diff --name-only -- app routes bootstrap composer.lock composer.json || true)"
  if [[ -n "${core_diff}" ]]; then
    echo "Overlay must not modify official core files:"
    printf '%s\n' "${core_diff}"
    exit 1
  fi
fi

if [[ "${DRY_RUN}" == "1" ]]; then
  echo "Dry run completed; MobileApp would be installed, migrated, enabled and health-checked"
  exit 0
fi

if [[ "${SKIP_COMPOSE}" != "1" ]]; then
  restart_service_if_exists "${WEB_SERVICE}"
  restart_service_if_exists "${HORIZON_SERVICE}"
  refresh_theme_if_possible
fi

if [[ "${SKIP_POST_DEPLOY}" != "1" ]]; then
  echo "Run MobileApp post-deploy install/upgrade/enable/health"
  if [[ "${SKIP_COMPOSE}" == "1" ]]; then
    php "${OFFICIAL_ROOT}/plugins/MobileApp/bin/post-deploy.php"
  else
    if [[ -z "${WEB_SERVICE}" ]] || ! has_compose_service "${WEB_SERVICE}"; then
      echo "Web service missing; cannot run MobileApp health check"
      exit 1
    fi
    (
      cd "${OFFICIAL_ROOT}"
      "${COMPOSE_CMD[@]}" exec -T "${WEB_SERVICE}" php plugins/MobileApp/bin/post-deploy.php
    )
  fi
fi

cat <<EOF
Overlay deploy completed.

Next checks:
1. Confirm stripe_payment, bepusdt_payment, wallet_center and mobile_app are installed and enabled.
2. Confirm php artisan mobile-app:health returns ok=true for v0 and v1 routes.
3. Open theme management and confirm ${THEME_NAME} is still the active theme.
EOF
