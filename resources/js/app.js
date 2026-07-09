import 'flowbite';

import Alpine from 'alpinejs';
import flatpickr from 'flatpickr';
import 'flatpickr/dist/flatpickr.min.css';

window.Alpine = Alpine;

/**
 * x-flatpickr — initialise a flatpickr calendar on an input, even when the
 * input is cloned dynamically by x-for (multi-city). The onChange bridge
 * dispatches a native 'input' event so x-model stays in sync, and cleanup()
 * destroys the calendar when the row is removed (no orphaned popups).
 */
Alpine.directive('flatpickr', (el, { expression }, { evaluate, evaluateLater, effect, cleanup }) => {
    // Usage: x-flatpickr="{ model: 'segment.departure' }"
    //        x-flatpickr="{ model: 'returnDate', min: 'segment.departure' }"
    // `model` is the Alpine property this picker reads/writes (replaces x-model
    // so we can keep flatpickr's display in sync). `min` is an optional reactive
    // path whose value becomes this picker's minimum (departure -> return).
    const cfg = expression ? evaluate(expression) : {};
    const modelPath = cfg.model ?? null;
    const minPath = cfg.min ?? null;

    const fp = flatpickr(el, {
        dateFormat: 'Y-m-d',       // value sent to the server
        altInput: true,            // visible field shows a friendly format…
        altFormat: 'j, M Y',       // …e.g. "29, Jun 2026"
        altInputClass: el.className,
        minDate: 'today',
        disableMobile: true,
        onChange: (dates, str) => {
            if (modelPath) {
                evaluate(`${modelPath} = ${JSON.stringify(str)}`);
            }
        },
    });

    if (fp.altInput && el.placeholder) {
        fp.altInput.placeholder = el.placeholder;
    }

    // Keep flatpickr's display in sync when the model is set programmatically
    // (URL restore / recent search), without re-triggering onChange.
    if (modelPath) {
        const readModel = evaluateLater(modelPath);
        effect(() => {
            readModel((value) => {
                if ((value || '') !== fp.input.value) {
                    fp.setDate(value || null, false);
                }
            });
        });
    }

    // Reactive minimum — the chosen departure date becomes the return's min.
    if (minPath) {
        const readMin = evaluateLater(minPath);
        effect(() => {
            readMin((value) => fp.set('minDate', value || 'today'));
        });
    }

    cleanup(() => fp.destroy());
});

const CABIN_LABELS = {
    any: 'Any Class',
    economy: 'Economy',
    premium: 'Premium Economy',
    business: 'Business',
    first: 'First Class',
};

// Compact, URL-safe encoding of the search params for the `?q=` token — the
// same tidy, opaque look as Google Flights' `tfs` param (theirs is a base64
// protobuf; ours is base64url JSON).
const encodeSearch = (obj) => {
    const bytes = new TextEncoder().encode(JSON.stringify(obj));
    let bin = '';
    bytes.forEach((b) => {
        bin += String.fromCharCode(b);
    });
    return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
};

const decodeSearch = (token) => {
    const bin = atob(token.replace(/-/g, '+').replace(/_/g, '/'));
    const bytes = Uint8Array.from(bin, (c) => c.charCodeAt(0));
    return JSON.parse(new TextDecoder().decode(bytes));
};

/**
 * airportField — a per-segment Origin/Destination picker. The canonical value
 * lives on the shared `segment` object (segment.origin / segment.dest), so swap,
 * applySearch and setTripType keep working; this component only owns the
 * open/filter UI state.
 */
Alpine.data('airportField', (segment, field, airports) => ({
    open: false,
    airports: airports ?? [],

    get filtered() {
        const q = (segment[field] ?? '').toLowerCase().trim();
        const list = this.airports;
        if (! q) return list.slice(0, 8);

        return list
            .filter(
                (a) =>
                    a.city.toLowerCase().includes(q) ||
                    a.code.toLowerCase().includes(q) ||
                    (a.country ?? '').toLowerCase().includes(q),
            )
            .slice(0, 8);
    },

    pick(a) {
        segment[field] = `${a.city} (${a.code})`;
        this.open = false;
    },
}));

