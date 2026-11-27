/**
 * Newsletter: Bausteine (JSON) -> e-mail-sicheres Tabellen-HTML.
 *
 * Eine einzige Implementierung fuer Editor-Leinwand, Vorschau und Versand:
 * der Client rendert, der Server speichert das Ergebnis in newsletter_campaigns.html_cache
 * und ersetzt beim Versand nur noch die Platzhalter ({{vorname}}, {{abmeldelink}} ...).
 *
 * Lizenz: AGPL-3.0 (wie FoodCoopShop).
 */
(function (root) {
    'use strict';

    var FORMAT = 'fcs-newsletter-blocks';
    var VERSION = 1;
    var WIDTH = 600;      // Breite des Mail-Inhalts
    var PAD = 24;         // seitlicher Innenabstand der Bloecke
    var INNER = WIDTH - 2 * PAD; // 552

    var T = {
        brand: '#800000',
        brandDark: '#5c0000',
        ink: '#2b2b2b',
        muted: '#6b6b6b',
        soft: '#f7efe9',
        line: '#e6d9cf',
        white: '#ffffff',
        font: 'Arial, Helvetica, sans-serif',
        head: "Georgia, 'Times New Roman', Times, serif"
    };

    /** Farben, die im Text-Editor angeboten werden (Corporate Design). */
    var CD_COLORS = ['#800000', '#a64d4d', '#5c0000', '#2b2b2b', '#6b6b6b', '#4d7a2f', '#ffffff'];

    var BG = { white: T.white, soft: T.soft, brand: T.brand };

    /** Platzhalter, die der Server pro Empfaenger ersetzt. */
    var PLACEHOLDERS = [
        // key = was die Redaktion tippt, server = Platzhalter in html_cache (NewsletterRenderService::personalize)
        { key: 'vorname', server: 'first_name', label: 'Vorname', sample: 'Anna' },
        { key: 'nachname', server: 'last_name', label: 'Nachname', sample: 'Muster' },
        { key: 'email', server: 'email', label: 'E-Mail-Adresse', sample: 'anna@example.org' },
        { key: 'abmeldelink', server: 'unsubscribe_url', label: 'Abmeldelink (URL)', sample: '#abmelden', system: true }
    ];

    /** {{vorname}} -> {{first_name}} usw.; Ersatztext {{vorname|liebe Mitglieder}} bleibt als {{first_name|liebe Mitglieder}} */
    function toServerPlaceholders(html) {
        var map = {};
        PLACEHOLDERS.forEach(function (p) { map[p.key] = p.server; map[p.server] = p.server; });
        return String(html).replace(/\{\{\s*([a-z_]+)\s*(\|[^}]*)?\}\}/g, function (m, k, def) {
            return map[k] ? '{{' + map[k] + (def ? def.replace(/\s+$/, '') : '') + '}}' : m;
        });
    }

    var DEFAULT_SETTINGS = {
        brand: T.brand,
        bodyBg: T.soft,
        contentBg: T.white,
        orgName: 'Beispiel-Foodcoop',
        orgAddress: 'Musterstraße 1, 12345 Musterstadt',
        orgEmail: 'post@example.org',
        imprintUrl: 'https://www.example.org/impressum',
        privacyUrl: 'https://www.example.org/datenschutz',
        shopUrl: 'https://www.example.org/shop'
    };

    // ------------------------------------------------------------------ helpers

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /** URL pruefen: http(s), mailto, tel, relative /pfade, # und {{platzhalter}}. */
    function safeUrl(u) {
        u = String(u || '').trim();
        if (!u) return '';
        if (/^\{\{[a-z_]+(\|[^}]*)?\}\}$/.test(u)) return u;
        if (/^(https?:|mailto:|tel:)/i.test(u)) return u;
        if (/^(\/|#|data:image\/)/i.test(u)) return u;
        if (/^[a-z0-9.-]+\.[a-z]{2,}(\/.*)?$/i.test(u)) return 'https://' + u;
        return '';
    }

    function attrEdit(ctx, kind, path) {
        if (!ctx.edit) return '';
        return ' data-edit-' + kind + '="' + esc(path) + '"';
    }

    function get(obj, path) {
        return String(path).split('.').reduce(function (o, k) { return o == null ? o : o[k]; }, obj);
    }

    function set(obj, path, value) {
        var keys = String(path).split('.');
        var last = keys.pop();
        var o = keys.reduce(function (o, k) { return o[k]; }, obj);
        o[last] = value;
    }

    function fmtPrice(p) {
        var n = Number(p);
        if (!isFinite(n)) return esc(p);
        return '€ ' + n.toFixed(2).replace('.', ',');
    }

    // ---------------------------------------------------- Rich-Text bereinigen

    var ALLOWED = { P: 1, BR: 1, STRONG: 1, B: 1, EM: 1, I: 1, U: 1, A: 1, UL: 1, OL: 1, LI: 1, SPAN: 1 };

    /**
     * Rich-Text aus dem Editor auf eine kleine, mailtaugliche Teilmenge reduzieren
     * und Inline-Styles setzen. Braucht DOM (Browser); auf dem Server wird nicht neu gerendert.
     */
    function cleanRich(html, opts) {
        opts = opts || {};
        var color = opts.color || T.ink;
        var link = opts.link || T.brand;
        var size = opts.size || 16;
        if (typeof document === 'undefined') return String(html || '');
        var tpl = document.createElement('template');
        tpl.innerHTML = String(html || '');
        var out = document.createElement('div');

        function walk(src, dst) {
            Array.prototype.forEach.call(src.childNodes, function (n) {
                if (n.nodeType === 3) { dst.appendChild(document.createTextNode(n.nodeValue)); return; }
                if (n.nodeType !== 1) return;
                var tag = n.tagName;
                if (tag === 'DIV' || tag === 'H1' || tag === 'H2' || tag === 'H3' || tag === 'H4' || tag === 'BLOCKQUOTE') tag = 'P';
                if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'IFRAME' || tag === 'OBJECT') return;
                if (!ALLOWED[tag]) { walk(n, dst); return; }
                var el = document.createElement(tag === 'B' ? 'STRONG' : (tag === 'I' ? 'EM' : tag));
                if (tag === 'A') {
                    var href = safeUrl(n.getAttribute('href'));
                    if (!href) { walk(n, dst); return; }
                    el.setAttribute('href', href);
                    el.setAttribute('target', '_blank');
                    el.setAttribute('style', 'color:' + link + ';text-decoration:underline;');
                }
                if (tag === 'SPAN') {
                    var c = (n.style && n.style.color) || '';
                    var fw = (n.style && n.style.fontWeight) || '';
                    var st = '';
                    if (c) st += 'color:' + c + ';';
                    if (fw === 'bold' || Number(fw) >= 600) st += 'font-weight:bold;';
                    if (!st) { walk(n, dst); return; }
                    el.setAttribute('style', st);
                }
                if (tag === 'P') el.setAttribute('style', 'margin:0 0 12px 0;');
                if (tag === 'UL' || tag === 'OL') el.setAttribute('style', 'margin:0 0 12px 0;padding:0 0 0 22px;');
                if (tag === 'LI') el.setAttribute('style', 'margin:0 0 6px 0;');
                walk(n, el);
                dst.appendChild(el);
            });
        }
        walk(tpl.content, out);
        // letzter Absatz ohne Abstand nach unten
        var last = out.lastElementChild;
        if (last && (last.tagName === 'P' || last.tagName === 'UL' || last.tagName === 'OL')) {
            last.style.marginBottom = '0';
        }
        return out.innerHTML;
    }

    /** Textstil fuer Fliesstext-Zellen. */
    function textStyle(color, size, align) {
        return 'font-family:' + T.font + ';font-size:' + (size || 16) + 'px;line-height:1.55;color:' + (color || T.ink) +
            ';text-align:' + (align || 'left') + ';';
    }

    // ------------------------------------------------------------- Bausteine

    function wrap(block, inner, opts) {
        opts = opts || {};
        var st = block.style || {};
        var bg = BG[st.bg] || st.bg || T.white;
        var pt = st.padTop != null ? st.padTop : (opts.padTop != null ? opts.padTop : 12);
        var pb = st.padBottom != null ? st.padBottom : (opts.padBottom != null ? opts.padBottom : 12);
        var px = opts.fullBleed ? 0 : PAD;
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;background-color:' + bg + ';" bgcolor="' + bg + '" data-block-id="' + esc(block.id) + '">' +
            '<tr><td class="' + (opts.fullBleed ? '' : 'px') + '" style="padding:' + pt + 'px ' + px + 'px ' + pb + 'px ' + px + 'px;">' + inner + '</td></tr></table>';
    }

    function onDark(block) { return (block.style || {}).bg === 'brand'; }

    function imgTag(src, alt, width, opts) {
        opts = opts || {};
        if (!src && !EDIT) return ''; // in der echten Mail kein Platzhalter
        if (!src) {
            return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td height="' + Math.round(width * 0.6) + '" style="background:#eee5dc;color:' + T.muted + ';font-family:' + T.font + ';font-size:14px;text-align:center;">Bild w&auml;hlen</td></tr></table>';
        }
        var radius = opts.radius ? 'border-radius:' + opts.radius + 'px;' : '';
        var center = opts.center ? 'margin:0 auto;' : '';
        return '<img src="' + esc(src) + '" alt="' + esc(alt || '') + '" width="' + width + '" class="' + (opts.cls || '') + '" style="display:block;' + center + 'width:100%;max-width:' + width + 'px;height:auto;border:0;outline:none;text-decoration:none;' + radius + '" />';
    }

    function linkWrap(url, html) {
        var u = safeUrl(url);
        return u ? '<a href="' + esc(u) + '" target="_blank" style="text-decoration:none;">' + html + '</a>' : html;
    }

    function button(d, ctx, path, fallbackAlign, dark) {
        path = path || '';
        var label = d.label != null ? d.label : d.buttonLabel;
        var url = safeUrl(d.url != null ? d.url : d.buttonUrl);
        if (!label) return '';
        var color = d.color || T.brand;
        var filled = (d.variant || 'filled') === 'filled';
        if (dark) { color = T.white; }
        var bg = filled ? color : 'transparent';
        var fg = filled ? (dark ? T.brand : T.white) : color;
        var align = d.align || fallbackAlign || 'left';
        var labelPath = d.label != null ? path + 'label' : path + 'buttonLabel';
        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="' + align + '" style="margin:' + (align === 'center' ? '0 auto' : '0') + ';">' +
            '<tr><td align="center" bgcolor="' + (filled ? color : '') + '" style="border-radius:24px;background-color:' + bg + ';border:2px solid ' + color + ';">' +
            '<a href="' + esc(url || '#') + '" target="_blank" style="display:inline-block;padding:' + (d.small ? '9px 16px' : '12px 26px') + ';font-family:' + T.font + ';font-size:' + (d.small ? 14 : 16) + 'px;white-space:nowrap;font-weight:bold;line-height:20px;color:' + fg + ';text-decoration:none;border-radius:24px;"' + attrEdit(ctx, 'plain', labelPath) + '>' + esc(label) + '</a>' +
            '</td></tr></table>';
    }

    /**
     * Spalten nach der "fluid hybrid"-Methode: inline-block-Divs mit max-width
     * (stapeln sich ohne Media Query auf schmalen Bildschirmen), fuer Outlook
     * Geister-Tabellen in bedingten Kommentaren.
     */
    function columns(cells, totalWidth, gap) {
        gap = gap == null ? 20 : gap;
        var n = cells.length;
        var w = Math.floor(totalWidth / n);
        var html = '<div class="cols" style="font-size:0;text-align:center;">' +
            '<!--[if mso]><table role="presentation" width="' + totalWidth + '" cellpadding="0" cellspacing="0" border="0"><tr><![endif]-->';
        cells.forEach(function (c, i) {
            var pl = i === 0 ? 0 : gap / 2;
            var pr = i === n - 1 ? 0 : gap / 2;
            html += '<!--[if mso]><td width="' + w + '" valign="' + (c.valign || 'top') + '"><![endif]-->' +
                '<div class="col" style="display:inline-block;width:100%;max-width:' + w + 'px;vertical-align:' + (c.valign || 'top') + ';text-align:left;">' +
                '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td class="colpad" style="padding:0 ' + pr + 'px 0 ' + pl + 'px;">' + c.html + '</td></tr></table>' +
                '</div><!--[if mso]></td><![endif]-->';
        });
        html += '<!--[if mso]></tr></table><![endif]--></div>';
        return html;
    }

    function headingHtml(text, level, color, align, ctx, path) {
        var sizes = { 1: 30, 2: 24, 3: 19 };
        var s = sizes[level] || 24;
        return '<h' + level + ' class="h' + level + '" style="margin:0;font-family:' + T.head + ';font-size:' + s + 'px;line-height:1.25;font-weight:bold;color:' + color + ';text-align:' + (align || 'left') + ';"' +
            attrEdit(ctx, 'plain', path) + '>' + esc(text) + '</h' + level + '>';
    }

    var R = {};
    var EDIT = false; // wird von renderBlock gesetzt (Leinwand oder echte Mail)

    R.header = function (b, ctx, s) {
        var d = b.data;
        var logo = d.logo ? '<img src="' + esc(d.logo) + '" alt="' + esc(d.alt || s.orgName) + '" width="' + (d.logoWidth || 140) + '" style="display:block;margin:0 auto;width:' + (d.logoWidth || 140) + 'px;max-width:100%;height:auto;border:0;" />' : '';
        var inner = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center">' +
            linkWrap(d.url, logo) + '</td></tr>' +
            (d.tagline ? '<tr><td align="center" style="padding-top:10px;font-family:' + T.font + ';font-size:14px;letter-spacing:1px;color:' + (onDark(b) ? '#f2dede' : T.muted) + ';"' + attrEdit(ctx, 'plain', 'tagline') + '>' + esc(d.tagline) + '</td></tr>' : '') +
            '</table>';
        return wrap(b, inner, { padTop: 24, padBottom: 20 });
    };

    R.heading = function (b, ctx) {
        var d = b.data;
        var color = onDark(b) ? T.white : (d.color || T.brand);
        return wrap(b, headingHtml(d.text, d.level || 2, color, d.align, ctx, 'text'), { padTop: 20, padBottom: 6 });
    };

    R.text = function (b, ctx) {
        var d = b.data;
        var color = onDark(b) ? T.white : T.ink;
        var html = cleanRich(d.html, { color: color, link: onDark(b) ? T.white : T.brand });
        return wrap(b, '<div class="rt" style="' + textStyle(color, 16, d.align) + '"' + attrEdit(ctx, 'rich', 'html') + '>' + html + '</div>');
    };

    // Originalinhalt einer importierten Mail: HTML unverändert (entsteht beim Kopieren archivierter Newsletter)
    R.raw = function (b) {
        return wrap(b, '<div>' + (b.data.html || '') + '</div>');
    };

    R.image = function (b, ctx) {
        var d = b.data;
        var size = d.size || 'wide';
        var widths = { full: WIDTH, wide: INNER, medium: 360, small: 200 };
        var w = widths[size] || INNER;
        var img = linkWrap(d.url, imgTag(d.src, d.alt, w, { center: true, radius: size === 'full' ? 0 : 8, cls: 'img' }));
        var cap = d.caption ? '<div style="padding-top:8px;font-family:' + T.font + ';font-size:13px;line-height:1.4;color:' + T.muted + ';text-align:center;"' + attrEdit(ctx, 'plain', 'caption') + '>' + esc(d.caption) + '</div>' : '';
        return wrap(b, '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center">' + img + cap + '</td></tr></table>',
            { fullBleed: size === 'full', padTop: size === 'full' ? 0 : 12, padBottom: size === 'full' ? 0 : 12 });
    };

    R.imageText = function (b, ctx) {
        var d = b.data;
        var dark = onDark(b);
        var color = dark ? T.white : T.ink;
        var imgW = Math.floor((INNER - 24) / 2);
        var imgCell = { html: linkWrap(d.url, imgTag(d.src, d.alt, imgW, { radius: 8, cls: 'img img-m' })), valign: 'middle' };
        var txt = (d.heading ? '<div style="padding-bottom:8px;">' + headingHtml(d.heading, 3, dark ? T.white : T.brand, 'left', ctx, 'heading') + '</div>' : '') +
            '<div class="rt" style="' + textStyle(color, 16) + '"' + attrEdit(ctx, 'rich', 'html') + '>' + cleanRich(d.html, { color: color, link: dark ? T.white : T.brand }) + '</div>' +
            (d.buttonLabel ? '<div style="padding-top:14px;">' + button({ label: d.buttonLabel, url: d.buttonUrl, variant: 'outline', color: T.brand }, ctx, '', 'left', dark).replace('data-edit-plain="label"', 'data-edit-plain="buttonLabel"') + '</div>' : '');
        var txtCell = { html: '<div class="mt" style="padding:4px 0;">' + txt + '</div>', valign: 'middle' };
        var cells = d.imagePos === 'right' ? [txtCell, imgCell] : [imgCell, txtCell];
        return wrap(b, columns(cells, INNER, 24), { padTop: 16, padBottom: 16 });
    };

    R.columns = function (b, ctx) {
        var d = b.data;
        var dark = onDark(b);
        var color = dark ? T.white : T.ink;
        var w = Math.floor((INNER - 24) / 2);
        var cells = (d.cols || []).slice(0, 2).map(function (c, i) {
            var p = 'cols.' + i + '.';
            return {
                html: '<div class="mt">' +
                    (c.src ? '<div style="padding-bottom:12px;">' + linkWrap(c.url, imgTag(c.src, c.alt, w, { radius: 8, cls: 'img img-m' })) + '</div>' : '') +
                    (c.heading ? '<div style="padding-bottom:6px;">' + headingHtml(c.heading, 3, dark ? T.white : T.brand, 'left', ctx, p + 'heading') + '</div>' : '') +
                    '<div class="rt" style="' + textStyle(color, 15) + '"' + attrEdit(ctx, 'rich', p + 'html') + '>' + cleanRich(c.html, { color: color, link: dark ? T.white : T.brand }) + '</div></div>'
            };
        });
        return wrap(b, columns(cells, INNER, 24), { padTop: 14, padBottom: 14 });
    };

    R.button = function (b, ctx) {
        return wrap(b, button(b.data, ctx, '', b.data.align || 'center', onDark(b)), { padTop: 14, padBottom: 14 });
    };

    R.divider = function (b) {
        var d = b.data;
        var color = d.color || T.line;
        var inner;
        if (d.variant === 'dots') {
            inner = '<div style="font-family:' + T.head + ';font-size:20px;letter-spacing:10px;line-height:20px;color:' + T.brand + ';text-align:center;">&#183;&#183;&#183;</div>';
        } else {
            inner = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td height="1" style="height:1px;line-height:1px;font-size:1px;border-top:' + (d.thickness || 1) + 'px solid ' + color + ';">&nbsp;</td></tr></table>';
        }
        return wrap(b, inner, { padTop: 12, padBottom: 12 });
    };

    R.spacer = function (b) {
        var h = Math.max(4, Math.min(120, Number(b.data.height) || 24));
        return wrap(b, '<div style="height:' + h + 'px;line-height:' + h + 'px;font-size:1px;">&nbsp;</div>', { padTop: 0, padBottom: 0 });
    };

    R.callout = function (b, ctx) {
        var d = b.data;
        var inner;
        if (d.variant === 'quote') {
            inner = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="padding:8px 16px;text-align:center;">' +
                '<div style="font-family:' + T.head + ';font-size:44px;line-height:36px;color:' + T.brand + ';">&#8222;</div>' +
                '<div class="rt" style="font-family:' + T.head + ';font-style:italic;font-size:20px;line-height:1.5;color:' + T.ink + ';text-align:center;"' + attrEdit(ctx, 'rich', 'html') + '>' + cleanRich(d.html, { color: T.ink }) + '</div>' +
                (d.author ? '<div style="padding-top:10px;font-family:' + T.font + ';font-size:14px;color:' + T.muted + ';"' + attrEdit(ctx, 'plain', 'author') + '>&ndash; ' + esc(d.author) + '</div>' : '') +
                '</td></tr></table>';
        } else {
            inner = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>' +
                '<td bgcolor="' + T.soft + '" style="background-color:' + T.soft + ';border-left:4px solid ' + T.brand + ';border-radius:6px;padding:18px 20px;">' +
                (d.title ? '<div style="padding-bottom:6px;font-family:' + T.font + ';font-size:16px;font-weight:bold;color:' + T.brand + ';"' + attrEdit(ctx, 'plain', 'title') + '>' + esc(d.title) + '</div>' : '') +
                '<div class="rt" style="' + textStyle(T.ink, 15) + '"' + attrEdit(ctx, 'rich', 'html') + '>' + cleanRich(d.html, { color: T.ink }) + '</div>' +
                '</td></tr></table>';
        }
        return wrap(b, inner, { padTop: 14, padBottom: 14 });
    };

    function teaser(b, ctx, d, kicker, meta) {
        var dark = onDark(b);
        var imgW = 200;
        var imgCell = { html: linkWrap(d.url, imgTag(d.image, d.title, imgW, { radius: 8, cls: 'img' })), valign: 'top' };
        var body = '<div class="mt">' +
            (kicker ? '<div style="padding-bottom:4px;font-family:' + T.font + ';font-size:12px;letter-spacing:1px;text-transform:uppercase;color:' + (dark ? '#f2dede' : T.muted) + ';">' + esc(kicker) + '</div>' : '') +
            '<div style="padding-bottom:6px;">' + headingHtml(d.title || 'Titel', 3, dark ? T.white : T.brand, 'left', ctx, 'title') + '</div>' +
            (meta ? '<div style="padding-bottom:6px;font-family:' + T.font + ';font-size:13px;color:' + (dark ? '#f2dede' : T.muted) + ';"' + attrEdit(ctx, 'plain', 'meta') + '>' + esc(meta) + '</div>' : '') +
            '<div style="' + textStyle(dark ? T.white : T.ink, 15) + '"' + attrEdit(ctx, 'plain', 'text') + '>' + esc(d.text) + '</div>' +
            '<div style="padding-top:12px;">' + button({ label: d.buttonLabel || 'Weiterlesen', url: d.url, variant: 'outline', color: T.brand }, ctx, '', 'left', dark).replace('data-edit-plain="label"', 'data-edit-plain="buttonLabel"') + '</div></div>';
        var widths = [imgW, INNER - imgW];
        // ungleiche Spalten: Bild 200 px, Text Rest
        var html = '<div class="cols" style="font-size:0;text-align:left;">' +
            '<!--[if mso]><table role="presentation" width="' + INNER + '" cellpadding="0" cellspacing="0" border="0"><tr><td width="' + widths[0] + '" valign="top"><![endif]-->' +
            '<div class="col" style="display:inline-block;width:100%;max-width:' + widths[0] + 'px;vertical-align:top;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td class="colpad" style="padding:0 0 12px 0;">' + imgCell.html + '</td></tr></table></div>' +
            '<!--[if mso]></td><td width="' + widths[1] + '" valign="top"><![endif]-->' +
            '<div class="col" style="display:inline-block;width:100%;max-width:' + widths[1] + 'px;vertical-align:top;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td class="colpad" style="padding:0 0 0 22px;">' + body + '</td></tr></table></div>' +
            '<!--[if mso]></td></tr></table><![endif]--></div>';
        return wrap(b, html, { padTop: 16, padBottom: 16 });
    }

    R.blog = function (b, ctx) {
        return teaser(b, ctx, b.data, b.data.kicker || 'Aus unserem Blog', null);
    };

    R.recipe = function (b, ctx) {
        return teaser(b, ctx, b.data, b.data.kicker || 'Rezept', b.data.meta || '');
    };

    R.products = function (b, ctx) {
        var d = b.data;
        var items = (d.items || []).slice(0, 3);
        var n = Math.max(1, items.length);
        var gap = 16;
        var w = Math.floor((INNER - gap * (n - 1)) / n);
        var cells = items.map(function (p, i) {
            return {
                html: '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid ' + T.line + ';border-radius:10px;" bgcolor="#ffffff"><tr><td class="pcard" style="padding:12px;text-align:center;">' +
                    linkWrap(p.url, imgTag(p.image, p.name, Math.min(w - 26, 220), { center: true, radius: 6, cls: 'pimg' })) +
                    '<div style="padding-top:10px;font-family:' + T.font + ';font-size:15px;line-height:1.35;font-weight:bold;color:' + T.ink + ';">' + esc(p.name) + '</div>' +
                    (p.manufacturer ? '<div style="font-family:' + T.font + ';font-size:13px;color:' + T.muted + ';">' + esc(p.manufacturer) + '</div>' : '') +
                    '<div style="padding:6px 0 10px;font-family:' + T.font + ';font-size:16px;color:' + T.brand + ';font-weight:bold;">' + fmtPrice(p.price) + (p.unit ? ' <span style="font-weight:normal;font-size:13px;color:' + T.muted + ';">/ ' + esc(p.unit) + '</span>' : '') + '</div>' +
                    button({ label: d.buttonLabel || 'Zum Produkt', url: p.url, variant: 'filled', color: T.brand, align: 'center', small: true }, { edit: false }, '', 'center') +
                    '</td></tr></table>'
            };
        });
        if (!cells.length) cells = [{ html: '<div style="padding:30px;text-align:center;font-family:' + T.font + ';font-size:14px;color:' + T.muted + ';border:1px dashed ' + T.line + ';">Produkte ausw&auml;hlen</div>' }];
        var title = d.title ? '<div style="padding-bottom:14px;">' + headingHtml(d.title, 2, onDark(b) ? T.white : T.brand, 'center', ctx, 'title') + '</div>' : '';
        return wrap(b, title + '<div class="products n' + n + '">' + columns(cells, INNER, gap) + '</div>', { padTop: 16, padBottom: 16 });
    };

    var SOCIAL = {
        facebook: 'Facebook', instagram: 'Instagram', website: 'Website', signal: 'Signal', whatsapp: 'WhatsApp', youtube: 'YouTube'
    };

    R.footer = function (b, ctx, s) {
        var d = b.data;
        var links = (d.social || []).filter(function (x) { return safeUrl(x.url); }).map(function (x) {
            return '<a href="' + esc(safeUrl(x.url)) + '" target="_blank" style="display:inline-block;margin:0 4px 8px;padding:7px 14px;border:1px solid #d9b3b3;border-radius:16px;font-family:' + T.font + ';font-size:13px;color:#ffffff;text-decoration:none;">' + esc(SOCIAL[x.network] || x.network) + '</a>';
        }).join('');
        var contact = cleanRich(d.contactHtml || '', { color: '#f2dede', link: '#ffffff' });
        // Pflichtteil: nicht editierbar, immer vorhanden
        var legal = '<div style="padding-top:16px;margin-top:16px;border-top:1px solid #9c3a3a;font-family:' + T.font + ';font-size:12px;line-height:1.6;color:#e8c9c9;">' +
            'Du erh&auml;ltst diesen Newsletter, weil du dich bei ' + esc(s.orgName) + ' daf&uuml;r angemeldet hast.<br />' +
            '<a href="{{webansicht_url}}" target="_blank" style="color:#ffffff;text-decoration:underline;">Web-Ansicht</a> &nbsp;&middot;&nbsp; ' +
            '<a href="{{abmeldelink}}" target="_blank" style="color:#ffffff;text-decoration:underline;">Newsletter abmelden</a>' +
            ' &nbsp;&middot;&nbsp; <a href="' + esc(safeUrl(s.imprintUrl)) + '" target="_blank" style="color:#ffffff;text-decoration:underline;">Impressum</a>' +
            (s.privacyUrl ? ' &nbsp;&middot;&nbsp; <a href="' + esc(safeUrl(s.privacyUrl)) + '" target="_blank" style="color:#ffffff;text-decoration:underline;">Datenschutz</a>' : '') +
            '<br />' + esc(s.orgName) + ' &middot; ' + esc(s.orgAddress) + '</div>';
        var inner = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" style="text-align:center;">' +
            (links ? '<div style="padding-bottom:8px;">' + links + '</div>' : '') +
            '<div class="rt" style="font-family:' + T.font + ';font-size:14px;line-height:1.6;color:#f2dede;text-align:center;"' + attrEdit(ctx, 'rich', 'contactHtml') + '>' + contact + '</div>' +
            '<div data-locked="1">' + legal + '</div>' +
            '</td></tr></table>';
        var fb = { id: b.id, style: { bg: 'brand' } };
        return wrap(fb, inner, { padTop: 28, padBottom: 28 });
    };

    // --------------------------------------------------------------- Dokument

    function normalize(doc) {
        doc = doc || {};
        var out = {
            format: FORMAT,
            version: VERSION,
            settings: Object.assign({}, DEFAULT_SETTINGS, doc.settings || {}),
            blocks: (doc.blocks || []).filter(function (b) { return b && R[b.type]; }).map(function (b, i) {
        // jeder Baustein braucht eine ID (data-block-id), sonst kann der Spam-Check Meldungen nicht zuordnen
        return b.id ? b : Object.assign({}, b, { id: b.type + '-' + i });
      })
        };
        // Footer ist Pflicht und steht immer am Ende
        var footers = out.blocks.filter(function (b) { return b.type === 'footer'; });
        out.blocks = out.blocks.filter(function (b) { return b.type !== 'footer'; });
        out.blocks.push(footers[0] || { id: 'footer', type: 'footer', locked: true, data: { social: [], contactHtml: '' } });
        out.blocks[out.blocks.length - 1].locked = true;
        return out;
    }

    function renderBlock(block, ctx, settings) {
        EDIT = !!(ctx && ctx.edit);
        var fn = R[block.type];
        if (!fn) return '';
        return fn(block, ctx || {}, Object.assign({}, DEFAULT_SETTINGS, settings || {}));
    }

    var HEAD_CSS =
        'body{margin:0;padding:0;width:100%!important;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;}' +
        'table,td{mso-table-lspace:0pt;mso-table-rspace:0pt;}img{-ms-interpolation-mode:bicubic;}' +
        'a[x-apple-data-detectors]{color:inherit!important;text-decoration:none!important;}' +
        '#MessageViewBody a{color:inherit;text-decoration:none;}' +
        '@media only screen and (max-width:620px){' +
        '.wrap{width:100%!important;}' +
        '.px{padding-left:18px!important;padding-right:18px!important;}' +
        '.col{max-width:100%!important;display:block!important;}' +
        '.colpad{padding:0 0 18px 0!important;}' +
        '.products .colpad{padding:0 0 14px 0!important;}' +
        '.pimg{max-width:200px!important;}' +
        '.img-m{max-width:100%!important;}' +
        '.h1{font-size:26px!important;}' +
        '.h2{font-size:22px!important;}' +
        '}';

    /**
     * Ganze Mail rendern.
     * opts.subject, opts.preheader; opts.preview=true ersetzt Platzhalter durch Beispielwerte.
     */
    function renderEmail(doc, opts) {
        opts = opts || {};
        doc = normalize(doc);
        var s = doc.settings;
        var body = doc.blocks.map(function (b) { return renderBlock(b, { edit: false }, s); }).join('\n');
        var pre = opts.preheader ? '<div data-preheader="1" style="display:none;max-height:0;max-width:0;overflow:hidden;mso-hide:all;">' + esc(opts.preheader) + '&#8199;&#65279;&#847;'.repeat(40) + '</div>' : '';
        var html = '<!DOCTYPE html>\n<html lang="de" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">\n<head>\n' +
            '<meta charset="utf-8" />\n<meta name="viewport" content="width=device-width, initial-scale=1" />\n<meta http-equiv="X-UA-Compatible" content="IE=edge" />\n' +
            '<meta name="x-apple-disable-message-reformatting" />\n<meta name="format-detection" content="telephone=no,address=no,email=no,date=no" />\n' +
            '<title>' + esc(opts.subject || s.orgName + ' Newsletter') + '</title>\n' +
            '<!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><![endif]-->\n' +
            '<style>' + HEAD_CSS + '</style>\n</head>\n' +
            '<body style="margin:0;padding:0;background-color:' + s.bodyBg + ';">\n' + pre +
            // Webansicht oben (wie früher bei rapidmail); beim Versand durch die echte URL ersetzt
            '<div style="font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:16px;text-align:center;padding:12px 16px 0;color:#8a8a8a;">' +
            '<a href="{{webansicht_url}}" style="color:#8a8a8a;text-decoration:underline;">Diese Mail im Browser ansehen</a></div>\n' +
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="' + s.bodyBg + '" style="background-color:' + s.bodyBg + ';"><tr><td align="center" style="padding:12px 0 24px;">\n' +
            '<!--[if mso]><table role="presentation" width="' + WIDTH + '" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->\n' +
            '<table role="presentation" class="wrap" width="' + WIDTH + '" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:' + WIDTH + 'px;background-color:' + s.contentBg + ';" bgcolor="' + s.contentBg + '"><tr><td>\n' +
            body +
            '\n</td></tr></table>\n<!--[if mso]></td></tr></table><![endif]-->\n</td></tr></table>\n</body>\n</html>\n';
        return opts.preview ? fillPlaceholders(html, null) : toServerPlaceholders(html);
    }

    /** {{name}} bzw. {{name|Ersatz}} ersetzen. values=null: Beispielwerte. */
    function fillPlaceholders(html, values) {
        var sample = {};
        PLACEHOLDERS.forEach(function (p) { sample[p.key] = p.sample; sample[p.server] = p.sample; });
        values = values || sample;
        return String(html).replace(/\{\{\s*([a-z_]+)\s*(?:\|([^}]*))?\}\}/g, function (m, k, def) {
            var v = values[k];
            return esc(v != null && v !== '' ? v : (def || ''));
        });
    }

    /** Neue Block-ID: stabil, wird nie neu vergeben (auch nicht beim Umsortieren). */
    function newId() {
        return 'b' + Date.now().toString(36) + Math.random().toString(36).slice(2, 7);
    }

    root.NLRender = {
        FORMAT: FORMAT,
        VERSION: VERSION,
        WIDTH: WIDTH,
        THEME: T,
        CD_COLORS: CD_COLORS,
        PLACEHOLDERS: PLACEHOLDERS,
        DEFAULT_SETTINGS: DEFAULT_SETTINGS,
        renderBlock: renderBlock,
        renderEmail: renderEmail,
        normalize: normalize,
        cleanRich: cleanRich,
        fillPlaceholders: fillPlaceholders,
        toServerPlaceholders: toServerPlaceholders,
        safeUrl: safeUrl,
        newId: newId,
        get: get,
        set: set,
        types: Object.keys(R)
    };
})(typeof window !== 'undefined' ? window : globalThis);
