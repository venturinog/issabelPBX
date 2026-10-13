(function () {
    'use strict';

    var context = window.issabelTabsContext;
    if (window.parent === window || !context || !context.instanceId || !context.tabId) return;

    var origin = window.location.origin;
    var tabs = Array.isArray(context.tabs) ? context.tabs : [];
    var currentTabId = Number(context.tabId);

    function normalizeExtdisplay(display, extdisplay) {
        extdisplay = extdisplay || '';
        if (display === 'did' && extdisplay.indexOf('/') !== -1) return '';
        if ((display === 'extensions' || display === 'users') && extdisplay.indexOf('/') !== -1) {
            extdisplay = extdisplay.split('/')[0];
        }
        return extdisplay;
    }

    function identity(url, useCurrentPageContext) {
        var parsed = new URL(url, window.location.href);
        var hasDisplay = parsed.searchParams.has('display');
        var display = (hasDisplay ? parsed.searchParams.get('display') :
            (useCurrentPageContext ? context.display : '') || '').toLowerCase();
        var extdisplay = parsed.searchParams.has('extdisplay')
            ? parsed.searchParams.get('extdisplay')
            : (useCurrentPageContext && !hasDisplay ? (context.extdisplay || '') : '');
        if (display === '' || display === 'index' || display === 'dashboard') return 'pbx|landing';
        if (display === 'users' && extdisplay !== '') display = 'extensions';
        return display + '|' + normalizeExtdisplay(display, extdisplay);
    }

    function labelForPage() {
        var display = (context.display || '').toLowerCase();
        var extdisplay = context.extdisplay || '';
        if (extdisplay && (display === 'extensions' || display === 'users')) {
            return 'Extension ' + normalizeExtdisplay(display, extdisplay);
        }
        var heading = document.querySelector('h1, .section-title, #title');
        if (heading && heading.textContent.trim()) return heading.textContent.trim();
        var label = context.display || 'PBX';
        if (context.extdisplay) label += ' · ' + context.extdisplay;
        return label;
    }

    function reportPage() {
        var key = context.key || identity(window.location.href, true);
        var title = context.title || labelForPage();
        window.parent.postMessage({
            type: 'tabinfo',
            kind: 'pbx',
            key: key,
            label: title,
            title: title,
            breadcrumb: Array.isArray(context.breadcrumb) ? context.breadcrumb : [],
            display: context.display || '',
            extdisplay: context.extdisplay || ''
        }, origin);
    }

    function addHiddenContext(form) {
        var fields = {
            iss_instance: context.instanceId,
            iss_tab: String(currentTabId)
        };
        Object.keys(fields).forEach(function (name) {
            var input = form.querySelector('input[name="' + name + '"]');
            if (!input) {
                input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                form.appendChild(input);
            }
            input.value = fields[name];
        });
    }

    document.addEventListener('submit', function (event) {
        if (event.target && event.target.tagName === 'FORM') addHiddenContext(event.target);
    }, true);

    document.addEventListener('click', function (event) {
        var link = event.target.closest('a[href]');
        if (!link || (link.target && link.target !== '_self') || event.ctrlKey ||
            event.metaKey || event.shiftKey || event.altKey) return;

        var url = new URL(link.href, window.location.href);
        if (url.origin !== origin || !/\/admin\/config\.php$/.test(url.pathname)) return;
        url.searchParams.set('iss_instance', context.instanceId);
        url.searchParams.set('iss_tab', String(currentTabId));
        link.href = url.href;

        // A target link without extdisplay means the list/add page. Do not
        // inherit extdisplay from the currently edited extension.
        var destination = identity(url.href, false);
        var existing = tabs.filter(function (tab) { return tab.key === destination; })[0];
        if (existing && Number(existing.id) !== currentTabId) {
            event.preventDefault();
            window.parent.postMessage({ type: 'focusTab', tabId: Number(existing.id) }, origin);
        }
    }, true);

    document.addEventListener('input', function (event) {
        var form = event.target.closest('form');
        if (!form || form.dataset.issDirty === '1') return;
        form.dataset.issDirty = '1';
        window.parent.postMessage({ type: 'dirtyChanged', dirty: true }, origin);
    }, true);

    document.addEventListener('change', function (event) {
        var form = event.target.closest('form');
        if (!form || form.dataset.issDirty === '1') return;
        form.dataset.issDirty = '1';
        window.parent.postMessage({ type: 'dirtyChanged', dirty: true }, origin);
    }, true);

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (form && form.tagName === 'FORM' && form.checkValidity()) {
            form.dataset.issDirty = '';
            window.parent.postMessage({ type: 'dirtyChanged', dirty: false }, origin);
        }
    }, true);

    window.addEventListener('message', function (event) {
        if (event.origin !== origin || event.source !== window.parent) return;
        var message = event.data || {};
        if (message.type === 'tabsChanged') {
            tabs = Array.isArray(message.tabs) ? message.tabs : tabs;
        } else if (message.type === 'themeChanged' &&
            (message.theme === 'light' || message.theme === 'dark')) {
            document.documentElement.setAttribute('data-bs-theme', message.theme);
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', reportPage, { once: true });
    } else {
        reportPage();
    }
}());
