# =============================================================================
# Single entry point for every task (CLAUDE.md section 5). Tools run inside
# containers; the host only needs Docker, GNU make, bash, git (and jq/curl for
# the interview demo scripts).
#
# Works from the main checkout and from any `make wt` worktree: the compose
# project name comes from the local .env, so stacks never collide.
# =============================================================================
SHELL := bash
.SHELLFLAGS := -eu -o pipefail -c
.DEFAULT_GOAL := help
MAKEFLAGS += --no-print-directory
# Git Bash on Windows rewrites container paths such as `-w /work/...` into
# host paths (C:/Program Files/Git/work/...); disable that conversion.
export MSYS_NO_PATHCONV := 1
export MSYS2_ARG_CONV_EXCL := *

ENV_FILE := $(if $(wildcard .env),.env,.env.example)
env_value = $(shell sed -n 's/^$(1)=//p' $(ENV_FILE) | tail -n1 | tr -d '"\r')

PROJECT   ?= $(or $(call env_value,COMPOSE_PROJECT_NAME),$(notdir $(CURDIR)))
EDGE_PORT ?= $(or $(call env_value,EDGE_PORT),8080)
# ENV=prod drops the development overlay (mailpit UI port, Vite dev server);
# COMPOSE_CI=1 adds the CI caches (compose.ci.yaml).
COMPOSE_FILES := -f compose.yaml $(if $(filter prod,$(ENV)),,-f compose.dev.yaml) $(if $(COMPOSE_CI),-f compose.ci.yaml)
COMPOSE   := docker compose -p $(PROJECT) $(COMPOSE_FILES)
TOOLS     := $(COMPOSE) --profile tools
TESTS     := $(COMPOSE) --profile test
SCRIPTS   := infra/scripts

# Base the breaking-change check on the remote main when it exists.
BREAKING_BASE ?= $(shell git rev-parse --verify -q origin/main >/dev/null && echo origin/main || echo main)

# Dev/test credentials for the host-side proof scripts (D-04, D-05).
DEMO_ENV = DEV_ADMIN_EMAIL="$(call env_value,DEV_ADMIN_EMAIL)" DEV_ADMIN_PASSWORD="$(call env_value,DEV_ADMIN_PASSWORD)"   SEED_CUSTOMER_COUNT="$(call env_value,SEED_CUSTOMER_COUNT)" SEED_CUSTOMER_PASSWORD="$(call env_value,SEED_CUSTOMER_PASSWORD)"

# need <path> <task id>: fail clearly when a component does not exist yet.
define need
	@test -e "$(1)" || { echo "✗ '$(1)' does not exist yet — it is created by task $(2)." >&2; exit 1; }
endef

define need_docker
	@command -v docker >/dev/null 2>&1 || { echo "✗ docker is not installed or not on PATH (see README → Prerequisites)." >&2; exit 1; }
endef

.PHONY: help
help: ## List every target
	@awk 'BEGIN {FS = ":.*## "; printf "Usage: make <target> [VAR=value]\n\n"} \
	  /^[a-zA-Z0-9_-]+:.*## / {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

# ----------------------------------------------------------------- stack --
.PHONY: up down ps logs restart shell-api bootstrap
up: ## Build and start the stack, wait until every service is healthy
	$(need_docker)
	$(COMPOSE) up -d --build --wait --wait-timeout 180
	@echo "✓ http://localhost:$(EDGE_PORT)  (API docs: /docs)"

down: ## Stop the stack (volumes are kept)
	$(need_docker)
	$(COMPOSE) down --remove-orphans

ps: ## Show service status and health
	$(need_docker)
	$(COMPOSE) ps

logs: ## Follow logs: make logs [S=api]
	$(need_docker)
	$(COMPOSE) logs -f --tail=200 $(S)

restart: ## Restart one service: make restart S=inventory
	$(need_docker)
	@test -n "$(S)" || { echo "usage: make restart S=<service>" >&2; exit 1; }
	$(COMPOSE) restart $(S)

shell-api: ## Open a shell in the api container
	$(need_docker)
	$(COMPOSE) exec api sh

bootstrap: ## First run: create .env, APP_KEY, JWT keys, migrate and seed
	$(need_docker)
	@PROJECT="$(PROJECT)" bash $(SCRIPTS)/bootstrap.sh

# ------------------------------------------------------------- contracts --
.PHONY: lint-contracts gen
lint-contracts: ## buf lint + buf breaking (vs main) + Redocly lint (zero warnings)
	$(need_docker)
	@rm -rf .cache/breaking-base && mkdir -p .cache/breaking-base
	@if git rev-parse --verify -q "$(BREAKING_BASE):contracts/proto" >/dev/null; then \
	  git archive "$(BREAKING_BASE)" contracts/proto | tar -x -C .cache/breaking-base; fi
	$(TOOLS) run --rm --no-deps -w /work/contracts/proto buf buf lint
	$(TOOLS) run --rm --no-deps -w /work/contracts/proto buf buf format -d --exit-code
	@if [ -d .cache/breaking-base/contracts/proto ]; then \
	  $(TOOLS) run --rm --no-deps -w /work/contracts/proto buf \
	    buf breaking --against /work/.cache/breaking-base/contracts/proto; \
	else echo "• buf breaking skipped: $(BREAKING_BASE) has no contracts/proto yet"; fi
	$(TOOLS) run --rm --no-deps redocly lint contracts/openapi/openapi.yaml --config contracts/openapi/redocly.yaml

