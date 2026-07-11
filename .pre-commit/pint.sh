#!/usr/bin/env bash
################################################################################
#
# Bash Laravel Pint
#
# This script fails if Pint has to fix anything or if Pint fails to fix something
#
# Exit 0 if no errors found
# Exit 1 if errors were found and fixed
# Exit 3 if errors were found and could not be fixed
#
# Requires
# - php
#
# Arguments
# See: https://laravel.com/docs/9.x/pint
#
################################################################################

RED='\033[0;31m'
BACKGROUND_RED='\033[0;41m'
YELLOW='\033[0;33m'
BOLD_YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check for Pint
if [ ! -f "./vendor/bin/pint" ]; then
  echo -e "${RED}Please install Pint (composer require laravel/pint --dev)${NC}"
  exit 1
fi

command_files_to_check="${@:2}"
command_args=$1
command_to_run="./vendor/bin/pint ${command_args} ${command_files_to_check}"

command_result=$(eval $command_to_run)

if [[ "$command_result" == *"FAIL"* ]]; then
    echo "$command_result"
    echo -e "${BACKGROUND_RED} Pint failed ${RED} Pint was unable to fix some issues in your files. \
Please fix the errors and try again.${NC}"
    exit 3
fi

if [[ "$command_result" == *"FIXED"* ]]; then
    echo -e "${BOLD_YELLOW} Pint fixed some issues in your files.${YELLOW} Please re-stage them and try again.${NC}"
    exit 1
fi

exit 0
