jQuery(document).ready(function($) {
    $('.validation-button').on('click', function(e) {
        e.preventDefault();

        var currentTab = $(this).data('tab');

        $.ajax({
            type: 'POST',
            url: bot_logger_ajax_obj.ajax_url,
            data: {
                action: 'bl_validate_ips',
                nonce: bot_logger_ajax_obj.nonce,
                tab: currentTab
            },
            success: function(response) {
                if (response.success) {
                    console.log(response.data.message);
                    location.reload();
                } else {
                    console.log('IP validation failed.');
                    console.log('Server responded with error:', response);
                    location.reload();
                }
            },
            error: function(error) {
                alert('An error occurred.');
                console.log(`An error occured validating the IP`,error);
            }
        });
    });

    $('#has_custom_log_path').on('change', function() {
        if ($(this).is(':checked')) {
            $('.custom-log-path').slideDown().css('display', 'flex');
        } else {
            $('.custom-log-path').slideUp();
        }
    });
});