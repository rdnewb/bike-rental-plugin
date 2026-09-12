/* Rental field visibility only; PHP remains authoritative for validation. */
jQuery(function ($) {
    'use strict';

    const panel = $('#brp_rental_data');
    if (!panel.length) {
        return;
    }

    function updateFields() {
        const enabled = panel.find('#brp-package-enabled').prop('checked');
        panel.find('.brp-package-fields').toggle(enabled).find(':input').prop('disabled', !enabled);
    }

    panel.on('change', '#brp-package-enabled', updateFields);
    updateFields();
});
