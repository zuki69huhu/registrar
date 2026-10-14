/*
 * A student profile, read-only, in a modal.
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
 * On a student's own pages it also shows how much of the profile is still to be
 * filled in: a red count on the sidebar picture, like the Notifications badge,
 * and a red dot on the phone's menu button, since the picture is inside the
 * closed drawer there. The count is what profile_config.php calls missing
 * (student_profile_view.php?status=1), or <body data-profile-missing="N"> when
 * the page already knows it. The last count is kept in sessionStorage so the
 * badge is there at once on the next page instead of popping in.
 *
 * Nothing in the modal can be edited; the form is in student_settings.php.
 * Keep this file ASCII-only: Apache serves .js without a charset.
 */
(function () {
    'use strict';

    var MARKUP =
        '<div class="sp" id="spRoot" role="dialog" aria-modal="true" aria-labelledby="spHeading" hidden>' +
            '<div class="sp-backdrop" data-sp-close></div>' +
            '<div class="sp-panel" tabindex="-1">' +
                '<div class="sp-bar">' +
                    '<h2 id="spHeading"><i class="fas fa-user-graduate"></i> Student Profile</h2>' +
                    '<button type="button" class="sp-close" data-sp-close aria-label="Close profile">&times;</button>' +
                '</div>' +
                '<div class="sp-body" id="spBody"></div>' +
            '</div>' +
        '</div>';

    var SKELETON =
        '<div class="sp-skel"><div class="sp-skel-photo"></div><div class="sp-skel-lines"><i></i><i></i></div></div>' +
        '<div class="sp-skel-block"></div><div class="sp-skel-block"></div><div class="sp-skel-block"></div>';

    var root, body, panel, lastFocus = null, current = '';
    var cache = {};     // user id ('' = own profile) -> Promise of the HTML

    function build() {
        if (root) return;
        document.body.insertAdjacentHTML('beforeend', MARKUP);
        root = document.getElementById('spRoot');
        body = document.getElementById('spBody');
        panel = root.querySelector('.sp-panel');
        root.addEventListener('click', function (event) {
            if (event.target.closest('[data-sp-close]')) close();
        });
    }

    function key(id) {
        return id === undefined || id === null ? '' : String(id);
    }

    // One request per profile per page view; a failed one is forgotten so a retry can work.
    function load(id) {
        id = key(id);
        if (!cache[id]) {
            var url = 'student_profile_view.php' + (id ? '?id=' + encodeURIComponent(id) : '');
            cache[id] = fetch(url, { credentials: 'same-origin' })
                .then(function (response) {
                    return response.text().then(function (html) {
                        if (!response.ok && !html) throw new Error('HTTP ' + response.status);
                        return html;
                    });
                })
                .catch(function (error) {
                    delete cache[id];
                    throw error;
                });
        }
        return cache[id];
    }

    function show(id) {
        body.innerHTML = SKELETON;
        load(id).then(function (html) {
            if (current !== id || root.hidden) return;      // another profile was opened meanwhile
            body.innerHTML = html;
            body.scrollTop = 0;
        }).catch(function () {
            if (current !== id || root.hidden) return;
            body.innerHTML =
                '<p class="sp-empty">The profile could not be loaded. Check your connection and ' +
                '<button type="button" class="sp-retry">try again</button>.</p>';
            body.querySelector('.sp-retry').addEventListener('click', function () { show(id); });
        });
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
        show(id);
        panel.focus();
    }

    function close() {
        if (!root || root.hidden) return;
        root.hidden = true;
        document.documentElement.classList.remove('sp-open');
        if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
    }

    // The element that opens a profile, and whose: null when it is not one.
    function triggerOf(target) {
        var element = target && target.closest ? target.closest('[data-profile-id], [data-open-profile], .sp-trigger') : null;
        if (!element) return null;
        return { element: element, id: element.getAttribute('data-profile-id') || '' };
    }

    function onKey(event) {
        if (root && !root.hidden) {
            if (event.key === 'Escape') {
                close();
            } else if (event.key === 'Tab') {
                // Keep the keyboard inside the modal while it is open.
                var items = panel.querySelectorAll('a[href], button');
                var first = items[0], last = items[items.length - 1];
                if (event.shiftKey && (document.activeElement === first || document.activeElement === panel)) {
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

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
