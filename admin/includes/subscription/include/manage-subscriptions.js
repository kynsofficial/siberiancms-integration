/**
 * PE Subscription - Admin Subscriptions JavaScript
 * 
 * Enhanced version with better AJAX interactions, search functionality,
 * and proper status handling for subscription management.
 * Modified to respect cancellation_source for PayPal subscriptions.
 */

jQuery(document).ready(function($) {
    // Use 20 items per page
    var itemsPerPage = 20;
    var currentPage = 1;
    var totalItems = $('#subscriptions-list tr.subscription-row').length;
    var filteredItems = totalItems;
    var totalPages = Math.ceil(totalItems / itemsPerPage);
    var searchTimeout = null;
    var lastSearchTerm = '';
    
    // Initialize the state
    function initializePagination() {
        // Update the total pages display
        $('#pagination-total').text(Math.max(1, totalPages));
        
        // Hide pagination controls if there's only one page
        if (totalPages <= 1) {
            $('.swsib-table-pagination').hide();
        } else {
            $('.swsib-table-pagination').show();
        }
        
        // Apply initial pagination
        applyPagination();
    }

    /**
     * Initialize bulk actions dropdown with default options
     */
    function initializeBulkActions() {
        var $bulkActions = $('#bulk-action-selector');
        
        // Clear existing options
        $bulkActions.empty();
        
        // Add default option
        $bulkActions.append('<option value="">' + swsib_subscription.bulk_action_default + '</option>');
        
        // Add all possible actions
        $bulkActions.append('<option value="cancel">' + swsib_subscription.bulk_action_cancel + '</option>');
        $bulkActions.append('<option value="resume">' + swsib_subscription.bulk_action_resume + '</option>');
        $bulkActions.append('<option value="force-cancel">' + swsib_subscription.bulk_action_force_cancel + '</option>');
        $bulkActions.append('<option value="delete">' + swsib_subscription.bulk_action_delete + '</option>');
        
        // Disable apply button initially
        $('#bulk-action-apply').prop('disabled', true);
    }

    /**
     * Filter subscriptions based on search text and filter selection
     */
    function filterSubscriptions() {
        var searchTerm = $('#subscription-search').val().toLowerCase();
        var statusFilter = $('#subscription-filter').val();
        
        currentPage = 1;
        totalItems = $('#subscriptions-list tr.subscription-row').length;
        filteredItems = 0;
        
        // Hide all details rows first
        $('.subscription-details-row').hide();
        
        $('#subscriptions-list tr.subscription-row').each(function() {
            var $row = $(this);
            var userName = $row.data('user-name').toString().toLowerCase();
            var userEmail = $row.data('user-email').toString().toLowerCase();
            var planName = $row.data('plan-name').toString().toLowerCase();
            var rowStatus = $row.data('status');
            
            var matchesSearch = (searchTerm === '' || 
                userName.includes(searchTerm) || 
                userEmail.includes(searchTerm) || 
                planName.includes(searchTerm));
                
            var matchesStatus = (statusFilter === 'all' || rowStatus === statusFilter);
            
            if (matchesSearch && matchesStatus) {
                $row.show();
                filteredItems++;
            } else {
                $row.hide();
            }
        });
        
        totalPages = Math.ceil(filteredItems / itemsPerPage);
        
        // Reset the "select all" checkbox
        $('#select-all-subscriptions').prop('checked', false);
        
        applyPagination();
        updateSearchResults();
        updateBulkActionAvailability();
    }
    
    /**
     * Apply pagination to the visible (filtered) rows
     */
    function applyPagination() {
        var visibleRows = $('#subscriptions-list tr.subscription-row:visible');
        
        // Hide all details rows
        $('.subscription-details-row').hide();
        $('.view-subscription-details').text('Details');
        
        // Hide all rows first
        visibleRows.hide();
        
        // Show only rows for current page
        var startIdx = (currentPage - 1) * itemsPerPage;
        var endIdx = startIdx + itemsPerPage;
        
        visibleRows.slice(startIdx, endIdx).show();
        
        updatePaginationControls();
    }
    
    /**
     * Update pagination controls and info text
     */
    function updatePaginationControls() {
        $('#pagination-current').text(currentPage);
        $('#pagination-total').text(Math.max(1, totalPages));
        
        // Calculate how many items are showing on current page
        var visibleRows = $('#subscriptions-list tr.subscription-row:visible').length;
        
        $('#pagination-count').text('Showing ' + visibleRows + ' of ' + filteredItems + ' subscriptions');
        
        // Hide pagination controls if there is only one page
        if (totalPages <= 1) {
            $('.swsib-table-pagination').hide();
        } else {
            $('.swsib-table-pagination').show();
        }
        
        // Enable/disable pagination buttons
        $('.pagination-prev').prop('disabled', currentPage === 1);
        $('.pagination-next').prop('disabled', currentPage >= totalPages);
    }
    
    /**
     * Update search results info
     */
    function updateSearchResults() {
        var searchTerm = $('#subscription-search').val().toLowerCase();
        
        if (searchTerm && filteredItems === 0) {
            // No results found
            if ($('#no-results-message').length === 0) {
                $('#swsib-message-container').html(
                    '<div id="no-results-message" class="swsib-notice info">' +
                    '<p>No subscriptions found matching "' + searchTerm + '". Try a different search term.</p>' +
                    '</div>'
                );
            }
        } else {
            // Clear no results message if it exists
            $('#no-results-message').remove();
        }
    }
    
    /**
     * Generate and display search suggestions
     */
    function generateSearchSuggestions(searchTerm) {
        if (!searchTerm || searchTerm.length < 2) {
            $('#search-suggestions').hide();
            return;
        }
        
        searchTerm = searchTerm.toLowerCase();
        var suggestions = [];
        var maxSuggestions = 5;
        
        // Collect unique users and plans that match the search term
        var users = {};
        var plans = {};
        
        $('#subscriptions-list tr.subscription-row').each(function() {
            var $row = $(this);
            var userName = $row.data('user-name').toString();
            var userEmail = $row.data('user-email').toString();
            var planName = $row.data('plan-name').toString();
            
            // Check for matches
            if (userName.toLowerCase().includes(searchTerm)) {
                users[userName] = userName;
            }
            
            if (userEmail.toLowerCase().includes(searchTerm)) {
                users[userEmail] = userEmail;
            }
            
            if (planName.toLowerCase().includes(searchTerm)) {
                plans[planName] = planName;
            }
        });
        
        // Convert to arrays
        var userSuggestions = Object.values(users);
        var planSuggestions = Object.values(plans);
        
        // Add suggestions in order: users first, then plans
        for (var i = 0; i < userSuggestions.length && suggestions.length < maxSuggestions; i++) {
            suggestions.push(userSuggestions[i]);
        }
        
        for (var i = 0; i < planSuggestions.length && suggestions.length < maxSuggestions; i++) {
            suggestions.push(planSuggestions[i]);
        }
        
        // Display suggestions
        if (suggestions.length > 0) {
            var html = '';
            for (var i = 0; i < suggestions.length; i++) {
                html += '<div class="search-suggestion-item" data-value="' + suggestions[i] + '">' + 
                        highlightMatch(suggestions[i], searchTerm) + '</div>';
            }
            
            $('#search-suggestions').html(html).show();
        } else {
            $('#search-suggestions').hide();
        }
    }
    
    /**
     * Highlight matching parts of the suggestion
     */
    function highlightMatch(text, searchTerm) {
        var index = text.toLowerCase().indexOf(searchTerm.toLowerCase());
        if (index >= 0) {
            return text.substring(0, index) + 
                   '<strong>' + text.substring(index, index + searchTerm.length) + '</strong>' + 
                   text.substring(index + searchTerm.length);
        }
        return text;
    }
    
    /**
     * Show success/error messages
     */
    function showMessage(message, type) {
        $('#swsib-message-container').html(
            '<div class="swsib-notice ' + (type || 'success') + '"><p>' + message + '</p></div>'
        ).fadeIn();
        
        // Scroll to top to ensure message is visible
        $('html, body').animate({
            scrollTop: $('#swsib-message-container').offset().top - 50
        }, 300);
        
        // Auto-hide success messages after 3 seconds
        if (type === 'success' || !type) {
            setTimeout(function() {
                $('#swsib-message-container').fadeOut(500, function() {
                    $(this).html('');
                });
            }, 3000);
        }
    }
    
    /**
     * Reload page after successful AJAX action
     */
    function reloadPage() {
        setTimeout(function() {
            window.location.reload();
        }, 1500);
    }
    
    /**
     * Show loading overlay
     */
    function showLoader() {
        $('#subscription-loader').show();
    }
    
    /**
     * Hide loading overlay
     */
    function hideLoader() {
        $('#subscription-loader').hide();
    }

    /**
     * Check if a subscription can be deleted based on its status
     * Only allow deletion of expired or cancelled subscriptions
     */
    function canDelete(status) {
        return status === 'expired' || status === 'cancelled';
    }

    /**
     * Check if a subscription can be cancelled (set to pending cancellation)
     * Only active subscriptions can be cancelled
     */
    function canCancel(status) {
        return status === 'active';
    }

    /**
     * Check if a subscription can be force cancelled
     * Only pending-cancellation subscriptions can be force cancelled
     */
    function canForceCancel(status) {
        return status === 'pending-cancellation';
    }

    /**
     * Check if a subscription can be resumed (uncancelled)
     * Only pending-cancellation subscriptions can be resumed, and only if:
     * 1. It's not a PayPal subscription with cancellation_source='paypal'
     * 2. For PayPal subscriptions, cancellation_source must be 'frontend'
     */
    function canResume(status, paymentMethod, cancellationSource) {
        if (status !== 'pending-cancellation') {
            return false;
        }
        
        // For PayPal subscriptions, check cancellation source
        if (paymentMethod === 'paypal') {
            return cancellationSource === 'frontend';
        }
        
        // For other payment methods (like Stripe), allow resume
        return true;
    }

    /**
     * Check if a subscription can be activated
     * Only expired subscriptions can be activated
     */
    function canActivate(status) {
        return status === 'expired';
    }

    /**
     * Update bulk action availability based on selected subscriptions
     */
    function updateBulkActionAvailability() {
        var selectedSubscriptions = $('.subscription-select:checked');
        
        if (selectedSubscriptions.length === 0) {
            // No subscriptions selected, disable apply button
            $('#bulk-action-apply').prop('disabled', true);
            return;
        }
        
        // Enable apply button
        $('#bulk-action-apply').prop('disabled', false);
        
        // Check which bulk actions are available based on selected subscriptions
        var canDeleteAll = true;
        var canCancelAll = true;
        var canForceCancelAll = true;
        var canResumeAll = true;
        
        selectedSubscriptions.each(function() {
            var subscriptionId = $(this).data('id');
            var $row = $('tr[data-subscription-id="' + subscriptionId + '"]');
            var status = $row.data('status');
            var paymentMethod = $row.data('payment-method');
            var cancellationSource = $row.data('cancellation-source');
            
            if (!canDelete(status)) {
                canDeleteAll = false;
            }
            
            if (!canCancel(status)) {
                canCancelAll = false;
            }
            
            if (!canForceCancel(status)) {
                canForceCancelAll = false;
            }
            
            if (!canResume(status, paymentMethod, cancellationSource)) {
                canResumeAll = false;
            }
        });
        
        // Disable/enable bulk action options based on selection
        var $bulkSelector = $('#bulk-action-selector');
        
        // For each option, disable if not applicable
        $bulkSelector.find('option[value="delete"]').prop('disabled', !canDeleteAll);
        $bulkSelector.find('option[value="cancel"]').prop('disabled', !canCancelAll);
        $bulkSelector.find('option[value="force-cancel"]').prop('disabled', !canForceCancelAll);
        $bulkSelector.find('option[value="resume"]').prop('disabled', !canResumeAll);
        
        // If the currently selected option is disabled, reset to default
        if ($bulkSelector.find('option:selected').prop('disabled')) {
            $bulkSelector.val('');
        }
    }
    
    // Initialize pagination and bulk actions on page load
    initializePagination();
    initializeBulkActions();

    // Pagination controls event handlers
    $('.pagination-prev').on('click', function() {
        if (currentPage > 1) {
            currentPage--;
            applyPagination();
        }
    });
    
    $('.pagination-next').on('click', function() {
        if (currentPage < totalPages) {
            currentPage++;
            applyPagination();
        }
    });
    
    // Select all checkbox with state validation
    $('#select-all-subscriptions').on('change', function() {
        var isChecked = $(this).is(':checked');
        
        // Check or uncheck all visible checkboxes
        $('#subscriptions-list tr.subscription-row:visible .subscription-select').prop('checked', isChecked);
        
        // Update bulk actions availability
        updateBulkActionAvailability();
    });
    
    // Individual checkbox change with state validation
    $(document).on('change', '.subscription-select', function() {
        // If any checkbox is unchecked, uncheck "select all"
        if (!$(this).is(':checked')) {
            $('#select-all-subscriptions').prop('checked', false);
        } 
        // If all visible checkboxes are checked, check "select all"
        else if ($('#subscriptions-list tr.subscription-row:visible .subscription-select:not(:checked)').length === 0) {
            $('#select-all-subscriptions').prop('checked', true);
        }
        
        // Update bulk actions availability
        updateBulkActionAvailability();
    });
    
    // Live search with debounce
    $('#subscription-search').on('keyup', function() {
        var searchTerm = $(this).val();
        
        // Clear previous timeout
        if (searchTimeout) {
            clearTimeout(searchTimeout);
        }
        
        // Don't filter if the search term is the same
        if (searchTerm === lastSearchTerm) {
            return;
        }
        
        lastSearchTerm = searchTerm;
        
        // Generate suggestions immediately
        generateSearchSuggestions(searchTerm);
        
        // Debounce the filter operation
        searchTimeout = setTimeout(function() {
            filterSubscriptions();
        }, 300);
    });
    
    // Search suggestion click handler
    $(document).on('click', '.search-suggestion-item', function() {
        var value = $(this).data('value');
        $('#subscription-search').val(value);
        $('#search-suggestions').hide();
        filterSubscriptions();
    });
    
    // Hide suggestions when clicking outside
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.swsib-subscription-search').length) {
            $('#search-suggestions').hide();
        }
    });
    
    // Reset search and filters
    $('#subscription-search-reset').on('click', function() {
        $('#subscription-search').val('');
        $('#subscription-filter').val('all');
        $('.swsib-card').removeClass('selected');
        lastSearchTerm = '';
        filterSubscriptions();
    });
    
    // Status filter dropdown change
    $('#subscription-filter').on('change', function() {
        // Update card selection to match dropdown
        var status = $(this).val();
        $('.swsib-card').removeClass('selected');
        
        if (status !== 'all') {
            $('.swsib-card[data-status="' + status + '"]').addClass('selected');
        }
        
        filterSubscriptions();
    });
    
    // Dashboard card filtering
    $('.swsib-card').on('click', function() {
        var selectedStatus = $(this).data('status');
        
        // If already selected, clear the filter
        if ($(this).hasClass('selected')) {
            $('#subscription-filter').val('all');
            $('.swsib-card').removeClass('selected');
        } else {
            $('#subscription-filter').val(selectedStatus);
            $('.swsib-card').removeClass('selected');
            $(this).addClass('selected');
        }
        
        filterSubscriptions();
    });
    
    // Toggle subscription details
    $(document).on('click', '.view-subscription-details', function() {
        var $button = $(this);
        var subscriptionId = $button.data('subscription-id');
        var $detailsRow = $('#details-' + subscriptionId);
        
        // Close other open detail rows
        $('.subscription-details-row').not($detailsRow).hide();
        $('.view-subscription-details').not($button).text('Details');
        
        // Toggle current details row
        if ($detailsRow.is(':visible')) {
            $detailsRow.hide();
            $button.text('Details');
        } else {
            $detailsRow.show();
            $button.text('Hide Details');
        }
    });
    
    // AJAX: Set subscription to pending cancellation
    $(document).on('click', '.cancel-subscription', function() {
        var $button = $(this);
        var subscriptionId = $button.data('subscription-id');
        var $row = $('tr[data-subscription-id="' + subscriptionId + '"]');
        var status = $row.data('status');
        
        if (!canCancel(status)) {
            showMessage(swsib_subscription.error_cannot_cancel, 'error');
            return;
        }
        
        if (confirm(swsib_subscription.confirm_cancel)) {
            $button.prop('disabled', true).text(swsib_subscription.processing);
            showLoader();
            
            $.ajax({
                url: swsib_subscription.ajaxurl,
                type: 'POST',
                data: {
                    action: 'swsib_set_pending_cancellation',
                    nonce: swsib_subscription.nonce,
                    subscription_id: subscriptionId
                },
                success: function(response) {
                    hideLoader();
                    
                    if (response.success) {
                        showMessage(response.data.message || swsib_subscription.success_cancel, 'success');
                        // Reload the page after showing success message
                        reloadPage();
                    } else {
                        showMessage(response.data && response.data.message ? response.data.message : swsib_subscription.error_general, 'error');
                        $button.prop('disabled', false).text('Cancel');
                    }
                },
                error: function() {
                    hideLoader();
                    showMessage(swsib_subscription.error_general, 'error');
                    $button.prop('disabled', false).text('Cancel');
                }
            });
        }
    });
    
    // AJAX: Resume (uncancel) subscription
    $(document).on('click', '.uncancel-subscription', function() {
        var $button = $(this);
        var subscriptionId = $button.data('subscription-id');
        var $row = $('tr[data-subscription-id="' + subscriptionId + '"]');
        var status = $row.data('status');
        var paymentMethod = $row.data('payment-method');
        var cancellationSource = $row.data('cancellation-source');
        
        if (!canResume(status, paymentMethod, cancellationSource)) {
            if (paymentMethod === 'paypal' && cancellationSource === 'paypal') {
                showMessage(swsib_subscription.error_cannot_resume_paypal, 'error');
            } else {
                showMessage(swsib_subscription.error_cannot_resume, 'error');
            }
            return;
        }
        
        if (confirm(swsib_subscription.confirm_uncancel)) {
            $button.prop('disabled', true).text(swsib_subscription.processing);
            showLoader();
            
            $.ajax({
                url: swsib_subscription.ajaxurl,
                type: 'POST',
                data: {
                    action: 'swsib_uncancel_subscription',
                    nonce: swsib_subscription.nonce,
                    subscription_id: subscriptionId
                },
                success: function(response) {
                    hideLoader();
                    
                    if (response.success) {
                        showMessage(response.data.message || swsib_subscription.success_uncancel, 'success');
                        // Reload the page after showing success message
                        reloadPage();
                    } else {
                        showMessage(response.data && response.data.message ? response.data.message : swsib_subscription.error_general, 'error');
                        $button.prop('disabled', false).text('Resume');
                    }
                },
                error: function() {
                    hideLoader();
                    showMessage(swsib_subscription.error_general, 'error');
                    $button.prop('disabled', false).text('Resume');
                }
            });
        }
    });
    
    // AJAX: Force cancel subscription
    $(document).on('click', '.force-cancel-subscription', function() {
        var $button = $(this);
        var subscriptionId = $button.data('subscription-id');
        var $row = $('tr[data-subscription-id="' + subscriptionId + '"]');
        var status = $row.data('status');
        
        if (!canForceCancel(status)) {
            showMessage(swsib_subscription.error_cannot_force_cancel, 'error');
            return;
        }
        
        if (confirm(swsib_subscription.confirm_force_cancel)) {
            $button.prop('disabled', true).text(swsib_subscription.processing);
            showLoader();
            
            $.ajax({
                url: swsib_subscription.ajaxurl,
                type: 'POST',
                data: {
                    action: 'swsib_cancel_subscription',
                    nonce: swsib_subscription.nonce,
                    subscription_id: subscriptionId,
                    force_cancel: true
                },
                success: function(response) {
                    hideLoader();
                    
                    if (response.success) {
                        showMessage(response.data.message || swsib_subscription.success_force_cancel, 'success');
                        // Reload the page after showing success message
                        reloadPage();
                    } else {
                        showMessage(response.data && response.data.message ? response.data.message : swsib_subscription.error_general, 'error');
                        $button.prop('disabled', false).text('Force Cancel');
                    }
                },
                error: function() {
                    hideLoader();
                    showMessage(swsib_subscription.error_general, 'error');
                    $button.prop('disabled', false).text('Force Cancel');
                }
            });
        }
    });
    
    // AJAX: Activate an expired subscription
    $(document).on('click', '.activate-subscription', function() {
        var $button = $(this);
        var subscriptionId = $button.data('subscription-id');
        var $row = $('tr[data-subscription-id="' + subscriptionId + '"]');
        var status = $row.data('status');
        
        if (!canActivate(status)) {
            showMessage(swsib_subscription.error_cannot_activate, 'error');
            return;
        }
        
        if (confirm(swsib_subscription.confirm_activate)) {
            $button.prop('disabled', true).text(swsib_subscription.processing);
            showLoader();
            
            $.ajax({
                url: swsib_subscription.ajaxurl,
                type: 'POST',
                data: {
                    action: 'swsib_activate_subscription',
                    nonce: swsib_subscription.nonce,
                    subscription_id: subscriptionId
                },
                success: function(response) {
                    hideLoader();
                    
                    if (response.success) {
                        showMessage(response.data.message || swsib_subscription.success_activate, 'success');
                        // Reload the page after showing success message
                        reloadPage();
                    } else {
                        showMessage(response.data && response.data.message ? response.data.message : swsib_subscription.error_general, 'error');
                        $button.prop('disabled', false).text('Activate');
                    }
                },
                error: function() {
                    hideLoader();
                    showMessage(swsib_subscription.error_general, 'error');
                    $button.prop('disabled', false).text('Activate');
                }
            });
        }
    });
    
    // AJAX: Delete subscription
    $(document).on('click', '.delete-subscription', function() {
        var $button = $(this);
        var subscriptionId = $button.data('subscription-id');
        var $row = $('tr[data-subscription-id="' + subscriptionId + '"]');
        var status = $row.data('status');
        
        if (!canDelete(status)) {
            showMessage(swsib_subscription.error_cannot_delete, 'error');
            return;
        }
        
        if (confirm(swsib_subscription.confirm_delete)) {
            $button.prop('disabled', true).text(swsib_subscription.processing);
            showLoader();
            
            $.ajax({
                url: swsib_subscription.ajaxurl,
                type: 'POST',
                data: {
                    action: 'swsib_delete_subscription',
                    nonce: swsib_subscription.nonce,
                    subscription_id: subscriptionId
                },
                success: function(response) {
                    hideLoader();
                    
                    if (response.success) {
                        showMessage(response.data.message || swsib_subscription.success_delete, 'success');
                        // Reload the page after showing success message
                        reloadPage();
                    } else {
                        showMessage(response.data && response.data.message ? response.data.message : swsib_subscription.error_general, 'error');
                        $button.prop('disabled', false).text('Delete');
                    }
                },
                error: function() {
                    hideLoader();
                    showMessage(swsib_subscription.error_general, 'error');
                    $button.prop('disabled', false).text('Delete');
                }
            });
        }
    });
    
    // Bulk action selector change
    $('#bulk-action-selector').on('change', function() {
        // Enable apply button if an action is selected and there are checked subscriptions
        $('#bulk-action-apply').prop('disabled', 
            $(this).val() === '' || $('.subscription-select:checked').length === 0);
    });
    
    // Bulk action apply button
    $('#bulk-action-apply').on('click', function() {
        var action = $('#bulk-action-selector').val();
        
        if (!action) {
            showMessage(swsib_subscription.no_action, 'error');
            return;
        }
        
        var selectedIds = [];
        $('.subscription-select:checked').each(function() {
            selectedIds.push($(this).data('id'));
        });
        
        if (selectedIds.length === 0) {
            showMessage(swsib_subscription.no_selection, 'error');
            return;
        }
        
        var confirmMessage = '';
        var ajaxAction = '';
        
        // Set the appropriate confirmation message and AJAX action
        switch(action) {
            case 'cancel':
                confirmMessage = swsib_subscription.confirm_bulk_cancel;
                ajaxAction = 'swsib_bulk_set_pending_cancellation';
                break;
            case 'resume':
                confirmMessage = swsib_subscription.confirm_bulk_resume;
                ajaxAction = 'swsib_bulk_uncancel_subscriptions';
                break;
            case 'force-cancel':
                confirmMessage = swsib_subscription.confirm_bulk_force_cancel;
                ajaxAction = 'swsib_bulk_cancel_subscriptions';
                break;
            case 'delete':
                confirmMessage = swsib_subscription.confirm_bulk_delete;
                ajaxAction = 'swsib_bulk_delete_subscriptions';
                break;
        }
        
        if (confirm(confirmMessage)) {
            var $button = $(this);
            $button.prop('disabled', true).text(swsib_subscription.processing);
            showLoader();
            
            $.ajax({
                url: swsib_subscription.ajaxurl,
                type: 'POST',
                data: {
                    action: ajaxAction,
                    nonce: swsib_subscription.nonce,
                    subscription_ids: selectedIds
                },
                success: function(response) {
                    hideLoader();
                    
                    if (response.success) {
                        showMessage(response.data && response.data.message ? response.data.message : swsib_subscription.success_bulk_action, 'success');
                        // Reload the page to reflect changes
                        reloadPage();
                    } else {
                        showMessage(response.data && response.data.message ? response.data.message : swsib_subscription.error_general, 'error');
                        $button.prop('disabled', false).text('Apply');
                    }
                },
                error: function() {
                    hideLoader();
                    showMessage(swsib_subscription.error_general, 'error');
                    $button.prop('disabled', false).text('Apply');
                }
            });
        }
    });
    
    // Create subscription page button
    $('#create_subscriptions_page').on('click', function() {
        var $button = $(this);
        $button.prop('disabled', true).text('Creating...');
        showLoader();
        
        $.ajax({
            url: swsib_subscription.ajaxurl,
            type: 'POST',
            data: {
                action: 'swsib_create_subscriptions_page',
                nonce: swsib_subscription.nonce
            },
            success: function(response) {
                hideLoader();
                
                if (response.success) {
                    // Show success message and update the UI
                    $('#subscriptions_page_creation_result').html(
                        '<div class="swsib-notice success"><p>' + response.data.message + '</p></div>'
                    );
                    
                    // Update the UI with the new page URL and link
                    $button.closest('.swsib-notice').replaceWith(
                        '<input type="text" value="' + response.data.page_url + '" class="regular-text" readonly />' +
                        '<p class="swsib-field-note">This is the URL for your Manage Subscriptions page.</p>' +
                        '<a href="' + response.data.page_url + '" target="_blank" class="button">' +
                        'View Subscriptions Page' +
                        '</a>'
                    );
                } else {
                    $('#subscriptions_page_creation_result').html(
                        '<div class="swsib-notice error"><p>' + response.data.message + '</p></div>'
                    );
                    $button.prop('disabled', false).text('Create Page');
                }
            },
            error: function() {
                hideLoader();
                $('#subscriptions_page_creation_result').html(
                    '<div class="swsib-notice error"><p>An error occurred. Please try again.</p></div>'
                );
                $button.prop('disabled', false).text('Create Page');
            }
        });
    });
    
    /**
     * Update the count display for a specific status in the dashboard cards
     */
    function updateStatusCount(status, change) {
        var $card = $('.swsib-card[data-status="' + status + '"]');
        if ($card.length) {
            var $count = $card.find('.swsib-card-count');
            var currentCount = parseInt($count.text(), 10);
            var newCount = currentCount + change;
            
            $count.text(newCount);
            
            // Update empty class for styling
            if (newCount === 0) {
                $card.addClass('empty');
            } else if (currentCount === 0 && newCount > 0) {
                $card.removeClass('empty');
            }
        }
    }
    
    // Initialize bulk action availability on page load
    updateBulkActionAvailability();
});