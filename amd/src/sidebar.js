/**
 * Sidebar mobile controller.
 *
 * @module local_akademikmonitor/sidebar
 */
define([], function() {
    return {
        /**
         * Initialize mobile sidebar interactions.
         */
        init: function() {
            const root = document.querySelector('.akademikmonitor');
            if (!root) {
                return;
            }

            /**
             * Open sidebar.
             */
            function openSidebar() {
                root.classList.add('sidebar-open');
            }

            /**
             * Close sidebar.
             */
            function closeSidebar() {
                root.classList.remove('sidebar-open');
            }

            /**
             * Handle click actions.
             *
             * @param {Event} e Click event.
             */
            function handleClick(e) {
                const button = e.target.closest('[data-action]');
                if (!button) {
                    return;
                }

                const action = button.getAttribute('data-action');

                if (action === 'open-sidebar') {
                    openSidebar();
                }

                if (action === 'close-sidebar') {
                    closeSidebar();
                }
            }

            /**
             * Handle resize event.
             */
            function handleResize() {
                if (window.innerWidth > 991) {
                    closeSidebar();
                }
            }

            document.addEventListener('click', handleClick);
            window.addEventListener('resize', handleResize);
        }
    };
});