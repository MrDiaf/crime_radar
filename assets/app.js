(() => {
    'use strict';

    const state = { map: null, cluster: null, events: [], markers: new Map(), charts: {} };
    const elements = {
        form: document.querySelector('#filter-form'), list: document.querySelector('#event-list'), count: document.querySelector('#result-count'),
        period: document.querySelector('#result-period'), type: document.querySelector('#type'), location: document.querySelector('#location'),
        from: document.querySelector('#from'), to: document.querySelector('#to'), total: document.querySelector('#archive-total'), toast: document.querySelector('#toast')
    };
    const formatter = new Intl.DateTimeFormat('sv-SE', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Europe/Stockholm' });
    const numberFormatter = new Intl.NumberFormat('sv-SE');

    function initializeMap() {
        state.map = L.map('map', { center: [62.1, 15.2], zoom: 5, zoomControl: false, minZoom: 3 });
        L.control.zoom({ position: 'topright' }).addTo(state.map);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
        }).addTo(state.map);
        state.cluster = L.markerClusterGroup({ showCoverageOnHover: false, maxClusterRadius: 45 });
        state.map.addLayer(state.cluster);
    }

    function category(type) {
        const normalized = type.toLocaleLowerCase('sv');
        if (/misshandel|våld|rån|mord|brand|skott|vapen|hot/.test(normalized)) return 'urgent';
        if (/trafik|fordon|rattfylleri/.test(normalized)) return 'traffic';
        return 'other';
    }

    function escapeHtml(value) {
        const node = document.createElement('div');
        node.textContent = value ?? '';
        return node.innerHTML;
    }

    function eventCard(event) {
        const date = formatter.format(new Date(event.occurred_at));
        return `<article class="event-card" data-id="${event.id}" tabindex="0">
            <div class="event-top"><span class="event-tag ${category(event.event_type)}">${escapeHtml(event.event_type)}</span><time class="event-time">${date}</time></div>
            <h3>${escapeHtml(event.title)}</h3><p>${escapeHtml(event.summary)}</p>
            <span class="event-location"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z"></path><circle cx="12" cy="10" r="2"></circle></svg>${escapeHtml(event.location_name)}</span>
        </article>`;
    }

    function popupContent(event) {
        const link = event.source_url ? `<a href="${escapeHtml(event.source_url)}" target="_blank" rel="noopener">Läs hos Polisen →</a>` : '';
        return `<span class="popup-time">${formatter.format(new Date(event.occurred_at))} · ${escapeHtml(event.event_type)}</span><h3>${escapeHtml(event.title)}</h3><p>${escapeHtml(event.summary)}</p>${link}`;
    }

    function renderEvents(events) {
        state.events = events;
        state.markers.clear();
        state.cluster.clearLayers();
        elements.count.textContent = numberFormatter.format(events.length);
        elements.list.innerHTML = events.length ? events.map(eventCard).join('') : '<div class="empty-state"><strong>Inga händelser hittades</strong><p>Prova att ändra datum eller filter.</p></div>';

        events.forEach(event => {
            const kind = category(event.event_type);
            const marker = L.marker([event.latitude, event.longitude], { icon: L.divIcon({ className: `incident-marker ${kind}`, iconSize: [24, 24], iconAnchor: [12, 12] }) });
            marker.bindPopup(popupContent(event));
            marker.on('click', () => activateCard(event.id, false));
            state.markers.set(String(event.id), marker);
            state.cluster.addLayer(marker);
        });
        fitMarkers();
    }

    function activateCard(id, openMarker = true) {
        document.querySelectorAll('.event-card.is-active').forEach(card => card.classList.remove('is-active'));
        const card = document.querySelector(`.event-card[data-id="${CSS.escape(String(id))}"]`);
        if (card) { card.classList.add('is-active'); card.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
        if (openMarker) {
            const marker = state.markers.get(String(id));
            if (marker) state.cluster.zoomToShowLayer(marker, () => marker.openPopup());
        }
    }

    function fitMarkers() {
        const bounds = state.cluster.getBounds();
        if (bounds.isValid()) state.map.fitBounds(bounds, { padding: [38, 38], maxZoom: 10 });
    }

    function parameters() {
        const data = new FormData(elements.form);
        const params = new URLSearchParams();
        data.forEach((value, key) => { if (String(value).trim()) params.set(key, String(value)); });
        params.set('limit', '1000');
        return params;
    }

    async function loadEvents() {
        elements.list.innerHTML = '<div class="loading-card"><span></span><p>Hämtar händelser…</p></div>';
        try {
            const response = await fetch(`api/events.php?${parameters()}`);
            if (!response.ok) throw new Error('Request failed');
            const result = await response.json();
            renderEvents(result.data);
            elements.period.textContent = elements.from.value || elements.to.value ? `${elements.from.value || 'början'} – ${elements.to.value || 'idag'}` : 'Alla datum';
        } catch (error) {
            elements.list.innerHTML = '<div class="empty-state"><strong>Kunde inte hämta händelser</strong><p>Kontrollera servern och försök igen.</p></div>';
            showToast('Händelserna kunde inte hämtas.');
        }
    }

    async function loadFilters() {
        try {
            const response = await fetch('api/filters.php');
            if (!response.ok) throw new Error('Request failed');
            const result = await response.json();
            fillSelect(elements.type, result.types);
            fillSelect(elements.location, result.locations);
            elements.total.textContent = numberFormatter.format(result.total_events);
        } catch (error) { showToast('Filterinformationen kunde inte hämtas.'); }
    }

    function fillSelect(select, values) {
        const current = select.value;
        values.forEach(value => {
            const option = document.createElement('option'); option.value = value; option.textContent = value; select.append(option);
        });
        select.value = current;
    }

    function showToast(message) {
        elements.toast.textContent = message; elements.toast.classList.add('is-visible');
        window.clearTimeout(showToast.timer); showToast.timer = window.setTimeout(() => elements.toast.classList.remove('is-visible'), 3500);
    }

    async function loadStatistics() {
        try {
            const params = new URLSearchParams();
            if (elements.from.value) params.set('from', elements.from.value);
            if (elements.to.value) params.set('to', elements.to.value);
            const response = await fetch(`api/statistics.php?${params}`);
            if (!response.ok) throw new Error('Request failed');
            const data = await response.json();
            document.querySelector('#stat-events').textContent = numberFormatter.format(data.totals.events);
            document.querySelector('#stat-types').textContent = numberFormatter.format(data.totals.types);
            document.querySelector('#stat-locations').textContent = numberFormatter.format(data.totals.locations);
            drawCharts(data);
        } catch (error) { showToast('Statistiken kunde inte hämtas.'); }
    }

    function chart(name, canvasId, type, labels, values, options = {}) {
        state.charts[name]?.destroy();
        state.charts[name] = new Chart(document.querySelector(`#${canvasId}`), {
            type,
            data: { labels, datasets: [{ data: values, backgroundColor: type === 'bar' ? '#28795b' : 'rgba(40,121,91,.16)', borderColor: '#28795b', borderWidth: type === 'bar' ? 0 : 2, borderRadius: 5, pointRadius: 2, fill: true, tension: .35 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false }, ticks: { color: '#718078', maxRotation: 0, autoSkip: true, maxTicksLimit: 10 } }, y: { beginAtZero: true, grid: { color: '#edf0ed' }, ticks: { color: '#718078', precision: 0 } } }, ...options }
        });
    }

    function drawCharts(data) {
        chart('daily', 'daily-chart', 'line', data.by_day.map(row => row.label), data.by_day.map(row => row.value));
        chart('type', 'type-chart', 'bar', data.by_type.map(row => row.label), data.by_type.map(row => row.value), { indexAxis: 'y' });
        chart('location', 'location-chart', 'bar', data.by_location.map(row => row.label), data.by_location.map(row => row.value), { indexAxis: 'y' });
        const empty = document.querySelector('#historical-empty');
        empty.style.display = data.historical.length ? 'none' : 'grid';
        document.querySelector('#historical-chart').style.display = data.historical.length ? 'block' : 'none';
        if (data.historical.length) chart('historical', 'historical-chart', 'line', data.historical.map(row => row.year), data.historical.map(row => row.value));
    }

    document.querySelectorAll('.nav-link').forEach(button => button.addEventListener('click', () => {
        document.querySelectorAll('.nav-link, .view').forEach(node => node.classList.remove('is-active'));
        button.classList.add('is-active'); document.querySelector(`#${button.dataset.view}`).classList.add('is-active');
        if (button.dataset.view === 'map-view') window.setTimeout(() => state.map.invalidateSize(), 0);
        if (button.dataset.view === 'statistics-view') loadStatistics();
    }));
    document.querySelector('.brand').addEventListener('click', event => {
        event.preventDefault();
        document.querySelector('.nav-link[data-view="map-view"]').click();
    });
    elements.form.addEventListener('submit', event => { event.preventDefault(); loadEvents(); });
    document.querySelector('#reset-filters').addEventListener('click', () => { elements.form.reset(); elements.from.value = ''; elements.to.value = ''; loadEvents(); });
    document.querySelector('#fit-markers').addEventListener('click', fitMarkers);
    elements.list.addEventListener('click', event => { const card = event.target.closest('.event-card'); if (card) activateCard(card.dataset.id); });
    elements.list.addEventListener('keydown', event => { const card = event.target.closest('.event-card'); if (card && (event.key === 'Enter' || event.key === ' ')) { event.preventDefault(); activateCard(card.dataset.id); } });

    initializeMap();
    Promise.all([loadFilters(), loadEvents()]);
})();