gen: ## Generate PHP gRPC stubs (from proto) and TS types (from OpenAPI)
	$(need_docker)
	$(call need,backend/api,P0-API-01)
	$(TOOLS) run --rm --no-deps -w /work/contracts/proto buf buf generate
	@if [ -f frontend/package.json ]; then \
	  $(TESTS) run --rm --no-deps web-test sh -c "pnpm install --frozen-lockfile && pnpm run gen:api"; \
	else echo "• TS types skipped: frontend/ is created by P0-WEB-01"; fi

# ---------------------------------------------------------------- verify --
.PHONY: verify verify-api verify-inventory verify-web verify-e2e test-api test-inventory
verify: lint-contracts verify-api verify-inventory verify-web verify-e2e ## Merge gate: everything below + E2E

verify-api: ## Laravel: Pint --test + Larastan max + Pest (real Postgres)
	$(need_docker)
	$(call need,backend/api/composer.json,P0-API-01)
	$(TESTS) run --rm --build api-test sh -c "composer lint:check && composer analyse && composer test"

test-api: ## Run part of the Laravel suite: make test-api F=Orders
	$(need_docker)
	$(call need,backend/api/composer.json,P0-API-01)
	$(TESTS) run --rm --build api-test composer test -- $(if $(F),--filter=$(F))

verify-inventory: ## Spring: Spotless + ErrorProne + tests (Testcontainers) + JaCoCo
	$(need_docker)
	$(call need,backend/inventory/build.gradle.kts,P0-INV-01)
	$(TESTS) run --rm inventory-test ./gradlew --no-daemon check

test-inventory: ## Run one inventory test class: make test-inventory T=ReservationConcurrencyIT
	$(need_docker)
	$(call need,backend/inventory/build.gradle.kts,P0-INV-01)
	@test -n "$(T)" || { echo "usage: make test-inventory T=<TestClass>" >&2; exit 1; }
	$(TESTS) run --rm inventory-test ./gradlew --no-daemon test --tests '*$(T)'

verify-web: ## React: ESLint + tsc + Vitest + build
	$(need_docker)
	$(call need,frontend/package.json,P0-WEB-01)
	$(TESTS) run --rm --no-deps web-test sh -c "pnpm install --frozen-lockfile && pnpm run verify"

verify-e2e: ## Playwright E2E against the running stack (run `make up` first)
	$(need_docker)
	$(call need,tests/e2e/package.json,P0-QA-01)
	$(TESTS) run --rm e2e

# ---------------------------------------------------------------- proofs --
.PHONY: race-test demo-r1 demo-r2
race-test: ## PROOF-R2-1: N concurrent orders on the last unit: make race-test [N=50]
	$(need_docker)
	$(call need,tests/load/race.js,P2-QA-01)
	$(TOOLS) run --rm -e N=$(or $(N),50) k6 run --quiet /work/tests/load/race.js
	@$(DEMO_ENV) EDGE_URL="http://localhost:$(EDGE_PORT)" PROJECT="$(PROJECT)" bash tests/security/scripts/race-verify.sh

demo-r1: ## Interview demo, requirement 1 (IDOR, 403, rate limit, JWT, headers)
	$(call need,tests/security/scripts/demo-r1.sh,P1-QA-01)
	@$(DEMO_ENV) EDGE_URL="http://localhost:$(EDGE_PORT)" PROJECT="$(PROJECT)" bash tests/security/scripts/demo-r1.sh

demo-r2: ## Interview demo, requirement 2 (race, inventory down/slow)
	$(call need,tests/security/scripts/demo-r2.sh,P2-QA-02)
	@$(DEMO_ENV) EDGE_URL="http://localhost:$(EDGE_PORT)" PROJECT="$(PROJECT)" bash tests/security/scripts/demo-r2.sh

# ----------------------------------------------------------------- chaos --
# Toxiproxy sits between api/scheduler and inventory (docs/01 section 1).
.PHONY: chaos-down chaos-latency chaos-reset chaos-status
chaos-down: ## Cut the api → inventory link (gRPC UNAVAILABLE)
	$(need_docker)
	@PROJECT="$(PROJECT)" bash $(SCRIPTS)/chaos.sh down

chaos-latency: ## Add downstream latency: make chaos-latency MS=3000
	$(need_docker)
	@test -n "$(MS)" || { echo "usage: make chaos-latency MS=<milliseconds>" >&2; exit 1; }
	@PROJECT="$(PROJECT)" bash $(SCRIPTS)/chaos.sh latency "$(MS)"

chaos-reset: ## Remove every toxic and re-enable the link
	$(need_docker)
	@PROJECT="$(PROJECT)" bash $(SCRIPTS)/chaos.sh reset

chaos-status: ## Show the inventory proxy and its toxics
	$(need_docker)
	@PROJECT="$(PROJECT)" bash $(SCRIPTS)/chaos.sh status

# ------------------------------------------------------------- worktrees --
.PHONY: wt wt-rm fmt-file
wt: ## Agent worktree: make wt NAME=api BRANCH=feat/x [BASE=main MEMBER_NAME=.. MEMBER_EMAIL=..]
	@NAME="$(NAME)" BRANCH="$(BRANCH)" BASE="$(or $(BASE),main)" \
	  MEMBER_NAME="$(MEMBER_NAME)" MEMBER_EMAIL="$(MEMBER_EMAIL)" bash $(SCRIPTS)/wt.sh add

wt-rm: ## Remove an agent worktree and its stack: make wt-rm NAME=api
	@NAME="$(NAME)" bash $(SCRIPTS)/wt.sh remove

fmt-file: ## Format one file (used by the PostToolUse hook): make fmt-file FILE=<abs path>
	@PROJECT="$(PROJECT)" bash $(SCRIPTS)/fmt-file.sh "$(FILE)"
