# Local dev shortcuts. Mirrors the CI test suite (.github/workflows/test.yml)
# across both supported PHP versions.
#
# Override the binaries if yours are named differently, e.g.:
#   make test PHP74=php7.4 PHP84=php8.4

PHP74    ?= php7.4
PHP84    ?= php8.4
COMPOSER ?= $(shell command -v composer 2>/dev/null || echo "$(PHP84) composer.phar")

# phpcs only finds the WordPress standard if installed_paths points at the
# vendored sniff packages. The composer-installer plugin normally writes this
# into vendor/, but that's unreliable outside a full `composer install`, so we
# pass it per-run instead — harmless and idempotent in CI too.
PHPCS_PATHS := $(CURDIR)/vendor/wp-coding-standards/wpcs,$(CURDIR)/vendor/phpcsstandards/phpcsextra,$(CURDIR)/vendor/phpcsstandards/phpcsutils

.DEFAULT_GOAL := help

.PHONY: help install update lint phpcs phpcbf test test-7.4 test-8.4 build check clean doctor

help: ## Show this help
	@grep -E '^[a-zA-Z0-9_.-]+:.*## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN{FS=":.*## "}{printf "  \033[36m%-11s\033[0m %s\n",$$1,$$2}'

install: ## Install composer dependencies (pinned to PHP 7.4 via composer.json platform)
	$(COMPOSER) install --no-interaction --no-progress

update: ## Re-resolve and update composer dependencies
	$(COMPOSER) update --no-interaction --no-progress

lint: ## php -l syntax check on PHP 7.4 (the minimum supported)
	@find temso-ai.php uninstall.php includes -name '*.php' -print0 \
		| xargs -0 -n1 -P4 $(PHP74) -l > /dev/null && echo "lint OK"

phpcs: ## WordPress Coding Standards
	$(PHP74) vendor/bin/phpcs --runtime-set installed_paths "$(PHPCS_PATHS)"

phpcbf: ## Auto-fix coding-standard violations
	$(PHP74) vendor/bin/phpcbf --runtime-set installed_paths "$(PHPCS_PATHS)"

test: test-7.4 test-8.4 ## Run PHPUnit on both PHP versions

test-7.4: ## Run PHPUnit on PHP 7.4
	$(PHP74) vendor/bin/phpunit

test-8.4: ## Run PHPUnit on PHP 8.4
	$(PHP84) vendor/bin/phpunit

build: ## Build the distributable plugin zip
	bash bin/build.sh

check: lint phpcs test ## Run the full local suite (lint + phpcs + tests on both PHPs)

clean: ## Remove build artifacts
	rm -rf build dist

doctor: ## Report PHP extensions PHPUnit/PHPCS need but the local PHPs lack
	@for php in "$(PHP74)" "$(PHP84)"; do \
		echo "== $$php =="; \
		missing=""; \
		for ext in dom mbstring xml xmlwriter xmlreader simplexml tokenizer curl; do \
			$$php -m 2>/dev/null | grep -qi "^$$ext$$" || missing="$$missing $$ext"; \
		done; \
		[ -z "$$missing" ] && echo "  all required extensions present" || echo "  missing:$$missing"; \
	done
