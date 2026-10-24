/*
 * Region -> Province -> City / Municipality -> Barangay, as linked dropdowns.
 *
 * Every element marked [data-place-group] holds one address: up to four
 * <select data-place="region|province|city|barangay">. Choosing a region lists
 * its provinces, a province its cities and municipalities, a city its
 * barangays. A dropdown stays disabled until the one above it is chosen.
 * data-value on a select is the saved answer, and the chain is rebuilt down to
 * it when the page opens. A group without a barangay select simply stops at
 * the city.
 *
 * The lists are the local files in psgc/ (see psgc_helper.php): regions.json is
 * a few KB and is fetched once for the whole page; each province is one small
 * file, fetched the first time any group chooses it and then shared.
 *
 * The option values are the names themselves, which is what gets stored and
 * what psgc_helper.php checks on the server.
 *
 * A button with data-copy-from / data-copy-to (the ids of two groups) copies
 * one address into the other - "Same as my permanent address" - street
 * included; each group exposes setPlace({region, province, city, barangay})
 * for that.
 *
 * Whenever the city or barangay of a group changes - picked, restored on load,
 * or cleared because a level above changed - the group fires a `placechange`
 * event with {provinceCode, city, barangay}; school_picker.js listens to it.
 * A form added after the page loaded is set up with
 * window.PlacePicker.init(container); a group is never set up twice.
 * Keep this file ASCII-only.
 */
