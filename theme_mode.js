/*
 * Light mode / dark mode, for the student pages and the registrar pages.
 *
 * Load it in <head> WITHOUT defer, so the mode is on <html> before the first
 * paint and the page never flashes the wrong colours:
 *
 *   <script src="theme_mode.js" data-key="student-theme" data-base="light"
 *           data-clear="student-theme-color,student-bg-color,student-sidebar-bg"></script>
 *
 *   data-base   the mode the page's own CSS is written in: 'light' for the
 *               student pages, 'dark' for the registrar's. It is also the
 *               default until the user picks.
 *   data-key    the localStorage key holding 'light' or 'dark'. Students and
 *               registrars keep separate keys.
 *   data-clear  keys of the old Theme Personalization card to delete, so the
 *               colour loaders still at the bottom of each page find nothing
 *               and everyone is back on the standard palette.
 *
 * It sets data-theme="light|dark" on <html>, plus data-theme-invert when the
 * chosen mode is not the one the page was written in; theme_mode.css does the
 * rest. Any control carrying [data-theme-choice="light|dark"] switches the mode
 * and has aria-pressed kept in step.
 *
 * It also adds its own switch (.theme-fab) at the top right of every page,
 * showing a moon in light mode and a sun in dark mode, so nobody has to open
 * Settings to change it. No page carries markup for it; placeButtons() puts it
 *   - at the right end of the page's own top bar (.topbar / .top-bar), joining
 *     the group already sitting there when there is one,
 *   - floated to the top right of .main-content on the pages with no top bar,
 *   - and a second copy at the right end of the phone app bar (.mobile-appbar),
 *     which replaces the first one at 1024px and below.
 * Keep this file ASCII-only.
 */
(function () {
    'use strict';
    var script = document.currentScript;
    var root = document.documentElement;
    var base = script && script.getAttribute('data-base') === 'dark' ? 'dark' : 'light';
    var key = (script && script.getAttribute('data-key')) || 'student-theme';
    var clear = ((script && script.getAttribute('data-clear')) || '').split(',');

    function valid(theme) {
        return theme === 'light' || theme === 'dark' ? theme : base;
    }

    function read() {
        try {
            clear.forEach(function (old) {
                if (old) localStorage.removeItem(old.trim());
            });
            return valid(localStorage.getItem(key));
        } catch (error) {
            return base;
        }
    }

    var fabs = [];

    function mark(theme) {
        var next = theme === 'dark' ? 'light' : 'dark';
        fabs.forEach(function (fab) {
            fab.innerHTML = '<i class="fas ' + (theme === 'dark' ? 'fa-sun' : 'fa-moon') + '"></i>';
            fab.setAttribute('title', 'Switch to ' + next + ' mode');
            fab.setAttribute('aria-label', 'Switch to ' + next + ' mode');
        });
        document.querySelectorAll('[data-theme-choice]').forEach(function (control) {
            control.setAttribute('aria-pressed', control.getAttribute('data-theme-choice') === theme ? 'true' : 'false');
        });
    }

    function apply(theme) {
        theme = valid(theme);
        root.setAttribute('data-theme', theme);
        root.setAttribute('data-theme-base', base);
        if (theme === base) {
            root.removeAttribute('data-theme-invert');
        } else {
            root.setAttribute('data-theme-invert', '');
        }
        mark(theme);
        return theme;
    }

    window.setThemeMode = function (theme) {
        theme = apply(theme);
        try { localStorage.setItem(key, theme); } catch (error) { /* private mode: lasts for this page only */ }
    };

    function makeButton(extraClass) {
        var fab = document.createElement('button');
        fab.type = 'button';
        fab.className = 'theme-fab' + (extraClass ? ' ' + extraClass : '');
        fab.addEventListener('click', function () {
            window.setThemeMode(root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
        });
        fabs.push(fab);
        return fab;
    }

    function placeButtons() {
        // Phone: the right end of the fixed app bar.
        var appbar = document.querySelector('.mobile-appbar');
        if (appbar) {
            appbar.appendChild(makeButton('in-appbar'));
            root.classList.add('theme-has-appbar');
        }

        // Desktop: the right end of the page's top bar. Every top bar is a
        // space-between flex row, so a third child would land in the middle;
        // when there is already a right-hand group the button joins it.
        var bar = document.querySelector('.topbar, .top-bar');
        if (bar) {
            var group = bar.querySelector('.topbar-right');
            if (!group && bar.children.length >= 2) {
                var last = bar.lastElementChild;
                if (getComputedStyle(last).display.indexOf('flex') !== -1) {
                    group = last;
                } else {
                    group = document.createElement('div');
                    group.className = 'theme-fab-group';
                    bar.appendChild(group);
                    group.appendChild(last);
                }
            }
            (group || bar).appendChild(makeButton('in-topbar'));
            return;
        }

        // No top bar: float it beside the page heading.
        var main = document.querySelector('.main-content, .main');
        if (main) {
            main.insertBefore(makeButton('is-float'), main.firstChild);
        } else {
            document.body.appendChild(makeButton('is-fixed'));
        }
    }

    apply(read());

    document.addEventListener('DOMContentLoaded', function () {
        placeButtons();
        mark(root.getAttribute('data-theme'));
        document.addEventListener('click', function (event) {
            var control = event.target.closest('[data-theme-choice]');
            if (control) window.setThemeMode(control.getAttribute('data-theme-choice'));
        });
    });

    // Another tab changed the mode: follow it.
    window.addEventListener('storage', function (event) {
        if (event.key === key) apply(event.newValue);
    });
})();
