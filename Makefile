.DEFAULT_GOAL := help

COMPOSE      ?= docker compose
PHP_SERVICE  ?= php
RUN          := $(COMPOSE) run --rm $(PHP_SERVICE)

.PHONY: help
help: ## Show this help
	@awk 'BEGIN {FS = ":.*##"; printf "Usage:\n  make \033[36m<target>\033[0m\n\nTargets:\n"} /^[a-zA-Z_-]+:.*?##/ { printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2 }' $(MAKEFILE_LIST)

.PHONY: build
build: ## Build the PHP image
	$(COMPOSE) build $(PHP_SERVICE)

.PHONY: up
up: ## Start MongoDB and the PHP container in the background
	$(COMPOSE) up -d

.PHONY: down
down: ## Stop and remove the containers
	$(COMPOSE) down --remove-orphans

.PHONY: install
install: ## Install Composer dependencies inside the container
	$(RUN) composer install

.PHONY: update
update: ## Update Composer dependencies inside the container
	$(RUN) composer update

.PHONY: test
test: ## Run the full PHPUnit suite against MongoDB
	$(RUN) vendor/bin/phpunit --colors=always

.PHONY: test-unit
test-unit: ## Run the unit test suite
	$(RUN) vendor/bin/phpunit --colors=always --testsuite=Unit

.PHONY: test-feature
test-feature: ## Run the MongoDB-backed feature test suite
	$(RUN) vendor/bin/phpunit --colors=always --testsuite=Feature

.PHONY: pint
pint: ## Apply Laravel Pint formatting
	$(RUN) vendor/bin/pint

.PHONY: pint-check
pint-check: ## Verify formatting without writing changes
	$(RUN) vendor/bin/pint --test

.PHONY: stan
stan: ## Run PHPStan/Larastan static analysis
	$(RUN) vendor/bin/phpstan analyse --memory-limit=512M

.PHONY: shell
shell: ## Open an interactive shell in the PHP container
	$(RUN) bash

.PHONY: mongo-shell
mongo-shell: ## Open a mongosh shell against the test database
	$(COMPOSE) exec mongo mongosh telescope_mongodb_ci

.PHONY: playground
playground: ## Bootstrap a fresh Laravel app under playground/ that uses this package
	$(RUN) bash scripts/bootstrap-playground.sh

.PHONY: playground-up
playground-up: ## Start the playground Laravel app on http://127.0.0.1:8000
	$(COMPOSE) up -d laravel
	@echo "Playground at http://127.0.0.1:8000 — Telescope at http://127.0.0.1:8000/telescope"

.PHONY: playground-down
playground-down: ## Stop the playground Laravel app
	$(COMPOSE) stop laravel

.PHONY: playground-reset
playground-reset: ## Stop the playground, delete it, and re-bootstrap from scratch
	$(COMPOSE) stop laravel || true
	$(RUN) rm -rf playground
	$(MAKE) playground

.PHONY: bench-setup
bench-setup: ## Bootstrap both playgrounds (Mongo + MySQL) for benchmarking
	$(COMPOSE) --profile bench up -d mongo mysql
	$(RUN) bash -c "[ -d playground ] || bash scripts/bootstrap-playground.sh"
	$(RUN) bash -c "[ -d playground-mysql ] || bash scripts/bootstrap-playground-mysql.sh"
	$(COMPOSE) --profile bench up -d laravel laravel-mysql

.PHONY: bench
bench: ## Run the side-by-side benchmark (REQUESTS and CONCURRENCY env vars supported)
	bash scripts/bench.sh

.PHONY: bench-down
bench-down: ## Stop the benchmark containers (keeps the bootstrapped playgrounds)
	$(COMPOSE) --profile bench down --remove-orphans

.PHONY: clean
clean: ## Remove vendor/, cache, and stop containers
	$(COMPOSE) down -v --remove-orphans
	rm -rf vendor composer.lock .phpunit.cache
