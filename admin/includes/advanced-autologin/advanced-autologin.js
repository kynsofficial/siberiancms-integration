/**
 * SwiftSpeed Siberian Integration - Advanced Auto Login
 */
(function($) {
    'use strict';
    
    // Function to display save reminder
    function displaySaveReminder() {
        // Remove any existing reminder
        $('#save-reminder').remove();
        
        // Create new reminder
        var $reminder = $('<div id="save-reminder" class="swsib-notice warning" style="margin-top: 15px; margin-bottom: 15px;">' +
            '<p><strong>Important:</strong> Remember to save your changes for them to take effect on the frontend!</p>' +
            '</div>');
        
        // Add to buttons list container
        $('.swsib-buttons-list').after($reminder);
        
        // Scroll to reminder
        $('html, body').animate({
            scrollTop: $('#save-reminder').offset().top - 100
        }, 300);
    }
    
    // Initialize when document is ready
    $(document).ready(function() {
        console.log('Advanced Auto Login JS loaded');
        
        // Initialize color pickers
        if ($.fn.wpColorPicker) {
            $('.swsib-color-picker').wpColorPicker();
        }
        
        // Toggle Advanced Auto Login settings visibility
        $('#swsib_options_advanced_autologin_enabled').on('change', function() {
            if ($(this).is(':checked')) {
                $('#advanced-autologin-settings').slideDown();
            } else {
                $('#advanced-autologin-settings').slideUp();
            }
        });
        
        // Add new button
        $('#add_advanced_button').on('click', function() {
            var text = $('#new_button_text').val();
            var role_id = $('#new_button_role').val();
            var color = $('#new_button_color').val();
            var text_color = $('#new_button_text_color').val();
            var sync_existing_role = $('#new_button_sync_existing_role').is(':checked') ? 1 : 0;
            
            if (!text) {
                alert('Please enter button text');
                $('#new_button_text').focus();
                return;
            }
            
            // Show loading state
            $(this).prop('disabled', true).text('Adding...');
            
            // AJAX request to add button
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'swsib_add_advanced_login_button',
                    nonce: swsib_adv_autologin_vars.nonce,
                    text: text,
                    role_id: role_id,
                    color: color,
                    text_color: text_color,
                    sync_existing_role: sync_existing_role
                },
                success: function(response) {
                    if (response.success) {
                        // Add new button to list
                        var button = response.data.button;
                        var button_id = response.data.button_id;
                        
                        // Get role label
                        var role_label = 'Role ID: ' + button.role_id;
                        
                        // Create button item HTML - Removed button-preview div
                        var buttonHtml = `
                            <div class="swsib-button-item" data-id="${button_id}">
                                <input type="hidden" name="swsib_options[advanced_autologin][buttons][${button_id}][text]" value="${button.text}" class="button-text-input" />
                                <input type="hidden" name="swsib_options[advanced_autologin][buttons][${button_id}][role_id]" value="${button.role_id}" class="button-role-input" />
                                <input type="hidden" name="swsib_options[advanced_autologin][buttons][${button_id}][color]" value="${button.color}" />
                                <input type="hidden" name="swsib_options[advanced_autologin][buttons][${button_id}][text_color]" value="${button.text_color}" />
                                <input type="hidden" name="swsib_options[advanced_autologin][buttons][${button_id}][sync_existing_role]" value="${button.sync_existing_role ? 1 : 0}" class="button-sync-role-input" />
                                
                                <div class="button-info">
                                    <div class="button-name">${button.text}</div>
                                    <div class="button-role">${role_label}</div>
                                    <div class="button-sync-role">
                                        ${button.sync_existing_role ? '<span class="sync-enabled">Role Sync: Enabled</span>' : ''}
                                    </div>
                                    <div class="button-shortcode">
                                        <code>[swsib_advanced_login id="${button_id}"]</code>
                                        <button type="button" class="copy-shortcode" data-shortcode='[swsib_advanced_login id="${button_id}"]'>
                                            <span class="dashicons dashicons-clipboard"></span>
                                        </button>
                                    </div>
                                </div>
                                
                                <div class="button-actions">
                                    <button type="button" class="edit-button" data-id="${button_id}">
                                        <span class="dashicons dashicons-edit"></span>
                                    </button>
                                    <button type="button" class="delete-button" data-id="${button_id}">
                                        <span class="dashicons dashicons-trash"></span>
                                    </button>
                                </div>
                                
                                <div class="button-edit-form" style="display:none;">
                                    <div class="edit-field">
                                        <label>Button Text:</label>
                                        <input type="text" class="edit-button-text" value="${button.text}" />
                                    </div>
                                    <div class="edit-field">
                                        <label>Role ID:</label>
                                        <select class="edit-button-role">
                                            ${generateRoleOptions(button.role_id)}
                                        </select>
                                    </div>
                                    <div class="edit-field switch-field">
                                        <label>Sync Existing User Role:</label>
                                        <div class="toggle-container">
                                            <label class="switch">
                                                <input type="checkbox" class="edit-button-sync-role" value="1" ${button.sync_existing_role ? 'checked' : ''} />
                                                <span class="slider round"></span>
                                            </label>
                                            <p class="swsib-field-note swsib-warning-note">
                                                <strong>Warning:</strong> 
                                                This will update existing Siberian user roles to match this button's role when they login. Only enable if you know exactly what you're doing.
                                            </p>
                                        </div>
                                    </div>
                                    <div class="edit-actions">
                                        <button type="button" class="button button-primary save-button-edit">Save</button>
                                        <button type="button" class="button cancel-button-edit">Cancel</button>
                                    </div>
                                </div>
                            </div>
                        `;
                        
                        // Remove no buttons message if present
                        $('.swsib-no-buttons').remove();
                        
                        // Add to buttons list
                        $('#advanced-buttons-list').append(buttonHtml);
                        
                        // Display save reminder
                        displaySaveReminder();
                        
                        // Reset form
                        $('#new_button_text').val('');
                        $('#new_button_color').wpColorPicker('color', '#3a4b79');
                        $('#new_button_text_color').wpColorPicker('color', '#ffffff');
                        $('#new_button_sync_existing_role').prop('checked', false);
                    } else {
                        // Show error
                        alert('Error adding button: ' + (response.data ? response.data.message : 'Unknown error'));
                    }
                },
                error: function() {
                    // Show error
                    alert('Error adding button. Please try again.');
                },
                complete: function() {
                    // Reset button state
                    $('#add_advanced_button').prop('disabled', false).text(swsib_adv_autologin_vars.add_button_text || 'Add Button');
                }
            });
        });
        
        // Function to generate role options HTML
        function generateRoleOptions(selectedRoleId) {
            var options = '';
            
            // First check if we have a select with roles already in the DOM to copy from
            if ($('#new_button_role').length && $('#new_button_role').is('select')) {
                // Clone options from existing select
                $('#new_button_role option').each(function() {
                    var roleId = $(this).val();
                    var roleText = $(this).text();
                    var selected = roleId == selectedRoleId ? 'selected' : '';
                    options += `<option value="${roleId}" ${selected}>${roleText}</option>`;
                });
            } else {
                // Fallback - just add the current role ID
                options = `<option value="${selectedRoleId}">Role ID: ${selectedRoleId}</option>`;
            }
            
            return options;
        }
        
        // Edit button click handler
        $(document).on('click', '.edit-button', function() {
            var $buttonItem = $(this).closest('.swsib-button-item');
            var $editForm = $buttonItem.find('.button-edit-form');
            
            // Toggle edit form
            $editForm.slideDown();
            $buttonItem.addClass('editing');
        });
        
        // Cancel edit button click handler
        $(document).on('click', '.cancel-button-edit', function() {
            var $buttonItem = $(this).closest('.swsib-button-item');
            var $editForm = $buttonItem.find('.button-edit-form');
            
            // Hide edit form
            $editForm.slideUp();
            $buttonItem.removeClass('editing');
        });
        
        // Save edit button click handler
        $(document).on('click', '.save-button-edit', function() {
            var $buttonItem = $(this).closest('.swsib-button-item');
            var buttonId = $buttonItem.data('id');
            
            // Get edited values
            var newText = $buttonItem.find('.edit-button-text').val();
            var newRoleId = $buttonItem.find('.edit-button-role').val();
            var newSyncRole = $buttonItem.find('.edit-button-sync-role').is(':checked') ? 1 : 0;
            
            // Basic validation
            if (!newText) {
                alert('Button text cannot be empty');
                return;
            }
            
            // Update hidden inputs
            $buttonItem.find('.button-text-input').val(newText);
            $buttonItem.find('.button-role-input').val(newRoleId);
            $buttonItem.find('.button-sync-role-input').val(newSyncRole);
            
            // Update displayed text
            $buttonItem.find('.button-name').text(newText);
            
            // Find the selected option text for the role to update the display
            var newRoleText = $buttonItem.find('.edit-button-role option:selected').text();
            $buttonItem.find('.button-role').text(newRoleText);
            
            // Update sync role indicator
            var $syncRoleDiv = $buttonItem.find('.button-sync-role');
            if (newSyncRole) {
                if ($syncRoleDiv.find('.sync-enabled').length === 0) {
                    $syncRoleDiv.html('<span class="sync-enabled">Role Sync: Enabled</span>');
                }
            } else {
                $syncRoleDiv.empty();
            }
            
            // Hide edit form
            $buttonItem.find('.button-edit-form').slideUp();
            $buttonItem.removeClass('editing');
            
            // Display save reminder
            displaySaveReminder();
        });
        
        // Delete button
        $(document).on('click', '.delete-button', function() {
            if (!confirm(swsib_adv_autologin_vars.confirm_delete || 'Are you sure you want to delete this button?')) {
                return;
            }
            
            var button_id = $(this).data('id');
            var $button_item = $(this).closest('.swsib-button-item');
            
            // Show loading state
            $(this).prop('disabled', true);
            
            // AJAX request to delete button
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'swsib_delete_advanced_login_button',
                    nonce: swsib_adv_autologin_vars.nonce,
                    button_id: button_id
                },
                success: function(response) {
                    if (response.success) {
                        // Remove button from list
                        $button_item.slideUp(300, function() {
                            $(this).remove();
                            
                            // If no buttons left, show message
                            if ($('.swsib-button-item').length === 0) {
                                $('#advanced-buttons-list').html(
                                    '<div class="swsib-no-buttons">' +
                                    '<p>No buttons created yet. Use the form above to create your first button.</p>' +
                                    '</div>'
                                );
                            }
                            
                            // Display save reminder
                            displaySaveReminder();
                        });
                    } else {
                        // Show error
                        alert('Error deleting button: ' + (response.data ? response.data.message : 'Unknown error'));
                        // Reset button state
                        $button_item.find('.delete-button').prop('disabled', false);
                    }
                },
                error: function() {
                    // Show error
                    alert('Error deleting button. Please try again.');
                    // Reset button state
                    $button_item.find('.delete-button').prop('disabled', false);
                }
            });
        });
        
        // Copy shortcode
        $(document).on('click', '.copy-shortcode', function() {
            var shortcode = $(this).data('shortcode');
            
            // Create temporary textarea to copy from
            var $temp = $('<textarea>');
            $('body').append($temp);
            $temp.val(shortcode).select();
            document.execCommand('copy');
            $temp.remove();
            
            // Show "Copied" message briefly
            var $button = $(this);
            var $icon = $button.find('.dashicons');
            $icon.removeClass('dashicons-clipboard').addClass('dashicons-yes');
            
            setTimeout(function() {
                $icon.removeClass('dashicons-yes').addClass('dashicons-clipboard');
            }, 1500);
        });
    });
})(jQuery);