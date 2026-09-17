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

## Snabbstart med Docker

Docker-versionen kör Apache och PHP 8.3 med SQLite-stöd.

```bash
docker compose up -d --build
docker compose exec web php scripts/import_police_events.php
```

Öppna sedan <http://localhost:8080>. Databasen sparas i Docker-volymen `crime-radar-data`.

## Installation på Apache

Installera Apache, PHP och nödvändiga PHP-tillägg. På Ubuntu/Debian:

```bash
sudo apt update
sudo apt install apache2 php libapache2-mod-php php-sqlite3 php-mbstring
sudo a2enmod headers
```

Kopiera projektet och skapa en datakatalog som Apache får skriva till:

```bash
sudo cp -a . /var/www/crime-radar
sudo install -d -o www-data -g www-data /var/lib/crime-radar
sudo cp apache/crime-radar.conf /etc/apache2/sites-available/crime-radar.conf
sudo a2ensite crime-radar.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
```

Exempelkonfigurationen använder `/var/www/crime-radar` som `DocumentRoot` och lagrar databasen i `/var/lib/crime-radar/crime-radar.sqlite`. Ändra sökvägarna i `apache/crime-radar.conf` om projektet placeras någon annanstans.

Om webbhotellet inte tillåter egna VirtualHost-inställningar fungerar standardplatsen `data/crime_radar.sqlite`. Ge då Apache skrivrättighet till enbart `data/`:

```bash
sudo chown www-data:www-data /var/www/crime-radar/data
sudo chmod 750 /var/www/crime-radar/data
```

`data/.htaccess` blockerar nedladdning av databasen, men placering utanför `DocumentRoot` är säkrare i produktion.

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
schema.sql      tabeller och index
index.php       applikationens gränssnitt
```

## Datakällor och ansvarsfriskrivning

Händelser hämtas från Polismyndighetens öppna händelse-API. Historisk statistik kan importeras från Brottsförebyggande rådet. En polisrapporterad händelse betyder inte att ett brott är fastställt, och kartans koordinater kan vara ungefärliga. Vid akuta händelser ska användaren ringa 112.
