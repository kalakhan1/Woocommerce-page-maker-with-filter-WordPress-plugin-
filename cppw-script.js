jQuery(document).ready(function($) {
    // Filter form submit
    $(document).on('submit', '.cppw-filter-form', function(e) {
        e.preventDefault();
        var form = $(this);
        var container = form.closest('.cppw-product-container');
        var pageId = container.data('page-id');
        if (!container.length) {
            // If filter is standalone (sidebar), find container by data-page-id
            pageId = form.data('page-id');
            container = $('.cppw-product-container[data-page-id="' + pageId + '"]');
        }
        if (!container.length) {
            alert('No product container found for this page.');
            return;
        }
        var data = form.serialize() + '&action=cppw_filter_products&page_id=' + pageId;
        data += '&nonce=' + $('#cppw_filter_nonce').val();
        $.ajax({
            url: cppw_ajax.ajax_url,
            type: 'POST',
            data: data,
            dataType: 'json',
            beforeSend: function() {
                container.find('.cppw-products').html('<p>Loading...</p>');
            },
            success: function(response) {
                if (response.success) {
                    container.find('.cppw-products').html(response.data.html);
                } else {
                    container.find('.cppw-products').html('<p class="error">' + response.data.message + '</p>');
                }
            },
            error: function() {
                container.find('.cppw-products').html('<p class="error">An error occurred.</p>');
            }
        });
    });

    // Reset filter
    $(document).on('click', '.cppw-reset-filter', function(e) {
        e.preventDefault();
        var form = $(this).closest('form');
        form[0].reset();
        form.find('select').val('');
        form.trigger('submit');
    });
});
