# Fast local pre-check for auth_flexaccess.
# The authoritative release gate is GitHub CI; `make check` is intentionally
# a quick developer feedback loop and does not attempt to duplicate the full CI.
THIS_DIR := $(patsubst %/,%,$(dir $(abspath $(lastword $(MAKEFILE_LIST)))))
PLUGIN_DIR ?= $(THIS_DIR)
MOODLE_ROOT ?= $(abspath $(PLUGIN_DIR)/../..)
PLUGIN_REL ?= auth/flexaccess
PHP ?= php
PHPCS ?= phpcs

.PHONY: check lint-php lint-syntax lint-phpdoc phpunit

check: lint-syntax lint-php lint-phpdoc
	@echo "Fast local pre-check complete. Full release gate runs in GitHub CI."

lint-syntax:
	@find $(PLUGIN_DIR) -name '*.php' -not -path '*/vendor/*' -print0 | xargs -0 -n1 $(PHP) -l

lint-php:
	@if command -v $(PHPCS) >/dev/null 2>&1; then \
		cd $(PLUGIN_DIR) && $(PHPCS) --standard=moodle --extensions=php --severity=1 --no-cache --ignore=tools/ .; \
	else echo "phpcs not installed; skipped."; fi

lint-phpdoc:
	@if [ -f $(MOODLE_ROOT)/local/moodlecheck/cli/moodlecheck.php ]; then \
		cd $(MOODLE_ROOT) && $(PHP) local/moodlecheck/cli/moodlecheck.php --path=$(PLUGIN_REL) --exclude=$(PLUGIN_REL)/tools --format=text; \
	else echo "local_moodlecheck not installed; skipped."; fi

phpunit:
	@cd $(MOODLE_ROOT) && vendor/bin/phpunit --testsuite auth_flexaccess_testsuite