(function () {
    'use strict';

    var BASE = 'psgc/';
    var VERSION = '?v=2';   // bump when psgc/ is rebuilt, so browsers fetch the new lists
    var LEVELS = ['region', 'province', 'city', 'barangay'];
    var regionsPromise = null;
    var provinceCache = {};
    var PLACEHOLDER = {
        region: 'Select region...',
        province: 'Select province...',
        city: 'Select city / municipality...',
        barangay: 'Select barangay...'
    };
    var WAITING = {
        province: 'Choose a region first',
        city: 'Choose a province first',
        barangay: 'Choose a city / municipality first'
    };

    function getJSON(url) {
        return fetch(url, { credentials: 'same-origin' }).then(function (response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        });
    }

    function loadRegions() {
        if (!regionsPromise) {
            regionsPromise = getJSON(BASE + 'regions.json' + VERSION).catch(function (error) {
                regionsPromise = null;
                throw error;
            });
        }
        return regionsPromise;
    }

    function loadProvince(code) {
        if (!provinceCache[code]) {
            provinceCache[code] = getJSON(BASE + 'p_' + code + '.json' + VERSION).catch(function (error) {
                delete provinceCache[code];
                throw error;
            });
        }
        return provinceCache[code];
    }

    // Replaces a dropdown's options. `items` are {n: name, c: code?}; an empty
    // list leaves it disabled, showing `message`. Returns whether `chosen` was found.
    function fill(select, items, message, chosen) {
        select.innerHTML = '';
        var first = document.createElement('option');
        first.value = '';
        first.textContent = message;
        select.appendChild(first);
        var found = false;
        items.forEach(function (item) {
            var option = document.createElement('option');
            option.value = item.n;
            option.textContent = item.n;
            if (item.c) option.setAttribute('data-code', item.c);
            if (chosen && item.n === chosen) { option.selected = true; found = true; }
            select.appendChild(option);
        });
        select.disabled = items.length === 0;
        return found;
    }

    function code(select) {
        var option = select.options[select.selectedIndex];
        return option ? option.getAttribute('data-code') || '' : '';
    }

    function setup(group) {
        var el = {};
        LEVELS.forEach(function (level) {
            el[level] = group.querySelector('select[data-place="' + level + '"]');
        });
        if (!el.region || !el.province || !el.city || group.spPlaceReady) return;
        group.spPlaceReady = true;     // init may run again over a form added later

        // What to restore, used once on the way down and then forgotten.
        var want = {};
        LEVELS.forEach(function (level) {
            want[level] = el[level] ? el[level].getAttribute('data-value') || '' : '';
        });
        var regions = [], rows = [];

        function notify() {
            group.dispatchEvent(new CustomEvent('placechange', { detail: {
                provinceCode: code(el.province),
                city: el.city.value,
                barangay: el.barangay ? el.barangay.value : ''
            } }));
        }

        function reset(level) {
            if (el[level]) fill(el[level], [], WAITING[level]);
        }

        function failed(level) {
            fill(el[level], [], 'Could not load the list - check your connection');
        }

        function onCity() {
            if (!el.barangay) { notify(); return; }
            var name = el.city.value;
            var row = rows.filter(function (r) { return r.n === name; })[0];
            if (!row) { reset('barangay'); notify(); return; }
            fill(el.barangay, row.b.map(function (b) { return { n: b }; }), PLACEHOLDER.barangay, want.barangay);
            want.barangay = '';
            notify();
        }

        function onProvince() {
            var provinceCode = code(el.province);
            reset('city');
            reset('barangay');
            notify();
            if (!provinceCode) return;
            fill(el.city, [], 'Loading...');
            loadProvince(provinceCode).then(function (data) {
                if (code(el.province) !== provinceCode) return;      // changed again meanwhile
                rows = data;
                var restored = fill(el.city, rows, PLACEHOLDER.city, want.city);
                want.city = '';
                if (restored) onCity(); else want.barangay = '';
            }).catch(function () { failed('city'); });
        }

        function onRegion() {
            var name = el.region.value;
            var region = regions.filter(function (r) { return r.n === name; })[0];
            reset('province');
            reset('city');
            reset('barangay');
            if (!region) return;
            var restored = fill(el.province, region.p, PLACEHOLDER.province, want.province);
            want.province = '';
            if (restored) onProvince(); else { want.city = ''; want.barangay = ''; }
        }

        el.region.addEventListener('change', onRegion);
        el.province.addEventListener('change', onProvince);
        el.city.addEventListener('change', onCity);
        if (el.barangay) el.barangay.addEventListener('change', notify);

        // Rebuilds the chain down to the given names, as on page load.
        group.setPlace = function (values) {
            LEVELS.forEach(function (level) { want[level] = (values && values[level]) || ''; });
            loadRegions().then(function (data) {
                regions = data;
                var restored = fill(el.region, regions, PLACEHOLDER.region, want.region);
                want.region = '';
                onRegion();
                if (!restored) { want.province = ''; want.city = ''; want.barangay = ''; }
            });
        };

        reset('province');
        reset('city');
        reset('barangay');
        loadRegions().then(function (data) {
            regions = data;
            var restored = fill(el.region, regions, PLACEHOLDER.region, want.region);
            want.region = '';
            if (restored) onRegion();
        }).catch(function () { failed('region'); });
    }

    function copyPlace(button) {
        var from = document.getElementById(button.getAttribute('data-copy-from'));
        var to = document.getElementById(button.getAttribute('data-copy-to'));
        if (!from || !to || typeof to.setPlace !== 'function') return;
        var values = {};
        LEVELS.forEach(function (level) {
            var select = from.querySelector('select[data-place="' + level + '"]');
            values[level] = select ? select.value : '';
        });
        if (!values.region) {
            if (!button.hasAttribute('data-label')) button.setAttribute('data-label', button.innerHTML);
            button.textContent = 'Pick the permanent address first';
            setTimeout(function () { button.innerHTML = button.getAttribute('data-label'); }, 2500);
            return;
        }
        to.setPlace(values);
        if (!button.hasAttribute('data-label')) button.setAttribute('data-label', button.innerHTML);
        var street = from.querySelector('input[name$="[street]"]');
        var target = to.querySelector('input[name$="[street]"]');
        if (street && target) target.value = street.value;
        button.classList.add('is-done');
        button.innerHTML = '<i class="fas fa-check"></i> Copied';
        setTimeout(function () {
            button.classList.remove('is-done');
            button.innerHTML = button.getAttribute('data-label');
        }, 2000);
    }

    function setupAll(root) {
        (root || document).querySelectorAll('[data-place-group]').forEach(setup);
    }

    // For a form injected after the page loaded (the registrar's profile editor).
    window.PlacePicker = { init: setupAll };

    function init() {
        setupAll(document);
        document.addEventListener('click', function (event) {
            var button = event.target.closest('[data-copy-from]');
            if (button) copyPlace(button);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
