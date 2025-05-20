(function($) {
    'use strict';
    
    $(document).ready(function() {
        // Debug paths on page load
        console.log("FTP Path:", $('#swsib_options_installation_path_ftp').val());
        console.log("SFTP Path:", $('#swsib_options_installation_path_sftp').val());
        console.log("Local Path:", $('#swsib_options_installation_path_local').val());
        
        // Store initial path values for each connection method
        var savedPaths = {
            'ftp': $('#swsib_options_installation_path_ftp').val(),
            'sftp': $('#swsib_options_installation_path_sftp').val(),
            'local': $('#swsib_options_installation_path_local').val()
        };

        // Ensure paths are not the placeholder value
        Object.keys(savedPaths).forEach(function(method) {
            if (!savedPaths[method] || savedPaths[method] === '/') {
                savedPaths[method] = '';
            }
        });
        
        // Custom modal implementation (since jQuery UI dialog isn't available)
        var $directoryBrowser = $('#directory_browser_dialog');
        
        function showDirectoryBrowser() {
            $directoryBrowser.fadeIn(200);
            $('body').addClass('modal-open');
        }
        
        function hideDirectoryBrowser() {
            $directoryBrowser.fadeOut(200);
            $('body').removeClass('modal-open');
        }
        
        // Add close button click handler
        $(document).on('click', '.directory-browser-close', function() {
            hideDirectoryBrowser();
        });
        
        // Add select button click handler
        $(document).on('click', '.directory-browser-select', function() {
            var selected_path = $('#current_path').text();
            var active_method = $('#swsib_options_installation_connection_method').val();
            
            // Update the input field and save the path value
            $('#swsib_options_installation_path_' + active_method).val(selected_path);
            savedPaths[active_method] = selected_path;
            
            hideDirectoryBrowser();
            
            // Show verify button after selecting a path
            $('#verify_siberian_installation').show();
        });
        
        // Handle tab clicks
        $('.swsib-section-tabs a').on('click', function(e) {
            e.preventDefault();
            var href = $(this).attr('href');
            var tabId = $(this).data('tab');
            
            // Update URL without page reload
            window.history.pushState({}, '', href);
            
            // Update active tab styling
            $('.swsib-section-tabs a').removeClass('active');
            $(this).addClass('active');
            
            // Show the correct content
            if (tabId === 'database') {
                $('#database-tab-content').show();
                $('#installation-tab-content').hide();
            } else {
                $('#database-tab-content').hide();
                $('#installation-tab-content').show();
            }
        });
        
        // Toggle DB Connect configuration visibility
        $('#swsib_options_db_connect_enabled').on('change', function() {
            if ($(this).is(':checked')) {
                $('#advanced-features-config').slideDown();
            } else {
                $('#advanced-features-config').slideUp();
            }
        });
        
        // Toggle Installation configuration visibility
        $('#swsib_options_installation_enabled').on('change', function() {
            if ($(this).is(':checked')) {
                $('#installation-config').slideDown();
            } else {
                $('#installation-config').slideUp();
            }
        });
        
        // Handle connection method change
        $('#swsib_options_installation_connection_method').on('change', function() {
            var method = $(this).val();
            
            // Hide all method-specific fields
            $('.connection-fields-container').hide();
            
            // Show the method-specific fields for the selected method
            $('.connection-fields-' + method).show();
            
            // Show/hide method-specific notes
            $('.method-note').hide();
            $('.method-note-' + method).show();
            
            // Hide verify button and reset result message
            $('#test_installation_result').hide();
            
            // IMPORTANT: Check if we have a saved path for this method
            var path = savedPaths[method] || '';
            
            // If there's a saved path, show the verify button
            if (path && path !== '/') {
                $('#verify_siberian_installation').show();
            } else {
                $('#verify_siberian_installation').hide();
            }
            
            // Update required attributes for the current connection method
            updateRequiredAttributes();
        });
        
        // Function to update required attributes based on the current method
        function updateRequiredAttributes() {
            var activeMethod = $('#swsib_options_installation_connection_method').val();
            var enabled = $('#swsib_options_installation_enabled').is(':checked');
            
            // First, remove 'required' from all fields
            $('[id^="swsib_options_installation_host_"]').prop('required', false);
            $('[id^="swsib_options_installation_username_"]').prop('required', false);
            $('[id^="swsib_options_installation_password_"]').prop('required', false);
            
            // Only add 'required' to active method fields if enabled
            if (enabled) {
                // FTP fields
                if (activeMethod === 'ftp') {
                    $('#swsib_options_installation_host_ftp').prop('required', true);
                    $('#swsib_options_installation_username_ftp').prop('required', true);
                    $('#swsib_options_installation_password_ftp').prop('required', true);
                }
                // SFTP fields
                else if (activeMethod === 'sftp') {
                    $('#swsib_options_installation_host_sftp').prop('required', true);
                    $('#swsib_options_installation_username_sftp').prop('required', true);
                    $('#swsib_options_installation_password_sftp').prop('required', true);
                }
            }
        }
        
        // Test DB Connection
        $('#test_db_connection').on('click', function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var originalText = $button.text();
            var $result = $('#test_connection_result');
            
            // Get form values
            var host = $('#swsib_options_db_connect_host').val();
            var database = $('#swsib_options_db_connect_database').val();
            var username = $('#swsib_options_db_connect_username').val();
            var password = $('#swsib_options_db_connect_password').val();
            var port = $('#swsib_options_db_connect_port').val() || '3306';
            
            // Validate required fields
            if (!host || !database || !username || !password) {
                $result.html(
                    '<div class="swsib-notice error"><p>' + swsib_af_vars.fill_required_fields + '</p></div>'
                ).show();
                return;
            }
            
            // Change button state: update text and disable it
            $button.text(swsib_af_vars.testing_text).prop('disabled', true);
            $result.removeClass('success error').hide().html('');
            
            $.ajax({
                url: swsib_af_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'swsib_test_db_connection',
                    nonce: swsib_af_vars.nonce,
                    host: host,
                    database: database,
                    username: username,
                    password: password,
                    port: port
                },
                success: function(response) {
                    var noticeClass = response.success ? 'success' : 'error';
                    var message = response.data ? response.data.message : (response.success ? 'Connection successful!' : 'Connection failed!');
                    
                    if (response.success) {
                        message += ' <strong>Remember to click Save Changes to store these settings.</strong>';
                    }
                    
                    $result.html(
                        '<div class="swsib-notice ' + noticeClass + '"><p>' + message + '</p></div>'
                    ).show();
                    
                    // Set a longer display time but always restore the button
                    setTimeout(function() {
                        $button.text(originalText).prop('disabled', false);
                    }, 2000);
                    
                    $('html, body').animate({
                        scrollTop: $result.offset().top - 100
                    }, 300);
                },
                error: function() {
                    $result.html(
                        '<div class="swsib-notice error"><p>' + swsib_af_vars.error_occurred + '</p></div>'
                    ).show();
                    
                    $button.text(originalText).prop('disabled', false);
                }
            });
        });
        
        // Test Installation Connection
        $('#test_installation_connection').on('click', function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var originalText = $button.text();
            var $result = $('#test_installation_result');
            
            // Get selected connection method
            var method = $('#swsib_options_installation_connection_method').val();
            
            // Get method-specific form values
            var host = '';
            var username = '';
            var password = '';
            var port = '';
            var path = '';
            
            if (method === 'ftp') {
                host = $('#swsib_options_installation_host_ftp').val();
                username = $('#swsib_options_installation_username_ftp').val();
                password = $('#swsib_options_installation_password_ftp').val();
                port = $('#swsib_options_installation_port_ftp').val() || '21';
                path = $('#swsib_options_installation_path_ftp').val() || '';
                
                // Validate required fields for FTP
                if (!host || !username || !password) {
                    $result.html(
                        '<div class="swsib-notice error"><p>' + swsib_af_vars.fill_required_fields + '</p></div>'
                    ).show();
                    return;
                }
            }
            else if (method === 'sftp') {
                host = $('#swsib_options_installation_host_sftp').val();
                username = $('#swsib_options_installation_username_sftp').val();
                password = $('#swsib_options_installation_password_sftp').val();
                port = $('#swsib_options_installation_port_sftp').val() || '22';
                path = $('#swsib_options_installation_path_sftp').val() || '';
                
                // Validate required fields for SFTP
                if (!host || !username || !password) {
                    $result.html(
                        '<div class="swsib-notice error"><p>' + swsib_af_vars.fill_required_fields + '</p></div>'
                    ).show();
                    return;
                }
            } 
            else { // local
                path = $('#swsib_options_installation_path_local').val() || '';
            }
            
            // If path is empty, use '/' for the request
            if (!path) {
                path = '/';
            }
            
            // Change button state: update text and disable it
            $button.text(swsib_af_vars.testing_text).prop('disabled', true);
            $result.removeClass('success error').hide().html('');
            
            // Hide verify button during testing
            $('#verify_siberian_installation').hide();
            
            // Prepare AJAX data
            var data = {
                action: 'swsib_test_installation_connection',
                nonce: swsib_af_vars.nonce,
                method: method
            };
            
            // Add method-specific fields
            if (method === 'ftp') {
                data['host_ftp'] = host;
                data['username_ftp'] = username;
                data['password_ftp'] = password;
                data['port_ftp'] = port;
            }
            else if (method === 'sftp') {
                data['host_sftp'] = host;
                data['username_sftp'] = username;
                data['password_sftp'] = password;
                data['port_sftp'] = port;
            }
            data['path_' + method] = path;
            
            $.ajax({
                url: swsib_af_vars.ajax_url,
                type: 'POST',
                data: data,
                success: function(response) {
                    console.log('Connection test response:', response);
                    var noticeClass = response.success ? 'success' : 'error';
                    var message = response.data ? response.data.message : (response.success ? 'Connection successful!' : 'Connection failed!');
                    
                    $result.html(
                        '<div class="swsib-notice ' + noticeClass + '"><p>' + message + '</p></div>'
                    ).show();
                    
                    if (response.success) {
                        // If we received a path from the server, update and save it
                        if (response.data && response.data.path) {
                            var responsePath = response.data.path;
                            $('#swsib_options_installation_path_' + method).val(responsePath);
                            savedPaths[method] = responsePath;
                        }
                        
                        // Show verify button
                        $('#verify_siberian_installation').show();
                    }
                    
                    $('html, body').animate({
                        scrollTop: $result.offset().top - 100
                    }, 300);
                    
                    // Restore the button text
                    $button.text(originalText).prop('disabled', false);
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error:', status, error);
                    $result.html(
                        '<div class="swsib-notice error"><p>' + swsib_af_vars.error_occurred + '</p></div>'
                    ).show();
                    
                    $button.text(originalText).prop('disabled', false);
                }
            });
        });
        
        // Verify Siberian Installation
        $('#verify_siberian_installation').on('click', function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var originalText = $button.text();
            var $result = $('#test_installation_result');
            
            // Get selected connection method
            var method = $('#swsib_options_installation_connection_method').val();
            
            // Get method-specific form values
            var host = '';
            var username = '';
            var password = '';
            var port = '';
            var path = '';
            
            if (method === 'ftp') {
                host = $('#swsib_options_installation_host_ftp').val();
                username = $('#swsib_options_installation_username_ftp').val();
                password = $('#swsib_options_installation_password_ftp').val();
                port = $('#swsib_options_installation_port_ftp').val() || '21';
                path = $('#swsib_options_installation_path_ftp').val();
            }
            else if (method === 'sftp') {
                host = $('#swsib_options_installation_host_sftp').val();
                username = $('#swsib_options_installation_username_sftp').val();
                password = $('#swsib_options_installation_password_sftp').val();
                port = $('#swsib_options_installation_port_sftp').val() || '22';
                path = $('#swsib_options_installation_path_sftp').val();
            } 
            else { // local
                path = $('#swsib_options_installation_path_local').val();
            }
            
            // Check if we have a path to verify
            if (!path) {
                $result.html(
                    '<div class="swsib-notice error"><p>Please select a path to verify.</p></div>'
                ).show();
                return;
            }
            
            // Change button state: update text and disable it
            $button.text('Verifying...').prop('disabled', true);
            
            // Prepare AJAX data
            var data = {
                action: 'swsib_verify_siberian_installation',
                nonce: swsib_af_vars.nonce,
                method: method,
                path: path
            };
            
            // Add method-specific fields
            if (method === 'ftp') {
                data['host_ftp'] = host;
                data['username_ftp'] = username;
                data['password_ftp'] = password;
                data['port_ftp'] = port;
            }
            else if (method === 'sftp') {
                data['host_sftp'] = host;
                data['username_sftp'] = username;
                data['password_sftp'] = password;
                data['port_sftp'] = port;
            }
            
            $.ajax({
                url: swsib_af_vars.ajax_url,
                type: 'POST',
                data: data,
                success: function(response) {
                    console.log('Verification response:', response);
                    var noticeClass = response.success ? 'success' : 'error';
                    var message = response.data ? response.data.message : (response.success ? 'Siberian installation verified!' : 'Verification failed!');
                    
                    $result.html(
                        '<div class="swsib-notice ' + noticeClass + '"><p>' + message + '</p></div>'
                    ).show();
                    
                    // Enable/disable Save button based on verification result
                    if (response.success) {
                        $('#save-installation-settings').prop('disabled', false);
                    } else {
                        $('#save-installation-settings').prop('disabled', true);
                    }
                    
                    $('html, body').animate({
                        scrollTop: $result.offset().top - 100
                    }, 300);
                    
                    $button.text(originalText).prop('disabled', false);
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error:', status, error);
                    $result.html(
                        '<div class="swsib-notice error"><p>' + swsib_af_vars.error_occurred + '</p></div>'
                    ).show();
                    
                    $button.text(originalText).prop('disabled', false);
                    $('#save-installation-settings').prop('disabled', true);
                }
            });
        });
        
        // Handle "Browse" button clicks
        $(document).on('click', '.browse-directory-button', function() {
            var method = $(this).data('method');
            var pathField = $('#swsib_options_installation_path_' + method);
            var currentPath = pathField.val() || '/';
            
            // For remote methods (FTP/SFTP), validate credentials first
            if (method === 'ftp' || method === 'sftp') {
                var host = $('#swsib_options_installation_host_' + method).val();
                var username = $('#swsib_options_installation_username_' + method).val();
                var password = $('#swsib_options_installation_password_' + method).val();
                
                if (!host || !username || !password) {
                    $('#test_installation_result').html(
                        '<div class="swsib-notice error"><p>' + swsib_af_vars.fill_required_fields + '</p></div>'
                    ).show();
                    
                    $('html, body').animate({
                        scrollTop: $('#test_installation_result').offset().top - 100
                    }, 300);
                    
                    return;
                }
            }
            
            // Show loading indicator in the browser
            $('#directory_browser_loading').show();
            $('#directory_listing').empty().hide();
            
            // Prepare to browse directory
            browseToDirectory(currentPath, method);
            
            // Open the custom modal
            showDirectoryBrowser();
        });
        
        // Handle directory navigation
        $(document).on('click', '.directory-item', function() {
            var path = $(this).data('path');
            var method = $('#swsib_options_installation_connection_method').val();
            $('#directory_browser_loading').show();
            $('#directory_listing').hide();
            browseToDirectory(path, method);
        });
        
        // Helper function to display directory contents in the browser
        function displayDirectoryContents(directories, files, currentPath, method) {
            // Update the current path display
            $('#current_path').text(currentPath);
            
            // Build directory listing
            var html = '';
            
            // Add directories
            if (directories && directories.length > 0) {
                for (var i = 0; i < directories.length; i++) {
                    var dir = directories[i];
                    var isParent = dir.is_parent || false;
                    var displayName = isParent ? '..' : dir.name;
                    var iconClass = isParent ? 'dashicons dashicons-arrow-up-alt' : 'dashicons dashicons-portfolio';
                    html += '<div class="directory-item" data-path="' + dir.path + '">' + 
                            '<span class="' + iconClass + '"></span> <strong>' + displayName + '</strong></div>';
                }
            } else {
                html += '<div><em>No directories found in this location</em></div>';
            }
            
            // Add files section if files are present
            if (files && files.length > 0) {
                html += '<hr style="margin: 10px 0;"><h4>Files:</h4>';
                for (var j = 0; j < files.length; j++) {
                    var file = files[j];
                    var fileIconClass = getFileIconClass(file.name);
                    html += '<div>' + 
                            '<span class="' + fileIconClass + '"></span> ' + file.name + 
                            '</div>';
                }
            }
            
            // Update the directory listing
            $('#directory_listing').html(html);
            $('#directory_listing').show();
            $('#directory_browser_loading').hide();
        }
        
        // Helper function to get appropriate icon class based on file extension
        function getFileIconClass(filename) {
            var extension = filename.split('.').pop().toLowerCase();
            
            if (extension === 'php') return 'dashicons dashicons-media-code';
            else if (extension === 'js') return 'dashicons dashicons-media-code';
            else if (extension === 'css') return 'dashicons dashicons-media-code';
            else if (extension === 'html' || extension === 'htm') return 'dashicons dashicons-media-text';
            else if (extension === 'jpg' || extension === 'jpeg' || extension === 'png' || extension === 'gif') return 'dashicons dashicons-format-image';
            else if (extension === 'pdf') return 'dashicons dashicons-pdf';
            else if (extension === 'zip' || extension === 'tar' || extension === 'gz') return 'dashicons dashicons-media-archive';
            else if (extension === 'txt' || extension === 'md') return 'dashicons dashicons-media-text';
            else if (filename === '.htaccess') return 'dashicons dashicons-privacy';
            else return 'dashicons dashicons-media-default'; // Default file icon
        }
        
        // Helper function to browse to a different directory
        function browseToDirectory(path, method) {
            // Show loading indicator
            $('#directory_browser_loading').show();
            $('#directory_listing').hide();
            
            // Get connection details for the selected method
            var data = {
                action: 'swsib_browse_directory',
                nonce: swsib_af_vars.nonce,
                method: method,
                path: path
            };
            
            // Add method-specific fields
            if (method === 'ftp') {
                data['host_ftp'] = $('#swsib_options_installation_host_ftp').val();
                data['username_ftp'] = $('#swsib_options_installation_username_ftp').val();
                data['password_ftp'] = $('#swsib_options_installation_password_ftp').val();
                data['port_ftp'] = $('#swsib_options_installation_port_ftp').val() || '21';
            }
            else if (method === 'sftp') {
                data['host_sftp'] = $('#swsib_options_installation_host_sftp').val();
                data['username_sftp'] = $('#swsib_options_installation_username_sftp').val();
                data['password_sftp'] = $('#swsib_options_installation_password_sftp').val();
                data['port_sftp'] = $('#swsib_options_installation_port_sftp').val() || '22';
            }
            
            $.ajax({
                url: swsib_af_vars.ajax_url,
                type: 'POST',
                data: data,
                success: function(response) {
                    console.log('Browse directory response:', response);
                    if (response.success) {
                        displayDirectoryContents(
                            response.data.directories || [],
                            response.data.files || [],
                            response.data.path,
                            method
                        );
                    } else {
                        alert('Failed to browse directory: ' + response.data.message);
                        $('#directory_browser_loading').hide();
                        $('#directory_listing').show();
                    }
                },
                error: function() {
                    alert('Error browsing directory. Please try again.');
                    $('#directory_browser_loading').hide();
                    $('#directory_listing').show();
                }
            });
        }
        
        // Add the SFTP Info box under the SFTP section with installation instructions
        var sftp_info_html = '<div class="swsib-notice info sftp-extension-note" style="margin-top: 15px;">' +
            '<p><strong>SFTP Extension:</strong> For optimal performance with SFTP connections, the PHP SSH2 extension is recommended.</p>' +
            '<p>If you have SSH access to your server, you can install it using: <code>sudo apt-get install -y php-ssh2</code></p>' +
            '<p>Without the extension, the plugin will automatically use a built-in fallback method for SFTP connections.</p>' +
        '</div>';
        
        // Insert the SFTP info box
        $('.connection-fields-sftp').append(sftp_info_html);
        
        // Check and show the info box only when SFTP is selected
        if ($('#swsib_options_installation_connection_method').val() !== 'sftp') {
            $('.sftp-extension-note').hide();
        }
        
        // Show the SFTP info box when switching to SFTP method
        $('#swsib_options_installation_connection_method').on('change', function() {
            if ($(this).val() === 'sftp') {
                $('.sftp-extension-note').show();
            } else {
                $('.sftp-extension-note').hide();
            }
        });
        
        // Trigger the connection method handler on page load to ensure correct field visibility
        $('#swsib_options_installation_connection_method').trigger('change');
        
        // Update required attributes on page load
        updateRequiredAttributes();
        
        // Update required attributes when enabling/disabling the feature
        $('#swsib_options_installation_enabled').on('change', function() {
            updateRequiredAttributes();
        });

        // If a path exists for the active method, ensure the verify button is shown
        var activeMethod = $('#swsib_options_installation_connection_method').val();
        var activePath = $('#swsib_options_installation_path_' + activeMethod).val();
        
        if (activePath && activePath !== '/') {
            $('#verify_siberian_installation').show();
            
            // If we've previously verified (form is submittable), enable save button
            if (!$('#save-installation-settings').prop('disabled')) {
                // Make sure this remains enabled
                console.log("Ensuring save button is enabled");
                $('#save-installation-settings').prop('disabled', false);
            }
        }
    });
})(jQuery);