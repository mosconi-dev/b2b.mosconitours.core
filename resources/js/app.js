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
    // so we can keep flatpickr's display in sync). `min` and `max` are optional
    // reactive paths whose values bound this picker (departure -> return, or
    // check-in -> the last night of a capped stay).
    const cfg = expression ? evaluate(expression) : {};
    const modelPath = cfg.model ?? null;
    const minPath = cfg.min ?? null;
    const maxPath = cfg.max ?? null;
    const placeholderPath = cfg.placeholder ?? null;

    // Static bounds. Travel dates are always ahead of today, so that stays the
    // default minimum; a field reaching into the past (date of birth) passes
    // `minDate: null` and caps the other end with `maxDate` instead.
    const minDate = 'minDate' in cfg ? cfg.minDate : 'today';
    const maxDate = cfg.maxDate ?? null;

    const fp = flatpickr(el, {
        dateFormat: 'Y-m-d',       // value sent to the server
        altInput: true,            // visible field shows a friendly format…
        altFormat: 'j, M Y',       // …e.g. "29, Jun 2026"
        altInputClass: el.className,
        minDate,
        maxDate,
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

    // altInput is a visible copy; flatpickr hides the original. An id has to
    // travel with it, or the <label for> above points at a hidden field and
    // clicking the label opens nothing.
    const id = el.id;

    if (fp.altInput && id) {
        el.removeAttribute('id');
        fp.altInput.id = id;
    }

    // Reactive placeholder. The field the user actually sees is flatpickr's
    // altInput, so a plain :placeholder on this input would only ever update
    // the hidden original — the visible copy above is taken once, at init.
    if (placeholderPath) {
        const readPlaceholder = evaluateLater(placeholderPath);
        effect(() => {
            readPlaceholder((value) => {
                el.placeholder = value ?? '';
                if (fp.altInput) fp.altInput.placeholder = value ?? '';
            });
        });
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
            readMin((value) => fp.set('minDate', value || minDate));
        });
    }

    // Reactive maximum, same idea at the other end. Note that flatpickr only
    // redraws on a bounds change — it will not clear a selection that has just
    // fallen outside them, so a component with moving bounds has to keep its own
    // model honest rather than assume the picker did it.
    if (maxPath) {
        const readMax = evaluateLater(maxPath);
        effect(() => {
            readMax((value) => fp.set('maxDate', value || maxDate));
        });
    }

    cleanup(() => {
        fp.destroy();

        if (id) el.id = id;
    });
});

const CABIN_LABELS = {
    any: 'Any Class',
    economy: 'Economy',
    premium: 'Premium Economy',
    business: 'Business',
    first: 'First Class',
};

// Recent searches are capped so the list stays a quick shortcut rather than an
// archive; the server-side cache is bounded to the same size.
const RECENT_MAX = 6;

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

// ----- display helpers -----
// Module-level so the results list and the booking wizard format identically —
// both expose them on their scope for the shared itinerary partial to call.
function formatTime(iso) {
    if (! iso) return '—';
    const d = new Date(iso);
    return isNaN(d) ? iso : d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
}

function formatDate(iso) {
    if (! iso) return '';
    const d = new Date(iso);
    return isNaN(d) ? iso : d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}

/**
 * A calendar day a person can read: "2026-09-04" -> "4 Sept 2026".
 *
 * Day-first with a named month, because an all-numeric date means two different
 * days either side of the Pacific and the ones shown here are deadlines. Accepts a
 * date or a datetime, and keeps the time when there is one — a refund window that
 * shuts at 2pm must not be shown as if it lasted all day.
 */
function formatDay(value) {
    if (! value) return '';

    // Two parsing traps. "2026-09-04 00:00:00" is not a form every browser accepts,
    // and a bare "2026-09-04" is defined as UTC midnight — which is mid-morning in
    // Manila, so it would report a time on a date that never carried one.
    const text = String(value).trim().replace(' ', 'T');
    const d = new Date(text.length === 10 ? `${text}T00:00:00` : text);

    if (isNaN(d)) return String(value);

    const day = d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });

    return d.getHours() || d.getMinutes()
        ? `${day}, ${formatTime(d)}`
        : day;
}

function formatDuration(mins) {
    mins = Number(mins) || 0;
    return `${Math.floor(mins / 60)}h ${mins % 60}m`;
}

