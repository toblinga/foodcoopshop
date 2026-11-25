/**
 * Chip-Eingabe (Tom Select) fuer die Zuordnung von Blogbeitraegen zu Lieferanten und Produkten.
 * Die Suche laeuft live (search as you type) ueber den JSON-Endpunkt Admin/BlogPosts/searchTargets.
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
            foodcoopshop.TomSelectCustom.init(element, {
                plugins: ['remove_button'],
                valueField: 'value',
                labelField: 'text',
                searchField: ['text'],
                maxItems: null,
                hideSelected: true,
                preload: 'focus',
                placeholder: element.getAttribute('data-placeholder') || '',
                load: function (query, callback) {
                    self.search(element, type, query, callback);
                }
            });
        });

        var addAllButton = document.querySelector('.add-all-products-of-manufacturer');
        if (addAllButton) {
            addAllButton.addEventListener('click', function (event) {
                event.preventDefault();
                self.addAllProductsOfManufacturer();
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

    addAllProductsOfManufacturer: function () {
        var manufacturer = this.getTomSelect('manufacturer');
        var products = this.getTomSelect('product');
        var productElement = this.getSelect('product');
        if (!manufacturer || !products || !productElement) {
            return;
        }
        var manufacturerId = manufacturer.getValue();
        if (Array.isArray(manufacturerId)) {
            manufacturerId = manufacturerId[0];
        }
        if (!manufacturerId) {
            window.alert('Bitte zuerst einen Lieferanten auswählen.');
            return;
        }
        var url = productElement.getAttribute('data-search-url') +
            '?type=product&q=&manufacturer_id=' + encodeURIComponent(manufacturerId);
        fetch(url, { credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (items) {
                items.forEach(function (item) {
                    products.addOption(item);
                    products.addItem(item.value);
                });
            });
    }
};
