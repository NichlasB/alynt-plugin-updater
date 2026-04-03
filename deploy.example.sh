#!/usr/bin/env bash
# Copy this file to deploy.sh and customize deploy.sh locally.
# Keep deploy.sh gitignored; commit only deploy.example.sh.
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
  --exclude '.github' \
  --exclude '.git' \
  --exclude 'docs' \
  --exclude 'node_modules' \
  --exclude 'vendor' \
  --exclude 'coverage' \
  --exclude 'assets/src' \
  --exclude 'tests' \
  --exclude 'scripts/' \
  --exclude 'build/' \
  --exclude '.DS_Store' \
  --exclude '.env' \
  --exclude '.env.local' \
  --exclude 'composer.phar' \
  --exclude 'composer.json' \
  --exclude 'composer.lock' \
  --exclude 'package.json' \
  --exclude 'package-lock.json' \
  --exclude '.phpcs.xml' \
  --exclude '.phpcs.xml.dist' \
  --exclude '.gitignore' \
  --exclude '.gitattributes' \
  --exclude '.editorconfig' \
  --exclude 'phpunit.xml' \
  --exclude 'phpunit.xml.dist' \
  --exclude 'deploy.sh' \
  --exclude 'deploy.example.sh' \
  --exclude 'session-context.tmp.md' \
  --exclude 'README.md' \
  --exclude 'CHANGELOG.md' \
  --exclude '*.map' \
  ./ "${ALYNT_PU_SSH_ALIAS}:${ALYNT_PU_REMOTE_PATH}"
