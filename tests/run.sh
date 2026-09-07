#!/usr/bin/env bash
# Slate test runner (Phase 0). Runs the dependency-free smoke suite.
# Usage: bash tests/run.sh   (exit 0 = pass, non-zero = fail)
set -euo pipefail
cd "$(dirname "$0")/.."
echo "== Slate unit tests =="
php tests/unit/run.php
echo "== Slate integration tests =="
php tests/integration/run.php
echo "== Slate smoke tests =="
php tests/smoke.php
echo "== Slate page-render tests =="
php tests/render/run.php

# The second renderer. Skipped when node is absent — most shared hosts have no
# node, and the PHP suites must stay runnable there (ADR-0003).
if command -v node >/dev/null 2>&1; then
  # Syntax gate for every shipped script, not just the renderer. builder.js is
  # ~700 lines of admin JS that nothing parsed: the renderer tests exercise
  # public/assets/slate-content.js only, and php -l obviously does not read .js.
  # A syntax error there is a silently dead editor panel, found by a user.
  echo "== Slate JS syntax check =="
  find plugins -name '*.js' -not -path '*/node_modules/*' -print0 \
    | xargs -0 -r -n1 node --check
  echo "   all scripts parse"

  echo "== Slate JS renderer tests =="
  node tests/js/render.test.mjs
else
  echo "== Slate JS syntax check == (skipped: node not found)"
  echo "== Slate JS renderer tests == (skipped: node not found)"
fi
