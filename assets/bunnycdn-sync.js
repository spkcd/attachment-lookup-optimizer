/**
 * BunnyCDN Manual Full Sync JavaScript
 * 
 * Handles the manual full sync functionality for uploading all media to BunnyCDN
 */

// Sync state variables
let done = 0;
let total = 0;
let syncInProgress = false;
let syncStopped = false;
let batchStartTime = 0;
let totalProcessed = 0;

/**
 * Update the progress bar
 */
function updateProgressBar(done, total) {
    const container = document.getElementById('bunnycdn-progress-container');
    const bar = document.getElementById('bunnycdn-progress-bar');
    const label = document.getElementById('bunnycdn-progress-label');

    container.style.display = 'block';

    const percent = total ? Math.round((done / total) * 100) : 0;
    bar.style.width = percent + '%';
    label.textContent = `${percent}% complete (${done} of ${total})`;
}

/**
 * Run a single batch of the sync process
 */
function runBatch() {
    if (syncStopped) return;

    const url = BUNNYCDN_SYNC.ajax_url + '?action=bunnycdn_sync_next_batch&nonce=' + BUNNYCDN_SYNC.nonce;
    console.log('BunnyCDN Sync: Making request to:', url);
    console.log('BunnyCDN Sync: AJAX URL:', BUNNYCDN_SYNC.ajax_url);
    console.log('BunnyCDN Sync: Nonce:', BUNNYCDN_SYNC.nonce);

    fetch(url)
        .then(res => {
            console.log('BunnyCDN Sync: Response status:', res.status);
            console.log('BunnyCDN Sync: Response headers:', res.headers);
            return res.text();
        })
        .then(text => {
            console.log('BunnyCDN Sync: Raw response:', text);
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                throw new Error('Invalid JSON response: ' + text.substring(0, 200));
            }
            
            if (!data.success) {
                throw new Error(data.data?.message || 'Sync failed');
            }
            
            const responseData = data.data;
            done = responseData.done || 0;
            total = responseData.total || 0;
            totalProcessed += responseData.processed || 0;
            
            const statusEl = document.getElementById('bunnycdn-sync-status');
            
            // Calculate processing speed
            let speedInfo = '';
            if (batchStartTime > 0) {
                const elapsed = (Date.now() - batchStartTime) / 1000; // seconds
                const speed = totalProcessed / elapsed;
                speedInfo = ` (${speed.toFixed(1)} files/sec)`;
            }
            
            // Update status with spinner and detailed info
            statusEl.innerHTML = `<span class="spinner is-active" style="float:none;margin:0 5px 0 0;"></span>Uploading to BunnyCDN... ${done}/${total}${speedInfo}`;
            
            // Update progress bar
            updateProgressBar(done, total);

            if (responseData.completed) {
                const bar = document.getElementById('bunnycdn-progress-bar');
                const label = document.getElementById('bunnycdn-progress-label');
                
                if (responseData.stopped) {
                    statusEl.innerHTML = `⏹️ Sync stopped manually at ${done}/${total}.`;
                    if (label) {
                        label.textContent += ' – Stopped';
                    }
                } else {
                    statusEl.innerHTML = `✅ BunnyCDN sync complete (${total} files).`;
                    if (bar) {
                        bar.style.background = '#46b450'; // WordPress green
                    }
                    if (label) {
                        label.textContent += ' – Finished';
                    }
                }
                
                // Re-enable buttons
                resetButtonStates();
                syncInProgress = false;
            } else {
                // Even faster processing for large batches - minimal timeout
                const batchTimeout = 500; // 0.5 seconds between batches (reduced from 1s)
                setTimeout(runBatch, batchTimeout);
            }
        })
        .catch(error => {
            console.error('BunnyCDN Sync Error:', error);
            const statusEl = document.getElementById('bunnycdn-sync-status');
            
            // Check if this is a timeout error
            if (error.message.includes('Invalid JSON response') && error.message.includes('Request Timeout')) {
                // Record the timeout for adaptive batch sizing
                fetch(`${BUNNYCDN_SYNC.ajax_url}?action=bunnycdn_record_timeout&nonce=${BUNNYCDN_SYNC.nonce}`)
                    .catch(recordError => console.log('Failed to record timeout:', recordError));
                
                // This is a server timeout - continue with next batch after delay
                statusEl.innerHTML = `⚠️ Server timeout occurred, reducing batch size and continuing... ${done}/${total}`;
                
                // Longer delay after timeout to let server recover
                setTimeout(runBatch, 3000); // 3 seconds instead of 0.5
                return; // Don't stop the sync
            }
            
            // For other errors, stop the sync
            statusEl.innerHTML = `
                <div style="padding: 10px; background: #ffeaa7; border: 1px solid #d63638; border-radius: 4px; margin: 10px 0;">
                    <p><strong>❌ Sync failed:</strong> ${error.message}</p>
                    <p>Please check your BunnyCDN settings and try again.</p>
                </div>
            `;
            
            // Hide progress bar on error
            const container = document.getElementById('bunnycdn-progress-container');
            if (container) {
                container.style.display = 'none';
            }
            
            // Re-enable buttons
            resetButtonStates();
            syncInProgress = false;
        });
}

/**
 * Reset button states to default
 */
