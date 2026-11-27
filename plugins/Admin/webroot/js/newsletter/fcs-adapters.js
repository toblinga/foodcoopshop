/**
 * FCS-Adapter fuer den Newsletter-Editor: gleiche Methoden wie proto-adapters.js,
 * aber per fetch() gegen die Admin-Endpunkte. Konfiguration ueber window.NL_CONFIG:
 *   { campaignId, urls: { save, check, test, count, start, progress, products, posts, recipes, media, upload, templates, templateSave, templateDelete } }
 * Alle POSTs senden JSON und den CSRF-Token aus <meta name="csrfToken">.
 * Lizenz: AGPL-3.0
 */
(function (root) {
    'use strict';

    var C = root.NL_CONFIG || { urls: {} };
    var U = C.urls;

    function token() { var m = document.querySelector('meta[name="csrfToken"]'); return m ? m.getAttribute('content') : ''; }

    function req(method, url, body) {
        var opts = { method: method, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': token() } };
        if (body instanceof FormData) opts.body = body;
        else if (body instanceof URLSearchParams) { opts.headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=utf-8'; opts.body = body.toString(); }
        else if (body !== undefined) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
        return fetch(url, opts).then(function (r) {
            return r.text().then(function (t) {
                var j; try { j = t ? JSON.parse(t) : {}; } catch (e) { throw new Error('Antwort des Servers ist kein JSON (HTTP ' + r.status + ').'); }
                if (!r.ok || j.status === 0 || j.ok === false && j.msg) throw new Error(j.msg || j.message || ('HTTP ' + r.status));
                return j;
            });
        });
    }
    function q(url, params) {
        var s = Object.keys(params).filter(function (k) { return params[k] != null && params[k] !== ''; })
            .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&');
        return s ? url + (url.indexOf('?') === -1 ? '?' : '&') + s : url;
    }
    function fill(url) { return String(url).replace('{id}', C.campaignId); }

    root.NLData = {
        searchProducts: function (s) { return req('GET', q(U.products, { q: s })).then(function (j) { return j.items || []; }); },
        getProducts: function (ids) { return req('GET', q(U.products, { ids: ids.join(',') })).then(function (j) { return j.items || []; }); },
        searchPosts: function (s) { return req('GET', q(U.posts, { q: s })).then(function (j) { return j.items || []; }); },
        searchRecipes: function (s) { return req('GET', q(U.recipes || U.posts, { q: s, recipe: 1 })).then(function (j) { return j.items || []; }); },
        listMedia: function () { return req('GET', U.media).then(function (j) { return j.items || []; }); },
        uploadImage: function (file) {
            var fd = new FormData(); fd.append('image', file);
            return req('POST', U.upload, fd).then(function (j) { return { src: j.src, name: j.name }; });
        }
    };

    var initial = C.initial || null;
    root.NLStore = {
        loadDraft: function () { return Promise.resolve(initial); },
        saveDraft: function (state, html) {
            return req('POST', fill(U.save), {
                blocks: state.doc, html: html,
                subject: state.meta.subject, preheader: state.meta.preheader,
                sender_name: state.meta.senderName, sender_email: state.meta.senderEmail
            }).then(function (j) {
                if (j.campaignId && !C.campaignId) { C.campaignId = j.campaignId; if (history.replaceState && j.editUrl) history.replaceState(null, '', j.editUrl); }
                return { ok: true, savedAt: j.savedAt || new Date().toISOString() };
            });
        },
        clearDraft: function () { return Promise.resolve(true); },
        list: function (type) { return req('GET', q(U.templates, { type: type })).then(function (j) { return j.items || []; }); },
        save: function (type, name, blocks) { return req('POST', U.templateSave, { type: type, name: name, blocks: blocks }).then(function (j) { return j.item; }); },
        remove: function (id) { return req('POST', String(U.templateDelete).replace('{tid}', id), {}).then(function () { return true; }); }
    };

    root.NLCheck = {
        check: function (state, html, opts) {
            // NewsletterSpamCheckController liest Formulardaten (getData) und prueft den mitgeschickten Stand, ohne zu speichern
            var fd = new URLSearchParams();
            fd.append('blocks', JSON.stringify(state.doc));
            fd.append('html', html);
            fd.append('subject', state.meta.subject || '');
            fd.append('preheader', state.meta.preheader || '');
            fd.append('sender_name', state.meta.senderName || '');
            fd.append('sender_email', state.meta.senderEmail || '');
            if (opts && opts.checkLinks) fd.append('checkLinks', '1');
            return req('POST', fill(U.check), fd);
        }
    };

    root.NLMail = {
        sendTest: function (state, html, email) {
            return root.NLStore.saveDraft(state, html).then(function () { return req('POST', fill(U.test), { email: email }); })
                .then(function (j) { return { ok: true, message: j.msg || ('Testmail an ' + email + ' ist in der Warteschlange.') }; });
        },
        recipientCount: function () { return req('GET', fill(U.count)).then(function (j) { return j.count; }); },
        startSend: function () { return req('POST', fill(U.start), {}); },
        // Termin setzen ("2026-12-04T09:30", Ortszeit) oder leer = Planung aufheben
        schedule: function (value) { return req('POST', fill(U.schedule), { scheduled_at: value || '' }); },
        progress: function () { return req('GET', fill(U.progress)); },
        // nach dem Start ist die Kampagne kein Entwurf mehr: Statusseite laden
        onSendFinished: function () { location.reload(); }
    };
})(window);
