/**
 * Chip-Eingabe (Tom Select) fuer Blogbeitraege:
 * - supplier-product: Lieferanten und Produkte in einem Feld, Live-Suche beim Tippen
 * - tag: Stichwoerter, Live-Suche, neue Stichwoerter per Eingabe
 * - category: feste Auswahl als Chips
 * Die Chips werden sichtbar gerendert (die Vorgabe der Instanz blendet sie aus).
 */
foodcoopshop.BlogPostMapping = {

    getSelect: function (type) {
        return document.querySelector('select.blog-post-mapping[data-mapping="' + type + '"]');
    },

    getTomSelect: function (type) {
        var element = this.getSelect(type);
        return element ? element.tomselect : null;
    },

    escapeHtml: function (text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    },

    /**
     * Text fuer den Chip: nur der Name, ohne Praefix "Produkt: " bzw. "Lieferant: ".
     * Bei Produkten entfaellt zusaetzlich die Klammer mit dem Lieferanten am Ende,
     * z. B. "Produkt: Bio Kartoffel-Chips Meersalz (BioArt)" -> "Bio Kartoffel-Chips Meersalz".
     * Die Dropdown-Auswahl (render.option) zeigt weiterhin Praefix und Klammer.
     */
    chipLabel: function (type, text) {
        var label = String(text || '');
        if (type !== 'supplier-product') {
            return label;
        }
        if (/^Produkt: /.test(label)) {
            return label.replace(/^Produkt: /, '').replace(/\s\([^()]*\)$/, '');
        }
        return label.replace(/^Lieferant: /, '');
    },

    init: function () {
        var self = this;
        document.querySelectorAll('select.blog-post-mapping').forEach(function (element) {
            // vorhandene Instanz (z. B. nach Neuladen) sauber entfernen
            if (element.tomselect) {
                element.tomselect.destroy();
            }
            var type = element.getAttribute('data-mapping');
            var searchUrl = element.getAttribute('data-search-url');
            var settings = {
                plugins: ['remove_button'],
                valueField: 'value',
                labelField: 'text',
                searchField: ['text'],
                maxItems: null,
                hideSelected: true,
                placeholder: element.getAttribute('data-placeholder') || '',
                closeAfterSelect: false,
                render: {
                    // Chip: nur Name (ohne Praefix), Text umbricht statt abgeschnitten zu werden
                    item: function (data, escape) {
                        return '<div class="blog-chip">' + escape(self.chipLabel(type, data.text)) + '</div>';
                    },
                    // Dropdown: vollstaendige Bezeichnung mit Praefix und Lieferant in Klammern
                    option: function (data, escape) {
                        return '<div class="option">' + escape(data.text) + '</div>';
                    },
                    no_results: function () {
                        return '<div class="no-results">Keine Treffer</div>';
                    }
                }
            };
            if (searchUrl) {
                settings.preload = 'focus';
                settings.load = function (query, callback) {
                    self.search(searchUrl, type, query, callback);
                };
            }
            if (type === 'tag') {
                settings.create = true;
            }
            var ts = new TomSelect(element, settings);
            element.tomselect = ts;
        });

        var addAllButton = document.querySelector('.add-all-products-of-manufacturer');
        if (addAllButton) {
            addAllButton.addEventListener('click', function (event) {
                event.preventDefault();
                self.addAllProductsOfFirstSupplier();
            });
        }
    },

    search: function (searchUrl, type, query, callback) {
        var url = searchUrl + '?type=' + encodeURIComponent(type) + '&q=' + encodeURIComponent(query || '');
        fetch(url, { credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok) { throw new Error('HTTP ' + response.status); }
                return response.json();
            })
            .then(function (items) { callback(items); })
            .catch(function () { callback(); });
    },

    addAllProductsOfFirstSupplier: function () {
        var supplierProducts = this.getTomSelect('supplier-product');
        var element = this.getSelect('supplier-product');
        if (!supplierProducts || !element) {
            return;
        }
        var values = supplierProducts.getValue();
        if (!Array.isArray(values)) {
            values = values ? [values] : [];
        }
        var supplierValue = values.find(function (value) { return String(value).indexOf('m:') === 0; });
        if (!supplierValue) {
            window.alert('Bitte zuerst einen Lieferanten auswählen.');
            return;
        }
        var manufacturerId = String(supplierValue).substring(2);
        var url = element.getAttribute('data-search-url') +
            '?type=product&q=&manufacturer_id=' + encodeURIComponent(manufacturerId);
        fetch(url, { credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (items) {
                items.forEach(function (item) {
                    var value = 'p:' + item.value;
                    supplierProducts.addOption({ value: value, text: 'Produkt: ' + item.text });
                    supplierProducts.addItem(value);
                });
            });
    }
};
