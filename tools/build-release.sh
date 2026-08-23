#!/usr/bin/env bash
set -euo pipefail

readonly PLUGIN_SLUG='dma-internlink-mapper'
readonly SCRIPT_DIRECTORY="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly REPOSITORY_ROOT="$(git -C "$SCRIPT_DIRECTORY/.." rev-parse --show-toplevel)"
readonly MANIFEST="$REPOSITORY_ROOT/tools/release-manifest.txt"
REQUESTED_OUTPUT="${1:-build}"
if [[ "$REQUESTED_OUTPUT" = /* ]]; then
	readonly OUTPUT_ROOT="$REQUESTED_OUTPUT"
else
	readonly OUTPUT_ROOT="$REPOSITORY_ROOT/$REQUESTED_OUTPUT"
fi
readonly RELEASE_DIRECTORY="$OUTPUT_ROOT/$PLUGIN_SLUG"
readonly RELEASE_ZIP="$OUTPUT_ROOT/$PLUGIN_SLUG.zip"

if [[ ! -f "$MANIFEST" ]]; then
	printf 'Release manifest is missing: %s\n' "$MANIFEST" >&2
	exit 1
fi

mapfile -t RELEASE_FILES < "$MANIFEST"
if [[ 0 -eq "${#RELEASE_FILES[@]}" ]]; then
	printf 'Release manifest is empty.\n' >&2
	exit 1
fi

declare -A SEEN_PATHS=()
for RELEASE_FILE in "${RELEASE_FILES[@]}"; do
	if [[ -z "$RELEASE_FILE" || "$RELEASE_FILE" = /* || "$RELEASE_FILE" == *'..'* ]]; then
		printf 'Unsafe release path: %s\n' "$RELEASE_FILE" >&2
		exit 1
	fi
	if [[ -n "${SEEN_PATHS[$RELEASE_FILE]:-}" ]]; then
		printf 'Duplicate release path: %s\n' "$RELEASE_FILE" >&2
		exit 1
	fi
	SEEN_PATHS[$RELEASE_FILE]=1
	if [[ ! -f "$REPOSITORY_ROOT/$RELEASE_FILE" ]]; then
		printf 'Manifest path is not a file: %s\n' "$RELEASE_FILE" >&2
		exit 1
	fi
	if ! git -C "$REPOSITORY_ROOT" ls-files --error-unmatch -- "$RELEASE_FILE" >/dev/null; then
		printf 'Manifest path is not tracked by Git: %s\n' "$RELEASE_FILE" >&2
		exit 1
	fi
done

case "$OUTPUT_ROOT" in
	"$REPOSITORY_ROOT"|/|"")
		printf 'Unsafe output directory: %s\n' "$OUTPUT_ROOT" >&2
		exit 1
		;;
esac

mkdir -p "$OUTPUT_ROOT"
if [[ -e "$RELEASE_DIRECTORY" ]]; then
	rm -rf -- "$RELEASE_DIRECTORY"
fi
if [[ -e "$RELEASE_ZIP" ]]; then
	rm -f -- "$RELEASE_ZIP"
fi

git -C "$REPOSITORY_ROOT" archive \
	--format=tar \
	--prefix="$PLUGIN_SLUG/" \
	HEAD \
	-- "${RELEASE_FILES[@]}" | tar -xf - -C "$OUTPUT_ROOT"

git -C "$REPOSITORY_ROOT" archive \
	--format=zip \
	--prefix="$PLUGIN_SLUG/" \
	--output="$RELEASE_ZIP" \
	HEAD \
	-- "${RELEASE_FILES[@]}"

mapfile -t ARCHIVE_FILES < <(unzip -Z1 "$RELEASE_ZIP" | sed -n '/\/$/d; s#^dma-internlink-mapper/##p' | LC_ALL=C sort)
mapfile -t EXPECTED_FILES < <(printf '%s\n' "${RELEASE_FILES[@]}" | LC_ALL=C sort)
if [[ "$(printf '%s\n' "${ARCHIVE_FILES[@]}")" != "$(printf '%s\n' "${EXPECTED_FILES[@]}")" ]]; then
	printf 'Release ZIP does not match tools/release-manifest.txt.\n' >&2
	exit 1
fi

unzip -tqq "$RELEASE_ZIP"
( cd "$OUTPUT_ROOT" && sha256sum "$PLUGIN_SLUG.zip" | tee "$PLUGIN_SLUG.zip.sha256" )
printf 'Release verified: %s files\n' "${#RELEASE_FILES[@]}"
