/**
 * Baustein-Katalog: Name, Symbol, Startwerte und Eingabefelder je Bausteintyp.
 * Lizenz: AGPL-3.0
 */
(function (root) {
    'use strict';

    var BG_FIELD = { key: 'style.bg', label: 'Hintergrund', type: 'choice', options: [['white', 'Weiß'], ['soft', 'Beige'], ['brand', 'Weinrot']] };
    var ALIGN = [['left', 'Links'], ['center', 'Mitte'], ['right', 'Rechts']];

    // einfache Strich-Symbole, 24x24
    var ICONS = {
        header: '<rect x="3" y="4" width="18" height="7" rx="1.5"/><path d="M8 7.5h8"/><path d="M5 15h14M5 19h10"/>',
        heading: '<path d="M6 5v14M18 5v14M6 12h12"/>',
        text: '<path d="M4 6h16M4 10h16M4 14h16M4 18h10"/>',
        image: '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.8"/><path d="M21 16l-5-5-8 8"/>',
        imageText: '<rect x="3" y="6" width="8" height="12" rx="1.5"/><path d="M14 8h7M14 12h7M14 16h5"/>',
        columns: '<rect x="3" y="5" width="7.5" height="14" rx="1.5"/><rect x="13.5" y="5" width="7.5" height="14" rx="1.5"/>',
        button: '<rect x="3" y="8" width="18" height="8" rx="4"/><path d="M9 12h6"/>',
        divider: '<path d="M3 12h18"/><path d="M8 7h8M8 17h8" opacity=".4"/>',
        spacer: '<path d="M12 4v16M8 8l4-4 4 4M8 16l4 4 4-4"/>',
        callout: '<path d="M5 5h14v10H9l-4 4z"/><path d="M9 9h6"/>',
        blog: '<path d="M5 4h10l4 4v12H5z"/><path d="M8 11h8M8 14h8M8 17h5"/>',
        recipe: '<path d="M4 11h16a8 8 0 0 1-16 0z"/><path d="M9 4c0 2 2 2 2 4M13 4c0 2 2 2 2 4"/>',
        products: '<path d="M5 8h14l-1.5 11h-11z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
        footer: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 14h18M7 17h10"/>'
    };

    function icon(name) {
        return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (ICONS[name] || ICONS.text) + '</svg>';
    }

    var SHOP = 'https://www.example.org/shop';

    var DEFS = {
        header: {
            label: 'Kopf mit Logo', group: 'Grundlagen',
            make: function () { return { data: { logo: (root.NL_CONFIG && root.NL_CONFIG.logoUrl) || '', alt: 'Beispiel-Foodcoop', logoWidth: 140, tagline: 'Beispiel-Untertitel', url: 'https://www.example.org' }, style: { bg: 'brand' } }; },
            fields: [
                { key: 'data.logo', label: 'Logo', type: 'image', altKey: 'data.alt' },
                { key: 'data.logoWidth', label: 'Logo-Breite', type: 'range', min: 80, max: 260, step: 10, unit: 'px' },
                { key: 'data.tagline', label: 'Untertitel', type: 'text' },
                { key: 'data.url', label: 'Link beim Klick aufs Logo', type: 'url' },
                BG_FIELD
            ]
        },
        heading: {
            label: 'Überschrift', group: 'Grundlagen',
            make: function () { return { data: { text: 'Neue Überschrift', level: 2, align: 'left' } }; },
            fields: [
                { key: 'data.level', label: 'Größe', type: 'choice', options: [[1, 'Groß'], [2, 'Mittel'], [3, 'Klein']], number: true },
                { key: 'data.align', label: 'Ausrichtung', type: 'choice', options: ALIGN },
                BG_FIELD
            ],
            hint: 'Text direkt in der Vorschau anklicken und tippen.'
        },
        text: {
            label: 'Text', group: 'Grundlagen',
            make: function () { return { data: { html: '<p>Hier steht dein Text. Klick hinein, um ihn zu bearbeiten.</p>', align: 'left' } }; },
            fields: [
                { key: 'data.align', label: 'Ausrichtung', type: 'choice', options: ALIGN },
                BG_FIELD
            ],
            hint: 'Klick in den Text: oben erscheint die Leiste für Fett, Kursiv, Listen, Links, Farben und Platzhalter wie {{vorname}}.'
        },
        // ohne group: nicht in der Bausteinpalette, entsteht nur beim Kopieren einer importierten Mail
        raw: {
            label: 'Originalinhalt (HTML)',
            make: function () { return { data: { html: '' } }; },
            fields: [
                { key: 'data.html', label: 'HTML der Originalmail (nur bearbeiten, wenn du weißt, was du tust)', type: 'textarea' },
                BG_FIELD
            ],
            hint: 'Inhalt der ursprünglichen Mail. Du kannst ihn im HTML-Feld ändern oder den Block löschen und eigene Bausteine nehmen.'
        },
        image: {
            label: 'Bild', group: 'Grundlagen',
            make: function () { return { data: { src: '', alt: '', url: '', size: 'wide', caption: '' } }; },
            fields: [
                { key: 'data.src', label: 'Bild', type: 'image', altKey: 'data.alt' },
                { key: 'data.alt', label: 'Bildbeschreibung (Alt-Text)', type: 'text', required: true, help: 'Wird angezeigt, wenn Bilder blockiert sind, und vorgelesen.' },
                { key: 'data.url', label: 'Link beim Klick aufs Bild', type: 'url' },
                { key: 'data.size', label: 'Größe', type: 'choice', options: [['full', 'Randlos'], ['wide', 'Breit'], ['medium', 'Mittel'], ['small', 'Klein']] },
                { key: 'data.caption', label: 'Bildunterschrift', type: 'text' },
                BG_FIELD
            ]
        },
        imageText: {
            label: 'Bild + Text', group: 'Grundlagen',
            make: function () { return { data: { src: '', alt: '', url: '', heading: 'Überschrift', html: '<p>Ein kurzer Text neben dem Bild.</p>', imagePos: 'left', buttonLabel: '', buttonUrl: '' } }; },
            fields: [
                { key: 'data.src', label: 'Bild', type: 'image', altKey: 'data.alt' },
                { key: 'data.alt', label: 'Bildbeschreibung (Alt-Text)', type: 'text', required: true },
                { key: 'data.imagePos', label: 'Bild', type: 'choice', options: [['left', 'Links'], ['right', 'Rechts']] },
                { key: 'data.url', label: 'Link beim Klick aufs Bild', type: 'url' },
                { key: 'data.heading', label: 'Überschrift (leer = keine)', type: 'text' },
                { key: 'data.buttonLabel', label: 'Knopf-Text (leer = kein Knopf)', type: 'text' },
                { key: 'data.buttonUrl', label: 'Knopf-Link', type: 'url' },
                BG_FIELD
            ],
            hint: 'Am Handy steht das Bild über dem Text.'
        },
        columns: {
            label: '2 Spalten', group: 'Grundlagen',
            make: function () {
                return { data: { cols: [
                    { src: '', alt: '', url: '', heading: 'Linke Spalte', html: '<p>Text links.</p>' },
                    { src: '', alt: '', url: '', heading: 'Rechte Spalte', html: '<p>Text rechts.</p>' }
                ] } };
            },
            fields: [
                { type: 'section', label: 'Linke Spalte' },
                { key: 'data.cols.0.src', label: 'Bild (optional)', type: 'image', altKey: 'data.cols.0.alt', optional: true },
                { key: 'data.cols.0.alt', label: 'Alt-Text', type: 'text' },
                { key: 'data.cols.0.heading', label: 'Überschrift', type: 'text' },
                { type: 'section', label: 'Rechte Spalte' },
                { key: 'data.cols.1.src', label: 'Bild (optional)', type: 'image', altKey: 'data.cols.1.alt', optional: true },
                { key: 'data.cols.1.alt', label: 'Alt-Text', type: 'text' },
                { key: 'data.cols.1.heading', label: 'Überschrift', type: 'text' },
                BG_FIELD
            ],
            hint: 'Am Handy stehen die Spalten untereinander.'
        },
        button: {
            label: 'Knopf', group: 'Grundlagen',
            make: function () { return { data: { label: 'Jetzt bestellen', url: SHOP, align: 'center', variant: 'filled' } }; },
            fields: [
                { key: 'data.label', label: 'Beschriftung', type: 'text' },
                { key: 'data.url', label: 'Link', type: 'url', required: true },
                { key: 'data.variant', label: 'Stil', type: 'choice', options: [['filled', 'Gefüllt'], ['outline', 'Umrandet']] },
                { key: 'data.align', label: 'Ausrichtung', type: 'choice', options: ALIGN },
                BG_FIELD
            ]
        },
        divider: {
            label: 'Trenner', group: 'Gestaltung',
            make: function () { return { data: { variant: 'line' } }; },
            fields: [
                { key: 'data.variant', label: 'Art', type: 'choice', options: [['line', 'Linie'], ['dots', 'Punkte']] },
                BG_FIELD
            ]
        },
        spacer: {
            label: 'Abstand', group: 'Gestaltung',
            make: function () { return { data: { height: 24 } }; },
            fields: [
                { key: 'data.height', label: 'Höhe', type: 'range', min: 8, max: 96, step: 4, unit: 'px' },
                BG_FIELD
            ]
        },
        callout: {
            label: 'Hinweis / Zitat', group: 'Gestaltung',
            make: function () { return { data: { variant: 'hint', title: 'Gut zu wissen', html: '<p>Ein wichtiger Hinweis für alle.</p>', author: '' } }; },
            fields: [
                { key: 'data.variant', label: 'Art', type: 'choice', options: [['hint', 'Hinweisbox'], ['quote', 'Zitat']] },
                { key: 'data.title', label: 'Titel (nur Hinweisbox)', type: 'text' },
                { key: 'data.author', label: 'Von wem (nur Zitat)', type: 'text' },
                BG_FIELD
            ]
        },
        blog: {
            label: 'Blog-Beitrag', group: 'Aus dem Shop',
            make: function () { return { data: { postId: null, kicker: 'Aus unserem Blog', title: 'Beitrag auswählen', text: '', image: '', url: '', buttonLabel: 'Weiterlesen' } }; },
            fields: [
                { type: 'picker', picker: 'blog', label: 'Beitrag aus FCS wählen' },
                { key: 'data.kicker', label: 'Dachzeile', type: 'text' },
                { key: 'data.title', label: 'Titel', type: 'text' },
                { key: 'data.text', label: 'Kurztext', type: 'textarea' },
                { key: 'data.image', label: 'Bild', type: 'image' },
                { key: 'data.url', label: 'Link', type: 'url' },
                { key: 'data.buttonLabel', label: 'Knopf-Text', type: 'text' },
                BG_FIELD
            ]
        },
        recipe: {
            label: 'Rezept', group: 'Aus dem Shop',
            make: function () { return { data: { recipeId: null, kicker: 'Rezept', title: 'Rezept auswählen', meta: '30 Min · 4 Portionen', text: '', image: '', url: '', buttonLabel: 'Zum Rezept' } }; },
            fields: [
                { type: 'picker', picker: 'recipe', label: 'Rezept wählen' },
                { type: 'note', text: 'Platzhalter: Das Rezept-Modell kommt später. Bis dahin werden Rezepte aus dem Blog angeboten.' },
                { key: 'data.title', label: 'Titel', type: 'text' },
                { key: 'data.meta', label: 'Zeit und Portionen', type: 'text' },
                { key: 'data.text', label: 'Kurztext', type: 'textarea' },
                { key: 'data.image', label: 'Bild', type: 'image' },
                { key: 'data.url', label: 'Link', type: 'url' },
                { key: 'data.buttonLabel', label: 'Knopf-Text', type: 'text' },
                BG_FIELD
            ]
        },
        products: {
            label: 'Produkte', group: 'Aus dem Shop',
            make: function () { return { data: { title: 'Neu im Shop', items: [], buttonLabel: 'Zum Produkt' } }; },
            fields: [
                { type: 'picker', picker: 'products', label: 'Produkte wählen (1 bis 3)' },
                { key: 'data.title', label: 'Überschrift', type: 'text' },
                { key: 'data.buttonLabel', label: 'Knopf-Text', type: 'text' },
                BG_FIELD
            ],
            hint: 'Preis und Bild werden beim Auswählen aus dem Shop übernommen. „Aktualisieren“ holt den aktuellen Stand.'
        },
        footer: {
            label: 'Fußzeile', group: null,
            make: function () { return { data: { social: [], contactHtml: '' } }; },
            fields: [
                { type: 'social', key: 'data.social', label: 'Social-Media-Links' },
                { type: 'note', text: 'Abmeldelink, Impressum und Adresse sind Pflicht und werden immer angehängt. Die Fußzeile lässt sich nicht löschen.' }
            ],
            hint: 'Kontakttext in der Vorschau anklicken, um ihn zu ändern.'
        }
    };

    function create(type) {
        var def = DEFS[type];
        var b = def.make();
        b.id = root.NLRender.newId();
        b.type = type;
        b.style = b.style || { bg: 'white' };
        return b;
    }

    root.NLBlocks = { DEFS: DEFS, icon: icon, create: create };
})(window);