/**
 * POST JSON with the CSRF token and get back { ok, data }. Shared by the search
 * modal and the booking wizard.
 */
async function postJson(url, body) {
    const res = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify(body),
    });
    const data = await res.json().catch(() => ({}));
    return { ok: res.ok, data };
}

/**
 * flightSearch — state + behaviour for the flight search form and results.
 */
Alpine.data('flightSearch', (config = {}) => ({
    // --- form state ---
    tripType: 'round', // 'round' | 'oneway' | 'multi'
    cabin: 'any',
    paxOpen: false,
    pax: { adults: 1, children: 0, infants: 0 },
    segments: [{ origin: '', dest: '', departure: '' }],
    returnDate: '',

    // --- injected from the blade ---
    airports: config.airports ?? [],
    searchUrl: config.searchUrl ?? '',
    bookingCreateUrl: config.bookingCreateUrl ?? '',

    // --- results state ---
    searched: false,
    collapsed: false,
    loading: false,
    error: null,
    results: [],
    traceId: null,
    currency: 'PHP',
    sort: 'price',
    filters: { stops: [], airlines: [], maxPrice: null },

    // --- fare selection ---
    selecting: null, // resultIndex being handed off to the wizard (loading state)

    // Sample recent searches (display only — clicking one re-fills the form).
    recent: [
        {
            id: 1,
            tripType: 'round',
            cabin: 'economy',
            pax: { adults: 2, children: 0, infants: 0 },
            segments: [{ origin: 'Manila (MNL)', dest: 'Cebu (CEB)', departure: '2026-06-30' }],
            returnDate: '2026-07-04',
            routeText: 'Manila (MNL) → Cebu (CEB)',
            dateText: 'Jun 30 – Jul 4',
            metaText: '2 Pax · Economy',
        },
        {
            id: 2,
            tripType: 'oneway',
            cabin: 'business',
            pax: { adults: 1, children: 0, infants: 0 },
            segments: [{ origin: 'Manila (MNL)', dest: 'Singapore (SIN)', departure: '2026-07-12' }],
            returnDate: '',
            routeText: 'Manila (MNL) → Singapore (SIN)',
            dateText: 'Jul 12',
            metaText: '1 Pax · Business',
        },
        {
            id: 3,
            tripType: 'round',
            cabin: 'economy',
            pax: { adults: 2, children: 1, infants: 0 },
            segments: [{ origin: 'Manila (MNL)', dest: 'Caticlan (MPH)', departure: '2026-08-02' }],
            returnDate: '2026-08-09',
            routeText: 'Manila (MNL) → Caticlan (MPH)',
            dateText: 'Aug 2 – Aug 9',
            metaText: '3 Pax · Economy',
        },
    ],

    // ----- passengers / cabin -----
    get totalPax() {
        return this.pax.adults + this.pax.children + this.pax.infants;
    },

    get paxSummary() {
        return `${this.totalPax} Pax.`;
    },

    get cabinLabel() {
        return CABIN_LABELS[this.cabin] ?? this.cabin;
    },

    setTripType(type) {
        this.tripType = type;

        if (type === 'multi') {
            if (this.segments.length < 2) {
                this.segments.push({ origin: '', dest: '', departure: '' });
            }
        } else {
            this.segments = this.segments.slice(0, 1);
        }
    },

    canInc(kind) {
        if (kind === 'adults') return this.pax.adults < 9;
        if (kind === 'children') return this.pax.children < 8;
        if (kind === 'infants') return this.pax.infants < this.pax.adults;
        return false;
    },

    canDec(kind) {
        const min = kind === 'adults' ? 1 : 0;
        return this.pax[kind] > min;
    },

    inc(kind) {
        if (this.canInc(kind)) this.pax[kind]++;
    },

    dec(kind) {
        if (! this.canDec(kind)) return;
        this.pax[kind]--;
        if (kind === 'adults' && this.pax.infants > this.pax.adults) {
            this.pax.infants = this.pax.adults;
        }
    },

    swap(i) {
        const seg = this.segments[i];
        [seg.origin, seg.dest] = [seg.dest, seg.origin];
    },

    addSegment() {
        if (this.segments.length >= 6) return;
        this.segments.push({ origin: '', dest: '', departure: '' });
    },

    removeSegment(i) {
        if (this.segments.length <= 2) return;
        this.segments.splice(i, 1);
    },

    // ----- search -----
    async submit() {
        this.error = null;
        this.loading = true;
        this.searched = true;
        this.collapsed = true;

        try {
            const res = await fetch(this.searchUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify({
                    tripType: this.tripType,
                    cabin: this.cabin,
                    adults: this.pax.adults,
                    children: this.pax.children,
                    infants: this.pax.infants,
                    segments: this.segments.map((s) => ({ origin: s.origin, dest: s.dest, departure: s.departure })),
                    returnDate: this.tripType === 'round' ? this.returnDate : null,
                }),
            });

            const data = await res.json().catch(() => ({}));

            if (! res.ok) {
                this.results = [];
                this.error =
                    data.message ||
                    (data.errors ? Object.values(data.errors)[0][0] : null) ||
                    'Search failed. Please check your inputs and try again.';
                return;
            }

            this.results = data.results ?? [];
            this.traceId = data.traceId ?? null;
            this.currency = data.currency ?? 'PHP';
            this.resetFilters();
            this.syncUrl();
        } catch (e) {
            this.results = [];
            this.error = 'Network error. Please try again.';
        } finally {
            this.loading = false;
        }
    },

    editSearch() {
        this.collapsed = false;
    },

    // ----- fare selection -----
    // Select hands off to the full-page wizard, which does the single re-price
    // (FareQuote) and shows a price-change gate if the fare changed. We pass the
    // searched fare (oldFare) so the wizard can show the before/after difference.
    selectOffer(offer) {
        if (! this.traceId || this.selecting) return;
        this.selecting = offer.resultIndex; // brief loading state while we navigate away

        const params = new URLSearchParams({
            traceId: this.traceId,
            resultIndex: offer.resultIndex,
            oldFare: Number(offer.price?.offeredFare) || 0,
            airline: offer.airlineName || offer.airlineCode || '',
            from: offer.departure?.code || '',
            to: offer.arrival?.code || '',
            search: this.summary || '', // carried to the wizard's search-context bar
            q: new URLSearchParams(window.location.search).get('q') || '', // so "Edit search" restores it
        });
        window.location = `${this.bookingCreateUrl}?${params.toString()}`;
    },

    // Restore a search from the URL (?q=…) on page load, then re-run it.
    // Within the cache window this is a cache hit (no API); otherwise it
    // auto re-runs the search. Alpine calls init() automatically.
    init() {
        const q = new URLSearchParams(window.location.search).get('q');
        if (! q) return;

        let p = null;
        try {
            p = decodeSearch(q);
        } catch (e) {
            return;
        }
        if (! p) return;

        this.tripType = p.tripType ?? this.tripType;
        this.cabin = p.cabin ?? this.cabin;
        this.pax = {
            adults: p.adults ?? 1,
            children: p.children ?? 0,
            infants: p.infants ?? 0,
        };
        this.returnDate = p.returnDate ?? '';
        if (Array.isArray(p.segments) && p.segments.length) {
            this.segments = p.segments.map((s) => ({
                origin: s.origin ?? '',
                dest: s.dest ?? '',
                departure: s.departure ?? '',
            }));
        }

        this.submit();
    },

    // Reflect the current search in the URL so a refresh / shared link restores it.
    syncUrl() {
        const params = {
            tripType: this.tripType,
            cabin: this.cabin,
            adults: this.pax.adults,
            children: this.pax.children,
            infants: this.pax.infants,
            segments: this.segments.map((s) => ({ origin: s.origin, dest: s.dest, departure: s.departure })),
            returnDate: this.tripType === 'round' ? this.returnDate : null,
        };

        const url = new URL(window.location.href);
        url.searchParams.set('q', encodeSearch(params));
        window.history.replaceState({}, '', url);
    },

    get summary() {
        const seg = this.segments[0] ?? {};
        const route = `${seg.origin || '—'} → ${seg.dest || '—'}`;
        const date = this.formatDate(seg.departure);
        const ret = this.tripType === 'round' && this.returnDate ? ` – ${this.formatDate(this.returnDate)}` : '';
        return `${route} · ${date}${ret} · ${this.totalPax} Pax · ${this.cabinLabel}`;
    },

    // ----- filters / sorting -----
    resetFilters() {
        this.sort = 'price';
        this.filters = { stops: [], airlines: [], maxPrice: this.priceBounds.max };
    },

    get priceBounds() {
        const prices = this.results.map((r) => r.price?.offeredFare ?? 0);
        if (! prices.length) return { min: 0, max: 0 };
        return { min: Math.floor(Math.min(...prices)), max: Math.ceil(Math.max(...prices)) };
    },

    get airlineOptions() {
        const map = {};
        this.results.forEach((r) => {
            if (r.airlineCode) map[r.airlineCode] = r.airlineName || r.airlineCode;
        });
        return Object.entries(map)
            .map(([code, name]) => ({ code, name }))
            .sort((a, b) => a.name.localeCompare(b.name));
    },

    stopBucket(stops) {
        if (stops <= 0) return '0';
        if (stops === 1) return '1';
        return '2';
    },

    get visibleResults() {
        let list = [...this.results];

        if (this.filters.stops.length) {
            list = list.filter((r) => this.filters.stops.includes(this.stopBucket(r.stops)));
        }
        if (this.filters.airlines.length) {
            list = list.filter((r) => this.filters.airlines.includes(r.airlineCode));
        }
        if (this.filters.maxPrice != null) {
            list = list.filter((r) => (r.price?.offeredFare ?? 0) <= Number(this.filters.maxPrice));
        }

        const sorters = {
            price: (a, b) => (a.price?.offeredFare ?? 0) - (b.price?.offeredFare ?? 0),
            duration: (a, b) => (a.duration ?? 0) - (b.duration ?? 0),
            departure: (a, b) =>
                String(a.departure?.time ?? '').localeCompare(String(b.departure?.time ?? '')),
        };

        return list.sort(sorters[this.sort] ?? sorters.price);
    },

    // ----- display helpers -----
    formatTime(iso) {
        if (! iso) return '—';
        const d = new Date(iso);
        return isNaN(d) ? iso : d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
    },

    formatDate(iso) {
        if (! iso) return '';
        const d = new Date(iso);
        return isNaN(d) ? iso : d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    },

    formatDuration(mins) {
        mins = Number(mins) || 0;
        return `${Math.floor(mins / 60)}h ${mins % 60}m`;
    },

    money(amount) {
        return Number(amount || 0).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    },

    stopsLabel(stops) {
        if (stops <= 0) return 'Non-stop';
        return `${stops} stop${stops > 1 ? 's' : ''}`;
    },

    // ----- recent searches (sample data) -----
    applySearch(item) {
        this.tripType = item.tripType;
        this.cabin = item.cabin;
        this.pax = { ...item.pax };
        this.segments = item.segments.map((s) => ({ ...s }));
        this.returnDate = item.returnDate ?? '';
        this.searched = false;
        this.collapsed = false;
        this.error = null;
        this.$refs.form?.scrollIntoView({ behavior: 'smooth' });
    },

    removeRecent(id) {
        this.recent = this.recent.filter((r) => r.id !== id);
    },

    clearRecent() {
        this.recent = [];
    },
}));

