SHELL := /bin/bash

PHP ?= php
HOST ?= 127.0.0.1
PORT ?= 8080
APP_URL := http://$(HOST):$(PORT)
RUNTIME_DIR := .runtime
PID_FILE := $(RUNTIME_DIR)/server.pid
LOG_FILE := $(RUNTIME_DIR)/server.log

.DEFAULT_GOAL := help

.PHONY: help install-deps check start import import-bra status logs stop down reset test apache-setup apache-start apache-stop

help: ## Visa tillgängliga kommandon
	@echo "Brottsradarn — körs direkt på datorn, utan Docker"
	@echo ""
	@echo "  make install-deps       Installera Apache, PHP och SQLite-stöd"
	@echo "  make start              Starta lokalt och hämta polishändelser"
	@echo "  make import             Hämta de senaste polishändelserna igen"
	@echo "  make import-bra FILE=x  Importera en CSV-fil från Brå"
	@echo "  make status             Visa serverstatus och antal händelser"
	@echo "  make logs               Följ den lokala serverloggen"
	@echo "  make stop / make down   Stoppa den lokala servern"
	@echo "  make reset              Stoppa servern och radera SQLite-data"
	@echo "  make test               Kör syntax- och API-kontroller"
	@echo ""
	@echo "  make apache-setup       Konfigurera systemets Apache för projektet"
	@echo "  make apache-start       Starta Apache och importera data"
	@echo "  make apache-stop        Stoppa systemets Apache"

install-deps: ## Installera paket som behövs på Ubuntu/Debian
	sudo apt-get update
	sudo apt-get install -y apache2 php libapache2-mod-php php-sqlite3 php-mbstring curl rsync

check: ## Kontrollera lokala beroenden
	@command -v $(PHP) >/dev/null 2>&1 || { echo "PHP saknas. Kör 'make install-deps' först."; exit 1; }
	@$(PHP) -r 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);' || { echo "PHP-tillägget pdo_sqlite saknas. Kör 'make install-deps'."; exit 1; }
	@command -v curl >/dev/null 2>&1 || { echo "curl saknas. Kör 'make install-deps'."; exit 1; }

start: check ## Starta lokalt och fyll SQLite-databasen
	@mkdir -p "$(RUNTIME_DIR)" data
	@if [ -f "$(PID_FILE)" ] && kill -0 "$$(cat "$(PID_FILE)")" 2>/dev/null; then \
		echo "Servern kör redan på $(APP_URL)."; \
	else \
		echo "Startar Brottsradarn utan Docker..."; \
		nohup $(PHP) -S "$(HOST):$(PORT)" -t . router.php >"$(LOG_FILE)" 2>&1 & echo $$! >"$(PID_FILE)"; \
	fi
	@for attempt in $$(seq 1 20); do \
		if curl --silent --fail --output /dev/null "$(APP_URL)/api/filters.php"; then break; fi; \
		if [ "$$attempt" -eq 20 ]; then \
			echo "Servern startade inte. Kör 'make logs' för mer information."; \
			exit 1; \
		fi; \
		sleep 1; \
	done
	@$(MAKE) --no-print-directory import
	@echo ""
	@echo "Brottsradarn är klar: $(APP_URL)"

import: check ## Hämta och lagra de senaste polishändelserna
	@$(PHP) scripts/import_police_events.php

import-bra: check ## Importera Brå-CSV, exempel: make import-bra FILE=statistik.csv
	@if [ -z "$(FILE)" ]; then echo "Ange fil: make import-bra FILE=/sökväg/statistik.csv"; exit 1; fi
	@if [ ! -f "$(FILE)" ]; then echo "Filen finns inte: $(FILE)"; exit 1; fi
	@$(PHP) scripts/import_bra_csv.php "$(FILE)"

status: check ## Visa lokal serverstatus och antalet arkiverade händelser
	@if [ -f "$(PID_FILE)" ] && kill -0 "$$(cat "$(PID_FILE)")" 2>/dev/null; then \
		echo "Server: kör på $(APP_URL) (PID $$(cat "$(PID_FILE)"))"; \
		curl --silent --fail "$(APP_URL)/api/filters.php" | $(PHP) -r '$$d=json_decode(stream_get_contents(STDIN), true); echo "Händelser i SQLite: ".($$d["total_events"] ?? 0).PHP_EOL;'; \
	else \
		echo "Server: stoppad"; \
		exit 1; \
	fi

logs: ## Följ den lokala serverloggen
	@touch "$(LOG_FILE)"
	@tail -f "$(LOG_FILE)"

stop down: ## Stoppa den lokala servern men behåll databasen
	@if [ -f "$(PID_FILE)" ]; then \
		pid=$$(cat "$(PID_FILE)"); \
		if kill -0 "$$pid" 2>/dev/null; then kill "$$pid"; echo "Servern stoppades."; else echo "Servern är redan stoppad."; fi; \
		rm -f "$(PID_FILE)"; \
	else \
		echo "Servern är redan stoppad."; \
	fi

reset: stop ## Radera den lokala SQLite-databasen
	@rm -f data/crime_radar.sqlite data/crime_radar.sqlite-shm data/crime_radar.sqlite-wal
	@echo "SQLite-databasen raderades."

test: check ## Kontrollera PHP, JavaScript och API
	@find . -name "*.php" -not -path "./example/*" -print0 | xargs -0 -n1 $(PHP) -l
	@if command -v node >/dev/null 2>&1; then node --check assets/app.js; fi
	@if [ -f "$(PID_FILE)" ] && kill -0 "$$(cat "$(PID_FILE)")" 2>/dev/null; then curl --silent --fail "$(APP_URL)/api/events.php?limit=1" >/dev/null; fi
	@echo "Alla kontroller godkändes."

apache-setup: check ## Installera projektets konfiguration i systemets Apache
	@sudo a2enmod headers
	@sudo install -d /var/www/crime-radar /var/lib/crime-radar
	@sudo rsync -a --exclude='.git/' --exclude='.runtime/' --exclude='data/*.sqlite*' ./ /var/www/crime-radar/
	@sudo chown -R www-data:www-data /var/lib/crime-radar
	@sudo chmod 750 /var/lib/crime-radar
	@sudo cp apache/crime-radar.conf /etc/apache2/sites-available/crime-radar.conf
	@sudo a2ensite crime-radar.conf
	@sudo apache2ctl configtest
	@sudo systemctl reload-or-restart apache2
	@echo "Apache är konfigurerad. Kör 'make apache-start'."

apache-start: check ## Starta systemets Apache och importera händelser
	@sudo systemctl start apache2
	@sudo -u www-data env CRIME_RADAR_DB_PATH=/var/lib/crime-radar/crime-radar.sqlite $(PHP) /var/www/crime-radar/scripts/import_police_events.php
	@echo "Brottsradarn kör via Apache: http://localhost/"

apache-stop: ## Stoppa systemets Apache
	@sudo systemctl stop apache2
