#!/usr/bin/env bash
# Proves that the committed vendor tree is what composer.lock and Strauss produce:
#   1. composer.json and composer.lock agree;
#   2. the committed runtime packages (vendor/composer) are the locked ones: a --no-dev install has nothing to do;
#   3. vendor/vendor-prefixed is what the pinned Strauss makes of them: a run changes nothing.
# Needs network (the locked runtime packages and the pinned Strauss are fetched); the vendor tree it
# leaves behind is the committed one when the check passes.
set -euo pipefail
cd "$(dirname "$0")/.."
export COMPOSER_NO_INTERACTION=1

composer validate --strict --no-check-publish

dry="$(composer install --no-dev --dry-run 2>&1)"
if ! grep -q 'Nothing to install, update or remove' <<< "$dry"; then
	echo "$dry"
	echo "::error::vendor/ is not the tree composer.lock describes; run composer install --no-dev and commit vendor/composer"
	exit 1
fi

composer install --no-dev --no-scripts
composer run-script strauss

drift="$(git status --porcelain -- vendor/vendor-prefixed)"
if [ -n "$drift" ]; then
	echo "$drift" | head -n 50
	echo "::error::vendor/vendor-prefixed is not what Strauss produces from the locked packages; run composer run-script strauss and commit the result"
	exit 1
fi

echo "vendor ok: the lock matches composer.json, vendor/ matches the lock, the prefixed copy is current"
