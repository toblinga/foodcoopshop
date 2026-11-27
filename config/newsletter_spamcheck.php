<?php
declare(strict_types=1);

/**
 * Regelwerk fuer den Newsletter-Spam- und Qualitaetscheck.
 *
 * Admins koennen diese Datei anpassen. Wer Updates einfach halten will, legt
 * daneben eine Datei newsletter_spamcheck.custom.php an, die nur die
 * abweichenden Schluessel zurueckgibt (wird rekursiv darueber gelegt).
 *
 * Stufen (level): error | warning | hint | info
 *   error   = deutlich rot, blockiert den Versand nicht, verlangt aber eine Bestaetigung
 *   warning = gelb, sollte behoben werden
 *   hint    = blau, Verbesserungsvorschlag
 *   info    = grau, nur zur Information, zaehlt nicht in die Punkte
 * weight = Abzug von 100 Punkten je Treffer (null = Standard der Stufe).
 * enabled = false schaltet eine Regel ab.
 *
 * Herkunft der Schwellwerte "Faustregel" heisst:
 * nicht offiziell belegt, Branchenuebliche Empfehlung.
 */
return [
    // Standardabzug je Stufe
    'levelWeights' => [
        'error' => 20,
        'warning' => 6,
        'hint' => 2,
        'info' => 0,
    ],

    // Ampel: rot sobald ein Fehler vorliegt oder Punkte < red, gelb unter green
    'traffic' => [
        'green' => 80,
        'red' => 50,
    ],

    // Attribut, mit dem der Renderer jeden Baustein in der Vorschau kennzeichnet
    'blockAttribute' => 'data-block-id',
    // Element(e) des Preheaders: wird beim versteckten Text nicht gemeldet
    'preheaderAttribute' => 'data-preheader',
    'preheaderClasses' => ['preheader'],

    'rules' => [
        // ---------------------------------------------------------------- Betreff
        'SUBJECT_MISSING' => ['level' => 'error'],
        'SUBJECT_LENGTH' => [
            'level' => 'hint',
            'min' => 30,              // Faustregel
            'max' => 60,              // Faustregel, mobile Clients kuerzen ab ca. 40-60 Zeichen
            'hardMin' => 10,          // darunter oder darueber: Warnung
            'hardMax' => 90,
        ],
        'SUBJECT_CAPS' => [
            'level' => 'warning',
            'minLetters' => 8,        // erst ab so vielen Buchstaben pruefen
            'maxUpperShare' => 0.5,   // SpamAssassin SUBJ_ALL_CAPS greift bei komplett Gross
            'maxCapsWords' => 2,      // Woerter >= 4 Buchstaben komplett in Versalien
        ],
        'SUBJECT_PUNCTUATION' => [
            'level' => 'warning',
            'pattern' => '/[!?]{2,}|[!?](?:\s*[!?]){2,}/u',
            'maxExclamation' => 1,    // mehr als ein ! insgesamt: Hinweis
        ],
        'SUBJECT_SPAM_WORDS' => ['level' => 'warning'],
        'SUBJECT_EMOJI' => [
            'level' => 'warning',
            'max' => 1,               // Faustregel: 1 Emoji ok, ab 2 Warnung
        ],
        'SUBJECT_PLACEHOLDER' => ['level' => 'error'],
        'SUBJECT_CURRENCY' => [
            'level' => 'hint',
            'pattern' => '/(€|\$|EUR)\s*\d|\d\s*(€|\$|EUR)|%/u',
        ],
        'PREHEADER_MISSING' => ['level' => 'warning'],
        'PREHEADER_LENGTH' => [
            'level' => 'hint',
            'min' => 30,
            'max' => 110,             // Faustregel, sichtbar sind je nach Client ca. 40-140 Zeichen
        ],
        'PREHEADER_SAME_AS_SUBJECT' => ['level' => 'hint'],
        // Preheader per Schriftfarbe = Hintergrund oder 1px-Schrift versteckt. Lokaler Test
        // (SpamAssassin 4, ohne Netztests): <span> mit Farbe = Hintergrund + 1px loeste
        // FONT_INVIS_MSGID (+2,0) aus, das FCS-Layout (<div> mit opacity:0) nicht. Daher nur Hinweis.
        'PREHEADER_INVISIBLE_FONT' => ['level' => 'hint'],

        // ---------------------------------------------------------------- Inhalt
        'BODY_EMPTY' => ['level' => 'error'],
        'IMAGE_ONLY' => [
            'level' => 'error',
            'minText' => 200,         // SpamAssassin HTML_IMAGE_ONLY_04 = Bilder mit 0-400 Byte Text
        ],
        'TEXT_IMAGE_RATIO' => [
            'level' => 'warning',
            'minTextShare' => 0.6,    // Mailjet: mind. 60/40 Text zu Bild
            'minTextChars' => 500,    // ab so viel Text keine Meldung, egal wie viele Bilder
            'pxPerChar' => 160,       // geschaetzte Flaeche je Zeichen (ca. 8 x 20 px bei 16 px Schrift)
            'defaultImageArea' => 180000, // 600 x 300, wenn keine Groesse bekannt
        ],
        'BODY_SPAM_WORDS' => [
            'level' => 'hint',
            'maxBeforeWarning' => 4,  // ab so vielen verschiedenen Treffern Warnung
        ],
        'BODY_CAPS' => [
            'level' => 'hint',
            'minRun' => 4,            // so viele Versalienwoerter in Folge
        ],
        'BODY_EXCLAMATION' => [
            'level' => 'hint',
            'pattern' => '/!{3,}|\?{3,}/u',
        ],
        'DUMMY_TEXT' => [
            'level' => 'warning',
            'pattern' => '/\blorem ipsum\b|\bdolor sit amet\b|\bTODO\b|\bXXX\b|\bBlindtext\b|\bPlatzhaltertext\b/u',
        ],
        'IMAGE_ALT_MISSING' => ['level' => 'warning'],
        'IMAGE_ALT_EMPTY_LINKED' => ['level' => 'warning'],
        'IMAGE_ALT_EMPTY' => [
            'level' => 'hint',
            'minWidth' => 100,        // kleinere Bilder gelten als Zierbild
        ],
        'IMAGE_FILESIZE' => [
            'level' => 'warning',
            'warnBytes' => 200 * 1024,   // Mailjet: Bilder unter 200 KB
            'errorBytes' => 1024 * 1024, // Faustregel
        ],
        'IMAGES_TOTAL_SIZE' => [
            'level' => 'warning',
            'warnBytes' => 1024 * 1024,  // Faustregel: Ladegewicht aller Bilder
        ],
        'IMAGE_DISPLAY_WIDTH' => [
            'level' => 'warning',
            'max' => 600,                 // Faustregel 600-640 px Mailbreite
        ],
        'IMAGE_INTRINSIC_WIDTH' => [
            'level' => 'hint',
            'max' => 1200,                // 2x fuer hochaufloesende Displays genuegt
        ],
        'IMAGE_SIZE_UNKNOWN' => ['level' => 'info'],
        'IMAGE_DATA_URI' => ['level' => 'warning'],
        'HTML_SIZE' => [
            'level' => 'warning',
            'warnBytes' => 90 * 1024,
            'errorBytes' => 102 * 1024,  // Gmail kuerzt ab ca. 102 KB HTML ("Nachricht gekuerzt")
        ],

        // ---------------------------------------------------------------- Links
        'LINK_COUNT' => [
            'level' => 'hint',
            'max' => 25,              // Faustregel
            'maxWarning' => 50,
        ],
        'LINK_SHORTENER' => ['level' => 'warning'],
        'LINK_IP' => ['level' => 'error'],
        'LINK_TEXT_MISMATCH' => ['level' => 'error'],
        'LINK_HTTP' => ['level' => 'warning'],
        'LINK_EMPTY' => ['level' => 'warning'],
        'LINK_PLACEHOLDER' => ['level' => 'error'],
        'LINK_BROKEN' => [
            'enabled' => false,       // macht HEAD-Anfragen an die Linkziele
            'level' => 'warning',
            'timeout' => 4,           // Sekunden je Link
            'maxLinks' => 30,
            'userAgent' => 'FoodCoopShop-Newsletter-Linkcheck/1.0',
        ],
        'LINK_BLOCKLIST' => [
            // DNS-Abfrage der Linkdomains bei einer Domain-Blockliste. Aus, weil
            // Spamhaus DBL nur fuer geringe nicht-kommerzielle Nutzung frei ist und
            // ueber oeffentliche Resolver (8.8.8.8, 1.1.1.1) nicht antwortet.
            'enabled' => false,
            'level' => 'error',
            'zones' => [],            // z. B. ['dbl.spamhaus.org']
        ],

        // ---------------------------------------------------------------- Pflichtangaben
        'UNSUBSCRIBE_LINK_MISSING' => ['level' => 'error'],
        'IMPRINT_MISSING' => ['level' => 'error'],
        'LIST_UNSUBSCRIBE_MISSING' => ['level' => 'error'],
        'LIST_UNSUBSCRIBE_POST_MISSING' => ['level' => 'warning'],
        'LIST_UNSUBSCRIBE_NO_HTTPS' => ['level' => 'warning'],
        'HEADERS_UNKNOWN' => ['level' => 'info'],

        // ---------------------------------------------------------------- Technik
        'TEXT_PART_MISSING' => ['level' => 'warning'],
        'TEXT_PART_SHORT' => [
            'level' => 'hint',
            'minShare' => 0.3,        // Textteil hat weniger als 30 % des HTML-Texts
        ],
        'SENDER_INVALID' => ['level' => 'error'],
        'SENDER_FREEMAIL' => ['level' => 'warning'],
        'SENDER_NOREPLY' => ['level' => 'hint'],
        'REPLY_TO_INVALID' => ['level' => 'error'],
        'REPLY_TO_FREEMAIL' => ['level' => 'warning'], // SpamAssassin FREEMAIL_REPLYTO 1.0
        'REPLY_TO_OTHER_DOMAIN' => ['level' => 'hint'],
        'DNS_SPF' => ['enabled' => true, 'level' => 'warning'],
        'DNS_SPF_MULTIPLE' => ['level' => 'error'],
        'DNS_DMARC' => ['enabled' => true, 'level' => 'warning'],
        'DNS_DKIM' => [
            'enabled' => true,
            'level' => 'warning',
            // DKIM laesst sich nur mit bekanntem Selektor pruefen
            'selectors' => ['brevo1', 'brevo2', 'mail', 'default', 'dkim', 'google', 'selector1', 'selector2', 'k1', 's1'],
        ],
        'HTML_SCRIPT' => ['level' => 'error'],
        'HTML_FORM' => ['level' => 'warning'],
        'HTML_EMBED' => ['level' => 'error'],
        'HIDDEN_TEXT' => [
            'level' => 'error',
            'minContrast' => 1.5,     // SpamAssassin HTML_FONT_LOW_CONTRAST: "aehnlich oder gleich"
        ],
        'LOW_CONTRAST' => [
            'level' => 'hint',
            'minContrast' => 3.0,     // WCAG 2.x verlangt 4.5 (normal) bzw. 3.0 (gross)
        ],
        'TINY_FONT' => [
            'level' => 'warning',
            'minPx' => 11,
            'hiddenPx' => 4,          // darunter: wie versteckter Text, Fehler
        ],
        'PLACEHOLDER_UNREPLACED' => ['level' => 'error'],

        // ---------------------------------------------------------------- SpamAssassin (optional)
        'SPAMASSASSIN' => [
            'enabled' => false,
            'level' => 'info',
            'host' => '127.0.0.1',
            'port' => 783,
            'timeout' => 10,
            'threshold' => 5.0,       // Standard required_score von SpamAssassin
            'warnAt' => 2.5,
        ],
    ],

    // Platzhalter, die erst beim Versand je Empfaenger:in ersetzt werden.
    // Alles andere in {{ }}, [[ ]], *|...|* oder %%...%% gilt als nicht ersetzt.
    // Ersatztext-Form {{name|Ersatz}} zaehlt wie {{name}}.
    'knownPlaceholders' => [
        // NewsletterRenderService::PLACEHOLDER_* und Editor (html_cache)
        '{{first_name}}', '{{last_name}}', '{{email}}', '{{unsubscribe_url}}',
        // Schreibweise im Editor
        '{{vorname}}', '{{nachname}}',
    ],
    'placeholderPattern' => '/\{\{\s*[^{}]{1,60}\s*\}\}|\[\[\s*[^\[\]]{1,60}\s*\]\]|\*\|[A-Z0-9_]{1,40}\|\*|%%[A-Za-z0-9_]{1,40}%%/u',

    // Erkennung Abmeldelink (href oder Linktext, ohne Gross/Klein)
    'unsubscribePatterns' => [
        'abmelden', 'abmeldung', 'abbestellen', 'austragen', 'unsubscribe', 'opt-out', 'optout',
        'newsletter/unsubscribe', '{{abmeldelink}}', '{{unsubscribe_url}}',
    ],
    // Erkennung Impressum/Offenlegung (Linktext oder Text)
    'imprintPatterns' => ['impressum', 'offenlegung', 'imprint', 'medieninhaber', 'zvr'],
    // Alternativ: postalische Adresse (Oesterreich/Deutschland: PLZ + Ort)
    'addressPattern' => '/\b(?:A-|AT-|D-)?\d{4,5}\s+[A-ZÄÖÜ][\p{L}\-. ]{2,}/u',

    'shortenerDomains' => [
        'bit.ly', 'bitly.com', 'tinyurl.com', 't.co', 'goo.gl', 'ow.ly', 'is.gd', 'buff.ly',
        'rebrand.ly', 'cutt.ly', 'shorturl.at', 'tiny.cc', 'rb.gy', 'v.gd', 'lnkd.in', 's.id',
        'adf.ly', 'bl.ink', 'shorte.st', 't.ly', 'qrco.de',
    ],

    'freemailDomains' => [
        'gmail.com', 'googlemail.com', 'gmx.at', 'gmx.net', 'gmx.de', 'gmx.com', 'web.de',
        'yahoo.com', 'yahoo.de', 'hotmail.com', 'outlook.com', 'outlook.at', 'live.com', 'live.at',
        'aon.at', 'chello.at', 'icloud.com', 'me.com', 'aol.com', 'mail.com', 't-online.de',
        'a1.net', 'kabsi.at', 'utanet.at', 'posteo.de', 'proton.me', 'protonmail.com',
    ],

    // Zweistufige Domain-Endungen fuer den Vergleich Linktext/Ziel
    'secondLevelSuffixes' => ['co.at', 'or.at', 'gv.at', 'ac.at', 'co.uk', 'org.uk', 'ac.uk', 'com.au'],

    // Spam-Woerter. Eintrag = Wort/Phrase (ganzes Wort, ohne Gross/Klein) oder
    // Regex in /.../u. Gewicht = Abzug im Betreff; im Text gilt die halbe Zahl.
    // Hinweis: Moderne Filter (SpamAssassin mit Bayes, Gmail) werten einzelne
    // Woerter kaum noch. Die Liste ist eine Faustregel gegen "werbliche" Betreffs.
    'spamWords' => [
        'de' => [
            'gratis' => 4, 'kostenlos' => 4, 'umsonst' => 4, '100 %' => 5, '100%' => 5,
            'gewinn' => 5, 'gewinnen' => 5, 'gewinnspiel' => 3, 'sie haben gewonnen' => 10,
            'jetzt kaufen' => 6, 'sofort kaufen' => 6, 'jetzt bestellen' => 3, 'dringend' => 6,
            'geld zurück' => 6, 'geld-zurück-garantie' => 6, 'nur heute' => 4, 'letzte chance' => 5,
            'einmalige gelegenheit' => 6, 'garantiert' => 3, 'risikofrei' => 6, 'ohne risiko' => 5,
            'klicken sie hier' => 6, 'hier klicken' => 5, 'sonderangebot' => 2, 'schnäppchen' => 3,
            'bestpreis' => 3, 'nicht verpassen' => 3, 'sofort' => 2, 'exklusiv' => 2,
            'millionär' => 8, 'kredit' => 5, 'schulden' => 6, 'abnehmen' => 5, 'reich werden' => 8,
            'geld verdienen' => 8, 'ohne kosten' => 4, 'achtung' => 3, '/\bnur\s+\d+\s*(?:€|eur)/u' => 3,
        ],
        'en' => [
            'free' => 4, 'winner' => 6, 'you have won' => 10, 'win' => 3, 'cash' => 5, 'buy now' => 6,
            'order now' => 4, 'act now' => 6, 'urgent' => 6, 'click here' => 6, 'limited time' => 4,
            'risk-free' => 6, 'risk free' => 6, 'guarantee' => 3, '100% free' => 8, 'no cost' => 4,
            'earn money' => 8, 'make money' => 8, 'money back' => 6, 'dear friend' => 6,
            'viagra' => 10, 'casino' => 8, 'credit' => 4, 'debt' => 6, 'lowest price' => 5,
            'once in a lifetime' => 6, "don't miss" => 3, '$$$' => 8,
        ],
    ],
];
