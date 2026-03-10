#!/usr/bin/env bash
set -euo pipefail

CUSTOM_ROOT="${CUSTOM_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
REMOTE_NAME="${REMOTE_NAME:-origin}"
CURRENT_BRANCH="$(git -C "${CUSTOM_ROOT}" branch --show-current 2>/dev/null || true)"
CUSTOM_BRANCH="${CUSTOM_BRANCH:-${CURRENT_BRANCH:-main}}"
DEPLOY_SCRIPT="${DEPLOY_SCRIPT:-${CUSTOM_ROOT}/scripts/deploy-overlay.sh}"
FORCE_DEPLOY="${FORCE_DEPLOY:-0}"

if ! command -v git >/dev/null 2>&1; then
  echo "git is required but not installed"
  exit 1
fi

if [[ ! -d "${CUSTOM_ROOT}/.git" ]]; then
  echo "Custom repo is not a git repository: ${CUSTOM_ROOT}"
  exit 1
fi

if [[ ! -f "${DEPLOY_SCRIPT}" ]]; then
  echo "Deploy script does not exist: ${DEPLOY_SCRIPT}"
  exit 1
fi

cd "${CUSTOM_ROOT}"

current_head="$(git rev-parse HEAD)"
echo "Fetch ${REMOTE_NAME}/${CUSTOM_BRANCH}"
git fetch --prune "${REMOTE_NAME}" "${CUSTOM_BRANCH}"

remote_head="$(git rev-parse FETCH_HEAD)"
if [[ "${current_head}" == "${remote_head}" ]]; then
  echo "Custom repo is already up to date"
  if [[ "${FORCE_DEPLOY}" != "1" ]]; then
    exit 0
  fi

  if [[ -z "${OFFICIAL_ROOT:-}" ]]; then
    echo "OFFICIAL_ROOT is required when FORCE_DEPLOY=1"
    exit 1
  fi

  echo "FORCE_DEPLOY=1, redeploy current overlay without git changes"
  CUSTOM_ROOT="${CUSTOM_ROOT}" OFFICIAL_ROOT="${OFFICIAL_ROOT}" bash "${DEPLOY_SCRIPT}"
  exit 0
fi

if ! git merge-base --is-ancestor "${current_head}" "${remote_head}"; then
  echo "Custom repo cannot fast-forward from ${current_head} to ${remote_head}"
  echo "Resolve local divergence manually before running this task again"
  exit 1
fi

runtime_changes="$(git diff --name-only "${current_head}" "${remote_head}" -- plugins theme)"
needs_deploy="0"
if [[ -n "${runtime_changes}" || "${FORCE_DEPLOY}" == "1" ]]; then
  needs_deploy="1"
fi

if [[ "${needs_deploy}" == "1" && -z "${OFFICIAL_ROOT:-}" ]]; then
  echo "OFFICIAL_ROOT is required when plugin/theme changes exist or FORCE_DEPLOY=1"
  exit 1
fi

echo "Fast-forward update ${current_head:0:7} -> ${remote_head:0:7}"
git merge --ff-only FETCH_HEAD

if [[ "${needs_deploy}" != "1" ]]; then
  echo "Only non-runtime files changed; skip overlay deploy and service restart"
  exit 0
fi

if [[ -n "${runtime_changes}" ]]; then
  echo "Runtime changes detected:"
  printf '%s\n' "${runtime_changes}"
elif [[ "${FORCE_DEPLOY}" == "1" ]]; then
  echo "FORCE_DEPLOY=1, run overlay deploy even though plugins/theme are unchanged"
fi

CUSTOM_ROOT="${CUSTOM_ROOT}" OFFICIAL_ROOT="${OFFICIAL_ROOT}" bash "${DEPLOY_SCRIPT}"
