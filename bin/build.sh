#!/usr/bin/env bash
set -euo pipefail

TAG_NAME="$(git describe --tags --abbrev=0 2>/dev/null || echo '0.1.0')"
COMMIT_HASH="$(git rev-parse --short HEAD 2>/dev/null || echo 'nogit')"
VERSION="${TAG_NAME}-${COMMIT_HASH}"

sed -i "s|{{VERSION}}|${VERSION}|g" wp-block-forker.php

grep -Fq "Version:           ${VERSION}" wp-block-forker.php || {
	echo "Version stamp failed to apply. Aborting." >&2
	exit 1
}

echo "Stamped version ${VERSION}"
