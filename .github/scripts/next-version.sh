#!/usr/bin/env bash
# Prints "version=X.Y.Z" for the next release, or "version=" when nothing releasable changed.
# Also writes release-notes.md. Run from the repository root with full history and tags.
set -euo pipefail

last=$(git tag --list 'v[0-9]*.[0-9]*.[0-9]*' --sort=-v:refname | head -n1)
if [ -z "$last" ]; then
  range="HEAD"
  base="0.0.0"
else
  range="${last}..HEAD"
  base="${last#v}"
fi

# Only changes that reach users of the package make a release.
if [ -n "$last" ] && [ -z "$(git diff --name-only "$last" HEAD -- src _register.php composer.json LICENSE README.md)" ]; then
  echo "version="
  exit 0
fi

subjects=$(git log --no-merges --format='%s' "$range")
bodies=$(git log --no-merges --format='%B' "$range")

IFS=. read -r major minor patch <<< "$base"
if [ -z "$last" ]; then
  major=0; minor=1; patch=0
elif grep -qE '^[a-z]+(\([^)]*\))?!:' <<< "$subjects" || grep -q 'BREAKING CHANGE' <<< "$bodies"; then
  if [ "$major" -eq 0 ]; then minor=$((minor + 1)); patch=0; else major=$((major + 1)); minor=0; patch=0; fi
elif grep -qE '^feat(\([^)]*\))?:' <<< "$subjects"; then
  minor=$((minor + 1)); patch=0
else
  patch=$((patch + 1))
fi
version="${major}.${minor}.${patch}"

{
  echo "## Changes"
  echo
  git log --no-merges --format='- %s (%h)' "$range"
  echo
  echo "Install: \`composer require koraitech/opentelemetry-php-extras:^${major}.${minor}\`"
} > release-notes.md

echo "version=${version}"
