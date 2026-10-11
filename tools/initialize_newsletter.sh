#!/bin/sh
# Operator-only setup: run once with an explicitly authorized test recipient.
set -eu
if [ "$#" -ne 1 ]; then
  echo 'Usage: initialize_newsletter.sh authorized-test-email' >&2
  exit 1
fi
PT_SITE_ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
/usr/bin/php "$PT_SITE_ROOT/newsletter/worker.php" --enable
/usr/bin/php "$PT_SITE_ROOT/newsletter/worker.php" --queue-test "$PT_SITE_ROOT/newsletter/edition-01.json" "$1"
/usr/bin/php "$PT_SITE_ROOT/newsletter/worker.php"
/usr/bin/php "$PT_SITE_ROOT/newsletter/worker.php" --status
