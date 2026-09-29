(() => {
    'use strict';
    document.querySelectorAll('.brp-block-dates').forEach((group) => {
        const allDay = group.querySelector('[name=all_day]');
        const noEnd = group.querySelector('[name=no_end]');
        const sync = (event) => {
            if (event?.target === allDay && allDay.checked && noEnd) noEnd.checked = false;
            if (event?.target === noEnd && noEnd.checked) allDay.checked = false;
            const indefinite = !!noEnd?.checked;
            for (const name of ['start_time', 'end_time', 'end_date']) {
                const field = group.querySelector(`[name=${name}]`);
                field.disabled = (name.endsWith('_time') && allDay.checked) || (name.startsWith('end_') && indefinite);
                field.required = !field.disabled;
            }
        };
        allDay.addEventListener('change', sync);
        noEnd?.addEventListener('change', sync);
        sync();
    });
})();
