// define(['jquery'], function($) {
//     return {
//         init: function() {
//              /**
//              * Handle tab switching
//              * @param {string} tab
//              * @param {HTMLElement} el
//              */
//             function showTab(tab, el) {
//                 $('.tab-content').removeClass('active');
//                 $('.tab').removeClass('active');

//                 $('#' + tab).addClass('active');
//                 $(el).addClass('active');
//             }

//             // Klik tab
//             $('document').on('click', '.tab', function() {
//                 const tab = $(this).data('tab');
//                 showTab(tab, this);
//             });

//             // Default tab
//             // $('.tab-content').removeClass('active');
//             // $('#data').addClass('active');
//             // Default tab
//             $('.tab-content').removeClass('active');
//             $('.tab').removeClass('active');

//             $('#data').addClass('active');
//             $('.tab[data-tab="data"]').addClass('active');

//         }
//     };
// });
define(['jquery'], function($) {
    return {
        init: function() {

            $(function() {

            /**
             * Handle tab switching
             * @param {string} tab
             * @param {HTMLElement} el
             */
                function showTab(tab, el) {
                    const container = $('.akademikmonitor');

                    container.find('.tab-content').removeClass('active');
                    container.find('.tab').removeClass('active');

                    container.find('#' + tab).addClass('active');
                    $(el).addClass('active');
                }

                $(document).on('click', '.akademikmonitor .tab', function() {
                    const tab = $(this).data('tab');
                    showTab(tab, this);
                });

                // default
                const container = $('.akademikmonitor');

                container.find('.tab-content').removeClass('active');
                container.find('.tab').removeClass('active');

                container.find('#data').addClass('active');
                container.find('.tab[data-tab="data"]').addClass('active');

            });
        }
    };
});