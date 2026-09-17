SHELL := /bin/bash

COMPOSE := docker compose
APP_URL := http://localhost:8080

.DEFAULT_GOAL := help

.PHONY: help start import import-bra status logs stop down reset test

help: ## Visa tillgängliga kommandon
	@echo "Brottsradarn"
	@echo ""
	@echo "  make start              Bygg, starta och hämta polishändelser"
	@echo "  make import             Hämta de senaste polishändelserna igen"
	@echo "  make import-bra FILE=x  Importera en CSV-fil från Brå"
	@echo "  make status             Visa serverstatus och antal händelser"
	@echo "  make logs               Följ Apache-loggen"
	@echo "  make stop               Stoppa servern"
	@echo "  make down               Stoppa och ta bort containern"
	@echo "  make reset              Ta bort containern och SQLite-databasen"
	@echo "  make test               Kör syntax- och API-kontroller"

start: ## Starta appen och fyll SQLite-databasen
	@echo "Bygger och startar Apache..."
	@$(COMPOSE) up -d --build
	@echo "Väntar på att webbservern ska bli klar..."
	@for attempt in $$(seq 1 30); do \
		if curl --silent --fail --output /dev/null "$(APP_URL)/api/filters.php"; then break; fi; \
		if [ "$$attempt" -eq 30 ]; then \
			echo "Webbservern startade inte. Kör 'make logs' för mer information."; \
			exit 1; \
		fi; \
		sleep 1; \
	done
	@$(MAKE) --no-print-directory import
	@echo ""
	@echo "Brottsradarn är klar: $(APP_URL)"

import: ## Hämta och lagra de senaste polishändelserna
	@$(COMPOSE) exec -T --user www-data web php scripts/import_police_events.php

import-bra: ## Importera Brå-CSV, exempel: make import-bra FILE=statistik.csv
	@if [ -z "$(FILE)" ]; then echo "Ange fil: make import-bra FILE=/sökväg/statistik.csv"; exit 1; fi
	@if [ ! -f "$(FILE)" ]; then echo "Filen finns inte: $(FILE)"; exit 1; fi
	@$(COMPOSE) cp "$(FILE)" web:/tmp/bra-import.csv
	@$(COMPOSE) exec -T --user www-data web php scripts/import_bra_csv.php /tmp/bra-import.csv
	@$(COMPOSE) exec -T web rm -f /tmp/bra-import.csv

status: ## Visa serverstatus och antalet arkiverade händelser
	@$(COMPOSE) ps
	@curl --silent --fail "$(APP_URL)/api/filters.php" \
		| $(COMPOSE) exec -T web php -r '$$d=json_decode(stream_get_contents(STDIN), true); echo "Händelser i SQLite: ".($$d["total_events"] ?? 0).PHP_EOL;'

logs: ## Följ Apache-loggen
	@$(COMPOSE) logs --follow --tail=100 web

stop: ## Stoppa servern men behåll databasen
	@$(COMPOSE) stop

down: ## Stoppa och ta bort containern men behåll databasen
	@$(COMPOSE) down

reset: ## Ta bort containern och den sparade SQLite-databasen
	@$(COMPOSE) down --volumes

test: ## Kontrollera PHP, JavaScript och API
	@$(COMPOSE) exec -T web sh -lc 'find . -name "*.php" -not -path "./example/*" -print0 | xargs -0 -n1 php -l'
	@node --check assets/app.js
	@curl --silent --fail "$(APP_URL)/api/events.php?limit=1" >/dev/null
	@echo "Alla kontroller godkändes."
