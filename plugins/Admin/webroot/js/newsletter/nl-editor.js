/**
 * Newsletter-Editor (Bausteine, Drag & Drop, Inline-Text mit Jodit).
 * Abhaengigkeiten: NLRender, NLBlocks, Jodit 4 (wie in FCS), sowie die Adapter
 * NLData, NLStore, NLCheck, NLMail (Prototyp: proto-adapters.js, FCS: fcs-adapters.js).
 * Lizenz: AGPL-3.0
 */
(function (root) {
    'use strict';

    var R = root.NLRender;
    var BL = root.NLBlocks;

    var S = {
        doc: null,
        meta: {},
        selectedId: null,
        device: 'desktop',
        history: [],
        hIndex: -1,
        editors: [],          // aktive Jodit-Instanzen
        dirty: false,
        dragId: null,
        dragNew: null
    };

    var $ = function (sel, el) { return (el || document).querySelector(sel); };
    var $$ = function (sel, el) { return Array.prototype.slice.call((el || document).querySelectorAll(sel)); };
    function h(tag, attrs, html) {
        var e = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            if (k === 'class') e.className = attrs[k];
            else if (k.indexOf('on') === 0) e.addEventListener(k.slice(2), attrs[k]);
            else e.setAttribute(k, attrs[k]);
        });
        if (html != null) e.innerHTML = html;
        return e;
    }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function clone(o) { return JSON.parse(JSON.stringify(o)); }
    function debounce(fn, ms) { var t; return function () { var a = arguments; clearTimeout(t); t = setTimeout(function () { fn.apply(null, a); }, ms); }; }
    function blockById(id) { return S.doc.blocks.filter(function (b) { return b.id === id; })[0]; }
    function indexOf(id) { for (var i = 0; i < S.doc.blocks.length; i++) if (S.doc.blocks[i].id === id) return i; return -1; }
    function footerIndex() { return S.doc.blocks.length - 1; }

    // ------------------------------------------------------------- Verlauf

    function snapshot() { return JSON.stringify({ doc: S.doc, meta: S.meta }); }

    function commit() {
        var snap = snapshot();
        if (S.history[S.hIndex] === snap) return;
        S.history = S.history.slice(0, S.hIndex + 1);
        S.history.push(snap);
        if (S.history.length > 150) S.history.shift();
        S.hIndex = S.history.length - 1;
        updateUndoButtons();
        scheduleSave();
    }
    var commitSoon = debounce(commit, 500);

    function restore(i) {
        finishEditing(true);
        var st = JSON.parse(S.history[i]);
        S.doc = st.doc; S.meta = st.meta; S.hIndex = i;
        if (S.selectedId && !blockById(S.selectedId)) S.selectedId = null;
        renderCanvas(); renderPanel(); updateUndoButtons(); scheduleSave();
    }
    function undo() { finishEditing(); commit(); if (S.hIndex > 0) restore(S.hIndex - 1); }
    function redo() { if (S.hIndex < S.history.length - 1) restore(S.hIndex + 1); }
    function updateUndoButtons() {
        $('#btn-undo').disabled = S.hIndex <= 0;
        $('#btn-redo').disabled = S.hIndex >= S.history.length - 1;
    }

    // ------------------------------------------------------ Automatisch speichern

    function setStatus(txt, cls) { var el = $('#save-status'); el.textContent = txt; el.className = 'save-status ' + (cls || ''); }
    var scheduleSave = debounce(function () {
        setStatus('Speichert …', 'busy');
        var html = R.renderEmail(S.doc, { subject: S.meta.subject, preheader: S.meta.preheader });
        NLStore.saveDraft({ doc: S.doc, meta: S.meta }, html).then(function (r) {
            var t = new Date(r.savedAt || Date.now());
            setStatus('Entwurf gespeichert ' + String(t.getHours()).padStart(2, '0') + ':' + String(t.getMinutes()).padStart(2, '0'), 'ok');
        }).catch(function (e) { setStatus('Nicht gespeichert: ' + e.message, 'err'); });
    }, 1200);

    // -------------------------------------------------------------- Leinwand

    function renderCanvas() {
        finishEditing(true);
        var paper = $('#paper');
        paper.innerHTML = '';
        paper.appendChild(insertZone(0));
        S.doc.blocks.forEach(function (b, i) {
            paper.appendChild(blockEl(b));
            if (b.type !== 'footer') paper.appendChild(insertZone(i + 1));
        });
        $('#paper-wrap').style.background = S.doc.settings.bodyBg;
    }

    function insertZone(index) {
        var z = h('div', { class: 'ins', 'data-index': index });
        var btn = h('button', { class: 'ins-btn', type: 'button', title: 'Baustein hier einfügen', 'aria-label': 'Baustein hier einfügen' }, '+');
        btn.addEventListener('click', function (e) { e.stopPropagation(); openQuickAdd(btn, Number(z.getAttribute('data-index'))); });
        z.appendChild(btn);
        return z;
    }

    function blockEl(b) {
        var def = BL.DEFS[b.type];
        var wrap = h('div', { class: 'blk' + (b.id === S.selectedId ? ' sel' : '') + (b.locked ? ' locked' : ''), 'data-id': b.id, tabindex: '-1' });
        var tools = h('div', { class: 'blk-tools' });
        tools.innerHTML =
            '<span class="blk-name">' + esc(def.label) + (b.locked ? ' · Pflicht' : '') + '</span>' +
            (b.locked ? '' :
                '<span class="blk-handle" draggable="true" title="Ziehen zum Verschieben" aria-hidden="true">' + svg('grip') + '</span>' +
                '<button type="button" data-act="up" title="Nach oben">' + svg('up') + '</button>' +
                '<button type="button" data-act="down" title="Nach unten">' + svg('down') + '</button>' +
                '<button type="button" data-act="dup" title="Duplizieren">' + svg('copy') + '</button>' +
                '<button type="button" data-act="save" title="Als Baustein speichern">' + svg('star') + '</button>' +
                '<button type="button" data-act="del" class="danger" title="Löschen">' + svg('trash') + '</button>');
        wrap.appendChild(tools);
        wrap.appendChild(h('div', { class: 'rt-toolbar' }));
        var body = h('div', { class: 'blk-body' }, R.renderBlock(b, { edit: true }, S.doc.settings));
        wrap.appendChild(body);
        return wrap;
    }

    function rerenderBlock(id) {
        var el = $('.blk[data-id="' + id + '"]');
        var b = blockById(id);
        if (!el || !b) return;
        $('.blk-body', el).innerHTML = R.renderBlock(b, { edit: true }, S.doc.settings);
    }

    function svg(n) {
        var p = {
            grip: '<circle cx="9" cy="6" r="1.4"/><circle cx="15" cy="6" r="1.4"/><circle cx="9" cy="12" r="1.4"/><circle cx="15" cy="12" r="1.4"/><circle cx="9" cy="18" r="1.4"/><circle cx="15" cy="18" r="1.4"/>',
            up: '<path d="M12 19V5M6 11l6-6 6 6"/>',
            down: '<path d="M12 5v14M6 13l6 6 6-6"/>',
            copy: '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3"/>',
            star: '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z"/>',
            trash: '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/>',
            undo: '<path d="M9 14L4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-3"/>',
            redo: '<path d="M15 14l5-5-5-5"/><path d="M20 9H9a5 5 0 0 0 0 10h3"/>',
            desktop: '<rect x="3" y="4" width="18" height="12" rx="1.5"/><path d="M8 20h8M12 16v4"/>',
            phone: '<rect x="7" y="3" width="10" height="18" rx="2"/><path d="M11 18h2"/>',
            eye: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
            check: '<path d="M4 12l5 5L20 6"/>',
            mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
            send: '<path d="M22 2L11 13M22 2l-7 20-4-9-9-4z"/>'
        }[n] || '';
        var fill = n === 'grip' ? 'currentColor' : 'none';
        return '<svg viewBox="0 0 24 24" width="18" height="18" fill="' + fill + '" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + p + '</svg>';
    }

    // ------------------------------------------------------------- Auswahl

    function select(id, opts) {
        opts = opts || {};
        if (S.selectedId === id && !opts.force) return;
        finishEditing();
        S.selectedId = id;
        $$('.blk').forEach(function (el) { el.classList.toggle('sel', el.getAttribute('data-id') === id); });
        renderPanel();
        if (opts.scroll && id) {
            var el = $('.blk[data-id="' + id + '"]');
            if (el) {
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                el.classList.remove('flash'); void el.offsetWidth; el.classList.add('flash');
            }
        }
    }

    // ----------------------------------------------------------- Blockaktionen

    function insertBlock(block, index) {
        finishEditing();
        if (index == null) {
            var si = S.selectedId ? indexOf(S.selectedId) : -1;
            index = si >= 0 && si < footerIndex() ? si + 1 : footerIndex();
        }
        index = Math.min(index, footerIndex());
        S.doc.blocks.splice(index, 0, block);
        S.selectedId = block.id;
        renderCanvas(); renderPanel(); commit();
        select(block.id, { force: true, scroll: true });
    }

    function addType(type, index) { insertBlock(BL.create(type), index); }

    function freshIds(b) { b = clone(b); b.id = R.newId(); delete b.locked; return b; }

    function act(id, action) {
        var i = indexOf(id);
        var b = S.doc.blocks[i];
        if (!b || b.locked) return;
        finishEditing();
        if (action === 'up' && i > 0) { S.doc.blocks.splice(i - 1, 0, S.doc.blocks.splice(i, 1)[0]); }
        else if (action === 'down' && i < footerIndex() - 1) { S.doc.blocks.splice(i + 1, 0, S.doc.blocks.splice(i, 1)[0]); }
        else if (action === 'dup') { var c = freshIds(b); S.doc.blocks.splice(i + 1, 0, c); S.selectedId = c.id; }
        else if (action === 'del') { S.doc.blocks.splice(i, 1); if (S.selectedId === id) S.selectedId = null; toast('Baustein gelöscht. Rückgängig mit Strg+Z.', { label: 'Rückgängig', fn: undo }); }
        else if (action === 'save') { saveSnippet(b); return; }
        else return;
        renderCanvas(); renderPanel(); commit();
        if (S.selectedId) select(S.selectedId, { force: true, scroll: action !== 'del' });
    }

    function moveBlock(id, toIndex) {
        var from = indexOf(id);
        if (from < 0 || S.doc.blocks[from].locked) return;
        var b = S.doc.blocks.splice(from, 1)[0];
        if (toIndex > from) toIndex--;
        toIndex = Math.min(toIndex, footerIndex());
        S.doc.blocks.splice(toIndex, 0, b);
        S.selectedId = id;
        renderCanvas(); renderPanel(); commit();
    }

    // ------------------------------------------------------------ Text bearbeiten

    function joditOptions(toolbarEl) {
        var list = {};
        R.PLACEHOLDERS.forEach(function (p) { if (!p.system) list[p.key] = p.label; });
        return {
            inline: true,
            toolbarInline: false,
            toolbar: toolbarEl,
            language: 'de',
            enter: 'p',
            hidePoweredByJodit: true,
            showCharsCounter: false, showWordsCounter: false, showXPathInStatusbar: false,
            toolbarAdaptive: false,
            disablePlugins: 'paste,image,video,file,media,iframe,table,source,fullsize,print,about,speech-recognize,ai-assistant,spellcheck',
            askBeforePasteHTML: false, askBeforePasteFromWord: false,
            defaultActionOnPaste: 'insert_clear_html',
            colors: { 'Farben der Foodcoop': R.CD_COLORS },
            colorPickerDefaultTab: 'color',
            link: { noFollowCheckbox: false, openInNewTabCheckbox: false, modeClassName: false },
            buttons: ['bold', 'italic', 'underline', '|', 'ul', 'ol', '|', 'link', 'brush', '|', 'nlPlaceholder', '|', 'eraser'],
            controls: {
                brush: { tooltip: 'Textfarbe' },
                nlPlaceholder: {
                    name: 'nlPlaceholder',
                    text: '{{ }} Platzhalter',
                    tooltip: 'Platzhalter einfügen, z. B. den Vornamen',
                    list: list,
                    exec: function (editor, cur, opt) {
                        var key = opt && opt.control && opt.control.args && opt.control.args[0];
                        if (key) editor.s.insertHTML('{{' + key + '}}');
                        return false;
                    }
                }
            }
        };
    }

    function startRich(el) {
        var blkEl = el.closest('.blk');
        var id = blkEl.getAttribute('data-id');
        var path = el.getAttribute('data-edit-rich');
        var b = blockById(id);
        if (!b || el.classList.contains('jodit-wysiwyg')) return;
        finishEditing();
        var tb = $('.rt-toolbar', blkEl);
        tb.innerHTML = '';
        // Inhalt aus dem Modell (nicht die gestylte Darstellung)
        el.innerHTML = R.get(b.data, path) || '<p></p>';
        var ed = root.Jodit.make(el, joditOptions(tb));
        blkEl.classList.add('editing');
        ed.events.on('change', function (v) { R.set(b.data, path, v); S.dirty = true; commitSoon(); });
        S.editors.push({ ed: ed, id: id, path: path, block: b });
        setTimeout(function () { try { ed.s.focus(); } catch (e) { el.focus(); } }, 0);
    }

    function startPlain(el) {
        var blkEl = el.closest('.blk');
        var id = blkEl.getAttribute('data-id');
        var path = el.getAttribute('data-edit-plain');
        var b = blockById(id);
        if (!b || el.isContentEditable) return;
        finishEditing();
        el.textContent = R.get(b.data, path) || '';
        try { el.contentEditable = 'plaintext-only'; } catch (e) { el.contentEditable = 'true'; }
        if (el.contentEditable !== 'plaintext-only') el.contentEditable = 'true';
        el.classList.add('plain-editing');
        el.focus();
        var range = document.createRange(); range.selectNodeContents(el); range.collapse(false);
        var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(range);
        function onInput() { R.set(b.data, path, el.textContent.replace(/\s+/g, ' ')); commitSoon(); }
        function onKey(e) { if (e.key === 'Enter' || e.key === 'Escape') { e.preventDefault(); el.blur(); } }
        function onBlur() {
            el.removeEventListener('input', onInput); el.removeEventListener('keydown', onKey); el.removeEventListener('blur', onBlur);
            el.contentEditable = 'false';
            R.set(b.data, path, el.textContent.replace(/\s+/g, ' ').trim());
            rerenderBlock(id); commit(); if (S.selectedId === id) renderPanel();
        }
        el.addEventListener('input', onInput); el.addEventListener('keydown', onKey); el.addEventListener('blur', onBlur);
    }

    function finishEditing(silent) {
        if (!S.editors.length) return;
        var ids = {};
        S.editors.forEach(function (x) {
            try { R.set(x.block.data, x.path, x.ed.value); } catch (e) { /* */ }
            try { x.ed.destruct(); } catch (e) { /* */ }
            ids[x.id] = 1;
        });
        S.editors = [];
        $$('.blk.editing').forEach(function (el) { el.classList.remove('editing'); $('.rt-toolbar', el).innerHTML = ''; });
        if (!silent) { Object.keys(ids).forEach(rerenderBlock); commit(); }
    }

    // --------------------------------------------------------- Ziehen & Ablegen

    function bindCanvas() {
        var paper = $('#paper');

        paper.addEventListener('click', function (e) {
            var a = e.target.closest('a');
            if (a) e.preventDefault(); // Links in der Leinwand nicht oeffnen
            var actBtn = e.target.closest('[data-act]');
            var blk = e.target.closest('.blk');
            if (actBtn && blk) { act(blk.getAttribute('data-id'), actBtn.getAttribute('data-act')); return; }
            if (!blk) { select(null); return; }
            var id = blk.getAttribute('data-id');
            if (S.selectedId !== id) select(id);
            var rich = e.target.closest('[data-edit-rich]');
            var plain = e.target.closest('[data-edit-plain]');
            if (rich && !rich.classList.contains('jodit-wysiwyg')) startRich(rich);
            else if (plain && !rich) startPlain(plain);
        });

        paper.addEventListener('dragstart', function (e) {
            var handle = e.target.closest && e.target.closest('.blk-handle');
            if (!handle) { if (!e.target.closest || !e.target.closest('.pal-item')) e.preventDefault(); return; }
            var blk = handle.closest('.blk');
            S.dragId = blk.getAttribute('data-id');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', 'nl-move');
            try { e.dataTransfer.setDragImage(blk, 40, 20); } catch (x) { /* */ }
            setTimeout(function () { blk.classList.add('dragging'); }, 0);
        });
        paper.addEventListener('dragend', function () { S.dragId = null; clearDrop(); $$('.blk.dragging, .blk[draggable]').forEach(function (x) { x.classList.remove('dragging'); x.removeAttribute('draggable'); }); });

        var wrap = $('#paper-wrap');
        wrap.addEventListener('dragover', function (e) {
            if (!S.dragId && !S.dragNew) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = S.dragId ? 'move' : 'copy';
            showDrop(dropIndex(e.clientY));
        });
        wrap.addEventListener('dragleave', function (e) { if (!wrap.contains(e.relatedTarget)) clearDrop(); });
        wrap.addEventListener('drop', function (e) {
            if (!S.dragId && !S.dragNew) return;
            e.preventDefault();
            var idx = dropIndex(e.clientY);
            clearDrop();
            if (S.dragId) moveBlock(S.dragId, idx);
            else if (S.dragNew) S.dragNew(idx);
            S.dragId = null; S.dragNew = null;
        });
    }

    function dropIndex(y) {
        var blks = $$('#paper .blk');
        for (var i = 0; i < blks.length; i++) {
            var r = blks[i].getBoundingClientRect();
            if (y < r.top + r.height / 2) return i;
        }
        return footerIndex();
    }
    function showDrop(idx) {
        $$('#paper .ins').forEach(function (z) { z.classList.toggle('drop', Number(z.getAttribute('data-index')) === Math.min(idx, footerIndex())); });
    }
    function clearDrop() { $$('#paper .ins.drop').forEach(function (z) { z.classList.remove('drop'); }); }

    // ------------------------------------------------------------------ Palette

    function renderPalette() {
        var pal = $('#tab-blocks');
        pal.innerHTML = '';
        var groups = {};
        Object.keys(BL.DEFS).forEach(function (t) {
            var d = BL.DEFS[t];
            if (!d.group) return;
            (groups[d.group] = groups[d.group] || []).push(t);
        });
        Object.keys(groups).forEach(function (g) {
            pal.appendChild(h('h3', { class: 'pal-h' }, esc(g)));
            var grid = h('div', { class: 'pal-grid' });
            groups[g].forEach(function (t) {
                var tile = h('button', { class: 'pal-item', type: 'button', draggable: 'true', 'data-type': t, title: 'Klicken zum Einfügen oder in die Mail ziehen' },
                    BL.icon(t) + '<span>' + esc(BL.DEFS[t].label) + '</span>');
                tile.addEventListener('click', function () { addType(t); });
                tile.addEventListener('dragstart', function (e) {
                    S.dragNew = function (idx) { addType(t, idx); };
                    e.dataTransfer.effectAllowed = 'copy';
                    e.dataTransfer.setData('text/plain', 'nl-new');
                });
                tile.addEventListener('dragend', function () { S.dragNew = null; clearDrop(); });
                grid.appendChild(tile);
            });
            pal.appendChild(grid);
        });
        pal.appendChild(h('p', { class: 'pal-tip' }, 'Tipp: Baustein anklicken fügt ihn unter dem ausgewählten ein. Oder in die Mail ziehen.'));
    }

    function openQuickAdd(anchor, index) {
        closePopovers();
        var pop = h('div', { class: 'nl-popover quick-add', role: 'menu' });
        Object.keys(BL.DEFS).forEach(function (t) {
            if (!BL.DEFS[t].group) return;
            var b = h('button', { type: 'button', role: 'menuitem' }, BL.icon(t) + '<span>' + esc(BL.DEFS[t].label) + '</span>');
            b.addEventListener('click', function () { closePopovers(); addType(t, index); });
            pop.appendChild(b);
        });
        document.body.appendChild(pop);
        var r = anchor.getBoundingClientRect();
        var left = Math.max(8, Math.min(window.innerWidth - pop.offsetWidth - 8, r.left + r.width / 2 - pop.offsetWidth / 2));
        var top = r.bottom + 6;
        if (top + pop.offsetHeight > window.innerHeight - 8) top = Math.max(8, r.top - pop.offsetHeight - 6);
        pop.style.left = left + 'px'; pop.style.top = top + 'px';
        setTimeout(function () { document.addEventListener('click', closePopovers, { once: true }); }, 0);
    }
    function closePopovers() { $$('.nl-popover').forEach(function (p) { p.remove(); }); }

    // ---------------------------------------------- Gespeicherte Bausteine / Vorlagen

    function saveSnippet(b) {
        ask('Baustein speichern', 'Name für den gespeicherten Baustein', BL.DEFS[b.type].label + ' ' + new Date().toLocaleDateString('de-AT'), function (name) {
            var copy = clone(b);
            NLStore.save('block', name, [copy]).then(function () { toast('Baustein „' + name + '“ gespeichert.'); renderSaved(); showTab('saved'); });
        });
    }

    function saveTemplate() {
        finishEditing();
        ask('Als Vorlage speichern', 'Name der Vorlage', S.meta.name || 'Neue Vorlage', function (name) {
            NLStore.save('template', name, clone(S.doc)).then(function () { toast('Vorlage „' + name + '“ gespeichert.'); renderTemplates(); showTab('templates'); });
        });
    }

    function renderSaved() {
        var box = $('#tab-saved');
        NLStore.list('block').then(function (list) {
            box.innerHTML = '';
            if (!list.length) {
                box.appendChild(h('p', { class: 'empty' }, 'Noch keine gespeicherten Bausteine. Über den Stern ' + svg('star') + ' an einem Baustein legst du ihn hier ab, z. B. eine Grußformel oder die Öffnungszeiten.'));
                return;
            }
            list.forEach(function (t) {
                var b0 = t.blocks[0];
                var item = h('div', { class: 'saved-item', draggable: 'true' });
                item.innerHTML = '<span class="saved-ico">' + BL.icon(b0.type) + '</span><span class="saved-name"><strong>' + esc(t.name) + '</strong><small>' + esc(BL.DEFS[b0.type] ? BL.DEFS[b0.type].label : b0.type) + '</small></span>';
                var ins = h('button', { type: 'button', class: 'nl-btn small' }, 'Einfügen');
                ins.addEventListener('click', function () { insertBlock(freshIds(b0)); });
                var del = h('button', { type: 'button', class: 'icon-btn danger', title: 'Gespeicherten Baustein löschen' }, svg('trash'));
                del.addEventListener('click', function () {
                    confirmDialog('Gespeicherten Baustein löschen?', '„' + t.name + '“ wird aus der Liste entfernt. Kampagnen, die ihn schon verwenden, bleiben unverändert.', 'Löschen', function () { NLStore.remove(t.id).then(renderSaved); });
                });
                item.appendChild(ins); item.appendChild(del);
                item.addEventListener('dragstart', function (e) { S.dragNew = function (idx) { insertBlock(freshIds(b0), idx); }; e.dataTransfer.setData('text/plain', 'nl-new'); });
                item.addEventListener('dragend', function () { S.dragNew = null; clearDrop(); });
                box.appendChild(item);
            });
        });
    }

    function renderTemplates() {
        var box = $('#tab-templates');
        box.innerHTML = '';
        var save = h('button', { type: 'button', class: 'nl-btn primary block' }, 'Aktuelle Kampagne als Vorlage speichern');
        save.addEventListener('click', saveTemplate);
        box.appendChild(save);
        NLStore.list('template').then(function (own) {
            var all = own.concat(root.NLSamples ? root.NLSamples.builtinTemplates() : []);
            all.forEach(function (t) {
                var item = h('div', { class: 'tpl-item' });
                var thumb = h('div', { class: 'tpl-thumb' });
                var frame = h('iframe', { title: 'Vorschau ' + t.name, tabindex: '-1', loading: 'lazy' });
                thumb.appendChild(frame);
                item.appendChild(thumb);
                var info = h('div', { class: 'tpl-info' }, '<strong>' + esc(t.name) + '</strong><small>' + (t.builtin ? 'Mitgeliefert' : 'Eigene Vorlage') + '</small>');
                var use = h('button', { type: 'button', class: 'nl-btn small' }, 'Damit neu starten');
                use.addEventListener('click', function () {
                    confirmDialog('Mit dieser Vorlage neu starten?', 'Der Inhalt der aktuellen Kampagne wird ersetzt. Betreff und Absender bleiben. Mit „Rückgängig“ kommst du zurück.', 'Neu starten', function () {
                        finishEditing(true);
                        var d = R.normalize(clone(t.blocks));
                        d.blocks = d.blocks.map(function (b) { return b.type === 'footer' ? b : freshIds(b); });
                        S.doc = d; S.selectedId = null;
                        renderCanvas(); renderPanel(); commit();
                        toast('Vorlage „' + t.name + '“ geladen.', { label: 'Rückgängig', fn: undo });
                    });
                });
                info.appendChild(use);
                if (!t.builtin) {
                    var del = h('button', { type: 'button', class: 'icon-btn danger', title: 'Vorlage löschen' }, svg('trash'));
                    del.addEventListener('click', function () { confirmDialog('Vorlage löschen?', '„' + t.name + '“ wird gelöscht.', 'Löschen', function () { NLStore.remove(t.id).then(renderTemplates); }); });
                    info.appendChild(del);
                }
                item.appendChild(info);
                box.appendChild(item);
                frame.srcdoc = R.renderEmail(t.blocks, { preview: true });
            });
        });
    }

    function showTab(name) {
        $$('.tabs button').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-tab') === name); b.setAttribute('aria-selected', b.getAttribute('data-tab') === name); });
        $$('.tab-panel').forEach(function (p) { p.hidden = p.id !== 'tab-' + name; });
    }

    // -------------------------------------------------------- Eigenschaften-Panel

    function renderPanel() {
        var panel = $('#props');
        panel.innerHTML = '';
        var b = S.selectedId ? blockById(S.selectedId) : null;
        if (!b) { renderCampaignPanel(panel); return; }
        var def = BL.DEFS[b.type];
        var head = h('div', { class: 'props-head' }, '<span class="props-ico">' + BL.icon(b.type) + '</span><div><small>Baustein</small><h2>' + esc(def.label) + '</h2></div>');
        var close = h('button', { type: 'button', class: 'icon-btn', title: 'Auswahl aufheben' }, '&times;');
        close.addEventListener('click', function () { select(null); });
        head.appendChild(close);
        panel.appendChild(head);
        if (def.hint) panel.appendChild(h('p', { class: 'hint' }, esc(def.hint)));
        def.fields.forEach(function (f) { panel.appendChild(field(b, f)); });
        if (!b.locked) {
            var actions = h('div', { class: 'props-actions' });
            [['dup', 'Duplizieren', 'copy'], ['save', 'Als Baustein speichern', 'star'], ['del', 'Löschen', 'trash']].forEach(function (a) {
                var btn = h('button', { type: 'button', class: 'nl-btn' + (a[0] === 'del' ? ' danger' : '') }, svg(a[2]) + ' ' + a[1]);
                btn.addEventListener('click', function () { act(b.id, a[0]); });
                actions.appendChild(btn);
            });
            panel.appendChild(actions);
        }
        panel.appendChild(h('p', { class: 'block-id' }, 'Block-ID: <code>' + esc(b.id) + '</code>'));
    }

    function getPath(b, key) { return R.get(b, key); }
    function setPath(b, key, v) {
        var keys = key.split('.');
        var o = b;
        for (var i = 0; i < keys.length - 1; i++) { if (o[keys[i]] == null) o[keys[i]] = {}; o = o[keys[i]]; }
        o[keys[keys.length - 1]] = v;
    }
    function changed(b, opts) {
        if (S.editors.some(function (x) { return x.id === b.id; })) finishEditing(true);
        rerenderBlock(b.id); if (opts && opts.soon) commitSoon(); else commit(); }

    function field(b, f) {
        var row = h('div', { class: 'field' });
        var id = 'f-' + Math.random().toString(36).slice(2, 8);
        var val = f.key ? getPath(b, f.key) : null;
        if (f.type === 'section') { row.className = 'field-section'; row.textContent = f.label; return row; }
        if (f.type === 'note') { row.className = 'note'; row.textContent = f.text; return row; }
        if (f.label && f.type !== 'picker') row.appendChild(h('label', { for: id }, esc(f.label) + (f.required ? ' <span class="req" title="Pflichtfeld">*</span>' : '')));

        if (f.type === 'text' || f.type === 'url') {
            var inp = h('input', { id: id, type: f.type === 'url' ? 'url' : 'text', value: val == null ? '' : val, placeholder: f.type === 'url' ? 'https://…' : '' });
            inp.addEventListener('input', function () { setPath(b, f.key, inp.value); validate(); changed(b, { soon: true }); });
            var validate = function () {
                var bad = (f.required && !inp.value.trim()) || (f.type === 'url' && inp.value && !R.safeUrl(inp.value));
                inp.classList.toggle('invalid', !!bad);
            };
            validate();
            row.appendChild(inp);
        } else if (f.type === 'textarea') {
            var ta = h('textarea', { id: id, rows: 4 });
            ta.value = val || '';
            ta.addEventListener('input', function () { setPath(b, f.key, ta.value); changed(b, { soon: true }); });
            row.appendChild(ta);
        } else if (f.type === 'choice') {
            var seg = h('div', { class: 'seg', role: 'radiogroup', id: id });
            var cur = val == null && f.key === 'style.bg' ? 'white' : val;
            f.options.forEach(function (o) {
                var btn = h('button', { type: 'button', role: 'radio', 'aria-checked': String(String(cur) === String(o[0])), class: String(cur) === String(o[0]) ? 'on' : '' }, esc(o[1]));
                btn.addEventListener('click', function () {
                    setPath(b, f.key, f.number ? Number(o[0]) : o[0]);
                    $$('button', seg).forEach(function (x) { x.classList.remove('on'); x.setAttribute('aria-checked', 'false'); });
                    btn.classList.add('on'); btn.setAttribute('aria-checked', 'true');
                    changed(b);
                });
                seg.appendChild(btn);
            });
            row.appendChild(seg);
        } else if (f.type === 'range') {
            var wrapR = h('div', { class: 'range' });
            var rng = h('input', { id: id, type: 'range', min: f.min, max: f.max, step: f.step || 1, value: val || f.min });
            var out = h('output', {}, (val || f.min) + ' ' + (f.unit || ''));
            rng.addEventListener('input', function () { setPath(b, f.key, Number(rng.value)); out.textContent = rng.value + ' ' + (f.unit || ''); changed(b, { soon: true }); });
            wrapR.appendChild(rng); wrapR.appendChild(out);
            row.appendChild(wrapR);
        } else if (f.type === 'image') {
            row.appendChild(imageField(b, f, val));
        } else if (f.type === 'picker') {
            row.appendChild(pickerField(b, f));
        } else if (f.type === 'social') {
            row.appendChild(socialField(b, f));
        }
        if (f.help) row.appendChild(h('small', { class: 'help' }, esc(f.help)));
        return row;
    }

    function imageField(b, f, val) {
        var box = h('div', { class: 'img-field' });
        var prev = h('div', { class: 'img-prev' }, val ? '<img src="' + esc(val) + '" alt="">' : '<span>Kein Bild</span>');
        box.appendChild(prev);
        var btns = h('div', { class: 'img-btns' });
        var up = h('label', { class: 'nl-btn small' }, 'Hochladen<input type="file" accept="image/jpeg,image/png,image/gif,image/webp" hidden>');
        $('input', up).addEventListener('change', function (e) {
            var file = e.target.files[0]; if (!file) return;
            if (file.size > 3 * 1024 * 1024) { toast('Das Bild ist größer als 3 MB. Bitte verkleinern.'); return; }
            NLData.uploadImage(file).then(function (r) { setImg(r.src); toast('Bild hochgeladen.'); }).catch(function (err) { toast(err.message); });
        });
        var lib = h('button', { type: 'button', class: 'nl-btn small' }, 'Aus Mediathek');
        lib.addEventListener('click', function () { openMedia(setImg); });
        btns.appendChild(up); btns.appendChild(lib);
        if (f.optional && val) {
            var rm = h('button', { type: 'button', class: 'nl-btn small danger' }, 'Entfernen');
            rm.addEventListener('click', function () { setImg(''); });
            btns.appendChild(rm);
        }
        box.appendChild(btns);
        var url = h('input', { type: 'text', value: val && !/^data:/.test(val) ? val : '', placeholder: 'oder Bildadresse / FCS-Bildpfad' });
        url.addEventListener('change', function () { setImg(url.value.trim()); });
        box.appendChild(url);
        function setImg(src) { setPath(b, f.key, src); changed(b); renderPanel(); }
        return box;
    }

    function pickerField(b, f) {
        var box = h('div', { class: 'picker-field' });
        var btn = h('button', { type: 'button', class: 'nl-btn primary block' }, esc(f.label));
        btn.addEventListener('click', function () {
            if (f.picker === 'products') openProductPicker(b);
            else openPostPicker(b, f.picker);
        });
        box.appendChild(btn);
        if (f.picker === 'products') {
            var items = b.data.items || [];
            var list = h('ol', { class: 'chosen' });
            items.forEach(function (p, i) {
                var li = h('li', {}, '<img src="' + esc(p.image) + '" alt=""><span>' + esc(p.name) + '<small>' + esc(p.unit || '') + ' · € ' + Number(p.price).toFixed(2).replace('.', ',') + '</small></span>');
                var up = h('button', { type: 'button', class: 'icon-btn', title: 'Nach vorne' }, svg('up'));
                up.disabled = i === 0;
                up.addEventListener('click', function () { items.splice(i - 1, 0, items.splice(i, 1)[0]); changed(b); renderPanel(); });
                var rm = h('button', { type: 'button', class: 'icon-btn danger', title: 'Entfernen' }, '&times;');
                rm.addEventListener('click', function () { items.splice(i, 1); changed(b); renderPanel(); });
                li.appendChild(up); li.appendChild(rm);
                list.appendChild(li);
            });
            if (items.length) {
                box.appendChild(list);
                var refresh = h('button', { type: 'button', class: 'nl-btn small' }, 'Preise und Bilder aktualisieren');
                refresh.addEventListener('click', function () {
                    NLData.getProducts(items.map(function (p) { return p.productId; })).then(function (fresh) {
                        b.data.items = items.map(function (p) { return fresh.filter(function (x) { return x.productId === p.productId; })[0] || p; });
                        changed(b); renderPanel(); toast('Produktdaten aktualisiert.');
                    });
                });
                box.appendChild(refresh);
            }
        }
        return box;
    }

    function socialField(b, f) {
        var box = h('div', { class: 'social' });
        var list = getPath(b, f.key) || [];
        var nets = [['facebook', 'Facebook'], ['instagram', 'Instagram'], ['website', 'Website'], ['signal', 'Signal'], ['whatsapp', 'WhatsApp'], ['youtube', 'YouTube']];
        list.forEach(function (s, i) {
            var row = h('div', { class: 'social-row' });
            var sel = h('select', { 'aria-label': 'Netzwerk' });
            nets.forEach(function (n) { var o = h('option', { value: n[0] }, n[1]); if (n[0] === s.network) o.selected = true; sel.appendChild(o); });
            sel.addEventListener('change', function () { s.network = sel.value; changed(b); });
            var u = h('input', { type: 'url', value: s.url || '', placeholder: 'https://…', 'aria-label': 'Adresse' });
            u.addEventListener('input', function () { s.url = u.value; changed(b, { soon: true }); });
            var rm = h('button', { type: 'button', class: 'icon-btn danger', title: 'Entfernen' }, '&times;');
            rm.addEventListener('click', function () { list.splice(i, 1); changed(b); renderPanel(); });
            row.appendChild(sel); row.appendChild(u); row.appendChild(rm);
            box.appendChild(row);
        });
        var add = h('button', { type: 'button', class: 'nl-btn small' }, '+ Link hinzufügen');
        add.addEventListener('click', function () { list.push({ network: 'website', url: '' }); setPath(b, f.key, list); changed(b); renderPanel(); });
        box.appendChild(add);
        return box;
    }

    function renderCampaignPanel(panel) {
        panel.appendChild(h('div', { class: 'props-head' }, '<span class="props-ico">' + svg('mail') + '</span><div><small>Kampagne</small><h2>Einstellungen</h2></div>'));
        panel.appendChild(h('p', { class: 'hint' }, 'Klick auf einen Baustein in der Mail, um ihn zu bearbeiten.'));
        [
            ['name', 'Interner Name', 'text', 'Nur für euch, z. B. „Newsletter Dezember“.'],
            ['subject', 'Betreff', 'text', 'Kurz und konkret, höchstens 50 bis 70 Zeichen.'],
            ['preheader', 'Vorschautext', 'text', 'Erscheint im Postfach neben dem Betreff.'],
            ['senderName', 'Absender-Name', 'text', ''],
            ['senderEmail', 'Absender-Adresse', 'email', '']
        ].filter(function (x) { return !(x[0] === 'name' && root.NL_CONFIG); }).forEach(function (x) { // in FCS gibt es keinen internen Namen
            var row = h('div', { class: 'field' });
            var id = 'meta-' + x[0];
            row.appendChild(h('label', { for: id }, esc(x[1])));
            var inp = h('input', { id: id, type: x[2], value: S.meta[x[0]] || '' });
            var count = x[0] === 'subject' || x[0] === 'preheader' ? h('small', { class: 'count' }) : null;
            function upd() { if (count) count.textContent = (inp.value.length) + ' Zeichen'; }
            inp.addEventListener('input', function () { S.meta[x[0]] = inp.value; upd(); commitSoon(); });
            row.appendChild(inp);
            if (count) { upd(); row.appendChild(count); }
            if (x[3]) row.appendChild(h('small', { class: 'help' }, esc(x[3])));
            panel.appendChild(row);
        });
        var ph = h('div', { class: 'note' });
        ph.innerHTML = '<strong>Platzhalter</strong> im Text werden beim Versand ersetzt: ' +
            R.PLACEHOLDERS.filter(function (p) { return !p.system; }).map(function (p) { return '<code>{{' + p.key + '}}</code>'; }).join(' ') +
            '<br>Mit Ersatztext, falls leer: <code>{{vorname|liebe Mitglieder}}</code>';
        panel.appendChild(ph);
    }

    // ------------------------------------------------------------------- Dialoge

    function modal(opts) {
        closeModal();
        var back = h('div', { class: 'nl-modal-back' });
        var m = h('div', { class: 'nl-modal ' + (opts.cls || ''), role: 'dialog', 'aria-modal': 'true', 'aria-label': opts.title });
        var head = h('div', { class: 'nl-modal-head' }, '<h2>' + esc(opts.title) + '</h2>');
        var x = h('button', { type: 'button', class: 'icon-btn', title: 'Schließen', 'aria-label': 'Schließen' }, '&times;');
        x.addEventListener('click', closeModal);
        head.appendChild(x);
        m.appendChild(head);
        var body = h('div', { class: 'nl-modal-body' });
        if (typeof opts.body === 'string') body.innerHTML = opts.body; else if (opts.body) body.appendChild(opts.body);
        m.appendChild(body);
        if (opts.actions) {
            var foot = h('div', { class: 'nl-modal-foot' });
            opts.actions.forEach(function (a) {
                var b = h('button', { type: 'button', class: 'nl-btn ' + (a.cls || '') }, esc(a.label));
                b.addEventListener('click', function () { if (a.fn && a.fn() === false) return; if (!a.keep) closeModal(); });
                foot.appendChild(b);
            });
            m.appendChild(foot);
        }
        back.appendChild(m);
        back.addEventListener('mousedown', function (e) { if (e.target === back) closeModal(); });
        document.body.appendChild(back);
        var first = m.querySelector('input, .nl-modal-foot .primary, button');
        if (first) setTimeout(function () { first.focus(); }, 30);
        return { el: m, body: body };
    }
    function closeModal() { $$('.nl-modal-back').forEach(function (x) { x.remove(); }); }

    function confirmDialog(title, text, okLabel, fn, danger) {
        modal({ title: title, body: '<p>' + esc(text) + '</p>', actions: [{ label: 'Abbrechen' }, { label: okLabel, cls: danger === false ? 'primary' : 'primary', fn: fn }] });
    }

    function ask(title, label, def, fn) {
        var wrapEl = h('div', { class: 'field' }, '<label for="ask-in">' + esc(label) + '</label>');
        var inp = h('input', { id: 'ask-in', type: 'text', value: def || '' });
        wrapEl.appendChild(inp);
        var go = function () { var v = inp.value.trim(); if (!v) { inp.classList.add('invalid'); return false; } fn(v); };
        inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { if (go() !== false) closeModal(); } });
        modal({ title: title, body: wrapEl, actions: [{ label: 'Abbrechen' }, { label: 'Speichern', cls: 'primary', fn: go }] });
        setTimeout(function () { inp.select(); }, 40);
    }

    function toast(text, action) {
        var t = h('div', { class: 'nl-toast', role: 'status' }, '<span>' + esc(text) + '</span>');
        if (action) {
            var b = h('button', { type: 'button' }, esc(action.label));
            b.addEventListener('click', function () { action.fn(); t.remove(); });
            t.appendChild(b);
        }
        $('#toasts').appendChild(t);
        setTimeout(function () { t.classList.add('out'); setTimeout(function () { t.remove(); }, 400); }, 5000);
    }

    function openMedia(cb) {
        var grid = h('div', { class: 'media-grid' }, '<p>Lade …</p>');
        modal({ title: 'Bild aus der Mediathek', body: grid, cls: 'wide' });
        NLData.listMedia().then(function (list) {
            grid.innerHTML = '';
            list.forEach(function (m) {
                var b = h('button', { type: 'button', class: 'media-item' }, '<img src="' + esc(m.src) + '" alt=""><span>' + esc(m.name) + '</span>');
                b.addEventListener('click', function () { closeModal(); cb(m.src); });
                grid.appendChild(b);
            });
        });
    }

    function openPostPicker(b, kind) {
        var box = h('div', { class: 'picker' });
        var q = h('input', { type: 'search', placeholder: kind === 'recipe' ? 'Rezept suchen …' : 'Beitrag suchen …', 'aria-label': 'Suche' });
        var list = h('div', { class: 'pick-list' });
        box.appendChild(q); box.appendChild(list);
        if (kind === 'recipe') box.insertBefore(h('p', { class: 'note' }, 'Platzhalter: Bis das Rezept-Modell fertig ist, kommen die Rezepte aus dem Blog.'), q);
        modal({ title: kind === 'recipe' ? 'Rezept wählen' : 'Blog-Beitrag wählen', body: box, cls: 'wide' });
        function load() {
            (kind === 'recipe' ? NLData.searchRecipes : NLData.searchPosts)(q.value).then(function (rows) {
                list.innerHTML = rows.length ? '' : '<p class="empty">Nichts gefunden.</p>';
                rows.forEach(function (p) {
                    var it = h('button', { type: 'button', class: 'pick-item' }, '<img src="' + esc(p.image) + '" alt=""><span><strong>' + esc(p.title) + '</strong><small>' + esc(p.text) + '</small></span>');
                    it.addEventListener('click', function () {
                        Object.assign(b.data, { title: p.title, text: p.text, image: p.image, url: p.url });
                        if (kind === 'recipe') b.data.recipeId = p.postId; else b.data.postId = p.postId;
                        closeModal(); changed(b); renderPanel();
                    });
                    list.appendChild(it);
                });
            });
        }
        q.addEventListener('input', debounce(load, 200));
        load();
    }

    function openProductPicker(b) {
        var chosen = (b.data.items || []).slice();
        var box = h('div', { class: 'picker' });
        var q = h('input', { type: 'search', placeholder: 'Produkt suchen …', 'aria-label': 'Produkt suchen' });
        var info = h('p', { class: 'hint' });
        var list = h('div', { class: 'pick-grid' });
        box.appendChild(q); box.appendChild(info); box.appendChild(list);
        modal({
            title: 'Produkte aus dem Shop', body: box, cls: 'wide',
            actions: [{ label: 'Abbrechen' }, { label: 'Übernehmen', cls: 'primary', fn: function () { b.data.items = chosen; changed(b); renderPanel(); } }]
        });
        function upd() { info.textContent = chosen.length + ' von höchstens 3 ausgewählt'; }
        function isOn(p) { return chosen.some(function (c) { return c.productId === p.productId; }); }
        function load() {
            NLData.searchProducts(q.value).then(function (rows) {
                list.innerHTML = rows.length ? '' : '<p class="empty">Nichts gefunden.</p>';
                rows.forEach(function (p) {
                    var it = h('button', { type: 'button', class: 'pick-card' + (isOn(p) ? ' on' : ''), 'aria-pressed': String(isOn(p)) }, '<img src="' + esc(p.image) + '" alt=""><strong>' + esc(p.name) + '</strong><small>' + esc(p.unit) + ' · € ' + p.price.toFixed(2).replace('.', ',') + '</small>');
                    it.addEventListener('click', function () {
                        if (isOn(p)) chosen = chosen.filter(function (c) { return c.productId !== p.productId; });
                        else if (chosen.length < 3) chosen.push(clone(p));
                        else { toast('Höchstens 3 Produkte pro Baustein.'); return; }
                        it.classList.toggle('on', isOn(p)); it.setAttribute('aria-pressed', String(isOn(p))); upd();
                    });
                    list.appendChild(it);
                });
            });
        }
        q.addEventListener('input', debounce(load, 200));
        upd(); load();
    }

    // --------------------------------------------------- Vorschau, Pruefung, Versand

    function currentHtml(preview) {
        return R.renderEmail(S.doc, { subject: S.meta.subject, preheader: S.meta.preheader, preview: !!preview });
    }

    /** opts.checkLinks: Linkziele pruefen (vor Testmail und Versand). Netzfehler blockieren nie. */
    function runCheck(opts) {
        finishEditing();
        return NLCheck.check({ doc: S.doc, meta: S.meta }, currentHtml(false), opts || {})
            .catch(function (e) { return { failed: true, error: e.message, messages: [] }; });
    }

    function focusField(field) {
        select(null);
        var el = $('#meta-' + field);
        if (el) { el.focus(); el.classList.add('invalid'); setTimeout(function () { el.classList.remove('invalid'); }, 2000); }
    }

    function renderCheck(box, res) {
        box.innerHTML = '';
        // Pruef-Service kennt error | warning | hint | info; hint und info zeigen wir gemeinsam als Hinweis
        var msgs = (res.messages || []).map(function (m) { return Object.assign({}, m, { level: m.level === 'hint' ? 'info' : m.level }); });
        if (res.failed) {
            box.appendChild(h('div', { class: 'check-sum warn', role: 'status' }, 'Prüfung nicht möglich'));
            box.appendChild(h('small', { class: 'help' }, esc(res.error || '') + ' Testmail und Versand bleiben möglich.'));
            return;
        }
        var n = { error: 0, warning: 0, info: 0 };
        msgs.forEach(function (m) { n[m.level] = (n[m.level] || 0) + 1; });
        var r = res.result || {};
        var traffic = r.traffic || (n.error ? 'red' : (n.warning ? 'yellow' : 'green'));
        var sumTxt = n.error ? n.error + ' Fehler · ' + n.warning + ' Warnungen' : (n.warning ? n.warning + ' Warnungen' : 'Alles in Ordnung');
        box.appendChild(h('div', { class: 'check-sum ' + (n.error ? 'bad' : (n.warning ? 'warn' : 'good')), 'aria-live': 'polite' },
            '<span class="dot ' + esc(traffic) + '" aria-hidden="true"></span>' + (r.trafficLabel ? '<span>' + esc(r.trafficLabel) + '</span> ' : '') +
            (r.score != null ? '<span class="score">' + esc(r.score) + '/100</span> ' : '') + '<span>' + sumTxt + '</span>'));
        if (res.mock) box.appendChild(h('small', { class: 'help' }, 'Prototyp: Prüfung mit einer Attrappe des Prüf-Service.'));
        var labels = { error: 'Fehler', warning: 'Warnung', info: 'Hinweis' };
        var icons = { error: '✖', warning: '▲', info: 'i' };
        ['error', 'warning', 'info'].forEach(function (lvl) {
            var list = msgs.filter(function (m) { return m.level === lvl; });
            if (!list.length) return;
            // Fehler und Warnungen offen, Hinweise zugeklappt
            var group = h('details', { class: 'check-group ' + lvl });
            if (lvl !== 'info') group.open = true;
            group.appendChild(h('summary', {}, esc(labels[lvl]) + ' (' + list.length + ')'));
            list.forEach(function (m) {
                var b = m.blockId ? blockById(m.blockId) : null;
                var field = m.field === 'subject' || m.field === 'preheader' ? m.field : null;
                var clickable = b || field;
                var it = h(clickable ? 'button' : 'div', { class: 'check-item ' + lvl },
                    '<span class="lvl"><span aria-hidden="true">' + icons[lvl] + '</span> ' + labels[lvl] + '</span><span class="msg">' + esc(m.message) +
                    (m.tip ? '<em class="tip">' + esc(m.tip) + '</em>' : '') +
                    (b ? '<small>' + esc(BL.DEFS[b.type].label) + ' anzeigen</small>' : (field ? '<small>' + (field === 'subject' ? 'Betreff' : 'Vorschautext') + ' bearbeiten</small>' : '')) + '</span>');
                if (clickable) it.setAttribute('type', 'button');
                if (b) it.addEventListener('click', function () { closeModal(); select(m.blockId, { force: true, scroll: true }); });
                else if (field) it.addEventListener('click', function () { closeModal(); focusField(field === 'subject' ? 'subject' : 'preheader'); });
                group.appendChild(it);
            });
            box.appendChild(group);
        });
    }

    function openPreview(device) {
        finishEditing();
        device = device || 'desktop';
        var body = h('div', { class: 'preview' });
        var bar = h('div', { class: 'preview-bar' });
        var dev = h('div', { class: 'seg' });
        [['desktop', 'Desktop'], ['mobile', 'Handy (390 px)']].forEach(function (d) {
            var b = h('button', { type: 'button', class: d[0] === device ? 'on' : '' }, svg(d[0] === 'desktop' ? 'desktop' : 'phone') + ' ' + d[1]);
            b.addEventListener('click', function () { $$('button', dev).forEach(function (x) { x.classList.remove('on'); }); b.classList.add('on'); stage.className = 'preview-stage ' + d[0]; });
            dev.appendChild(b);
        });
        bar.appendChild(dev);
        var inbox = h('div', { class: 'inbox-line' }, '<strong>' + esc(S.meta.senderName || '') + '</strong> <span>' + esc(S.meta.subject || '(kein Betreff)') + '</span> <small>' + esc(S.meta.preheader || '') + '</small>');
        bar.appendChild(inbox);
        var dl = h('button', { type: 'button', class: 'nl-btn small' }, 'HTML herunterladen');
        dl.addEventListener('click', function () {
            var a = h('a', { href: URL.createObjectURL(new Blob([currentHtml(false)], { type: 'text/html' })), download: 'newsletter.html' });
            document.body.appendChild(a); a.click(); a.remove();
        });
        bar.appendChild(dl);
        body.appendChild(bar);
        var main = h('div', { class: 'preview-main' });
        var stage = h('div', { class: 'preview-stage ' + device });
        var frame = h('iframe', { title: 'Vorschau der Mail', id: 'preview-frame' });
        stage.appendChild(frame);
        main.appendChild(stage);
        var side = h('aside', { class: 'check-panel', 'aria-label': 'Prüfung' }, '<h3>' + svg('check') + ' Prüfung</h3><div class="check-list"><p>Prüfe …</p></div>');
        main.appendChild(side);
        body.appendChild(main);
        modal({ title: 'Vorschau', body: body, cls: 'full' });
        frame.srcdoc = currentHtml(true);
        side.setAttribute('role', 'region');
        var again = h('button', { type: 'button', class: 'nl-btn small' }, 'Erneut prüfen');
        $('h3', side).appendChild(again);
        function doCheck() {
            var listEl = $('.check-list', side);
            listEl.classList.add('loading');
            runCheck().then(function (res) { listEl.classList.remove('loading'); renderCheck(listEl, res); });
        }
        again.addEventListener('click', doCheck);
        doCheck();
    }

    /** Vor Testmail und Versand automatisch pruefen; bei Fehlern nachfragen. */
    /**
     * Vor Testmail und Versand automatisch pruefen (mit Linkpruefung). Bei Fehlern Dialog;
     * beim Versand an alle muss zusaetzlich "Ich habe die Fehler gelesen" angehakt sein.
     * fn bekommt das Pruefergebnis (fuer die Warnungszahl im Versanddialog).
     */
    function guarded(actionLabel, fn, requireAck) {
        toast('Prüfe …');
        runCheck({ checkLinks: true }).then(function (res) {
            var errs = (res.messages || []).filter(function (m) { return m.level === 'error'; });
            if (!errs.length) { fn(res); return; }
            var box = h('div', {});
            box.appendChild(h('p', {}, 'Die Prüfung hat ' + errs.length + ' Fehler gefunden. Trotzdem ' + actionLabel + '?'));
            var list = h('div', { class: 'check-list' });
            renderCheck(list, { messages: errs });
            box.appendChild(list);
            var ack = null;
            if (requireAck) {
                ack = h('label', { class: 'ack' }, '<input type="checkbox"> Ich habe die Fehler gelesen');
                box.appendChild(ack);
            }
            modal({ title: 'Fehler gefunden', body: box, cls: 'stack-actions', actions: [
                { label: 'Zurück zur Bearbeitung', cls: 'primary' },
                { label: 'Trotzdem ' + actionLabel, cls: 'danger', fn: function () {
                    if (ack && !$('input', ack).checked) { ack.classList.add('invalid'); return false; }
                    setTimeout(function () { fn(res); }, 50);
                } }
            ] });
        });
    }

    function testMail() {
        guarded('senden', function () {
            ask('Testmail senden', 'An welche Adresse?', localStorage.getItem('lsnl.testaddr') || '', function (email) {
                if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) { toast('Bitte eine gültige E-Mail-Adresse eingeben.'); return false; }
                try { localStorage.setItem('lsnl.testaddr', email); } catch (e) { /* */ }
                NLMail.sendTest({ doc: S.doc, meta: S.meta }, currentHtml(false), email).then(function (r) { toast(r.message || ('Testmail an ' + email + ' verschickt.')); })
                    .catch(function (e) { toast('Fehler: ' + e.message); });
            });
        });
    }

    // Termin in Ortszeit: "2026-12-04T09:30" -> "04.12.2026 09:30"
    function formatScheduled(v) {
        var m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(v || '');
        return m ? m[3] + '.' + m[2] + '.' + m[1] + ' ' + m[4] + ':' + m[5] : '';
    }

    function localInputValue(d) {
        function p(n) { return String(n).padStart(2, '0'); }
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T' + p(d.getHours()) + ':' + p(d.getMinutes());
    }

    function updateScheduleLabel() {
        var when = formatScheduled(S.meta.scheduledAt);
        $('#btn-schedule').innerHTML = '<span>' + (when ? 'Geplant: ' + when : 'Planen') + '</span>';
    }

    function openScheduleDialog() {
        var input = h('input', { type: 'datetime-local', class: 'sched-input', value: S.meta.scheduledAt || localInputValue(new Date(Date.now() + 3600000)) });
        var box = h('div', {});
        box.appendChild(h('p', {}, 'Der Versand startet automatisch zum gewählten Termin (Ortszeit). Verschickt wird die Fassung, die zu diesem Zeitpunkt gespeichert ist.'));
        box.appendChild(input);
        var actions = [{ label: 'Abbrechen' }];
        if (S.meta.scheduledAt) actions.push({ label: 'Planung aufheben', fn: function () { saveSchedule(''); } });
        actions.push({ label: 'Planen', cls: 'primary', fn: function () {
            if (!input.value) { toast('Bitte Datum und Uhrzeit wählen.'); return; }
            saveSchedule(input.value);
        } });
        modal({ title: 'Versand planen', body: box, actions: actions });
    }

    function saveSchedule(value) {
        // erst den aktuellen Stand speichern, damit die Prüfung auf dem Server die Fassung sieht, die verschickt wird
        NLStore.saveDraft({ doc: S.doc, meta: S.meta }, currentHtml(false))
            .then(function () { return NLMail.schedule(value); })
            .then(function (res) {
                S.meta.scheduledAt = value;
                updateScheduleLabel();
                toast(value ? 'Versand geplant für ' + (res.scheduled_at || formatScheduled(value)) + '.' : 'Planung aufgehoben.');
            })
            .catch(function (e) { toast('Planung nicht gespeichert: ' + e.message); });
    }

    function sendCampaign() {
        guarded('versenden', function (res) {
            var warnings = ((res && res.messages) || []).filter(function (m) { return m.level === 'warning'; }).length;
            NLMail.recipientCount().then(function (n) {
                modal({
                    title: 'Newsletter versenden?',
                    body: '<p>„<strong>' + esc(S.meta.subject) + '</strong>“ geht jetzt an <strong>' + n + ' Empfängerinnen und Empfänger</strong>.</p>' +
                        (warnings ? '<p class="warn-line">Die Prüfung meldet ' + warnings + ' Warnungen.</p>' : '') +
                        '<p>Der Versand läuft gedrosselt im Hintergrund und lässt sich nicht zurückholen.</p>',
                    actions: [{ label: 'Abbrechen' }, { label: 'Jetzt an ' + n + ' versenden', cls: 'primary', fn: function () {
                        NLMail.startSend().then(showProgress).catch(function (e) { toast('Versand nicht gestartet: ' + e.message); });
                    } }]
                });
            });
        }, true);
    }

    function showProgress() {
        var box = h('div', {}, '<div class="nl-progress"><div class="bar" style="width:0"></div></div><p class="progress-txt">Startet …</p>');
        modal({ title: 'Versand läuft', body: box, actions: [{ label: 'Schließen', fn: function () { if (NLMail.onSendFinished) NLMail.onSendFinished(); } }] });
        (function poll() {
            NLMail.progress().then(function (p) {
                var pct = p.total ? Math.round(p.sent / p.total * 100) : 0;
                var bar = $('.bar', box); if (!bar) return;
                bar.style.width = pct + '%';
                $('.progress-txt', box).textContent = p.sent + ' von ' + p.total + ' verschickt' + (p.status === 'sent' ? ' – fertig.' : ' …');
                if (p.status !== 'sent') setTimeout(poll, 800);
            });
        })();
    }

    // ------------------------------------------------------------------- Start

    function setDevice(d) {
        finishEditing();
        S.device = d;
        $('#paper').classList.toggle('mobile', d === 'mobile');
        $$('#device button').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-device') === d); b.setAttribute('aria-pressed', String(b.getAttribute('data-device') === d)); });
    }

    function bindTop() {
        $('#btn-undo').innerHTML = svg('undo') + '<span>Rückgängig</span>';
        $('#btn-redo').innerHTML = svg('redo') + '<span>Wiederholen</span>';
        $('#btn-undo').addEventListener('click', undo);
        $('#btn-redo').addEventListener('click', redo);
        $$('#device button').forEach(function (b) {
            b.innerHTML = svg(b.getAttribute('data-device') === 'desktop' ? 'desktop' : 'phone') + '<span>' + b.textContent + '</span>';
            b.addEventListener('click', function () { setDevice(b.getAttribute('data-device')); });
        });
        $('#btn-preview').innerHTML = svg('eye') + '<span>Vorschau &amp; Prüfung</span>';
        $('#btn-preview').addEventListener('click', function () { openPreview(S.device); });
        $('#btn-test').innerHTML = svg('mail') + '<span>Testmail</span>';
        $('#btn-test').addEventListener('click', testMail);
        $('#btn-schedule').addEventListener('click', openScheduleDialog);
        updateScheduleLabel();
        $('#btn-send').innerHTML = svg('send') + '<span>Versenden</span>';
        $('#btn-send').addEventListener('click', sendCampaign);
        $$('.tabs button').forEach(function (b) { b.addEventListener('click', function () { showTab(b.getAttribute('data-tab')); }); });

        document.addEventListener('keydown', function (e) {
            var inField = e.target.closest && (e.target.closest('input, textarea, select, [contenteditable="true"], [contenteditable="plaintext-only"]'));
            if ((e.ctrlKey || e.metaKey) && !e.altKey) {
                var k = e.key.toLowerCase();
                if (k === 'z' && !e.shiftKey && !inField) { e.preventDefault(); undo(); }
                else if ((k === 'y' || (k === 'z' && e.shiftKey)) && !inField) { e.preventDefault(); redo(); }
                else if (k === 's') { e.preventDefault(); finishEditing(); commit(); scheduleSave(); }
            }
            if (e.key === 'Escape' && !$('.nl-modal-back')) { finishEditing(); select(null); }
        });
        window.addEventListener('beforeunload', function () { finishEditing(true); });
        // Klick ausserhalb der Mail beendet die Textbearbeitung
        $('#paper-wrap').addEventListener('mousedown', function (e) { if (!e.target.closest('#paper')) { finishEditing(); } });
    }

    function load(state) {
        S.doc = R.normalize(state.doc);
        S.meta = Object.assign({ name: '', subject: '', preheader: '', senderName: 'Beispiel-Foodcoop', senderEmail: '' }, state.meta || {});
        S.history = []; S.hIndex = -1;
        renderCanvas(); renderPanel(); commit();
        if (window.matchMedia && window.matchMedia('(max-width: 640px)').matches) setDevice('mobile');
        setStatus('Entwurf geladen', 'ok');
    }

    root.NLEditor = {
        init: function (opts) {
            opts = opts || {};
            bindTop(); bindCanvas(); renderPalette(); renderSaved(); renderTemplates(); showTab('blocks');
            NLStore.loadDraft().then(function (draft) {
                load(draft && draft.doc ? draft : (opts.initial ? opts.initial() : { doc: { blocks: [] }, meta: {} }));
            });
        },
        state: function () { return { doc: S.doc, meta: S.meta }; },
        html: currentHtml,
        select: function (id) { select(id, { force: true, scroll: true }); },
        setDevice: setDevice,
        openPreview: openPreview,
        load: load
    };
})(window);
