/**
 * WooCommerce Integration Scripts
 */
(function($) {
    'use strict';
    
    // Initialize when document is ready
    $(document).ready(function() {
        console.log('WooCommerce integration scripts loaded');
        
        // Initialize test connection button
        initTestConnection();
        
        // Initialize sortable role priority list
        initRolePrioritySort();
        
        // Initialize mapping functionality
        initMappingFunctionality();
        
        // Initialize allowed origins functionality
        initAllowedOriginsFunctionality();
        
        // Initialize product access control settings
        initProductAccessSettings();

        // Fix non-unique ID warnings by adding unique IDs to duplicate elements
        fixDuplicateIds();
        
        // Add event listener for form submission
        $('.swsib-settings-form').on('submit', function() {
            console.log('WooCommerce settings form submitted');
            
            // Store role priorities before form submission
            updateRolePriorityInputs();
            
            // Return true to allow form submission
            return true;
        });
    });

    /**
     * Fix duplicate IDs in the DOM
     */
    function fixDuplicateIds() {
        // Find all inputs with id="_wpnonce" and give them unique IDs
        $('input[id="_wpnonce"]').each(function(index) {
            if (index > 0) {
                $(this).attr('id', '_wpnonce_' + index);
            }
        });

        // Find all inputs with duplicate tab IDs
        $('input[id$="-tab-id-field"]').each(function(index) {
            var originalId = $(this).attr('id');
            if (originalId) {
                $(this).attr('id', originalId + '_' + index);
            }
        });
    }
    
   function initTestConnection() {
    $('#test_woocommerce_db_connection').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        // Save original text if not already stored.
        if (!$button.data('originalText')) {
            $button.data('originalText', $button.text());
        }
        var originalText = $button.data('originalText');
        
        var $result = $('#woocommerce_db_test_result');
        $button.text('Testing...').prop('disabled', true);
        $result.stop(true, true).hide().html('');
        
        $.ajax({
            url: swsib_woocommerce.ajaxurl,
            type: 'POST',
            data: {
                action: 'swsib_test_woocommerce_db',
                nonce: swsib_woocommerce.nonce
            },
            success: function(response) {
                var noticeClass = response.success ? 'success' : 'error';
                var message = response.data && response.data.message ? response.data.message :
                              (response.success ? 'Connection successful!' : 'Connection failed');
                
                $result.html('<div class="swsib-notice ' + noticeClass + '"><p>' + message + '</p></div>')
                       .show().delay(3000).fadeOut(100, function() {
                           $button.text(originalText).prop('disabled', false);
                       });
                
                $('html, body').animate({
                    scrollTop: $result.offset().top - 100
                }, 300);
            },
            error: function() {
                $result.html('<div class="swsib-notice error"><p>Error occurred during test. Please try again.</p></div>')
                       .show().delay(1000).fadeOut(100, function() {
                           $button.text(originalText).prop('disabled', false);
                       });
            },
            complete: function() {
                // Fallback in case the fadeOut callback does not fire.
                setTimeout(function() {
                    $button.text(originalText).prop('disabled', false);
                }, 5000);
            }
        });
    });
}

    
    /**
     * Initialize sortable role priority list
     */
    function initRolePrioritySort() {
        if ($.fn.sortable) {
            $("#role_priority_list").sortable({
                placeholder: "swsib-sortable-placeholder",
                update: function(event, ui) {
                    // Reindex the hidden input values after sorting
                    updateRolePriorityInputs();
                }
            });
        }
    }
    
    /**
     * Update hidden inputs for role priorities
     */
    function updateRolePriorityInputs() {
        // Remove existing hidden inputs
        $('input[name^="swsib_options[woocommerce][role_priorities]"]').remove();
        
        // Add new inputs based on current order
        $('#role_priority_list li').each(function(index) {
            var roleId = $(this).data('role-id');
            $(this).append(
                '<input type="hidden" name="swsib_options[woocommerce][role_priorities][]" value="' + roleId + '">'
            );
        });
    }
    
    /**
     * Initialize mapping functionality
     */
    function initMappingFunctionality() {
        // Add mapping button click handler
        $('#add_mapping_button').on('click', function() {
            var $button = $(this);
            var originalText = $button.text();
            var $message = $('#mapping_message');
            
            // Get selected values
            var siberianPlanId = $('#siberian_plan_id').val();
            var wooProductId = $('#woo_product_id').val();
            var roleId = $('#role_id').val();
            
            // Validate selections
            if (!siberianPlanId || !wooProductId || !roleId) {
                $message.removeClass('success').addClass('error')
                    .text('Please select all required fields').show();
                setTimeout(function() {
                    $message.fadeOut();
                }, 3000);
                return;
            }
            
            // Change button state
            $button.text('Adding...').prop('disabled', true);
            $message.removeClass('success error').hide();
            
            // Send AJAX request
            $.ajax({
                url: swsib_woocommerce.ajaxurl,
                type: 'POST',
                data: {
                    action: 'swsib_woocommerce_update_mapping',
                    nonce: swsib_woocommerce.nonce,
                    siberian_plan_id: siberianPlanId,
                    woo_product_id: wooProductId,
                    role_id: roleId
                },
                success: function(response) {
                    if (response.success) {
                        // Show success message
                        $message.removeClass('error').addClass('success')
                            .text(response.data.message).show();
                        
                        // Add new row to mappings table
                        addMappingToTable(response.data.mapping);
                        
                        // Disable the selected options
                        $('#siberian_plan_id option[value="' + siberianPlanId + '"]').prop('disabled', true);
                        $('#woo_product_id option[value="' + wooProductId + '"]').prop('disabled', true);
                        
                        // Reset selections
                        $('#siberian_plan_id, #woo_product_id, #role_id').val('');
                    } else {
                        // Show error message
                        $message.removeClass('success').addClass('error')
                            .text(response.data.message).show();
                    }
                    
                    // Hide message after delay
                    setTimeout(function() {
                        $message.fadeOut();
                    }, 3000);
                },
                error: function() {
                    $message.removeClass('success').addClass('error')
                        .text('An error occurred. Please try again.').show();
                        
                    setTimeout(function() {
                        $message.fadeOut();
                    }, 3000);
                },
                complete: function() {
                    // Restore button state
                    $button.text(originalText).prop('disabled', false);
                }
            });
        });
        
        // Delete mapping click handler
        $(document).on('click', '.delete-mapping', function() {
            // Skip if this is also a delete-origin button (which has its own handler)
            if ($(this).hasClass('delete-origin')) {
                return;
            }
            
            var $button = $(this);
            var mappingId = $button.data('mapping-id');
            var $row = $button.closest('tr');
            
            if (!confirm('Are you sure you want to delete this mapping?')) {
                return;
            }
            
            // Disable button
            $button.prop('disabled', true);
            
            // Send AJAX request
            $.ajax({
                url: swsib_woocommerce.ajaxurl,
                type: 'POST',
                data: {
                    action: 'swsib_woocommerce_delete_mapping',
                    nonce: swsib_woocommerce.nonce,
                    mapping_id: mappingId
                },
                success: function(response) {
                    if (response.success) {
                        // Remove row with animation
                        $row.fadeOut(300, function() {
                            $(this).remove();
                            
                            // If no mappings left, show empty message
                            if ($('#mappings_tbody tr').length === 0) {
                                $('#mappings_tbody').html(
                                    '<tr class="no-mappings-row">' +
                                    '<td colspan="4">No mappings found. Add your first mapping above.</td>' +
                                    '</tr>'
                                );
                            }
                        });
                        
                        // Re-enable the options in selects
                        var siberianPlanId = $row.find('.siberian-plan-id').data('plan-id');
                        var wooProductId = $row.find('.woo-product-id').data('product-id');
                        
                        if (siberianPlanId) {
                            $('#siberian_plan_id option[value="' + siberianPlanId + '"]').prop('disabled', false);
                        }
                        
                        if (wooProductId) {
                            $('#woo_product_id option[value="' + wooProductId + '"]').prop('disabled', false);
                        }
                    } else {
                        alert(response.data.message);
                        $button.prop('disabled', false);
                    }
                },
                error: function() {
                    alert('An error occurred. Please try again.');
                    $button.prop('disabled', false);
                }
            });
        });
    }
    
    /**
     * Initialize allowed origins functionality
     */
    function initAllowedOriginsFunctionality() {
        // Add origin button click handler
        $('#add_allowed_origin').on('click', function() {
            var $button = $(this);
            var originalText = $button.text();
            var $input = $('#swsib_options_woocommerce_allowed_origin_url');
            var originUrl = $input.val().trim();
            
            // Validate URL
            if (!originUrl || !isValidUrl(originUrl)) {
                alert('Please enter a valid URL (e.g., https://example.com)');
                $input.focus();
                return;
            }
            
            // Change button state
            $button.text('Adding...').prop('disabled', true);
            
            // Send AJAX request
            $.ajax({
                url: swsib_woocommerce.ajaxurl,
                type: 'POST',
                data: {
                    action: 'swsib_woocommerce_add_allowed_origin',
                    nonce: swsib_woocommerce.nonce,
                    origin_url: originUrl
                },
                success: function(response) {
                    if (response.success) {
                        // Add new row to origins table
                        addOriginToTable(response.data.origin);
                        
                        // Clear input
                        $input.val('');
                    } else {
                        alert(response.data.message);
                    }
                },
                error: function() {
                    alert('An error occurred. Please try again.');
                },
                complete: function() {
                    // Restore button state
                    $button.text(originalText).prop('disabled', false);
                }
            });
        });
        
        // Delete origin click handler - revised to prevent double-binding
        $(document).on('click', '.delete-origin', function(e) {
            // Prevent event bubbling
            e.stopPropagation();
            
            var $button = $(this);
            var originId = $button.data('origin-id');
            
            // Check if we have an ID
            if (!originId) {
                console.error('Delete origin button missing origin-id attribute');
                return;
            }
            
            var $row = $button.closest('tr');
            
            if (!confirm('Are you sure you want to delete this origin?')) {
                return;
            }
            
            // Disable button to prevent double clicks
            $button.prop('disabled', true);
            
            // Send AJAX request
            $.ajax({
                url: swsib_woocommerce.ajaxurl,
                type: 'POST',
                data: {
                    action: 'swsib_woocommerce_delete_allowed_origin',
                    nonce: swsib_woocommerce.nonce,
                    origin_id: originId
                },
                success: function(response) {
                    if (response.success) {
                        // Remove row with animation
                        $row.fadeOut(300, function() {
                            $(this).remove();
                            
                            // If no origins left, show empty message
                            if ($('#allowed_origins_tbody tr').length === 0) {
                                $('#allowed_origins_tbody').html(
                                    '<tr class="no-origins-row">' +
                                    '<td colspan="2">No origins added yet. Add your first origin above.</td>' +
                                    '</tr>'
                                );
                            }
                        });
                    } else {
                        alert(response.data.message);
                        $button.prop('disabled', false);
                    }
                },
                error: function() {
                    alert('An error occurred. Please try again.');
                    $button.prop('disabled', false);
                }
            });
        });
        
        // Allow adding origin by pressing Enter in the input field
        $('#swsib_options_woocommerce_allowed_origin_url').keypress(function(e) {
            if (e.which === 13) {
                e.preventDefault();
                $('#add_allowed_origin').click();
            }
        });
    }
    
    /**
     * Check if a string is a valid URL
     */
    function isValidUrl(string) {
        try {
            new URL(string);
            return true;
        } catch (_) {
            return false;
        }
    }
    
    /**
     * Add a new allowed origin to the table
     */
    function addOriginToTable(origin) {
        // Remove empty message if present
        $('.no-origins-row').remove();
        
        // Create new row with only delete-origin class (not delete-mapping)
        var newRow = `
            <tr data-origin-id="${origin.id}" class="fade-in">
                <td>
                    <a href="${origin.url}" target="_blank" rel="noopener noreferrer">${origin.url}</a>
                </td>
                <td>
                    <button type="button" class="button button-small delete-origin" data-origin-id="${origin.id}">
                        Delete
                    </button>
                </td>
            </tr>
        `;
        
        // Add to table
        $('#allowed_origins_tbody').append(newRow);
    }
    
    /**
     * Add mapping to table
     */
    function addMappingToTable(mapping) {
        // Remove empty message if present
        $('.no-mappings-row').remove();
        
        // Get the text for the Siberian plan
        var planText = $('#siberian_plan_id option[value="' + mapping.siberian_plan_id + '"]').text();
        
        // Get the text for the WooCommerce product
        var productText = $('#woo_product_id option[value="' + mapping.woo_product_id + '"]').text();
        
        // Get the text for the role
        var roleText = $('#role_id option[value="' + mapping.role_id + '"]').text();
        
        // Create new row
        var newRow = `
            <tr data-mapping-id="${mapping.id}" class="fade-in">
                <td class="siberian-plan-id" data-plan-id="${mapping.siberian_plan_id}">${planText}</td>
                <td class="woo-product-id" data-product-id="${mapping.woo_product_id}">${productText}</td>
                <td>${roleText}</td>
                <td>
                    <button type="button" class="button button-small delete-mapping" data-mapping-id="${mapping.id}">
                        Delete
                    </button>
                </td>
            </tr>
        `;
        
        // Add to table
        $('#mappings_tbody').append(newRow);
    }
    
    /**
     * Initialize product access control settings
     */
    function initProductAccessSettings() {
        // Toggle access control settings visibility
        $('#swsib_options_woocommerce_restrict_product_access').change(function() {
            if ($(this).is(':checked')) {
                $('#product_access_settings').slideDown();
            } else {
                $('#product_access_settings').slideUp();
            }
        });
        
        // Toggle button settings visibility
        $('#swsib_options_woocommerce_use_autologin_button').change(function() {
            if ($(this).is(':checked')) {
                $('#autologin_button_settings').slideDown();
                $('#custom_button_settings').slideUp();
            } else {
                $('#autologin_button_settings').slideUp();
                $('#custom_button_settings').slideDown();
            }
        });
        
        // Update auto login button preview text when custom text changes
        $('#swsib_options_woocommerce_autologin_custom_text').on('input', function() {
            var customText = $(this).val();
            var defaultText = $('.swsib-button').data('default-text') || 'App Dashboard';
            $('.swsib-button').text(customText || defaultText);
        });
    }
    
})(jQuery);