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

CANDIDATE_IMAGE="${CANDIDATE_IMAGE:-}"
XBOARD_MASTER_SHA="${XBOARD_MASTER_SHA:-}"
TARGET_ENV="${TARGET_ENV:-prerelease}"
SKIP_PULL="${SKIP_PULL:-0}"
SKIP_BACKUP="${SKIP_BACKUP:-0}"
IDENTITY_DIR="${OFFICIAL_ROOT}/storage/app/mobile-app"
IDENTITY_FILE="${IDENTITY_DIR}/image-identity.json"

if [[ -z "${CANDIDATE_IMAGE}" ]]; then
  echo "CANDIDATE_IMAGE is required, for example ghcr.io/cedar2025/xboard@sha256:<64 hex>"
  echo "Pulling latest is forbidden as a production update method"
  exit 1
fi
if [[ "${CANDIDATE_IMAGE}" == *latest* || "${CANDIDATE_IMAGE}" == *:main || "${CANDIDATE_IMAGE}" == *:master ]]; then
  echo "Dynamic image tags latest/main/master are forbidden"
  exit 1
fi
if [[ ! "${CANDIDATE_IMAGE}" =~ @sha256:[a-fA-F0-9]{64}$ ]]; then
  echo "CANDIDATE_IMAGE must use an immutable sha256 digest"
  exit 1
fi
if [[ -z "${XBOARD_MASTER_SHA}" || "${#XBOARD_MASTER_SHA}" -lt 40 ]]; then
  echo "XBOARD_MASTER_SHA is required as the independent master source identity"
  exit 1
fi
if [[ "${TARGET_ENV}" != "prerelease" && "${TARGET_ENV}" != "production" ]]; then
  echo "TARGET_ENV must be prerelease or production"
  exit 1
fi

mkdir -p "${IDENTITY_DIR}"
previous_digest=""
previous_env=""
if [[ -f "${IDENTITY_FILE}" ]]; then
  previous_digest="$(tr -d '\r' < "${IDENTITY_FILE}" | sed -n 's/.*"imageDigest"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -n 1 || true)"
  previous_env="$(tr -d '\r' < "${IDENTITY_FILE}" | sed -n 's/.*"targetEnv"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -n 1 || true)"
fi
if [[ "${TARGET_ENV}" == "production" ]]; then
  if [[ "${previous_env}" != "prerelease" || -z "${previous_digest}" || "${CANDIDATE_IMAGE}" != *"${previous_digest}"* ]]; then
    echo "Production must reuse the digest that already passed prerelease"
    exit 1
  fi
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

if [[ "${SKIP_BACKUP}" != "1" ]]; then
  backup_dir="${IDENTITY_DIR}/backups"
  mkdir -p "${backup_dir}"
  backup_stamp="$(date +%Y%m%d%H%M%S)"
  echo "Record database backup marker ${backup_dir}/db-${backup_stamp}.marker"
  echo "previousDigest=${previous_digest}" > "${backup_dir}/db-${backup_stamp}.marker"
  (
    cd "${OFFICIAL_ROOT}"
    "${COMPOSE_CMD[@]}" exec -T "${WEB_SERVICE}" php artisan backup:database || echo "backup:database unavailable; marker retained for rollback inventory"
  )
fi

IMAGE_PIN_FILE="${IDENTITY_DIR}/compose.image-pin.yaml"
cat > "${IMAGE_PIN_FILE}" <<EOF
services:
  ${WEB_SERVICE}:
    image: ${CANDIDATE_IMAGE}
EOF
COMPOSE_BIN="docker compose -f ${OFFICIAL_ROOT}/compose.yaml -f ${OVERRIDE_COMPOSE_FILE} -f ${IMAGE_PIN_FILE}"
read -r -a COMPOSE_CMD <<< "${COMPOSE_BIN}"

if [[ "${SKIP_PULL}" == "1" ]]; then
  echo "SKIP_PULL=1; record candidate digest without docker pull"
else
  echo "Pull immutable candidate image ${CANDIDATE_IMAGE}"
  docker pull "${CANDIDATE_IMAGE}"
  docker tag "${CANDIDATE_IMAGE}" "ghcr.io/cedar2025/xboard:new"
fi
echo "Skip compose pull of latest; image identity is pinned by ${IMAGE_PIN_FILE}"

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

compose_sha="$(git -C "${OFFICIAL_ROOT}" rev-parse HEAD)"
cat > "${IDENTITY_FILE}" <<EOF
{
  "xboardMaster": "${XBOARD_MASTER_SHA}",
  "xboardCompose": "${compose_sha}",
  "imageDigest": "${CANDIDATE_IMAGE}",
  "previousImageDigest": "${previous_digest}",
  "targetEnv": "${TARGET_ENV}",
  "formalAcceptanceClaimed": false
}
EOF
echo "Recorded image identity to ${IDENTITY_FILE}"