function resetButtonStates() {
    const syncButton = document.getElementById('bunnycdn-run-sync');
    const stopButton = document.getElementById('bunnycdn-stop-sync');
    const progressContainer = document.getElementById('bunnycdn-progress-container');
    const progressBar = document.getElementById('bunnycdn-progress-bar');
    
    if (syncButton) {
        syncButton.disabled = false;
        syncButton.innerHTML = 'Upload All Media to BunnyCDN';
    }
    
    if (stopButton) {
        stopButton.disabled = false;
    }
    
    // Reset progress bar for next sync
    if (progressBar) {
        progressBar.style.width = '0%';
        progressBar.style.background = '#0073aa'; // Reset to default blue
    }
}

/**
 * Initialize the sync functionality when DOM is ready
 */
document.addEventListener('DOMContentLoaded', function() {
    const syncButton = document.getElementById('bunnycdn-run-sync');
    const stopButton = document.getElementById('bunnycdn-stop-sync');
    const statusButton = document.getElementById('bunnycdn-check-status');
    
    // Start sync button
    if (syncButton) {
        syncButton.addEventListener('click', function() {
            if (syncInProgress) {
                return;
            }
            
            // Confirm with user
            if (!confirm('Are you sure you want to upload all media files to BunnyCDN? This may take a long time for large media libraries.')) {
                return;
            }
            
            // Check if BUNNYCDN_SYNC object is properly loaded
            if (!BUNNYCDN_SYNC || !BUNNYCDN_SYNC.ajax_url || !BUNNYCDN_SYNC.nonce) {
                document.getElementById('bunnycdn-sync-status').innerHTML = '❌ BunnyCDN sync configuration is missing. Please refresh the page.';
                return;
            }
            
            syncInProgress = true;
            syncStopped = false;
            
            // Update button states
            syncButton.disabled = true;
            syncButton.innerHTML = '<span class="dashicons dashicons-update-alt" style="animation: spin 1s linear infinite; margin-right: 5px;"></span>Syncing...';
            
            if (stopButton) {
                stopButton.disabled = false;
            }
            
            // Initialize status
            const statusEl = document.getElementById('bunnycdn-sync-status');
            statusEl.innerHTML = 'Initializing...';
            
            // Reset counters
            done = 0;
            total = 0;
            totalProcessed = 0;
            batchStartTime = Date.now();
            
            // Start the sync process
            runBatch();
        });
    }
    
    // Stop sync button
    if (stopButton) {
        stopButton.addEventListener('click', function() {
            if (!syncInProgress) {
                return;
            }
            
            syncStopped = true;
            stopButton.disabled = true;
            
            fetch(BUNNYCDN_SYNC.ajax_url + '?action=bunnycdn_stop_sync', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'nonce=' + encodeURIComponent(BUNNYCDN_SYNC.nonce)
            }).then(() => {
                document.getElementById('bunnycdn-sync-status').innerHTML = '⏹️ Sync stopped by user.';
            }).catch(error => {
                console.error('Stop sync error:', error);
                document.getElementById('bunnycdn-sync-status').innerHTML = '❌ Failed to stop sync.';
            });
        });
    }
    
    // Status check button
    if (statusButton) {
        statusButton.addEventListener('click', function() {
            const statusEl = document.getElementById('bunnycdn-sync-status');
            statusEl.innerHTML = 'Checking BunnyCDN configuration...';
            
            fetch(BUNNYCDN_SYNC.ajax_url + '?action=bunnycdn_check_status', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'nonce=' + encodeURIComponent(BUNNYCDN_SYNC.nonce)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const status = data.data;
                    let html = '<div style="padding: 10px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">';
                    html += '<h4>BunnyCDN Configuration Status</h4>';
                    html += '<ul>';
                    html += '<li><strong>Manager Available:</strong> ' + (status.manager_available ? '✅ Yes' : '❌ No') + '</li>';
                    html += '<li><strong>Enabled:</strong> ' + (status.enabled ? '✅ Yes' : '❌ No') + '</li>';
                    html += '<li><strong>API Key Set:</strong> ' + (status.api_key_set ? '✅ Yes' : '❌ No') + '</li>';
                    html += '<li><strong>Storage Zone Set:</strong> ' + (status.storage_zone_set ? '✅ Yes' : '❌ No') + '</li>';
                    html += '<li><strong>Total Attachments:</strong> ' + status.total_attachments + '</li>';
                    html += '<li><strong>Unsynced Attachments:</strong> ' + status.unsynced_attachments + '</li>';
                    html += '</ul>';
                    
                    if (status.configuration_errors.length > 0) {
                        html += '<h4 style="color: #d63638;">Configuration Errors:</h4>';
                        html += '<ul>';
                        status.configuration_errors.forEach(error => {
                            html += '<li style="color: #d63638;">❌ ' + error + '</li>';
                        });
                        html += '</ul>';
                    }
                    
                    html += '</div>';
                    statusEl.innerHTML = html;
                } else {
                    statusEl.innerHTML = '❌ Failed to check status: ' + (data.data?.message || 'Unknown error');
                }
            })
            .catch(error => {
                console.error('Status check error:', error);
                statusEl.innerHTML = '❌ Failed to check status: ' + error.message;
            });
        });
    }
});

// Add CSS for spinning animation
const style = document.createElement('style');
style.textContent = `
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
`;
document.head.appendChild(style); 