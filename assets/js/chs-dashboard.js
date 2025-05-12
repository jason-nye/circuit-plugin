jQuery(document).ready(function($) {
    var totalPages = 0;
    var currentPage = 1;

    function fetchEvents(page) {
        $.ajax({
            url: chsDashboard.ajaxUrl,
            type: 'POST',
            data: {
                action: 'chs_fetch_events',
                security: chsDashboard.nonce,
                page: page
            },
            success: function(response) {
                console.log(response);
                if (response.success) {
                    totalPages = response.data.totalPages;
                    // Update the progress bar and counter
                    var percentage = (page / totalPages) * 10;
                    $('#fetch-events-progress').css('width', percentage + '%');
                    $('#fetch-events-counter').text('Batch ' + page + ' of ' + totalPages);

                    if (page < totalPages) {
                        fetchEvents(page + 1);
                    } else {
                        $('#fetch-events-result').html('<p>All events fetched successfully!</p>');
                    }
                } else {
                    var message = response?.data?.message || 'An unknown error occurred.';
                    $('#fetch-events-result').html('<p>Error: ' + message + '</p>');
                }
            },
            error: function(xhr, status, error) {
                var errorMsg = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : error;
                console.error(error);
                $('#fetch-events-result').html('<p>Error: ' + errorMsg + '</p>');
            }
        });
    }

    $('#fetch-events-button').on('click', function() {
        // Initialize progress
        $('#fetch-events-progress').css('width', '0%');
        $('#fetch-events-counter').text('Starting...');
        $('#fetch-events-result').html('');

        // Start fetching events
        fetchEvents(currentPage);
    });
});