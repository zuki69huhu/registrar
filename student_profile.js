/*
 * A student profile in a modal.
 *
 * Like doc_viewer.js and ui_dialog.js it injects its own markup, so a page
 * needs only student_profile.css and this script. It opens from:
 *
 *   - the picture (or initial) at the top of a student's own sidebar,
 *   - anything carrying [data-open-profile]          -> the signed-in student,
 *   - anything carrying [data-profile-id="<user id>"] -> that student, for a
 *     registrar (rows added later work too: the listener is on the document),
 *   - openStudentProfile(id) from other scripts.
 *
 * The profile itself is student_profile_view.php, which decides who may see
 * whom. Each profile is fetched once and kept for the rest of the page view,
 * and is requested as soon as the pointer or a finger reaches the trigger, so
 * it is normally there by the time the modal opens.
 *
 * The picture in the profile opens large ([data-sp-photo]) with a Download
 * button. A registrar also gets Edit Profile ([data-sp-edit]): the modal swaps
 * to the form of student_profile_view.php?mode=edit - the same fields the
 * student's own Edit Profile has - with the address and school lists of
 * address_picker.js / school_picker.js, loaded only then. Saving posts to
 * process_registrar_profile.php without leaving the page; an error comes back
 * as a list over the form with everything still as typed. Unsaved changes are
 * never lost to a stray click: closing asks first.
 *
 * On a student's own pages it also shows how much of the profile is still to be
 * filled in: a red count on the sidebar picture, like the Notifications badge,
 * and a red dot on the phone's menu button, since the picture is inside the
 * closed drawer there. The count is what profile_config.php calls missing
 * (student_profile_view.php?status=1), or <body data-profile-missing="N"> when
 * the page already knows it. The last count is kept in sessionStorage so the
 * badge is there at once on the next page instead of popping in.
 *
 * wireProfileForm(form) - the age hint and the school-year checks - serves both
 * the modal's form and Edit Profile in student_settings.php ([data-profile-form]).
 * Keep this file ASCII-only: Apache serves .js without a charset.
 */
