#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
version="$(grep -m1 'Version:' "$root/immens-forecast.php" | sed -E 's/.*Version:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+).*/\1/')"
test -n "$version"

stage="$(mktemp -d)"
mkdir -p "$stage/immens-forecast"

cd "$root"
tar --exclude='./.git' \
	--exclude='./.superpowers' \
	--exclude='./.github' \
	--exclude='./.gitignore' \
	--exclude='./.gitattributes' \
	--exclude='./.distignore' \
	--exclude='./.phpunit.result.cache' \
	--exclude='./composer.json' \
	--exclude='./composer.lock' \
	--exclude='./phpcs.xml.dist' \
	--exclude='./phpunit.xml.dist' \
	--exclude='./tests' \
	--exclude='./docs' \
	--exclude='./tools' \
	--exclude='./vendor' \
	--exclude='./node_modules' \
	--exclude='./build' \
	--exclude='./dist' \
	--exclude='./CHANGELOG.md' \
	--exclude='./README.md' \
	-cf - . | tar -xf - -C "$stage/immens-forecast"

mkdir -p "$root/dist"
(cd "$stage" && zip -qr "$root/dist/immens-forecast-$version.zip" immens-forecast)
rm -rf "$stage"
echo "Built $root/dist/immens-forecast-$version.zip"
