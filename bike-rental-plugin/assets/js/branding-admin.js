/* Native WordPress controls, loaded only on Bike Rentals > Settings. */
jQuery(($) => {
    const root = $('.brp-branding-settings');
    if (!root.length) return;
    root.find('.brp-color').wpColorPicker();
    let frame;
    root.find('.brp-choose-logo').on('click', () => {
        if (!frame) {
            frame = wp.media({ title: 'Choose booking logo / image', button: { text: 'Use this image' }, library: { type: 'image' }, multiple: false });
            frame.on('select', () => {
                const image = frame.state().get('selection').first().toJSON();
                root.find('#brp-brand-logo').val(image.id);
                const preview = $('<img>').attr({ src: image.sizes?.medium?.url || image.url, alt: image.alt || '' }).css({ maxWidth: '300px', width: '100%', height: 'auto' });
                root.find('.brp-logo-preview').empty().append(preview);
            });
        }
        frame.open();
    });
    root.find('.brp-remove-logo').on('click', () => {
        root.find('#brp-brand-logo').val('0'); root.find('.brp-logo-preview').empty();
    });
});