// Always two decimals: these amounts are charged against the agency wallet to the
// centavo, and rounding the display would show a figure nobody is actually billed.
function money(amount) {
    return Number(amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// Whole pesos, for a threshold the user drags rather than an amount they are
// charged — priceBounds already floors/ceils to integers, so centavos are noise.
// Never use this for a payable amount; that is what money() is for.
function moneyWhole(amount) {
    return Number(amount || 0).toLocaleString('en-US', { maximumFractionDigits: 0 });
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
    // Origin/destination stay two-up at every width, so on a phone each input
    // fits ~12 characters — the roomy instructional placeholders would clip.
    // Tracked from a media query (see init) rather than guessed from a class.
    isNarrow: false,

    // --- injected from the blade ---
    airports: config.airports ?? [],
    searchUrl: config.searchUrl ?? '',
    bookingCreateUrl: config.bookingCreateUrl ?? '',
    recentUrl: config.recentUrl ?? '',
    fareRuleUrl: config.fareRuleUrl ?? '',
    // Embedded mode (booking wizard's inline "Modify"): pre-fill from
    // `initialQ`, show the collapsed summary, and on submit hand off to the
    // flights page (`redirectUrl`) instead of searching in place.
    embedded: config.embedded ?? false,
    redirectUrl: config.redirectUrl ?? '',
    initialQ: config.initialQ ?? '',

    // --- results state ---
    // Embedded (booking) mode starts "searched + collapsed" so the shared form
    // renders as the collapsed summary bar until the user hits Modify.
    searched: config.embedded ?? false,
    collapsed: config.embedded ?? false,
    loading: false,
    error: null,
    results: [],
    traceId: null,
    resultType: null, // search-only, needed by Book — see seats
    currency: 'PHP',
    sort: 'price',
    filters: { stops: [], airlines: [], maxPrice: null, refundableOnly: false },

    // --- fare selection ---
    selecting: null, // resultIndex being handed off to the wizard (loading state)

    // Recent searches — real per-user history seeded from the server-side cache
    // (injected by the blade) and appended after each successful search. Clicking
    // one re-fills the form.
    recent: config.recent ?? [],

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

    // Placeholder copy for the search fields. These inputs carry no label, so
    // the placeholder is the field's only name: keep the instructional wording
    // where it fits and fall back to bare field names on a phone.
    get hints() {
        return this.isNarrow
            ? { origin: 'From', dest: 'To', departure: 'Departure', returnDate: 'Return' }
            : {
                  origin: 'From — city or airport',
                  dest: 'To — city or airport',
                  departure: 'Pick departure date',
                  returnDate: 'Pick return date',
              };
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

    // The current search as a plain object — the single source of truth shared by
    // the search request, the ?q= URL token, and the hand-off to the booking wizard.
    searchParams() {
        return {
            tripType: this.tripType,
            cabin: this.cabin,
            adults: this.pax.adults,
            children: this.pax.children,
            infants: this.pax.infants,
            segments: this.segments.map((s) => ({ origin: s.origin, dest: s.dest, departure: s.departure })),
            returnDate: this.tripType === 'round' ? this.returnDate : null,
        };
    },

    // Replace the segment values WITHOUT swapping out the objects. Each Origin/
    // Destination field is an airportField that captured its `segment` object at
    // init; a wholesale `this.segments = ...` would leave those components bound
    // to stale objects (typed value goes to the new object, but the autocomplete
    // filters on the old one → "No matches"). Mutating in place keeps identity.
    setSegments(list) {
        const next = list.map((s) => ({
            origin: s.origin ?? '',
            dest: s.dest ?? '',
            departure: s.departure ?? '',
        }));

        if (next.length < this.segments.length) this.segments.splice(next.length);
        next.forEach((s, i) => {
            if (this.segments[i]) {
                Object.assign(this.segments[i], s);
            } else {
                this.segments.push(s);
            }
        });
    },

    // ----- search -----
    async submit() {
        // Embedded (booking wizard) form: hand the edited search off to the
        // flights page, which restores it and runs the search / shows results.
        if (this.redirectUrl) {
            this.loading = true;
            window.location = `${this.redirectUrl}?q=${encodeSearch(this.searchParams())}`;
            return;
        }

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
                body: JSON.stringify(this.searchParams()),
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
            this.resultType = data.resultType ?? null;
            this.currency = data.currency ?? 'PHP';
            this.resetFilters();
            this.syncUrl();
            this.recordRecent();
        } catch (e) {
            this.results = [];
            this.error = 'Network error. Please try again.';
        } finally {
            this.loading = false;
        }
    },

    // The search as it stood when the user hit Modify. Cancel puts the form back
    // to it and re-collapses, so an abandoned edit leaves the results (and the
    // wizard's carried search) exactly as they were.
    beforeModify: null,

    modifySearch() {
        this.beforeModify = this.searchParams();
        this.collapsed = false;
    },

    cancelModify() {
        if (this.beforeModify) this.applyParams(this.beforeModify);
        this.beforeModify = null;
        this.collapsed = true;
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
            q: encodeSearch(this.searchParams()), // exact search that produced this offer, so "Modify" restores it
            // Per-segment seat availability, in segment order. TBO drops this from the
            // FareQuote response but wants it back on Book, so search is the only place
            // it can be captured — see the seats_available migration.
            seats: (offer.trips ?? []).flatMap((t) => t.segments ?? []).map((s) => s.seats ?? '').join(','),
            resultType: this.resultType ?? '',
        });
        window.location = `${this.bookingCreateUrl}?${params.toString()}`;
    },

    // ----- fare rules -----
    // Search only carries the coarse IsRefundable flag, so the actual cancellation
    // policy is pulled per result on demand (FareRule). Successful loads are cached
    // by result index — rules don't change inside the TraceId window — while
    // failures stay uncached so the user can retry.
    fareRules: {},
    fareRuleErrors: {},
    fareRuleLoading: null,

    async loadFareRule(resultIndex) {
        if (this.fareRules[resultIndex] || this.fareRuleLoading) return;

        if (! this.traceId || ! this.fareRuleUrl) {
            this.fareRuleErrors[resultIndex] = 'This search has expired. Please search again.';
            return;
        }

        this.fareRuleLoading = resultIndex;
        delete this.fareRuleErrors[resultIndex];

        try {
            const { ok, data } = await postJson(this.fareRuleUrl, { traceId: this.traceId, resultIndex });

            if (ok) {
                this.fareRules[resultIndex] = data.rules ?? [];
            } else {
                this.fareRuleErrors[resultIndex] = data.message ?? 'We could not load the fare rules.';
            }
        } catch (e) {
            this.fareRuleErrors[resultIndex] = 'We could not load the fare rules. Please try again.';
        } finally {
            this.fareRuleLoading = null;
        }
    },

    // Decode a ?q= token and fill the form from it. Returns false if the token
    // is missing/invalid. Shared by the flights page (URL restore) and the
    // booking wizard's embedded modify form (config restore).
    restoreFromQ(q) {
        if (! q) return false;

        let p = null;
        try {
            p = decodeSearch(q);
        } catch (e) {
            return false;
        }
        if (! p) return false;

        this.applyParams(p);

        return true;
    },

    // Fill the form from a searchParams()-shaped object.
    applyParams(p) {
        this.tripType = p.tripType ?? this.tripType;
        this.cabin = p.cabin ?? this.cabin;
        this.pax = {
            adults: p.adults ?? 1,
            children: p.children ?? 0,
            infants: p.infants ?? 0,
        };
        this.returnDate = p.returnDate ?? '';
        if (Array.isArray(p.segments) && p.segments.length) {
            this.setSegments(p.segments);
        }
    },

    // Alpine calls init() automatically.
    init() {
        // Placeholder width tracking — set up before the embedded early return
        // below, since the wizard's inline search form needs it too. `sm` is
        // where the form stops being cramped (tailwind's 640px breakpoint).
        const narrow = window.matchMedia('(max-width: 639px)');
        this.isNarrow = narrow.matches;
        narrow.addEventListener('change', (e) => (this.isNarrow = e.matches));

        // Embedded in the booking wizard: pre-fill from the passed search. The
        // searched/collapsed defaults (set from `embedded`) already render the
        // form as the collapsed summary; `searched=true` makes the shared form's
        // `!searched || !collapsed` visibility reduce to `!collapsed`, so the
        // Modify toggle works. Never auto-searches (submit navigates away).
        if (this.embedded) {
            this.restoreFromQ(this.initialQ);
            return;
        }

        // Flights page: restore from ?q= and re-run the search.
        const urlParams = new URLSearchParams(window.location.search);
        if (! this.restoreFromQ(urlParams.get('q'))) return;

        this.submit();
    },

    // Reflect the current search in the URL so a refresh / shared link restores it.
    syncUrl() {
        const url = new URL(window.location.href);
        url.searchParams.set('q', encodeSearch(this.searchParams()));
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
        this.filters = { stops: [], airlines: [], maxPrice: this.priceBounds.max, refundableOnly: false };
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
        // Search-level IsRefundable is the airline's coarse flag — FareQuote is the
        // binding one — so this narrows the shortlist rather than promising a refund.
        // The sidebar's "X of Y flights" count keeps the hidden results visible.
        if (this.filters.refundableOnly) {
            list = list.filter((r) => r.isRefundable);
        }

        const sorters = {
            price: (a, b) => (a.price?.offeredFare ?? 0) - (b.price?.offeredFare ?? 0),
            duration: (a, b) => (a.duration ?? 0) - (b.duration ?? 0),
            departure: (a, b) =>
                String(a.departure?.time ?? '').localeCompare(String(b.departure?.time ?? '')),
        };

        return list.sort(sorters[this.sort] ?? sorters.price);
    },

    // ----- display helpers (shared module functions, exposed on the scope) -----
    formatTime,
    formatDate,
    formatDuration,
    money,
    moneyWhole, // the max-price filter label only

    stopsLabel(stops) {
        if (stops <= 0) return 'Non-stop';
        return `${stops} stop${stops > 1 ? 's' : ''}`;
    },

    // ----- recent searches (per-user history in the server cache) -----
    applySearch(item) {
        this.tripType = item.tripType;
        this.cabin = item.cabin;
        this.pax = { ...item.pax };
        this.setSegments(item.segments);
        this.returnDate = item.returnDate ?? '';
        this.searched = false;
        this.collapsed = false;
        this.error = null;
        this.$refs.form?.scrollIntoView({ behavior: 'smooth' });
    },

    removeRecent(id) {
        this.recent = this.recent.filter((r) => r.id !== id);
        this.saveRecent();
    },

    clearRecent() {
        this.recent = [];
        this.saveRecent();
    },

    // Persist the list to the per-user cache (fire-and-forget — history is a
    // best-effort convenience, so a failed write is silently ignored).
    saveRecent() {
        if (! this.recentUrl) return;
        postJson(this.recentUrl, { recent: this.recent }).catch(() => {});
    },

    // Snapshot the current (successful) search and push it to the top of the
    // list. The dedup key doubles as the entry id, so re-running an identical
    // search just bumps it rather than duplicating.
    recordRecent() {
        const seg = this.segments[0] ?? {};
        if (! seg.origin || ! seg.dest) return; // ignore incomplete forms

        const params = {
            tripType: this.tripType,
            cabin: this.cabin,
            pax: { ...this.pax },
            segments: this.segments.map((s) => ({ origin: s.origin, dest: s.dest, departure: s.departure })),
            returnDate: this.tripType === 'round' ? this.returnDate : '',
        };

        const id = this.recentKey(params);
        const entry = {
            id,
            ...params,
            routeText: this.recentRoute(params),
            dateText: this.recentDates(params),
            metaText: `${this.totalPax} Pax · ${this.cabinLabel}`,
        };

        this.recent = [entry, ...this.recent.filter((r) => r.id !== id)].slice(0, RECENT_MAX);
        this.saveRecent();
    },

    recentKey(p) {
        return [
            p.tripType,
            p.cabin,
            p.pax.adults,
            p.pax.children,
            p.pax.infants,
            p.returnDate || '',
            ...p.segments.map((s) => `${s.origin}>${s.dest}@${s.departure}`),
        ].join('~');
    },

    recentRoute(p) {
        const seg = p.segments;
        if (p.tripType === 'multi' && seg.length > 1) {
            return [seg[0].origin, ...seg.map((s) => s.dest)].filter(Boolean).join(' → ');
        }
        return `${seg[0]?.origin || '—'} → ${seg[0]?.dest || '—'}`;
    },

    recentDates(p) {
        const dep = this.formatDate(p.segments[0]?.departure);
        if (p.tripType === 'round' && p.returnDate) {
            return `${dep} – ${this.formatDate(p.returnDate)}`;
        }
        if (p.tripType === 'multi' && p.segments.length > 1) {
            return `${dep} +${p.segments.length - 1}`;
        }
        return dep;
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

// logoDropzone — drag-and-drop (or click-to-browse) image picker.
//
// It drives a real <input type="file">, assigning the dropped file through a
// DataTransfer so the surrounding form submits normally with no JS on the server
// side. With JS unavailable the bare input still works.
Alpine.data('logoDropzone', (config = {}) => ({
    dragging: false,
    error: '',
    // Existing logo URL when editing; replaced by a local object URL on pick.
    preview: config.preview || '',
    fileName: '',
    objectUrl: '',
    accept: config.accept ?? ['image/jpeg', 'image/png', 'image/webp'],
    maxBytes: (config.maxKb ?? 2048) * 1024,
    removed: false,

    pick() {
        this.$refs.input.click();
    },

    onDrop(event) {
        this.dragging = false;
        this.assign(event.dataTransfer.files[0]);
    },

    onChange() {
        this.assign(this.$refs.input.files[0]);
    },

    assign(file) {
        if (!file) return;

        if (!this.accept.includes(file.type)) {
            this.fail('That file type is not supported. Use a JPG, PNG or WEBP image.');
            return;
        }

        if (file.size > this.maxBytes) {
            this.fail(`That image is ${this.mb(file.size)}MB — the limit is ${this.mb(this.maxBytes)}MB.`);
            return;
        }

        // A drop does not populate the input, so hand the file over explicitly.
        const transfer = new DataTransfer();
        transfer.items.add(file);
        this.$refs.input.files = transfer.files;

        this.error = '';
        this.removed = false;
        this.fileName = file.name;
        this.swapPreview(URL.createObjectURL(file));
    },

    clear() {
        this.$refs.input.value = '';
        this.error = '';
        this.fileName = '';
        this.removed = true;
        this.swapPreview('');
    },

    // Revoke the previous object URL so repeated picks do not leak blobs.
    swapPreview(url) {
        if (this.objectUrl) URL.revokeObjectURL(this.objectUrl);
        this.objectUrl = url.startsWith('blob:') ? url : '';
        this.preview = url;
    },

    fail(message) {
        this.error = message;
        this.$refs.input.value = '';
    },

    mb(bytes) {
        return (bytes / 1024 / 1024).toFixed(1);
    },
}));

// bookingWizard — the full-page Select Flight → Guest Details → Add-ons → Payment
// → Confirmation flow. Fare + SSR are injected (fetched server-side); it posts to
// /bookings on completion.
Alpine.data('bookingWizard', (config = {}) => ({
    traceId: config.traceId ?? '',
    // Both are search-only facts the Book payload needs and FareQuote does not
    // return. They ride the query string into this page and must be declared here,
    // or the submit below posts undefined.
    resultType: config.resultType ?? null,
    seats: config.seats ?? [],
    resultIndex: config.resultIndex ?? '',
    quote: config.quote ?? {},
    ssr: config.ssr ?? { baggage: [], meals: [] },
    summary: config.summary ?? {},
    oldFare: Number(config.oldFare) || 0,
    bookingUrl: config.bookingUrl ?? '',
    flightsUrl: config.flightsUrl ?? '',
    changeFlightUrl: config.changeFlightUrl ?? config.flightsUrl ?? '',

    step: 2, // 1 = Select Flight (already done); wizard starts at Guest Details
    priceGateOpen: false, // shown on load if the re-price differs from the searched fare
    detailsOpen: false, // full itinerary + fare conditions under the summary card
    passengers: [],
    // Address/mobile are collected once and copied onto every passenger server-side —
    // TBO wants them per passenger, but they do not vary per passenger.
    contact: { email: '', phone: '', mobileCountryCode: '63', addressLine1: '', addressLine2: '', city: '', countryCode: 'PH' },
    guestTab: 'contact', // active Guest-details sub-section: 'contact' or a passenger index
    submitting: false,
    error: null,
    reference: null,
    showUrl: '#',

    init() {
        if (! this.ssr) this.ssr = { baggage: [], meals: [] };
        this.passengers = this.buildPassengers();
        // The wizard's own FareQuote is the single re-price; gate the flow if it changed.
        if (this.quote?.isPriceChanged) this.priceGateOpen = true;

        // Restore the step from ?step=, then normalise the URL so a missing or
        // out-of-range value is rewritten rather than left to mislead.
        this.step = this.clampStep(this.stepFromUrl());
        this.syncUrl(false);

        // Back/Forward moves between wizard steps instead of leaving the page.
        window.addEventListener('popstate', () => {
            if (this.reference) return; // booking already made — stay on Confirmation
            this.step = this.clampStep(this.stepFromUrl());
        });
    },

    stepFromUrl() {
        return new URLSearchParams(window.location.search).get('step');
    },

    // Highest step the current state can legitimately show. Guest details are not
    // persisted across a reload, so a deep link to Add-ons/Payment on a fresh load
    // lands on Guest Details instead of a Payment step with empty passengers.
    get maxStep() {
        if (this.reference) return 5;

        return this.canProceedGuests ? 4 : 2;
    },

    clampStep(value) {
        const step = Number(value);
        if (! Number.isInteger(step)) return 2;

        return Math.min(Math.max(step, 2), this.maxStep);
    },

    // Reflect the current step in the URL so refresh, Back/Forward and shared links
    // land on the right step. push=true adds a history entry (user navigation);
    // false rewrites the current one (init normalisation, confirmation).
    syncUrl(push = true) {
        const url = new URL(window.location.href);
        url.searchParams.set('step', this.step);
        window.history[push ? 'pushState' : 'replaceState']({ step: this.step }, '', url);
    },

    get priceDiff() {
        return (Number(this.quote?.price?.offeredFare) || 0) - this.oldFare;
    },

    acceptPrice() {
        this.priceGateOpen = false;
    },

    declinePrice() {
        window.location = this.changeFlightUrl;
    },

    get currency() {
        return this.quote?.price?.currency ?? 'PHP';
    },

    buildPassengers() {
        const blank = (type) => ({ type, title: 'Mr', firstName: '', lastName: '', gender: '', dateOfBirth: '', documentNumber: '', documentExpiry: '', documentIssueCountry: '', documentIssueDate: '', nationality: '', baggage: [], meal: [], isLeadPax: false });
        const list = [];
        (this.quote?.fareBreakdown ?? []).forEach((b) => {
            const n = Number(b.count) || 0;
            for (let i = 0; i < n; i++) list.push(blank(b.passengerType || 'Adult'));
        });
        if (! list.length) list.push(blank('Adult'));

        // Default the first adult to lead; the server does the same if none is set.
        const firstAdult = list.findIndex((p) => p.type === 'Adult');
        if (firstAdult !== -1) list[firstAdult].isLeadPax = true;

        return list;
    },

    /** Exactly one lead guest — selecting one clears the rest. */
    setLeadPax(index) {
        this.passengers.forEach((p, i) => { p.isLeadPax = i === index; });
    },

    get hasSsr() {
        return !! (this.ssr && (this.ssr.baggage.length || this.ssr.meals.length));
    },

    get canProceedGuests() {
        return this.passengers.every((p) => p.firstName.trim() && p.lastName.trim()) &&
            this.contactComplete;
    },

    // Guest-details sub-sections: contact first, then one per passenger.
    get guestOrder() {
        return ['contact', ...this.passengers.map((_, i) => i)];
    },

    get guestActiveIndex() {
        return this.guestOrder.indexOf(this.guestTab);
    },

    get guestIsLast() {
        return this.guestActiveIndex === this.guestOrder.length - 1;
    },

    get contactComplete() {
        const c = this.contact;

        // addressLine2 is the only optional field here — the rest are mandatory on
        // TBO's Book payload, so a gap would only surface as a supplier rejection.
        return !! (c.email.trim() && c.phone.trim() && c.mobileCountryCode.trim() &&
            c.addressLine1.trim() && c.city.trim() && c.countryCode.trim());
    },

    /**
     * An international itinerary always needs a passport; a domestic one needs a
     * government ID whenever the fare asks for a document at all. Mirrors the check
     * BookingService re-runs against a fresh quote at submit.
     */
    get documentRequired() {
        return !! (this.quote?.isPassportMandatory || this.quote?.isDomestic === false);
    },

    passengerComplete(p) {
        if (! p || ! p.firstName.trim() || ! p.lastName.trim()) return false;

        // Always: TBO rejects a blank one at Ticket, by which point the booking has
        // been paid for and may already hold a PNR.
        if (! p.dateOfBirth?.trim()) return false;

        return this.documentRequired
            ? !! (p.documentNumber?.trim() && p.documentExpiry?.trim())
            : true;
    },

    get currentSectionComplete() {
        return this.guestTab === 'contact'
            ? this.contactComplete
            : this.passengerComplete(this.passengers[this.guestTab]);
    },

    guestAdvance() {
        if (! this.guestIsLast) {
            this.guestTab = this.guestOrder[this.guestActiveIndex + 1];
        } else if (this.canProceedGuests) {
            this.next();
        }
    },

    guestRetreat() {
        if (this.guestActiveIndex > 0) {
            this.guestTab = this.guestOrder[this.guestActiveIndex - 1];
        }
    },

    next() {
        if (this.step >= 4) return;
        this.step++;
        this.syncUrl();
    },

    back() {
        if (this.step <= 2) return;
        this.step--;
        this.syncUrl();
    },

    /** Total price of the keys a passenger holds for one kind of add-on. */
    ssrPrice(kind, keys) {
        return (keys ?? []).reduce((sum, key) => {
            const option = this.addOnOption(kind, key);
            return sum + (option ? Number(option.price) || 0 : 0);
        }, 0);
    },

    get ancillaryTotal() {
        if (! this.ssr) return 0;
        return this.passengers.reduce((sum, p) => sum + this.passengerAddOnTotal(p), 0);
    },

    /** What one passenger's chosen add-ons come to, across every leg. */
    passengerAddOnTotal(p) {
        if (! this.ssr) return 0;
        return this.ssrPrice('baggage', this.addOnKeys(p, 'baggage'))
            + this.ssrPrice('meal', this.addOnKeys(p, 'meal'));
    },

    /**
     * The card's summary: one line per leg the add-on is offered on.
     *
     * Every leg appears, chosen or not. A leg showing "—" is the point — an agent has
     * to be able to see at a glance that the return has no meal, which a list of only
     * what was bought cannot show.
     */
    addOnLines(p, kind) {
        const chosen = {};

        this.addOnChoices(p, kind).forEach((o) => {
            chosen[`${o.origin}|${o.destination}`] = o;
        });

        return this.addOnLegs(kind).map((leg) => {
            const option = chosen[leg.key];

            return {
                key: leg.key,
                route: `${leg.origin} → ${leg.destination}`,
                // Extra baggage is *added to* the fare's allowance, so it carries a
                // plus. Bare "5 kg" beside "30 KG included" reads as a downgrade.
                label: option ? (kind === 'baggage' ? `+${option.label}` : option.label) : null,
                price: option ? Number(option.price) || 0 : 0,
            };
        });
    },

    /** True once the passenger has chosen anything at all for this kind. */
    addOnChosen(p, kind) {
        return this.addOnKeys(p, kind).length > 0;
    },

    /**
     * The allowance already in the fare.
     *
     * Shown on the baggage card whether or not extra was bought: the question an
     * agent is asked is how much the passenger *has*, not how much was added.
     */
    get includedBaggage() {
        return this.quote?.baggage || null;
    },

    // ----- passenger dates ---------------------------------------------------
    // Three selects rather than a calendar, for every date on a passenger. All of them
    // are years away from today — a birth date decades back, a passport a decade
    // either side — and a picker makes an agent page through hundreds of months to
    // reach one. The parts are held here while they are half-filled, because a partial
    // date cannot be represented in the ISO string the passenger actually carries.

    dateParts: {}, // "index:field" -> { d, m, y }

    /**
     * Per field: how far the year list runs from today, and which side of today the
     * finished date has to fall on. A passport that expired is not a date-entry
     * mistake to be tolerated — it cannot be travelled on.
     */
    dateFields: {
        dateOfBirth: {
            from: -120, to: 0, direction: 'past',
            invalid: 'A date of birth cannot be in the future.',
        },
        documentExpiry: {
            from: 0, to: 15, direction: 'future',
            invalid: 'This document has already expired — it cannot be used to travel.',
        },
        documentIssueDate: {
            from: -20, to: 0, direction: 'past',
            invalid: 'An issue date cannot be in the future.',
        },
    },

    dobMonths: [
        { value: '01', name: 'January' }, { value: '02', name: 'February' },
        { value: '03', name: 'March' }, { value: '04', name: 'April' },
        { value: '05', name: 'May' }, { value: '06', name: 'June' },
        { value: '07', name: 'July' }, { value: '08', name: 'August' },
        { value: '09', name: 'September' }, { value: '10', name: 'October' },
        { value: '11', name: 'November' }, { value: '12', name: 'December' },
    ],

    dateYears(field) {
        const spec = this.dateFields[field];
        const now = new Date().getFullYear();
        const years = [];

        for (let y = now + spec.from; y <= now + spec.to; y++) years.push(String(y));

        // Nearest first: an expiry is a year or two out, a birth date a year or two
        // back. Either way the useful end should not need scrolling to.
        return spec.direction === 'future' ? years : years.reverse();
    },

    /** Days that exist in the chosen month, so 31 February is never offered. */
    dateDays(index, field) {
        const year = Number(this.datePart(index, field, 'y'));
        const month = Number(this.datePart(index, field, 'm'));

        // No month yet: 31 keeps every day reachable. A missing year is treated as a
        // leap year so 29 February stays selectable until the year says otherwise.
        const count = month ? new Date(year || 2000, month, 0).getDate() : 31;

        return Array.from({ length: count }, (_, k) => String(k + 1).padStart(2, '0'));
    },

    datePart(index, field, part) {
        const cached = this.dateParts[`${index}:${field}`];
        if (cached && cached[part] !== undefined) return cached[part];

        const iso = this.passengers[index]?.[field];
        if (! iso) return '';

        const [y, m, d] = String(iso).split('-');
        return { y, m, d }[part] ?? '';
    },

    setDatePart(index, field, part, value) {
        const key = `${index}:${field}`;

        const parts = {
            y: this.datePart(index, field, 'y'),
            m: this.datePart(index, field, 'm'),
            d: this.datePart(index, field, 'd'),
            ...(this.dateParts[key] ?? {}),
            [part]: value,
        };

        // Changing month or year can strand the day — 31 January to February.
        const available = Number(parts.m)
            ? new Date(Number(parts.y) || 2000, Number(parts.m), 0).getDate()
            : 31;

        if (Number(parts.d) > available) parts.d = '';

        this.dateParts[key] = parts;
        this.passengers[index][field] = this.composeDate(parts, field);
    },

    /** Only a complete date on the right side of today reaches the passenger. */
    composeDate(parts, field) {
        if (! parts.y || ! parts.m || ! parts.d) return '';

        const iso = `${parts.y}-${parts.m}-${parts.d}`;
        const when = new Date(`${iso}T00:00:00`);
        const today = new Date(new Date().toDateString());

        if (this.dateFields[field].direction === 'past' && when > today) return '';
        if (this.dateFields[field].direction === 'future' && when < today) return '';

        return iso;
    },

    /**
     * Why the field is still empty, when the agent has clearly filled something in.
     * Silence here reads as the form ignoring them.
     */
    dateError(index, field) {
        const parts = this.dateParts[`${index}:${field}`];
        if (! parts) return '';

        const filled = ['y', 'm', 'd'].filter((k) => parts[k]);
        if (! filled.length || this.passengers[index]?.[field]) return '';

        return filled.length < 3
            ? 'Choose a month, day and year.'
            : this.dateFields[field].invalid;
    },

    /** The resolved options a passenger holds for this kind, in leg order. */
    addOnChoices(p, kind) {
        return this.addOnKeys(p, kind)
            .map((key) => this.addOnOption(kind, key))
            .filter(Boolean);
    },

    /** Selections are a list now; tolerate the single-code shape from before. */
    addOnKeys(p, kind) {
        const value = p?.[kind];
        if (! value) return [];
        return Array.isArray(value) ? value : [value];
    },

    addOnOption(kind, key) {
        if (! key || ! this.ssr) return null;
        const list = (kind === 'baggage' ? this.ssr.baggage : this.ssr.meals) ?? [];
        return list.find((o) => o.key === key) ?? list.find((o) => o.code === key) ?? null;
    },

    // ----- the picker dialog -------------------------------------------------
    // Holds a draft so browsing options cannot change the price; only Select commits.

    // draft is a map of legKey -> option key (or '' for none on that leg), so a
    // passenger can take a meal outbound and nothing back.
    addOnPicker: null, // { index, kind, draft }

    openAddOnPicker(index, kind) {
        const draft = {};
        const legs = this.addOnLegs(kind);

        legs.forEach((leg) => { draft[leg.key] = ''; });

        this.addOnChoices(this.passengers[index], kind).forEach((o) => {
            draft[`${o.origin}|${o.destination}`] = o.key;
        });

        this.addOnPicker = { index, kind, draft, activeLeg: legs[0]?.key ?? null };
    },

    cancelAddOnPicker() {
        this.addOnPicker = null;
    },

    confirmAddOnPicker() {
        if (! this.addOnPicker) return;
        const { index, kind, draft } = this.addOnPicker;

        if (this.passengers[index]) {
            this.passengers[index][kind] = Object.values(draft).filter(Boolean);
        }

        this.addOnPicker = null;
    },

    /**
     * The legs this kind of add-on is offered on, in the order TBO listed them.
     *
     * Per kind, never shared: on a return with a layover TBO sold meals per flight
     * (DEL→DXB, DXB→BOM, BOM→DEL) but baggage per direction (DEL→DXB, DXB→DEL) —
     * a checked bag travels through the connection, a meal does not.
     */
    addOnLegs(kind) {
        const list = (kind === 'baggage' ? this.ssr?.baggage : this.ssr?.meals) ?? [];
        const legs = [];

        list.forEach((o) => {
            const key = `${o.origin}|${o.destination}`;
            if (! legs.some((l) => l.key === key)) {
                legs.push({
                    key,
                    origin: o.origin,
                    destination: o.destination,
                    label: this.legLabel(o.origin, o.destination),
                });
            }
        });

        return legs;
    },

    /**
     * Name a leg against the itinerary, so a connection is obvious.
     *
     * Returns null when nothing matches, which is not a bug: TBO's baggage row for a
     * connecting return is `DXB→DEL`, a route that appears in no single segment of
     * the flight. The route itself is then the only honest label.
     */
    legLabel(origin, destination) {
        const trips = this.quote?.trips ?? [];

        for (const trip of trips) {
            const segments = trip.segments ?? [];
            if (! segments.length) continue;

            const direction = trips.length > 1
                ? (trip.direction === 'inbound' ? 'Return' : 'Outbound')
                : null;

            const at = segments.findIndex(
                (s) => s.origin?.code === origin && s.destination?.code === destination,
            );

            if (at >= 0) {
                if (segments.length === 1) return direction;
                return direction
                    ? `${direction} · flight ${at + 1} of ${segments.length}`
                    : `Flight ${at + 1} of ${segments.length}`;
            }

            // The whole direction end to end — how baggage is sold through a connection.
            if (segments[0].origin?.code === origin
                && segments[segments.length - 1].destination?.code === destination) {
                return direction ? `${direction} · all flights` : 'All flights';
            }
        }

        return null;
    },

    get addOnPickerOptions() {
        if (! this.addOnPicker || ! this.ssr) return [];
        return (this.addOnPicker.kind === 'baggage' ? this.ssr.baggage : this.ssr.meals) ?? [];
    },

    /**
     * The picker's legs, as tabs.
     *
     * TBO sends options per leg, so a Spicejet DEL–DXB return came back as 41 meals —
     * the same dishes repeated across three flights at different prices. One scrolling
     * list showed "Veg Sandwich" three times with nothing to tell them apart; a tab
     * per leg makes the flight the agent is choosing for explicit.
     */
    get addOnPickerTabs() {
        if (! this.addOnPicker) return [];

        return this.addOnLegs(this.addOnPicker.kind).map((leg) => ({
            ...leg,
            route: `${leg.origin} → ${leg.destination}`,
            chosen: !! this.addOnPicker.draft[leg.key],
        }));
    },

    /** Options for the tab currently open. */
    get addOnPickerActiveOptions() {
        if (! this.addOnPicker?.activeLeg) return [];

        return this.addOnPickerOptions.filter(
            (o) => `${o.origin}|${o.destination}` === this.addOnPicker.activeLeg,
        );
    },

    get addOnPickerActiveLeg() {
        return this.addOnPickerTabs.find((t) => t.key === this.addOnPicker?.activeLeg) ?? null;
    },

    /** Most of these are free; "PHP 0.00" reads like a bug next to a real price. */
    addOnPriceLabel(price) {
        return Number(price) > 0 ? `${this.currency} ${this.money(price)}` : 'Free';
    },

    get addOnPickerPassenger() {
        return this.addOnPicker ? this.passengers[this.addOnPicker.index] : null;
    },

    get addOnPickerTitle() {
        if (! this.addOnPicker) return '';
        const who = this.addOnPickerPassenger;
        const name = who?.firstName || `Guest ${this.addOnPicker.index + 1}`;
        return `${this.addOnPicker.kind === 'baggage' ? 'Checked baggage' : 'Meal'} for ${name}`;
    },

    get addOnPickerSubtitle() {
        if (! this.addOnPicker) return '';
        return this.addOnPicker.kind === 'baggage'
            ? 'Charged per passenger, on top of the allowance already in the fare.'
            : 'Ordered in advance and served on board.';
    },

    get addOnPickerNoneTitle() {
        return this.addOnPicker?.kind === 'baggage' ? 'No extra baggage' : 'No meal';
    },

    get addOnPickerNoneNote() {
        if (this.addOnPicker?.kind !== 'baggage') return 'Nothing ordered';
        return this.quote?.baggage ? `${this.quote.baggage} already included` : 'Cabin bag only';
    },

    /** The footer's running answer, so Select is never a leap of faith. */
    get addOnPickerDraftLabel() {
        if (! this.addOnPicker) return '';

        const chosen = Object.values(this.addOnPicker.draft)
            .filter(Boolean)
            .map((key) => this.addOnOption(this.addOnPicker.kind, key))
            .filter(Boolean);

        if (! chosen.length) return this.addOnPickerNoneTitle;

        const total = chosen.reduce((sum, o) => sum + (Number(o.price) || 0), 0);

        return chosen.length === 1
            ? `${chosen[0].label} · ${this.addOnPriceLabel(total)}`
            : `${chosen.length} legs · ${this.addOnPriceLabel(total)}`;
    },

    // ----- add-on styling ----------------------------------------------------

    /** A passenger's summary card. Tinted once something is actually chosen. */
    addOnTileClass(chosen) {
        return 'flex w-full items-center gap-3 rounded-xl border p-3 text-left transition ' +
            (chosen
                ? 'border-blue-300 bg-blue-50/50 hover:border-blue-400'
                : 'border-gray-200 bg-white hover:border-gray-300 hover:bg-gray-50');
    },

    addOnIconClass(chosen) {
        return 'flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ' +
            (chosen ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-400');
    },

    /** One option row inside the picker. */
    addOnRowClass(selected) {
        return 'flex w-full items-center gap-3 rounded-lg border p-3 text-left transition ' +
            (selected
                ? 'border-blue-600 bg-blue-50/60 ring-1 ring-blue-600'
                : 'border-gray-200 bg-white hover:border-gray-300 hover:bg-gray-50');
    },

    addOnDotClass(selected) {
        return 'h-4 w-4 shrink-0 rounded-full border-[5px] transition ' +
            (selected ? 'border-blue-600' : 'border-gray-300');
    },

    get grandTotal() {
        return (Number(this.quote?.price?.offeredFare) || 0) + this.ancillaryTotal;
    },

    // ----- wallet ------------------------------------------------------------
    // config.wallet is null for platform staff, who are not charged at all.

    get hasWallet() {
        return this.wallet !== null && this.wallet !== undefined;
    },

    get walletBalance() {
        return this.hasWallet ? Number(this.wallet.balance) || 0 : 0;
    },

    // Money compared in centavos: subtracting 2dp floats is not exact, and being
    // a hundredth out here would either block a payable booking or wave through
    // one the server then rejects.
    get walletRemaining() {
        return (Math.round(this.walletBalance * 100) - Math.round(this.grandTotal * 100)) / 100;
    },

    get walletShort() {
        return this.hasWallet && this.walletRemaining < 0;
    },

    get walletShortfall() {
        return this.walletShort ? Math.abs(this.walletRemaining) : 0;
    },

    // Same helpers as the results list, so the shared itinerary partial renders
    // identically on both pages.
    formatTime,
    formatDate,
    formatDuration,
    money,

    async complete() {
        if (this.submitting) return;
        this.submitting = true;
        this.error = null;

        const { ok, data } = await postJson(this.bookingUrl, {
            traceId: this.traceId,
            resultIndex: this.resultIndex,
            contact: this.contact,
            passengers: this.passengers,
            seats: this.seats ?? [],
            resultType: this.resultType,
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
        // Replace, not push: the booking exists now, so Confirmation must not
        // become a history entry the user can Back into and re-submit.
        this.syncUrl(false);
    },
}));

/**
 * Hotel search.
 *
 * Simpler than flightSearch because the server does the hard part: one POST comes
 * back with every property that has availability, already joined to the catalogue
 * and sorted. What lives here is the form, the client-side sort/filter, and the
 * property panel — which fetches a hotel's description and photos the first time it
 * is opened, since most of the catalogue has never been enriched.
 */
Alpine.data('hotelSearch', (config = {}) => ({
    suggestUrl: config.suggestUrl,
    searchUrl: config.searchUrl,
    hotelUrl: config.hotelUrl,

    // Form
    locationLabel: '',
    locationType: '',
    locationCode: '',
    suggestions: [],
    checkIn: '',
    checkOut: '',
    guestNationality: 'PH',
    rooms: [{ adults: 2, children: 0, childrenAges: [] }],
    refundableOnly: false,

    // State
    loading: false,
    collapsed: false,
    error: '',
    result: null,
    open: null,
    detail: {},

    // Client-side view controls
    sort: 'price',
    onlyRefundable: false,
    onlyBreakfast: false,
    onlyTransfers: false,
    minRating: 0,

    init() {
        this.checkIn = this.shift(this.today, 30);
        this.checkOut = this.shift(this.checkIn, 2);

        // flatpickr redraws on a bounds change but keeps whatever was selected,
        // so moving the check-in has to carry the check-out with it. Left alone,
        // the agent sees a calendar that greys out the date its own field still
        // shows, and the server rejects the pair a round trip later.
        this.$watch('checkIn', () => {
            if (!this.checkIn) return;

            if (!this.checkOut || this.checkOut <= this.checkIn) {
                this.checkOut = this.checkOutMin;
            } else if (this.checkOut > this.checkOutMax) {
                this.checkOut = this.checkOutMax;
            }
        });
    },

    /**
     * The local calendar date. toISOString() is UTC, so anywhere east of
     * Greenwich it hands back yesterday for most of the working day — in Manila
     * "today" would be wrong until 8am, and with it every default and minimum.
     */
    iso(date) {
        return [
            date.getFullYear(),
            String(date.getMonth() + 1).padStart(2, '0'),
            String(date.getDate()).padStart(2, '0'),
        ].join('-');
    },

    shift(date, days) {
        const moved = new Date(`${date}T00:00:00`);
        moved.setDate(moved.getDate() + days);

        return this.iso(moved);
    },

    get today() {
        return this.iso(new Date());
    },

    // A stay is at least one night, and TBO prices per night up to the thirty
    // the server enforces — beyond that it is a series of bookings.
    get checkOutMin() {
        return this.shift(this.checkIn || this.today, 1);
    },

    get checkOutMax() {
        return this.shift(this.checkIn || this.today, 30);
    },

    get nights() {
        if (!this.checkIn || !this.checkOut) return 0;

        const from = new Date(`${this.checkIn}T00:00:00`);
        const to = new Date(`${this.checkOut}T00:00:00`);

        return Math.max(0, Math.round((to - from) / 86400000));
    },

    formatDay,

    get summary() {
        if (!this.result) return '';
        const guests = this.result.guests;
        return `${this.locationLabel} · ${formatDay(this.checkIn)} → ${formatDay(this.checkOut)} · ` +
            `${this.result.rooms} room${this.result.rooms === 1 ? '' : 's'}, ` +
            `${guests} guest${guests === 1 ? '' : 's'}`;
    },

    get filtered() {
        if (!this.result) return [];

        const rows = this.result.offers.filter((o) =>
            (!this.onlyRefundable || o.hasRefundable) &&
            (!this.onlyBreakfast || o.hasBreakfast) &&
            (!this.onlyTransfers || o.hasTransfers) &&
            (!this.minRating || (o.rating || 0) >= this.minRating));

        const by = {
            price: (a, b) => a.lowestFare - b.lowestFare,
            price_desc: (a, b) => b.lowestFare - a.lowestFare,
            rating: (a, b) => (b.rating || 0) - (a.rating || 0),
            name: (a, b) => a.name.localeCompare(b.name),
        };

        return rows.sort(by[this.sort] || by.price);
    },

    money(amount, currency) {
        return `${currency || ''} ${Number(amount).toLocaleString(undefined, {
            minimumFractionDigits: 2, maximumFractionDigits: 2,
        })}`.trim();
    },

    async suggest() {
        const term = this.locationLabel.trim();

        if (term.length < 2) {
            this.suggestions = [];
            return;
        }

        try {
            const res = await fetch(`${this.suggestUrl}?q=${encodeURIComponent(term)}`, {
                headers: { Accept: 'application/json' },
            });
            this.suggestions = (await res.json()).results || [];
        } catch {
            this.suggestions = [];
        }
    },

    choose(option) {
        this.locationLabel = option.label;
        this.locationType = option.type;
        this.locationCode = option.code;
        this.suggestions = [];
    },

    addRoom() {
        this.rooms.push({ adults: 2, children: 0, childrenAges: [] });
    },

    removeRoom(i) {
        this.rooms.splice(i, 1);
    },

    // One age per child, in the room that child sleeps in. TBO refuses the request
    // when the counts disagree, so the form keeps them in step rather than letting
    // the server explain it later.
    syncAges(room) {
        while (room.childrenAges.length < room.children) room.childrenAges.push(8);
        room.childrenAges.length = room.children;
    },

    async search(retry = false) {
        if (!this.locationCode) {
            this.error = 'Choose a destination or property from the list.';
            return;
        }

        this.error = '';
        this.loading = true;
        if (!retry) this.open = null;

        try {
            const res = await fetch(this.searchUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                body: JSON.stringify({
                    checkIn: this.checkIn,
                    checkOut: this.checkOut,
                    locationType: this.locationType,
                    locationCode: this.locationCode,
                    guestNationality: this.guestNationality,
                    rooms: this.rooms,
                    refundableOnly: this.refundableOnly,
                }),
            });

            const body = await res.json();

            if (!res.ok) {
                // 422 is our own validation; anything else is the supplier speaking.
                this.error = body.message || Object.values(body.errors || {}).flat()[0]
                    || 'The search could not be completed.';
                return;
            }

            this.result = body;
            this.collapsed = true;
        } catch {
            this.error = 'The search could not be completed. Please try again.';
        } finally {
            this.loading = false;
        }
    },

    async toggle(offer) {
        if (this.open === offer.hotelCode) {
            this.open = null;
            return;
        }

        this.open = offer.hotelCode;

        if (this.detail[offer.hotelCode]) return;

        try {
            const res = await fetch(`${this.hotelUrl}/${offer.hotelCode}`, {
                headers: { Accept: 'application/json' },
            });

            if (res.ok) this.detail[offer.hotelCode] = await res.json();
        } catch {
            // The rates are the point of the panel; a missing description is not
            // worth an error message.
        }
    },
}));

Alpine.start();
