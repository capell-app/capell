#!/usr/bin/env bash
################################################################################
#
# Rector check for staged PHP files.
#
# CI runs Rector and then `git diff --exit-code`, so a commit that leaves any
# Rector rewrite unapplied fails "Verify Rector left no uncommitted changes"
# long after the author has moved on. This reports the same thing at commit
# time, against the staged files only.
#
# Exit 0 when Rector has nothing to change.
# Exit 1 when Rector would rewrite a staged file.
#
################################################################################

RED='\033[0;31m'
BOLD_YELLOW='\033[1;33m'
YELLOW='\033[0;33m'
NC='\033[0m'

if [ ! -f "./vendor/bin/rector" ]; then
    echo -e "${RED}Please install Rector (composer require rector/rector --dev)${NC}"
    exit 1
fi

if [ "$#" -eq 0 ]; then
    exit 0
fi

# --dry-run reports what it would change and exits non-zero when there is
# anything to apply; it never writes, so a passing commit is never silently
# reformatted underneath the author.
if ! XDEBUG_MODE=off ./vendor/bin/rector process --dry-run --no-progress-bar "$@"; then
    echo -e "${BOLD_YELLOW} Rector would rewrite staged files.${YELLOW} Run 'composer rector', re-stage, and try again.${NC}"
    exit 1
fi

exit 0
