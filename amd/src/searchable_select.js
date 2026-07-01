define(['jquery', 'core/form-autocomplete'], function($, Autocomplete) {

    return {

        init: function() {

            $('.am-searchable').each(function() {

                const select = this;

                if ($(select).data('autocomplete-init')) {
                    return;
                }

                $(select).data('autocomplete-init', true);

                Autocomplete.enhance(select, {
                    placeholder: 'Cari...',
                    caseSensitive: false,
                    showSuggestions: true
                });

                // Tunggu Moodle selesai render autocomplete
                setTimeout(function() {

                    $(select)
                        .siblings('.form-autocomplete-selection')
                        .addClass('am-searchable-enhanced');

                }, 100);

            });

        }

    };

});