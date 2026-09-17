<?php
declare(strict_types=1);
$today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('Y-m-d');
$weekAgo = (new DateTimeImmutable('-7 days', new DateTimeZone('Europe/Stockholm')))->format('Y-m-d');
?>
<!doctype html>
<html lang="sv">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Utforska polisrapporterade händelser i Sverige på en interaktiv karta.">
    <title>Brottsradarn — Polisrapporterade händelser i Sverige</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css">
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
    <header class="site-header">
        <a class="brand" href="#map-view" aria-label="Brottsradarn startsida">
            <span class="brand-mark" aria-hidden="true"><span></span></span>
            <span>Brotts<span>radarn</span></span>
        </a>
        <nav class="main-nav" aria-label="Huvudmeny">
            <button class="nav-link is-active" data-view="map-view">Karta</button>
            <button class="nav-link" data-view="statistics-view">Statistik</button>
            <button class="nav-link" data-view="about-view">Om tjänsten</button>
        </nav>
        <div class="live-status"><span></span><span>Data uppdateras löpande</span></div>
    </header>

    <main>
        <section id="map-view" class="view is-active" aria-labelledby="map-heading">
            <aside class="sidebar">
                <div class="intro">
                    <p class="eyebrow">Polisens öppna data</p>
                    <h1 id="map-heading">Vad händer i Sverige?</h1>
                    <p>Sök bland de senaste polisrapporterade händelserna och se var de inträffade.</p>
                </div>

                <form id="filter-form" class="filters">
                    <label class="search-field">
                        <span class="sr-only">Sök händelser</span>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-4-4"></path></svg>
                        <input id="search" name="search" type="search" placeholder="Sök i händelser…" autocomplete="off">
                    </label>
                    <div class="filter-grid">
                        <label><span>Händelsetyp</span><select id="type" name="type"><option value="">Alla typer</option></select></label>
                        <label><span>Län / område</span><select id="location" name="location"><option value="">Hela Sverige</option></select></label>
                        <label><span>Från</span><input id="from" name="from" type="date" value="<?= htmlspecialchars($weekAgo) ?>"></label>
                        <label><span>Till</span><input id="to" name="to" type="date" value="<?= htmlspecialchars($today) ?>"></label>
                    </div>
                    <div class="filter-actions">
                        <button class="button button-primary" type="submit">Visa händelser</button>
                        <button class="button button-quiet" id="reset-filters" type="button">Rensa</button>
                    </div>
                </form>

                <div class="result-heading">
                    <div><strong id="result-count">—</strong><span> händelser</span></div>
                    <span id="result-period">Senaste 7 dagarna</span>
                </div>
                <div id="event-list" class="event-list" aria-live="polite">
                    <div class="loading-card"><span></span><p>Hämtar händelser…</p></div>
                </div>
            </aside>

            <div class="map-shell">
                <div id="map" aria-label="Karta över polisrapporterade händelser"></div>
                <div class="map-overlay map-key">
                    <span><i class="key-dot key-urgent"></i>Vålds- och säkerhetshändelser</span>
                    <span><i class="key-dot key-traffic"></i>Trafik</span>
                    <span><i class="key-dot key-other"></i>Övrigt</span>
                </div>
                <button class="map-overlay locate-button" id="fit-markers" type="button" title="Visa alla händelser" aria-label="Visa alla händelser">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2v3M12 19v3M2 12h3M19 12h3"></path><circle cx="12" cy="12" r="5"></circle></svg>
                </button>
                <div class="map-overlay archive-note"><span id="archive-total">—</span> händelser i arkivet</div>
            </div>
        </section>

        <section id="statistics-view" class="view page-view" aria-labelledby="statistics-heading">
            <div class="page-heading">
                <p class="eyebrow">Överblick</p>
                <h1 id="statistics-heading">Statistik från arkivet</h1>
                <p>Sammanställningar av de händelser som importerats från Polisen. Brå-data visas när den har importerats.</p>
            </div>
            <div class="stat-cards">
                <article><span>Händelser</span><strong id="stat-events">—</strong></article>
                <article><span>Händelsetyper</span><strong id="stat-types">—</strong></article>
                <article><span>Områden</span><strong id="stat-locations">—</strong></article>
            </div>
            <div class="chart-grid">
                <article class="chart-card chart-wide"><div><h2>Händelser per dag</h2><p>Senaste 30 dagarna i valt intervall</p></div><div class="chart-wrap"><canvas id="daily-chart"></canvas></div></article>
                <article class="chart-card"><div><h2>Vanligaste händelsetyper</h2><p>Topp 10</p></div><div class="chart-wrap"><canvas id="type-chart"></canvas></div></article>
                <article class="chart-card"><div><h2>Flest rapporter per område</h2><p>Topp 10</p></div><div class="chart-wrap"><canvas id="location-chart"></canvas></div></article>
                <article class="chart-card chart-wide" id="historical-card"><div><h2>Historisk Brå-statistik</h2><p>Totalt antal importerade anmälda brott per år</p></div><div class="chart-wrap"><canvas id="historical-chart"></canvas><p class="empty-chart" id="historical-empty">Ingen Brå-data har importerats ännu.</p></div></article>
            </div>
        </section>

        <section id="about-view" class="view page-view" aria-labelledby="about-heading">
            <div class="page-heading about-heading">
                <p class="eyebrow">Om projektet</p>
                <h1 id="about-heading">Ett tydligare läge, på en och samma plats.</h1>
                <p>Brottsradarn visualiserar öppna data. Tjänsten är ett informationsverktyg och ska inte användas för akuta ärenden.</p>
            </div>
            <div class="about-grid">
                <article><span class="step">01</span><h2>Händelser hämtas</h2><p>En schemalagd import hämtar Polisens senaste publicerade händelser.</p></article>
                <article><span class="step">02</span><h2>Historik byggs</h2><p>Händelserna lagras i SQLite och finns kvar när de lämnar Polisens senaste flöde.</p></article>
                <article><span class="step">03</span><h2>Mönster synliggörs</h2><p>Kartan och diagrammen gör det enklare att utforska plats, typ och tid.</p></article>
            </div>
            <div class="source-panel">
                <div><p class="eyebrow">Datakällor</p><h2>Öppen data, med tydlig avsändare</h2></div>
                <p>Enskilda händelser kommer från <a href="https://polisen.se/aktuellt/polisens-nyheter/" target="_blank" rel="noopener">Polismyndigheten</a>. Aggregerad historik kan importeras från <a href="https://bra.se/statistik" target="_blank" rel="noopener">Brottsförebyggande rådet (Brå)</a>. En rapporterad händelse är inte samma sak som ett fastställt brott.</p>
            </div>
        </section>
    </main>

    <div id="toast" class="toast" role="status" aria-live="polite"></div>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <script src="assets/app.js"></script>
</body>
</html>
