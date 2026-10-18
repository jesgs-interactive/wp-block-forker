#!/usr/bin/env bash
set -euo pipefail

TAG_NAME="$(git describe --tags --abbrev=0 2>/dev/null || echo '0.1.0')"
COMMIT_HASH="$(git rev-parse --short HEAD 2>/dev/null || echo 'nogit')"
# Strip the tag's leading "v" (release tags are vX.Y.Z; the plugin header and
# version_compare() want a bare X.Y.Z). The "-dev." marker matters: PHP's
# version_compare() reads "dev" as a pre-release, so a local build always sorts
# below the real release. A bare "-<hash>" does not — a hash starting with a
# digit sorts as a newer patch version, one starting with a letter as older.
VERSION="${TAG_NAME#v}-dev.${COMMIT_HASH}"

sed -i "s|{{VERSION}}|${VERSION}|g" wp-block-forker.php

grep -Fq "Version:           ${VERSION}" wp-block-forker.php || {
	echo "Version stamp failed to apply. Aborting." >&2
	exit 1
}

echo "Stamped version ${VERSION}"
