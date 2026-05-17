#!/usr/bin/env bash
set -euo pipefail

CUSTOM_ROOT="${CUSTOM_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
OFFICIAL_ROOT="${OFFICIAL_ROOT:-}"
OFFICIAL_REMOTE_NAME="${OFFICIAL_REMOTE_NAME:-origin}"
OFFICIAL_BRANCH="${OFFICIAL_BRANCH:-compose}"
OVERRIDE_COMPOSE_FILE="${OVERRIDE_COMPOSE_FILE:-${CUSTOM_ROOT}/deploy/compose.1panel.override.yaml}"
UPDATE_OVERLAY_SCRIPT="${UPDATE_OVERLAY_SCRIPT:-${CUSTOM_ROOT}/scripts/update-overlay-from-git.sh}"
WEB_SERVICE="${WEB_SERVICE:-}"
HORIZON_SERVICE="${HORIZON_SERVICE:-}"
OVERLAY_FORCE_DEPLOY="${OVERLAY_FORCE_DEPLOY:-1}"
COMPOSE_BIN="${COMPOSE_BIN:-}"
REMOVE_ORPHANS="${REMOVE_ORPHANS:-0}"

if [[ -z "${OFFICIAL_ROOT}" ]]; then
  echo "OFFICIAL_ROOT is required, for example: OFFICIAL_ROOT=/opt/1panel/www/sites/xboard/index"
  exit 1
fi

if ! command -v git >/dev/null 2>&1; then
  echo "git is required but not installed"
  exit 1
fi

if ! command -v docker >/dev/null 2>&1; then
  echo "docker is required but not installed"
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

if [[ ! -d "${OFFICIAL_ROOT}/.git" ]]; then
  echo "Official Xboard directory is not a git repository: ${OFFICIAL_ROOT}"
  exit 1
fi

if [[ ! -f "${OFFICIAL_ROOT}/compose.yaml" ]]; then
  echo "compose.yaml does not exist in official root: ${OFFICIAL_ROOT}"
  exit 1
fi

if [[ ! -f "${OVERRIDE_COMPOSE_FILE}" ]]; then
  echo "Compose override file does not exist: ${OVERRIDE_COMPOSE_FILE}"
  exit 1
fi

if [[ ! -f "${UPDATE_OVERLAY_SCRIPT}" ]]; then
  echo "Overlay update script does not exist: ${UPDATE_OVERLAY_SCRIPT}"
  exit 1
fi

cd "${OFFICIAL_ROOT}"

COMPOSE_BIN="${COMPOSE_BIN:-docker compose -f ${OFFICIAL_ROOT}/compose.yaml -f ${OVERRIDE_COMPOSE_FILE}}"
read -r -a COMPOSE_CMD <<< "${COMPOSE_BIN}"

# Keep the tracked compose.yaml clean so official fast-forward updates never block on local network tweaks.
if ! git diff --quiet -- compose.yaml || ! git diff --cached --quiet -- compose.yaml; then
  echo "compose.yaml has local changes in ${OFFICIAL_ROOT}"
  echo "Move local Docker customization into ${OVERRIDE_COMPOSE_FILE} before running this task again"
  exit 1
fi

echo "Pull ${OFFICIAL_REMOTE_NAME}/${OFFICIAL_BRANCH}"
git pull --ff-only "${OFFICIAL_REMOTE_NAME}" "${OFFICIAL_BRANCH}"

compose_services="$("${COMPOSE_CMD[@]}" config --services)"

if [[ -z "${WEB_SERVICE}" ]]; then
  if grep -qx "xboard" <<< "${compose_services}"; then
    WEB_SERVICE="xboard"
  elif grep -qx "web" <<< "${compose_services}"; then
    WEB_SERVICE="web"
  else
    echo "Cannot find a web service in compose services:"
    printf '%s\n' "${compose_services}"
    exit 1
  fi
fi

if [[ -z "${HORIZON_SERVICE}" && "${WEB_SERVICE}" == "web" ]] && grep -qx "horizon" <<< "${compose_services}"; then
  HORIZON_SERVICE="horizon"
fi

echo "Pull latest service images with compose override"
"${COMPOSE_CMD[@]}" pull

up_args=(up -d)
if [[ "${REMOVE_ORPHANS}" == "1" ]]; then
  up_args+=(--remove-orphans)
fi

if [[ "${WEB_SERVICE}" == "xboard" ]]; then
  echo "Start updated xboard service"
  "${COMPOSE_CMD[@]}" "${up_args[@]}"
else
  echo "Run xboard:update"
  "${COMPOSE_CMD[@]}" run --rm -T "${WEB_SERVICE}" php artisan xboard:update

  echo "Start updated services"
  "${COMPOSE_CMD[@]}" "${up_args[@]}"
fi

echo "Redeploy overlay with compose override"
CUSTOM_ROOT="${CUSTOM_ROOT}" \
OFFICIAL_ROOT="${OFFICIAL_ROOT}" \
COMPOSE_BIN="${COMPOSE_BIN}" \
WEB_SERVICE="${WEB_SERVICE}" \
HORIZON_SERVICE="${HORIZON_SERVICE}" \
FORCE_DEPLOY="${OVERLAY_FORCE_DEPLOY}" \
bash "${UPDATE_OVERLAY_SCRIPT}"
