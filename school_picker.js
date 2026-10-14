/*
 * The school a student attended, picked from the schools of the place they
 * chose - the "Name of School" of each level in Edit Profile.
 *
 * Markup (student_settings.php):
 *
 *   <div data-school-picker data-levels="1|2|4">        1 elementary, 2 junior high, 4 senior high
 *       <div data-place-group> ...the school's address dropdowns... </div>
 *       <select data-school-select></select>             what the student picks from
 *       <input data-school-other name="...[school]">     the field actually posted
 *   </div>
 *
 * When the address group reports a city (its `placechange` event, see
 * address_picker.js), the list fills with the schools of that city / municipality
 * that teach the level - the ones in the chosen barangay first. Picking one
 * writes its name into the input, and sets the barangay to the school's own when
 * none was chosen. "My school is not on the list" shows the input to type the
 * name instead. A saved name that is not in the list comes back the same way.
 * Without JavaScript the input is simply a text box, as before.
 *
 * The lists are psgc/s_<province code>.json, built from the DepEd masterlist of
 * schools (SY 2020-2021): {"<city>": [[name, barangay, levels, private], ...]}.
 * Colleges are not in it, so the college level keeps the plain text box.
 * Keep this file ASCII-only.
 */
(function () {
    'use strict';

    var BASE = 'psgc/';
    var VERSION = '?v=1';
    var OTHER = '__other__';
    var cache = {};

    function loadSchools(code) {
        if (!cache[code]) {
            cache[code] = fetch(BASE + 's_' + code + '.json' + VERSION, { credentials: 'same-origin' })
                .then(function (response) {
                    if (response.status === 404) return {};            // a province with no list
                    if (!response.ok) throw new Error('HTTP ' + response.status);
                    return response.json();
                })
                .catch(function (error) {
                    delete cache[code];
                    throw error;
                });
        }
        return cache[code];
    }

    function option(value, label) {
        var o = document.createElement('option');
        o.value = value;
        o.textContent = label;
        return o;
    }

    function setup(wrap) {
        var group = wrap.querySelector('[data-place-group]');
        var select = wrap.querySelector('select[data-school-select]');
        var input = wrap.querySelector('input[data-school-other]');
        if (!group || !select || !input) return;

        var mask = parseInt(wrap.getAttribute('data-levels'), 10) || 0;
        var barangaySelect = group.querySelector('select[data-place="barangay"]');
        var current = [];       // the schools listed now
        var request = 0;        // so a slow answer for an earlier city is ignored

        function showInput(show) {
            input.hidden = !show;
            input.required = false;
        }

        function message(text) {
            select.innerHTML = '';
            select.appendChild(option('', text));
            select.disabled = true;
            // Nothing to pick from: the typed name, if any, stays editable.
            showInput(input.value.trim() !== '');
        }

        function render(city, barangay) {
            select.innerHTML = '';
            select.disabled = false;
            select.appendChild(option('', current.length ? 'Select your school...' : 'No school of this level is listed here'));

            var here = barangay ? current.filter(function (s) { return s[1] === barangay; }) : [];
            var rest = barangay ? current.filter(function (s) { return s[1] !== barangay; }) : current;
            function addGroup(label, list) {
                if (!list.length) return;
                var og = document.createElement('optgroup');
                og.label = label;
                list.forEach(function (s) {
                    og.appendChild(option(s[0], s[0] + (s[3] ? ' (Private)' : '')));
                });
                select.appendChild(og);
            }
            addGroup('In ' + barangay, here);
            addGroup(here.length ? 'Elsewhere in ' + city : 'Schools in ' + city, rest);
            select.appendChild(option(OTHER, 'My school is not on the list - type it'));

            // Show the saved / typed name: picked when it is listed, typed when not.
            var name = input.value.trim();
            var listed = name !== '' && current.some(function (s) { return s[0] === name; });
            if (listed) {
                select.value = name;
                showInput(false);
            } else if (name !== '') {
                select.value = OTHER;
                showInput(true);
            } else {
                select.value = '';
                showInput(false);
            }
        }

        function onPlace(detail) {
            var mine = ++request;
            if (!detail.city || !detail.provinceCode) {
                current = [];
                message('Choose the school\'s city / municipality first');
                return;
            }
            message('Loading schools...');
            loadSchools(detail.provinceCode).then(function (data) {
                if (mine !== request) return;
                current = (data[detail.city] || []).filter(function (s) { return (s[2] & mask) !== 0; });
                render(detail.city, detail.barangay);
            }).catch(function () {
                if (mine !== request) return;
                current = [];
                message('Could not load the schools - type the name below');
                showInput(true);
            });
        }

        group.addEventListener('placechange', function (event) { onPlace(event.detail || {}); });

        select.addEventListener('change', function () {
            if (select.value === OTHER) {
                input.value = '';
                showInput(true);
                input.focus();
                return;
            }
            input.value = select.value;
            showInput(false);
            // Fill the barangay from the school when the student had not picked one.
            var school = current.filter(function (s) { return s[0] === select.value; })[0];
            if (school && school[1] && barangaySelect && !barangaySelect.value) {
                barangaySelect.value = school[1];
            }
        });

        message('Choose the school\'s city / municipality first');
    }

    function init() {
        document.querySelectorAll('[data-school-picker]').forEach(setup);
    }

    // Set up before address_picker.js restores saved places, so no event is missed.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
