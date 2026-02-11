#!/bin/sh

set -e

version="${1}"
sed="sed"

if [ -z "${version}" ]; then
  echo "Usage: $0 <version>"
  exit 1
fi

# Check if gsed is available (for macOS users)
if which gsed ; then
  sed="gsed"
fi

if [ ! -f "mittwald-ai-provider.php" ]; then
  echo "Error: mittwald-ai-provider.php not found in the current directory."
  exit 1
fi

${sed} -i -e "s,Version: trunk,Version: ${version}," mittwald-ai-provider.php
${sed} -i -e "s,Stable tag: trunk,Stable tag: ${version}," readme.txt

composer install --no-dev --optimize-autoloader

zip -r "mittwald-ai-provider.${version}.zip" includes vendor composer.* license.txt mittwald-ai-provider.php readme.txt