// Role permission grid: tracks selected permission IDs and per-module select-all.
Alpine.data('rolePermissions', (config = {}) => ({
    selected: (config.selected ?? []).map(Number),

    allChecked(ids) {
        return ids.length > 0 && ids.every((id) => this.selected.includes(id));
    },

    someChecked(ids) {
        const n = ids.filter((id) => this.selected.includes(id)).length;
        return n > 0 && n < ids.length;
    },

    toggleGroup(ids) {
        this.selected = this.allChecked(ids)
            ? this.selected.filter((id) => !ids.includes(id))
            : [...new Set([...this.selected, ...ids])];
    },
}));

// bookingWizard — the full-page Select Flight → Guest Details → Add-ons → Payment
// → Confirmation flow. Fare + SSR are injected (fetched server-side); it posts to
// /bookings on completion.
Alpine.data('bookingWizard', (config = {}) => ({
    traceId: config.traceId ?? '',
    resultIndex: config.resultIndex ?? '',
    quote: config.quote ?? {},
    ssr: config.ssr ?? { baggage: [], meals: [] },
    summary: config.summary ?? {},
    oldFare: Number(config.oldFare) || 0,
    bookingUrl: config.bookingUrl ?? '',
    flightsUrl: config.flightsUrl ?? '',

    step: 2, // 1 = Select Flight (already done); wizard starts at Guest Details
    priceGateOpen: false, // shown on load if the re-price differs from the searched fare
    passengers: [],
    contact: { email: '', phone: '' },
    submitting: false,
    error: null,
    reference: null,
    showUrl: '#',

    init() {
        if (! this.ssr) this.ssr = { baggage: [], meals: [] };
        this.passengers = this.buildPassengers();
        // The wizard's own FareQuote is the single re-price; gate the flow if it changed.
        if (this.quote?.isPriceChanged) this.priceGateOpen = true;
    },

    get priceDiff() {
        return (Number(this.quote?.price?.offeredFare) || 0) - this.oldFare;
    },

    acceptPrice() {
        this.priceGateOpen = false;
    },

    declinePrice() {
        window.location = this.flightsUrl;
    },

    get currency() {
        return this.quote?.price?.currency ?? 'PHP';
    },

    buildPassengers() {
        const blank = (type) => ({ type, title: 'Mr', firstName: '', lastName: '', gender: '', dateOfBirth: '', passportNo: '', passportExpiry: '', nationality: '', baggage: '', meal: '' });
        const list = [];
        (this.quote?.fareBreakdown ?? []).forEach((b) => {
            const n = Number(b.count) || 0;
            for (let i = 0; i < n; i++) list.push(blank(b.passengerType || 'Adult'));
        });
        if (! list.length) list.push(blank('Adult'));
        return list;
    },

    get hasSsr() {
        return !! (this.ssr && (this.ssr.baggage.length || this.ssr.meals.length));
    },

    get canProceedGuests() {
        return this.passengers.every((p) => p.firstName.trim() && p.lastName.trim()) &&
            this.contact.email.trim() && this.contact.phone.trim();
    },

    next() {
        if (this.step < 4) this.step++;
    },

    back() {
        if (this.step > 2) this.step--;
    },

    ssrPrice(list, code) {
        if (! code || ! list) return 0;
        const opt = list.find((o) => o.code === code);
        return opt ? Number(opt.price) || 0 : 0;
    },

    get ancillaryTotal() {
        if (! this.ssr) return 0;
        return this.passengers.reduce(
            (sum, p) => sum + this.ssrPrice(this.ssr.baggage, p.baggage) + this.ssrPrice(this.ssr.meals, p.meal),
            0,
        );
    },

    get grandTotal() {
        return (Number(this.quote?.price?.offeredFare) || 0) + this.ancillaryTotal;
    },

    money(amount) {
        return Number(amount || 0).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    },

    async complete() {
        if (this.submitting) return;
        this.submitting = true;
        this.error = null;

        const { ok, data } = await postJson(this.bookingUrl, {
            traceId: this.traceId,
            resultIndex: this.resultIndex,
            contact: this.contact,
            passengers: this.passengers,
        });

        this.submitting = false;
        if (! ok) {
            this.error =
                data.message ||
                (data.errors ? Object.values(data.errors)[0][0] : null) ||
                'We could not complete the booking. Please check the details and try again.';
            return;
        }

        this.reference = data.reference;
        this.showUrl = data.redirect ?? '#';
        this.step = 5;
    },
}));

Alpine.start();
