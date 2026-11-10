#!/usr/bin/env bash
# Construit le zip installable via WordPress > Extensions > Ajouter > Téléverser.
# Le zip contient un dossier portant le nom du dossier du plugin sur le serveur
# (par défaut "mk-transport-calculator-v4", celui de mkhorsesolutions.com), avec
# tous les fichiers du plugin + les dépendances Composer (PhpSpreadsheet).
# WordPress propose alors "Remplacer la version actuelle".
#
# Usage : ./build-zip.sh                 ->  dist/mk-transport-calculator-v4-<version>.zip
#         ./build-zip.sh autre-dossier   ->  dist/autre-dossier-<version>.zip
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
MAIN="shipping-calculator-v2-extended.php"
SLUG="${1:-mk-transport-calculator-v4}"
VERSION="$(grep -m1 -E '^\s*\*\s*Version:' "$ROOT/$MAIN" | sed -E 's/.*Version:\s*//' | tr -d '[:space:]')"
BUILD="$(mktemp -d)"
DEST="$BUILD/$SLUG"
OUT="$ROOT/dist/$SLUG-$VERSION.zip"

mkdir -p "$DEST" "$ROOT/dist"

# Fichiers suivis par git, sans les fichiers de développement
cd "$ROOT"
git ls-files | grep -v -E '^(\.gitignore|package(-lock)?\.json|build-zip\.sh)$' | while read -r f; do
    mkdir -p "$DEST/$(dirname "$f")"
    cp "$f" "$DEST/$f"
done

# Dépendances PHP de production
(cd "$DEST" && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --quiet)
find "$DEST/vendor" -mindepth 3 -maxdepth 3 \( -name .git -o -name tests -o -name samples -o -name docs -o -name .github \) -prune -exec rm -rf {} +

rm -f "$OUT"
(cd "$BUILD" && zip -qr "$OUT" "$SLUG")
rm -rf "$BUILD"

echo "Zip créé : $OUT"
