/**
 * SwiftSpeed Siberian Integration - Advanced Auto Login
 */
(function($) {
    'use strict';
    
    // Function to display save reminder
    function displaySaveReminder() {
        $('#save-reminder').remove();
        var $reminder = $(
            '<div id="save-reminder" class="swsib-notice warning" ' +
            'style="margin-top:15px; margin-bottom:15px;">' +
            '<p><strong>Important:</strong> Remember to save your changes for them to take effect on the frontend!</p>' +
            '</div>'
        );
        $('.swsib-buttons-list').after($reminder);
        $('html, body').animate({
            scrollTop: $reminder.offset().top - 100
        }, 300);
    }
    
    // Generate role options HTML
    function generateRoleOptions(selectedRoleId) {
        var options = '';
        if ($('#new_button_role').is('select')) {
            $('#new_button_role option').each(function() {
                var roleId = $(this).val(),
                    roleText = $(this).text(),
                    sel = roleId == selectedRoleId ? ' selected' : '';
                options += '<option value="' + roleId + '"' + sel + '>' + roleText + '</option>';
            });
        } else {
            options = '<option value="' + selectedRoleId + '">Role ID: ' + selectedRoleId + '</option>';
        }
        return options;
    }
    
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
            var $btn = $(this),
                text = $('#new_button_text').val().trim(),
                role_id = $('#new_button_role').val(),
                color = $('#new_button_color').val(),
                text_color = $('#new_button_text_color').val(),
                sync_existing_role = $('#new_button_sync_existing_role').is(':checked') ? 1 : 0;
            
            if (!text) {
                alert('Please enter button text');
                return $('#new_button_text').focus();
            }
            
            // Show loading state
            $btn.prop('disabled', true).text('Adding...');
            
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
                    if (!response.success) {
                        alert('Error adding button: ' + (response.data && response.data.message ? response.data.message : 'Unknown error'));
                        return;
                    }
                    
                    var button = response.data.button,
                        id = response.data.button_id,
                        role_label = 'Role ID: ' + button.role_id;
                    
                    var html = ''
                        + '<div class="swsib-button-item" data-id="' + id + '">'
                        +   '<input type="hidden" name="swsib_options[advanced_autologin][buttons][' + id + '][text]" value="' + button.text + '" class="button-text-input" />'
                        +   '<input type="hidden" name="swsib_options[advanced_autologin][buttons][' + id + '][role_id]" value="' + button.role_id + '" class="button-role-input" />'
                        +   '<input type="hidden" name="swsib_options[advanced_autologin][buttons][' + id + '][color]" value="' + button.color + '" />'
                        +   '<input type="hidden" name="swsib_options[advanced_autologin][buttons][' + id + '][text_color]" value="' + button.text_color + '" />'
                        +   '<input type="hidden" name="swsib_options[advanced_autologin][buttons][' + id + '][sync_existing_role]" value="' + (button.sync_existing_role ? 1 : 0) + '" class="button-sync-role-input" />'
                        +   '<div class="button-info">'
                        +     '<div class="button-name">' + button.text + '</div>'
                        +     '<div class="button-role">' + role_label + '</div>'
                        +     '<div class="button-sync-role">' + (button.sync_existing_role ? '<span class="sync-enabled">Role Sync: Enabled</span>' : '') + '</div>'
                        +     '<div class="button-shortcode">'
                        +       '<code>[swsib_advanced_login id="' + id + '"]</code>'
                        +       '<button type="button" class="copy-shortcode" data-shortcode=\'[swsib_advanced_login id="' + id + '"]\'>'
                        +         '<span class="dashicons dashicons-clipboard"></span>'
                        +       '</button>'
                        +     '</div>'
                        +   '</div>'
                        +   '<div class="button-actions">'
                        +     '<button type="button" class="edit-button" data-id="' + id + '"><span class="dashicons dashicons-edit"></span></button>'
                        +     '<button type="button" class="delete-button" data-id="' + id + '"><span class="dashicons dashicons-trash"></span></button>'
                        +   '</div>'
                        +   '<div class="button-edit-form" style="display:none;">'
                        +     '<div class="edit-field"><label>Button Text:</label><input type="text" class="edit-button-text" value="' + button.text + '" /></div>'
                        +     '<div class="edit-field"><label>Role ID:</label><select class="edit-button-role">' + generateRoleOptions(button.role_id) + '</select></div>'
                        +     '<div class="edit-field switch-field"><label>Sync Existing User Role:</label><div class="toggle-container">'
                        +       '<label class="switch"><input type="checkbox" class="edit-button-sync-role" ' + (button.sync_existing_role ? 'checked' : '') + ' /><span class="slider round"></span></label>'
                        +       '<p class="swsib-field-note swsib-warning-note"><strong>Warning:</strong> This will update existing Siberian user roles to match this button\'s role when they login.</p>'
                        +     '</div></div>'
                        +     '<div class="edit-actions"><button type="button" class="button button-primary save-button-edit">Save</button> '
                        +       '<button type="button" class="button cancel-button-edit">Cancel</button></div>'
                        +   '</div>'
                        + '</div>';
                    
                    $('.swsib-no-buttons').remove();
                    $('#advanced-buttons-list').append(html);
                    displaySaveReminder();
                    
                    // Reset form
                    $('#new_button_text').val('');
                    if ($('#new_button_color').data('wpColorPicker')) {
                        $('#new_button_color').wpColorPicker('color', '#3a4b79');
                    } else {
                        $('#new_button_color').val('#3a4b79');
                    }
                    if ($('#new_button_text_color').data('wpColorPicker')) {
                        $('#new_button_text_color').wpColorPicker('color', '#ffffff');
                    } else {
                        $('#new_button_text_color').val('#ffffff');
                    }
                    $('#new_button_sync_existing_role').prop('checked', false);
                },
                error: function() {
                    alert('Error adding button. Please try again.');
                },
                complete: function() {
                    var $btn = $('#add_advanced_button'),
                        defaultText = swsib_adv_autologin_vars.add_button_text || 'Create Shortcode';
                    $btn.prop('disabled', false).text(defaultText);
                }
            });
        });
        
        // Edit button click handler
        $(document).on('click', '.edit-button', function() {
            var $item = $(this).closest('.swsib-button-item');
            $item.addClass('editing').find('.button-edit-form').slideDown();
        });
        
        // Cancel edit
        $(document).on('click', '.cancel-button-edit', function() {
            var $item = $(this).closest('.swsib-button-item');
            $item.removeClass('editing').find('.button-edit-form').slideUp();
        });
        
        // Save edit
        $(document).on('click', '.save-button-edit', function() {
            var $item = $(this).closest('.swsib-button-item'),
                id = $item.data('id'),
                newText = $item.find('.edit-button-text').val().trim(),
                newRole = $item.find('.edit-button-role').val(),
                newSync = $item.find('.edit-button-sync-role').is(':checked') ? 1 : 0;
            
            if (!newText) {
                alert('Button text cannot be empty');
                return;
            }
            
            // Update inputs
            $item.find('.button-text-input').val(newText);
            $item.find('.button-role-input').val(newRole);
            $item.find('.button-sync-role-input').val(newSync);
            
            // Update display
            $item.find('.button-name').text(newText);
            $item.find('.button-role').text($item.find('.edit-button-role option:selected').text());
            var $syncDiv = $item.find('.button-sync-role');
            $syncDiv.html(newSync ? '<span class="sync-enabled">Role Sync: Enabled</span>' : '');
            
            $item.removeClass('editing').find('.button-edit-form').slideUp();
            displaySaveReminder();
        });
        
        // Delete button
        $(document).on('click', '.delete-button', function() {
            if (!confirm(swsib_adv_autologin_vars.confirm_delete || 'Are you sure you want to delete this button?')) {
                return;
            }
            var $item = $(this).closest('.swsib-button-item'),
                id = $item.data('id');
            
            $(this).prop('disabled', true);
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'swsib_delete_advanced_login_button',
                    nonce: swsib_adv_autologin_vars.nonce,
                    button_id: id
                },
                success: function(resp) {
                    if (resp.success) {
                        $item.slideUp(300, function() {
                            $(this).remove();
                            if ($('.swsib-button-item').length === 0) {
                                $('#advanced-buttons-list').html(
                                    '<div class="swsib-no-buttons">' +
                                    '<p>No buttons created yet. Use the form above to create your first button.</p>' +
                                    '</div>'
                                );
                            }
                            displaySaveReminder();
                        });
                    } else {
                        alert('Error deleting button: ' + (resp.data && resp.data.message ? resp.data.message : 'Unknown error'));
                        $item.find('.delete-button').prop('disabled', false);
                    }
                },
                error: function() {
                    alert('Error deleting button. Please try again.');
                    $item.find('.delete-button').prop('disabled', false);
                }
            });
        });
        
        // Copy shortcode
        $(document).on('click', '.copy-shortcode', function() {
            var sc = $(this).data('shortcode'),
                $temp = $('<textarea>');
            $('body').append($temp);
            $temp.val(sc).select();
            document.execCommand('copy');
            $temp.remove();
            
            var $icon = $(this).find('.dashicons');
            $icon.removeClass('dashicons-clipboard').addClass('dashicons-yes');
            setTimeout(function() {
                $icon.removeClass('dashicons-yes').addClass('dashicons-clipboard');
            }, 1500);
        });
    });
})(jQuery);