(function () {
    'use strict';

    var PICKERS = ['address_picker.js?v=5', 'school_picker.js?v=2'];

    var MARKUP =
        '<div class="sp" id="spRoot" role="dialog" aria-modal="true" aria-labelledby="spHeading" hidden>' +
            '<div class="sp-backdrop" data-sp-close></div>' +
            '<div class="sp-panel" tabindex="-1">' +
                '<div class="sp-bar">' +
                    '<h2 id="spHeading"></h2>' +
                    '<button type="button" class="sp-close" data-sp-close aria-label="Close profile">&times;</button>' +
                '</div>' +
                '<div class="sp-discard" id="spDiscard" role="alertdialog" aria-live="assertive" hidden>' +
                    '<span><i class="fas fa-triangle-exclamation"></i> Discard the changes you have not saved?</span>' +
                    '<span class="sp-discard-actions">' +
                        '<button type="button" class="pf-btn is-ghost" data-sp-keep>Keep editing</button>' +
                        '<button type="button" class="pf-btn is-danger" data-sp-discard>Discard</button>' +
                    '</span>' +
                '</div>' +
                '<div class="sp-body" id="spBody"></div>' +
            '</div>' +
            '<div class="sp-lightbox" id="spLightbox" role="dialog" aria-modal="true" aria-label="Profile picture" hidden>' +
                '<div class="sp-lb-bar">' +
                    '<span class="sp-lb-name" id="spLbName"></span>' +
                    '<a class="sp-lb-btn" id="spLbDownload" href="#" download><i class="fas fa-download"></i><span> Download</span></a>' +
                    '<button type="button" class="sp-lb-btn is-close" data-sp-lb-close aria-label="Close picture">&times;</button>' +
                '</div>' +
                '<div class="sp-lb-stage" data-sp-lb-close>' +
                    '<img class="sp-lb-img" id="spLbImg" alt="">' +
                '</div>' +
                '<div class="sp-lb-hint">Click the picture to see it at full size</div>' +
            '</div>' +
        '</div>';

    var SKELETON =
        '<div class="sp-skel"><div class="sp-skel-photo"></div><div class="sp-skel-lines"><i></i><i></i></div></div>' +
        '<div class="sp-skel-block"></div><div class="sp-skel-block"></div><div class="sp-skel-block"></div>';

    var HEADING = {
        view: '<i class="fas fa-user-graduate"></i> Student Profile',
        edit: '<i class="fas fa-user-pen"></i> Edit Student Profile'
    };

    var root, body, panel, heading, discardBar, lightbox, lastFocus = null, current = '';
    var mode = 'view', dirty = false;
    var cache = {};     // user id ('' = own profile) -> Promise of the HTML
    var pickersPromise = null;

    function build() {
        if (root) return;
        document.body.insertAdjacentHTML('beforeend', MARKUP);
        root = document.getElementById('spRoot');
        body = document.getElementById('spBody');
        heading = document.getElementById('spHeading');
        discardBar = document.getElementById('spDiscard');
        lightbox = document.getElementById('spLightbox');
        panel = root.querySelector('.sp-panel');

        root.addEventListener('click', function (event) {
            var target = event.target;
            if (target.closest('[data-sp-lb-close]') && !target.closest('.sp-lb-img')) { closePhoto(); return; }
            if (target.closest('.sp-lb-img')) { lightbox.classList.toggle('is-actual'); return; }
            var photo = target.closest('[data-sp-photo]');
            if (photo) { openPhoto(photo); return; }
            var editButton = target.closest('[data-sp-edit]');
            if (editButton) { edit(editButton.getAttribute('data-sp-edit')); return; }
            if (target.closest('[data-sp-cancel]')) { requestLeave(function () { view(current); }); return; }
            if (target.closest('[data-sp-keep]')) { discardBar.hidden = true; return; }
            if (target.closest('[data-sp-discard]')) { var go = discardBar.onConfirm; discardBar.hidden = true; dirty = false; if (go) go(); return; }
            if (target.closest('[data-sp-close]')) {
                // The backdrop never throws away an edit; the close button asks first.
                if (mode === 'edit' && target.classList.contains('sp-backdrop')) return;
                requestLeave(close);
            }
        });
        body.addEventListener('input', markDirty);
        body.addEventListener('change', markDirty);
        body.addEventListener('submit', save);
    }

    function markDirty(event) {
        if (mode === 'edit' && event.target.closest('form[data-sp-form]')) dirty = true;
    }

    // Leaving an edited form asks first; anything else goes straight ahead.
    function requestLeave(then) {
        if (mode === 'edit' && dirty) {
            discardBar.onConfirm = then;
            discardBar.hidden = false;
            discardBar.querySelector('[data-sp-keep]').focus();
            return;
        }
        then();
    }

    function key(id) {
        return id === undefined || id === null ? '' : String(id);
    }

    function fetchText(url) {
        return fetch(url, { credentials: 'same-origin' }).then(function (response) {
            return response.text().then(function (html) {
                if (!response.ok && !html) throw new Error('HTTP ' + response.status);
                return html;
            });
        });
    }

    // One request per profile per page view; a failed one is forgotten so a retry can work.
    function load(id) {
        id = key(id);
        if (!cache[id]) {
            cache[id] = fetchText('student_profile_view.php' + (id ? '?id=' + encodeURIComponent(id) : ''))
                .catch(function (error) {
                    delete cache[id];
                    throw error;
                });
        }
        return cache[id];
    }

    function failed(retry) {
        body.innerHTML =
            '<p class="sp-empty">The profile could not be loaded. Check your connection and ' +
            '<button type="button" class="sp-retry">try again</button>.</p>';
        body.querySelector('.sp-retry').addEventListener('click', retry);
    }

    function setMode(next) {
        mode = next;
        dirty = false;
        discardBar.hidden = true;
        heading.innerHTML = HEADING[next];
        root.classList.toggle('is-editing', next === 'edit');
    }

    // The read-only profile; `notice` is shown over it (after a save).
    function view(id, notice) {
        setMode('view');
        body.innerHTML = SKELETON;
        load(id).then(function (html) {
            if (current !== id || root.hidden || mode !== 'view') return;   // another profile was opened meanwhile
            body.innerHTML = (notice ? '<div class="sp-saved" role="status"><i class="fas fa-circle-check"></i> ' + notice + '</div>' : '') + html;
            body.scrollTop = 0;
        }).catch(function () {
            if (current !== id || root.hidden) return;
            failed(function () { view(id, notice); });
        });
    }

    // address_picker.js and school_picker.js, loaded the first time a form needs them.
    function loadPickers() {
        if (window.PlacePicker && window.SchoolPicker) return Promise.resolve();
        if (!pickersPromise) {
            pickersPromise = Promise.all(PICKERS.map(function (src) {
                return new Promise(function (resolve, reject) {
                    var script = document.createElement('script');
                    script.src = src;
                    script.onload = resolve;
                    script.onerror = reject;
                    document.head.appendChild(script);
                });
            })).catch(function (error) {
                pickersPromise = null;
                throw error;
            });
        }
        return pickersPromise;
    }

    // The registrar's form, always fresh: it must show what is saved right now.
    function edit(id) {
        id = key(id);
        current = id;
        setMode('edit');
        body.innerHTML = SKELETON;
        Promise.all([
            fetchText('student_profile_view.php?id=' + encodeURIComponent(id) + '&mode=edit'),
            loadPickers()
        ]).then(function (results) {
            if (current !== id || root.hidden || mode !== 'edit') return;
            body.innerHTML = results[0];
            body.scrollTop = 0;
            var form = body.querySelector('form[data-sp-form]');
            if (!form) return;
            // Schools first, so they hear the first city the address lists restore.
            window.SchoolPicker.init(form);
            window.PlacePicker.init(form);
            wireProfileForm(form);
            var first = form.querySelector('input:not([type="hidden"]):not([readonly])');
            if (first && window.matchMedia('(min-width: 900px)').matches) first.focus({ preventScroll: true });
        }).catch(function () {
            if (current !== id || root.hidden) return;
            failed(function () { edit(id); });
        });
    }

    function showErrors(form, errors) {
        var box = form.querySelector('.sp-form-errors');
        box.innerHTML = '<strong>The profile was not saved. Please fix the following:</strong><ul></ul>';
        var list = box.querySelector('ul');
        (errors && errors.length ? errors : ['Something went wrong. Please try again.']).forEach(function (text) {
            var item = document.createElement('li');
            item.textContent = text;
            list.appendChild(item);
        });
        box.hidden = false;
        body.scrollTop = 0;
        box.focus({ preventScroll: true });
    }

    function save(event) {
        var form = event.target.closest('form[data-sp-form]');
        if (!form) return;
        event.preventDefault();
        if (!form.reportValidity()) return;
        var id = form.getAttribute('data-sp-form');
        var button = form.querySelector('[data-sp-save]');
        var label = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        form.querySelector('.sp-form-errors').hidden = true;

        fetch('process_registrar_profile.php', { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
            .then(function (response) {
                return response.json().catch(function () {
                    return { ok: false, errors: ['The server did not answer properly (HTTP ' + response.status + '). Please try again.'] };
                });
            })
            .then(function (data) {
                if (!data.ok) { showErrors(form, data.errors); return; }
                delete cache[id];
                // The student's name on the page (a table row) follows the edit.
                if (data.name) {
                    document.querySelectorAll('.sp-link[data-profile-id="' + id + '"]').forEach(function (link) {
                        link.textContent = data.name;
                    });
                }
                dirty = false;
                if (current === id && !root.hidden) view(id, 'Changes saved. The student has been notified.');
            })
            .catch(function () {
                showErrors(form, ['The server could not be reached. Check your connection and try again.']);
            })
            .then(function () {
                if (button.isConnected) { button.disabled = false; button.innerHTML = label; }
            });
    }

    function openPhoto(trigger) {
        var img = document.getElementById('spLbImg');
        var name = trigger.getAttribute('data-sp-photo-name') || '';
        img.src = trigger.getAttribute('data-sp-photo');
        img.alt = 'Profile picture of ' + name;
        document.getElementById('spLbName').textContent = name;
        var download = document.getElementById('spLbDownload');
        download.href = trigger.getAttribute('data-sp-photo-download') || img.src;
        lightbox.classList.remove('is-actual');
        lightbox.hidden = false;
        lightbox.querySelector('[data-sp-lb-close]').focus();
    }

    function closePhoto() {
        if (!lightbox || lightbox.hidden) return false;
        lightbox.hidden = true;
        var trigger = body.querySelector('[data-sp-photo]');
        if (trigger) trigger.focus();
        return true;
    }

    function open(id) {
        id = key(id);
        build();
        if (root.hidden) {
            lastFocus = document.activeElement;
            if (typeof window.closeSidebar === 'function') window.closeSidebar();
            root.hidden = false;
            document.documentElement.classList.add('sp-open');
        }
        current = id;
        view(id);
        panel.focus();
    }

    function close() {
        if (!root || root.hidden) return;
        closePhoto();
        setMode('view');
        root.hidden = true;
        document.documentElement.classList.remove('sp-open');
        if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
    }

    // The element that opens a profile, and whose: null when it is not one.
    function triggerOf(target) {
        var element = target && target.closest ? target.closest('[data-profile-id], [data-open-profile], .sp-trigger') : null;
        if (!element || (root && root.contains(element))) return null;
        return { element: element, id: element.getAttribute('data-profile-id') || '' };
    }

    // What Tab may reach inside the open layer: visible and enabled.
    function focusables(container) {
        return Array.prototype.filter.call(
            container.querySelectorAll('a[href], button, input, select, textarea'),
            function (el) { return !el.disabled && el.type !== 'hidden' && el.offsetParent !== null; }
        );
    }

    function onKey(event) {
        if (root && !root.hidden) {
            if (event.key === 'Escape') {
                if (closePhoto()) return;
                if (!discardBar.hidden) { discardBar.hidden = true; return; }
                requestLeave(close);
            } else if (event.key === 'Tab') {
                // Keep the keyboard inside the modal (or the picture) while it is open.
                var layer = lightbox.hidden ? panel : lightbox;
                var items = focusables(layer);
                if (!items.length) return;
                var first = items[0], last = items[items.length - 1];
                if (event.shiftKey && (document.activeElement === first || document.activeElement === layer)) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            }
            return;
        }
        // A trigger that is not a real button still answers Enter and Space.
        if (event.key === 'Enter' || event.key === ' ') {
            var trigger = triggerOf(event.target);
            if (trigger && trigger.element === event.target && !/^(BUTTON|A)$/.test(event.target.tagName)) {
                event.preventDefault();
                open(trigger.id);
            }
        }
    }

    function prefetch(event) {
        var trigger = triggerOf(event.target);
        if (trigger) load(trigger.id).catch(function () { /* shown when it is opened */ });
    }

    /*
     * Edit Profile behaviour shared by Settings and the modal: the age appears as
     * soon as a birth date is picked, and a school's ending year may not be
     * earlier than its starting year.
     */
    function wireProfileForm(form) {
        if (!form || form.spWired) return;
        form.spWired = true;
        var birth = form.querySelector('[data-pf-birth]');
        var ageHint = form.querySelector('[data-pf-age]');
        if (birth && ageHint) {
            birth.addEventListener('input', function () {
                var parts = birth.value.split('-');
                if (parts.length !== 3) { ageHint.textContent = 'The age is worked out from this.'; return; }
                var today = new Date();
                var age = today.getFullYear() - parts[0];
                var beforeBirthday = (today.getMonth() + 1) < parts[1] ||
                    ((today.getMonth() + 1) === +parts[1] && today.getDate() < parts[2]);
                if (beforeBirthday) age--;
                ageHint.textContent = (age >= 0 && age < 130) ? 'Age: ' + age + ' years old' : 'Please check this date.';
            });
        }
        // A new picture: shown in the preview at once, and (for a student) the
        // formal-photo confirmation becomes required.
        var photoInput = form.querySelector('[data-pf-photo-input]');
        if (photoInput) {
            var confirmBox = form.querySelector('[data-pf-photo-confirm]');
            var previewUrl = null;
            photoInput.addEventListener('change', function () {
                var file = photoInput.files && photoInput.files[0];
                if (confirmBox) {
                    confirmBox.hidden = !file;
                    var tick = confirmBox.querySelector('input');
                    tick.required = !!file;
                    if (!file) tick.checked = false;
                }
                var holder = form.querySelector('.pf-photo-preview');
                if (!holder || !file || !/^image\//.test(file.type)) return;
                if (previewUrl) URL.revokeObjectURL(previewUrl);
                previewUrl = URL.createObjectURL(file);
                holder.innerHTML = '';
                var img = document.createElement('img');
                img.src = previewUrl;
                img.alt = 'New profile picture';
                holder.appendChild(img);
            });
        }
        form.querySelectorAll('.pf-edu').forEach(function (row) {
            var from = row.querySelector('.pf-year-from');
            var to = row.querySelector('.pf-year-to');
            if (!from || !to) return;
            function check() {
                var wrong = from.value && to.value && +from.value > +to.value;
                to.setCustomValidity(wrong ? 'The ending year cannot be before the starting year.' : '');
            }
            from.addEventListener('input', check);
            to.addEventListener('input', check);
        });
    }

    var STATUS_KEY = 'sp-missing';
    var avatar = null, badge = null;

    // Shows (or clears) the count of required answers still missing.
    function setMissing(count) {
        count = parseInt(count, 10);
        if (!(count > 0)) count = 0;
        document.documentElement.classList.toggle('sp-missing', count > 0);
        if (!avatar) return;
        var label = count
            ? 'View my profile - ' + count + (count === 1 ? ' required detail is' : ' required details are') + ' still missing'
            : 'View my profile';
        avatar.setAttribute('title', label);
        avatar.setAttribute('aria-label', label);
        if (count && !badge) {
            badge = document.createElement('span');
            badge.className = 'sp-badge';
            badge.setAttribute('data-open-profile', '');
            badge.setAttribute('aria-hidden', 'true');
            avatar.parentNode.appendChild(badge);
        }
        if (badge) {
            badge.hidden = !count;
            badge.textContent = count > 9 ? '9+' : String(count);
            badge.setAttribute('title', label);
        }
    }

    function refreshMissing() {
        var known = document.body.getAttribute('data-profile-missing');
        var remember = function (count) {
            try { sessionStorage.setItem(STATUS_KEY, String(count)); } catch (error) { /* not essential */ }
            setMissing(count);
        };
        if (known !== null) {
            remember(known);
            return;
        }
        try { setMissing(sessionStorage.getItem(STATUS_KEY)); } catch (error) { /* first paint without it */ }
        fetch('student_profile_view.php?status=1', { credentials: 'same-origin' })
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (data) { if (data && typeof data.missing === 'number') remember(data.missing); })
            .catch(function () { /* keep what is shown */ });
    }

    function init() {
        // The sidebar picture is the first thing inside .student-info on every
        // student page: an <img>, or a <div> holding the initial. It is wrapped
        // so the badge can sit on its corner.
        avatar = document.querySelector('.sidebar .student-info > :first-child');
        if (avatar) {
            var wrap = document.createElement('span');
            wrap.className = 'sp-avatar-wrap';
            avatar.parentNode.insertBefore(wrap, avatar);
            wrap.appendChild(avatar);
            avatar.classList.add('sp-trigger');
            avatar.setAttribute('role', 'button');
            avatar.setAttribute('tabindex', '0');
            setMissing(0);
            refreshMissing();
        }

        document.querySelectorAll('form[data-profile-form]').forEach(wireProfileForm);

        document.addEventListener('click', function (event) {
            var trigger = triggerOf(event.target);
            if (!trigger) return;
            event.preventDefault();
            open(trigger.id);
        });
        document.addEventListener('pointerover', prefetch);
        document.addEventListener('touchstart', prefetch, { passive: true });
        document.addEventListener('focusin', prefetch);
        document.addEventListener('keydown', onKey);
    }

    window.openStudentProfile = open;
    window.wireProfileForm = wireProfileForm;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
