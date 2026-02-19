jQuery(document).ready(function($) {
    console.log('WPAIE Admin JS Loaded');

    // Tab Switching
    $('.wpaie-nav-tabs .nav-tab').on('click', function(e) {
        e.preventDefault();
        var target = $(this).data('tab');
        
        // Update Tabs
        $('.wpaie-nav-tabs .nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        
        // Update Content
        $('.wpaie-tab-section').hide();
        $('#wpaie-tab-' + target).show();
        
        // Update URL (optional but good)
        var newUrl = new URL(window.location.href);
        newUrl.searchParams.set('tab', target);
        window.history.pushState({path: newUrl.href}, '', newUrl.href);
        
        // Dynamic Refresh for Report Tab
        if ( target === 'report' ) {
            $('#wpaie-tab-report').css('opacity', '0.5'); // Indicate loading
            $.post(wpaie_ajax.ajax_url, {
                action: 'wpaie_refresh_report',
                nonce: wpaie_ajax.nonce
            }, function(response) {
                $('#wpaie-tab-report').css('opacity', '1');
                if ( response.success ) {
                    $('#wpaie-tab-report').html(response.data.html);
                } else {
                    console.log('Failed to refresh report');
                }
            }).fail(function(){
                $('#wpaie-tab-report').css('opacity', '1');
            });
        }
    });

    // Export Form Handler
    $(document).on('submit', '#wpaie-export-form', function(e) {
        e.preventDefault();
        console.log('Export Form Submitted');
        
        var $form = $(this);
        var $progressBarContainer = $('.wpaie-progress-container');
        var $progressBar = $('.wpaie-progress-bar div');
        var $status = $('.wpaie-status');
        var $submitBtn = $form.find('input[type="submit"]');

        // Reset UI
        $submitBtn.prop('disabled', true);
        $progressBar.css('width', '0%').text('0%');
        $status.text('Analyzing data...');
        $progressBarContainer.show();
        
        // Step 1: Init Export
        var data = $form.serialize();
        data += '&action=wpaie_init_export';
        
        $.post(wpaie_ajax.ajax_url, data, function(response) {
            if (response.success) {
                var total = response.data.total;
                var counts = response.data.counts;
                var batchSize = response.data.batch_size;
                var totalBatches = Math.ceil(total / batchSize);
                var currentBatch = 1;
                var fileName = response.data.file_name;
                
                var breakdown = [];
                if(counts && counts.post) breakdown.push(counts.post + ' Posts');
                if(counts && counts.page) breakdown.push(counts.page + ' Pages');
                var breakdownText = breakdown.length ? ' (' + breakdown.join(', ') + ')' : '';

                $status.html('Found <strong>' + total + '</strong> items' + breakdownText + '. Starting export...');
                console.log('Init success, total: ' + total);

                if (total > 0) {
                    processBatch(currentBatch, totalBatches, fileName, total, $form.serialize());
                } else {
                    $status.text('No items found to export.');
                    $submitBtn.prop('disabled', false);
                }

            } else {
                console.error(response);
                $status.text('Error: ' + (response.data || 'Unknown error'));
                $submitBtn.prop('disabled', false);
            }
        }).fail(function(xhr, status, error) {
            console.error(error);
            $status.text('Server Error (Init): ' + error);
            $submitBtn.prop('disabled', false);
        });

        function processBatch(currentBatch, totalBatches, fileName, totalItems, formData) {
            var batchData = formData + '&action=wpaie_export_step&page=' + currentBatch + '&file_name=' + fileName;

            $.post(wpaie_ajax.ajax_url, batchData, function(response) {
                if (response.success) {
                    var percent = Math.round((currentBatch / totalBatches) * 100);
                    $progressBar.css('width', percent + '%').text(percent + '%');
                    $status.text('Exporting batch ' + currentBatch + ' of ' + totalBatches + '...');
                    
                    if (currentBatch < totalBatches) {
                        processBatch(currentBatch + 1, totalBatches, fileName, totalItems, formData);
                    } else {
                        finishExport(fileName);
                    }
                } else {
                    $status.text('Error during batch ' + currentBatch + ': ' + response.data);
                    $submitBtn.prop('disabled', false);
                }
            }).fail(function() {
                $status.text('Server Error during batch ' + currentBatch + '.');
                $submitBtn.prop('disabled', false);
            });
        }

        function finishExport(fileName) {
            $.post(wpaie_ajax.ajax_url, {
                action: 'wpaie_export_finish',
                file_name: fileName
            }, function(response) {
                 if (response.success) {
                     var downloadUrl = wpaie_ajax.ajax_url + '?action=wpaie_download_file&file=' + fileName + '&nonce=' + wpaie_ajax.nonce;
                     $status.html('Done! <a href="' + downloadUrl + '" class="button button-primary">Download Export File</a>');
                     $progressBar.css('width', '100%').text('100%');
                     $submitBtn.prop('disabled', false).val('Export Again');
                     
                     // Auto-trigger
                     window.location.href = downloadUrl;
                 } else {
                     $status.text('Error finishing export: ' + response.data);
                     $submitBtn.prop('disabled', false);
                 }
            });
        }
    });

    // Import Form Handler
    $(document).on('submit', '#wpaie-import-form', function(e) {
        e.preventDefault();
        console.log('Import Form Submitted');
        
        var $form = $(this);
        var $submitBtn = $form.find('input[type="submit"]'); // Changed from '#submit' to 'input[type="submit"]' to match existing pattern
        var $stopBtn = $('#wpaie-stop-import');
        var $status = $form.find('.wpaie-status'); // Changed from '.wpaie-status' to $form.find('.wpaie-status')
        var $progressBar = $form.find('.wpaie-progress-bar div'); // Changed from '.wpaie-progress-bar div' to $form.find('.wpaie-progress-bar div')
        var $logBox = $form.find('.wpaie-log-box'); // Changed from '.wpaie-log-box' to $form.find('.wpaie-log-box')
        var $progressBarContainer = $form.find('.wpaie-progress-container'); // Added this line as it was removed by the instruction

        $submitBtn.prop('disabled', true).val('Initializing...');
        $stopBtn.show().prop('disabled', false); // Show stop button
        $progressBar.css('width', '0%').text('0%');
        $status.text('Uploading and analyzing file...');
        $logBox.html(''); // Clear logs
        $progressBarContainer.show(); // Changed from '.wpaie-progress-container' to $progressBarContainer
        
        // Reset Stop Flag
        window.wpaie_stop_signal = false;

        var formData = new FormData(this);
        formData.append('action', 'wpaie_init_import'); // action for wp_ajax_

        $.ajax({
            url: wpaie_ajax.ajax_url,
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            success: function(response) {
                if (response.success) {
                    var total = response.data.total;
                    var batchSize = response.data.batch_size;
                    var importId = response.data.import_id;
                    var totalBatches = Math.ceil(total / batchSize);
                    var currentBatch = 1;
                    
                    $status.html('Found <strong>' + total + '</strong> items to import.'); // Re-added this line as it was removed by the instruction
                    log('File uploaded. Found ' + total + ' posts. Starting import...');
                    
                    if (total > 0) {
                        // Pass totalProcessed = 0 initiall, pass total for display
                        processImportBatch(currentBatch, totalBatches, importId, $form.serializeArray(), 0, total);
                    } else {
                        $status.text('No posts found in file.');
                        $submitBtn.prop('disabled', false);
                        $stopBtn.hide();
                    }
                } else {
                    $status.text('Error: ' + response.data);
                    $submitBtn.prop('disabled', false);
                    $stopBtn.hide();
                }
            },
            error: function() {
                $status.text('Upload failed.');
                $submitBtn.prop('disabled', false);
                $stopBtn.hide();
            }
        });

        function processImportBatch(currentBatch, totalBatches, importId, formFields, totalProcessed, totalItems) {
            // Check Stop Signal
            if ( window.wpaie_stop_signal ) {
                $status.html('Import stopped by user. <br><strong>Tip:</strong> check the <a href="?page=wpaie-settings&tab=report">Report Tab</a> to retry any failed image downloads.');
                log('<span style="color:red;">Import stopped by user.</span>');
                $submitBtn.prop('disabled', false).val('Run Importer');
                $stopBtn.hide();
                return;
            }
            
            // Immediate update before request
            var currentItem = totalProcessed + 1;
            $status.text('Importing item ' + currentItem + ' of ' + totalItems + '...');
            
            // Show immediate log - skipped to avoid spam
            
            var percent = Math.round((currentItem / totalItems) * 100); 
            // Update progress bar width regarding currentItem (more accurate than batch for size=1)
            $progressBar.css('width', percent + '%').text(currentItem + '/' + totalItems);

            var data = {
                action: 'wpaie_import_step',
                page: currentBatch,
                import_id: importId,
                processed_offset: totalProcessed // Pass current offset for logging
            };
            
            $.each(formFields, function(i, field){
                data[field.name] = field.value;
            });

            $.post(wpaie_ajax.ajax_url, data, function(response) {
                if (response.success) {
                    
                    // Update processed count
                    var itemsInBatch = response.data.processed_count || 0;
                    var newProcessed = totalProcessed + itemsInBatch;
                    
                    if (response.data.logs && response.data.logs.length) {
                        $.each(response.data.logs, function(i, msg) {
                             log(msg);
                        });
                    }

                    if (currentBatch < totalBatches) {
                        // Delay 2 seconds to prevent image download failure
                        setTimeout(function() {
                            processImportBatch(currentBatch + 1, totalBatches, importId, formFields, newProcessed, totalItems);
                        }, 2000);
                    } else {
                        finishImport(importId, totalItems);
                    }
                } else {
                    $status.text('Error during item ' + currentBatch + ': ' + response.data);
                    log('Error: ' + response.data);
                    // Do not stop automatically on error? User might want to stop manually.
                    // Or let it continue? Usually better to continue or ask. 
                    // For now, let's continue to next item if error is non-fatal?
                    // But response.data usually means fatal script error if not handled.
                    // If we want to continue:
                    // processImportBatch(currentBatch + 1, ...);
                    // For now, let's stop on error.
                    $submitBtn.prop('disabled', false);
                    $stopBtn.hide();
                }
            }).fail(function() {
                $status.text('Server Error during item ' + currentBatch + '.');
                $submitBtn.prop('disabled', false);
                $stopBtn.hide();
            });
        }
        
        function finishImport(importId, totalItems) {
             $progressBar.css('width', '100%').text('100%');
             $status.text('Import Complete! (' + totalItems + ' items processed)');
             log('All done.');
             $submitBtn.prop('disabled', false).val('Import Another');
             $stopBtn.hide();
             $.post(wpaie_ajax.ajax_url, { action: 'wpaie_import_cleanup', import_id: importId });
             
             // Success Modal
             if (window.wpaie_show_success_modal) {
                 window.wpaie_show_success_modal('All posts have been successfully imported!');
             }
        }
        
        function log(msg) {
            $logBox.append('<div>' + msg + '</div>');
            $logBox.scrollTop($logBox[0].scrollHeight);
        }
    });
    
    // Modal Helper
    function wpaie_modal(options) {
        var $modal = $('#wpaie-modal');
        var defaults = {
            title: 'Notification',
            message: '',
            confirm: false,
            confirmText: 'OK',
            cancelText: 'Cancel',
            onConfirm: function() {},
            onCancel: function() {}
        };
        var settings = $.extend({}, defaults, options);
        
        $('#wpaie-modal-title').text(settings.title);
        $('#wpaie-modal-message').html(settings.message);
        $('#wpaie-modal-confirm').text(settings.confirmText).off('click').on('click', function() {
             settings.onConfirm();
             $modal.hide();
        });
        
        if (settings.confirm) {
            $('#wpaie-modal-cancel').text(settings.cancelText).show().off('click').on('click', function() {
                settings.onCancel();
                $modal.hide();
            });
        } else {
             $('#wpaie-modal-cancel').hide();
        }
        
        $('.wpaie-modal-close').off('click').on('click', function() {
            $modal.hide();
        });
        
        // Close on outside click
        $(window).off('click.wpaie').on('click.wpaie', function(event) {
            if (event.target == $modal[0]) {
                $modal.hide();
            }
        });
        
        $modal.show();
    }

    // Stop Button Handler
    $(document).on('click', '#wpaie-stop-import', function() {
        var $btn = $(this);
        wpaie_modal({
            title: 'Stop Import',
            message: 'Are you sure you want to stop the import process?',
            confirm: true,
            confirmText: 'Yes, Stop',
            onConfirm: function() {
                window.wpaie_stop_signal = true;
                $btn.text('Stopping...').prop('disabled', true);
            }
        });
    });    

    // Report Tab: Retry Handler
    $(document).on('click', '.wpaie-retry-btn', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var postId = $btn.data('id');
        var url = $btn.data('url');
        var $row = $btn.closest('tr');
        var $status = $('#wpaie-report-status');
        
        $btn.prop('disabled', true).text('Retrying...');
        $status.text('Retrying post ' + postId + '...');
        
        $.post(wpaie_ajax.ajax_url, {
            action: 'wpaie_retry_image',
            post_id: postId,
            url: url,
            nonce: wpaie_ajax.nonce
        }, function(response) {
            if (response.success) {
                $status.text('Success: ' + response.data.message);
                $row.css('background-color', '#d4edda').fadeOut(1000, function(){ $(this).remove(); });
            } else {
                $status.text('Error: ' + response.data);
                $btn.prop('disabled', false).text('Retry');
                $row.find('.column-error').text(response.data);
            }
        }).fail(function() {
            $status.text('Server error.');
            $btn.prop('disabled', false).text('Retry');
            wpaie_modal({
                title: 'Error',
                message: 'Server Error occurred while retrying.'
            });
        });
    });
    
    // Bulk Retry
    $('#cb-select-all-1').on('click', function(){
        $('.subscriber-check').prop('checked', this.checked);
    });
    
    $('#wpaie-bulk-retry').on('click', function(e) {
        e.preventDefault();
        var $checked = $('.subscriber-check:checked');
        if($checked.length === 0) {
            wpaie_modal({
                title: 'Notice',
                message: 'Please select items to download images for.'
            });
            return;
        }
        
        var total = $checked.length;
        var current = 0;
        var $status = $('#wpaie-report-status');
        
        $(this).prop('disabled', true);
        
        function processNext() {
            if (current >= total) {
                $status.text('Bulk download complete.');
                $('#wpaie-bulk-retry').prop('disabled', false);
                wpaie_modal({
                    title: 'Success',
                    message: 'Bulk image download completed.'
                });
                return;
            }
            
            var $checkbox = $checked.eq(current);
            var $row = $checkbox.closest('tr');
            var $btn = $row.find('.wpaie-retry-btn');
            
            var postId = $checkbox.val();
            var url = $btn.data('url');
            
            $status.text('Retrying ' + (current + 1) + '/' + total + '...');
            $btn.text('Retrying...').prop('disabled', true);
            
            $.post(wpaie_ajax.ajax_url, {
                action: 'wpaie_retry_image',
                post_id: postId,
                url: url,
                nonce: wpaie_ajax.nonce
            }, function(response) {
                if (response.success) {
                    $row.css('background-color', '#d4edda').fadeOut(500, function(){ $(this).remove(); });
                } else {
                    $btn.text('Retry').prop('disabled', false);
                    $row.find('.column-error').text(response.data);
                }
                current++;
                processNext();
            }).fail(function() {
                current++;
                processNext();
            });
        }
        
        processNext();
    });

    // Make wpaie_modal global if needed mainly for finishImport invocation if moved
    window.wpaie_show_success_modal = function(msg) {
        wpaie_modal({
            title: 'Import Complete',
            message: msg
        });
    };

});
