#!/usr/bin/env bash
set -euo pipefail

if [ -z "${ALYNT_PU_SSH_ALIAS:-}" ]; then
  echo "ALYNT_PU_SSH_ALIAS is not set."
  exit 1
fi

if [ -z "${ALYNT_PU_REMOTE_PATH:-}" ]; then
  echo "ALYNT_PU_REMOTE_PATH is not set."
  exit 1
fi

rsync -avz --delete \
  --exclude '.git' \
  --exclude 'node_modules' \
  --exclude 'vendor' \
  --exclude 'assets/src' \
  --exclude 'tests' \
  ./ "${ALYNT_PU_SSH_ALIAS}:${ALYNT_PU_REMOTE_PATH}"
