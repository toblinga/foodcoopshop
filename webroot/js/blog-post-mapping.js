/**
 * Chip-Eingabe (Tom Select) fuer Blogbeitraege:
 * - supplier-product: Lieferanten und Produkte in einem Feld, Live-Suche
 * - tag: Stichwoerter, Live-Suche, neue Stichwoerter per Eingabe
 * - category: feste Auswahl ohne Suche
 */
foodcoopshop.BlogPostMapping = {

    getSelect: function (type) {
        return document.querySelector('select.blog-post-mapping[data-mapping="' + type + '"]');
    },

    getTomSelect: function (type) {
        var element = this.getSelect(type);
        return element ? element.tomselect : null;
    },

    init: function () {
        var self = this;
        document.querySelectorAll('select.blog-post-mapping').forEach(function (element) {
            var type = element.getAttribute('data-mapping');
            var hasSearch = !!element.getAttribute('data-search-url');
            var settings = {
                plugins: ['remove_button'],
                valueField: 'value',
                labelField: 'text',
                searchField: ['text'],
                maxItems: null,
                hideSelected: true,
                preload: hasSearch ? 'focus' : null,
                placeholder: element.getAttribute('data-placeholder') || ''
            };
            if (hasSearch) {
                settings.load = function (query, callback) {
                    self.search(element, type, query, callback);
                };
            }
            if (type === 'tag') {
                settings.create = true;
                settings.createOnBlur = false;
            }
            foodcoopshop.TomSelectCustom.init(element, settings);
        });

        var addAllButton = document.querySelector('.add-all-products-of-manufacturer');
        if (addAllButton) {
            addAllButton.addEventListener('click', function (event) {
                event.preventDefault();
                self.addAllProductsOfFirstSupplier();
            });
        }
    },

    search: function (element, type, query, callback) {
        var url = element.getAttribute('data-search-url') +
            '?type=' + encodeURIComponent(type) +
            '&q=' + encodeURIComponent(query || '');
        fetch(url, { credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
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
