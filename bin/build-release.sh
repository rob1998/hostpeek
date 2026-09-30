#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
version=$(cat VERSION)
[[ "$version" =~ ^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$ ]] || { echo 'VERSION must be MAJOR.MINOR.PATCH' >&2; exit 1; }
root=$PWD
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
mkdir -p "$stage/hostpeek/bin" "$root/dist"
# Explicit allowlist: never package local configuration, media or development tools.
cp -R src public database config.example.php composer.json composer.lock VERSION LICENSE "$stage/hostpeek/"
cp bin/install.php "$stage/hostpeek/bin/"
composer install --working-dir="$stage/hostpeek" --no-dev --prefer-dist --optimize-autoloader --no-interaction
php -r 'require $argv[1]; exit(class_exists("SitePreview\\Setup") ? 0 : 1);' "$stage/hostpeek/vendor/autoload.php"
archive="hostpeek-$version.zip"
rm -f "$root/dist/$archive"
(cd "$stage" && zip -qr "$root/dist/$archive" hostpeek)
(cd "$root/dist" && shasum -a 256 "$archive" > "$archive.sha256")
echo "Built dist/$archive"
