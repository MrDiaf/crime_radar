# Brottsradarn Sverige

En Apache/PHP-applikation som hämtar polisrapporterade händelser, arkiverar dem i SQLite och visar dem på en interaktiv karta. Appen har även en statistikvy och stöd för att importera aggregerad CSV-statistik från Brå.

## Funktioner

- Interaktiv Sverigekarta med klustrade markörer
- Filter för fritext, händelsetyp, område och datum
- Beständigt SQLite-arkiv som växer för varje import
- Diagram per dag, händelsetyp och område
- Separat import av historisk Brå-statistik
- Responsiv svensk design för dator och mobil
- JSON-API med förberedda SQL-frågor

## Snabbstart utan Docker

Installera först Apache, PHP, SQLite-stöd och curl på Ubuntu/Debian:

```bash
make install-deps
```

För lokal utveckling startar ett enda kommando PHP-servern, skapar SQLite-databasen och fyller den med de senaste polishändelserna:

```bash
make start
```

Öppna sedan <http://localhost:8080>. Databasen sparas i `data/crime_radar.sqlite` och finns kvar när servern stoppas.

Vanliga kommandon:

```bash
make import   # hämta nya händelser
make status   # kontrollera server och antal händelser
make logs     # följ serverloggen
make down     # stoppa men behåll databasen
make reset    # stoppa och ta bort databasen
```

Kör `make help` för hela listan. Den lokala utvecklingsservern kräver inga root-rättigheter. Manuell start utan `make`:

```bash
php scripts/import_police_events.php
php -S 127.0.0.1:8080 -t . router.php
```

## Kör med systemets Apache

Efter `make install-deps` kan Make konfigurera Apache med projektets aktuella sökväg:

```bash
make apache-setup
make apache-start
```

Öppna sedan <http://localhost/>. Stoppa Apache med `make apache-stop`.

`apache-setup` kräver `sudo`. Den kopierar applikationen till `/var/www/crime-radar`, installerar VirtualHost-konfigurationen i `/etc/apache2` och skapar den skrivbara databaskatalogen `/var/lib/crime-radar`. SQLite-databasen ligger alltså utanför den publika webbrooten.

`data/.htaccess` blockerar nedladdning av databasen från webben.

## Docker (valfritt)

Docker-filerna finns kvar som ett alternativ men krävs inte:

```bash
docker compose up -d --build
docker compose exec -T --user www-data web php scripts/import_police_events.php
```

## Hämta och arkivera polishändelser

Kör första importen som Apache-användaren:

```bash
sudo -u www-data CRIME_RADAR_DB_PATH=/var/lib/crime-radar/crime-radar.sqlite \
  php /var/www/crime-radar/scripts/import_police_events.php
```

Importen använder `police_id` som unik nyckel. Befintliga poster uppdateras, så kommandot kan köras ofta utan dubbletter. Lägg exempelvis till följande med `sudo crontab -u www-data -e` för import var femte minut:

```cron
*/5 * * * * CRIME_RADAR_DB_PATH=/var/lib/crime-radar/crime-radar.sqlite /usr/bin/php /var/www/crime-radar/scripts/import_police_events.php >> /var/log/crime-radar-import.log 2>&1
```

Polisens API visar bara det senaste flödet. Börja därför köra importen direkt och regelbundet för att bygga historiken.

## Importera Brå-statistik

Brå publicerar statistik i flera format. Exportera eller omvandla relevant tabell till CSV med följande fyra kolumner:

```csv
år;region;brottstyp;antal
2024;Stockholms län;Stöld;12345
```

Engelska rubriker (`year`, `region`, `crime_type`, `incident_count`) och kommatecken stöds också. Importera filen med:

```bash
sudo -u www-data CRIME_RADAR_DB_PATH=/var/lib/crime-radar/crime-radar.sqlite \
  php /var/www/crime-radar/scripts/import_bra_csv.php /path/to/bra-statistik.csv
```

Rader med samma år, region, brottstyp och källa uppdateras i stället för att dupliceras.

## API

- `GET /api/events.php` – händelser; parametrar: `type`, `location`, `search`, `from`, `to`, `limit`
- `GET /api/filters.php` – tillgängliga typer, områden och arkivstatus
- `GET /api/statistics.php` – aggregeringar; parametrar: `from`, `to`

Datum anges som `YYYY-MM-DD`. Maximalt 1 000 händelser returneras per kartanrop.

## Projektstruktur

```text
api/            JSON-endpoints
assets/         gränssnittets CSS och JavaScript
includes/       databas- och HTTP-hjälpfunktioner
scripts/        schemalagda importkommandon
apache/         exempel på VirtualHost
data/           lokal SQLite-databas (ignoreras av Git)
Makefile        start-, import- och underhållskommandon
schema.sql      tabeller och index
index.php       applikationens gränssnitt
router.php      skyddar privata filer i den lokala utvecklingsservern
```

## Datakällor och ansvarsfriskrivning

Händelser hämtas från Polismyndighetens öppna händelse-API. Historisk statistik kan importeras från Brottsförebyggande rådet. En polisrapporterad händelse betyder inte att ett brott är fastställt, och kartans koordinater kan vara ungefärliga. Vid akuta händelser ska användaren ringa 112.
