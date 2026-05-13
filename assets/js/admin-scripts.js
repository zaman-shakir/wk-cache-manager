jQuery(document).ready(function($) {
    console.log('WK Cache Manager: Admin scripts loaded');

    // Dashboard refresh functionality
    $('#refresh-stats').on('click', function(e) {
        e.preventDefault();
        location.reload();
    });

    // URL Crawler: Clear logs
    $('#clear-logs').on('click', function(e) {
        e.preventDefault();

        if (confirm('Are you sure you want to clear all logs?')) {
            $.post(ajaxurl, {
                action: 'wk_cache_manager_clear_url_logs',
                nonce: $(this).data('nonce')
            }, function() {
                location.reload();
            });
        }
    });

    // URL Crawler: View attempts modal
    $('.view-attempts').on('click', function() {
        var attempts = $(this).data('attempts');
        var html = '<ul>';

        if (Array.isArray(attempts)) {
            attempts.forEach(function(attempt, index) {
                var statusClass = 'status-' + (attempt.cf_cache_status || 'unknown').toLowerCase();
                html += '<li>' +
                       '<strong>Attempt ' + (index + 1) + ':</strong> ' +
                       '<span class="status-badge ' + statusClass + '">' +
                       (attempt.cf_cache_status || 'UNKNOWN') +
                       '</span> - ' +
                       (attempt.timestamp || 'N/A') +
                       '</li>';
            });
        } else {
            html += '<li>No attempt data available</li>';
        }

        html += '</ul>';

        $('.attempts-list').html(html);
        $('#attempts-modal').show();
    });

    // Close modal when clicking outside
    $(document).on('click', function(e) {
        if (!$(e.target).closest('#attempts-modal, .view-attempts').length) {
            $('#attempts-modal').hide();
        }
    });

    // LiteSpeed Manager: Clear stock log
    $('#clear-stock-log').on('click', function(e) {
        e.preventDefault();

        if (!confirm('Are you sure you want to clear the stock management log?')) {
            return;
        }

        var $button = $(this);
        $button.prop('disabled', true).text('Clearing...');

        $.post(ajaxurl, {
            action: 'wk_cache_manager_clear_stock_log',
            nonce: $button.data('nonce')
        }, function(response) {
            if (response.success) {
                location.reload();
            } else {
                alert('Error: ' + (response.data || 'Unknown error'));
                $button.prop('disabled', false).text('Clear Log');
            }
        });
    });
});
