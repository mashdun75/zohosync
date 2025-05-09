<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Check user permissions
if (!current_user_can('gravityforms_edit_forms')) {
    wp_die(__('You do not have sufficient permissions to access this page.'));
}

// Get sync logs
function get_zoho_sync_logs($page = 1, $per_page = 20) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'zoho_sync_logs';
    
    // Check if table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
    if (!$table_exists) {
        // Create the table if it doesn't exist
        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            date datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            form_id bigint(20) NOT NULL,
            form_title varchar(255) NOT NULL,
            entry_id bigint(20) NOT NULL,
            zoho_module varchar(100) NOT NULL,
            zoho_record_id varchar(100) DEFAULT '' NOT NULL,
            status varchar(50) NOT NULL,
            message text NOT NULL,
            PRIMARY KEY  (id),
            KEY form_id (form_id),
            KEY entry_id (entry_id)
        ) " . $wpdb->get_charset_collate();
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        return array(
            'logs' => array(),
            'total' => 0
        );
    }
    
    // Get logs with pagination
    $offset = ($page - 1) * $per_page;
    
    $logs = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$table_name} ORDER BY date DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        )
    );
    
    $total = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
    
    return array(
        'logs' => $logs ? $logs : array(),
        'total' => $total ? intval($total) : 0
    );
}

// Get current page
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = 20;

// Get logs
$result = get_zoho_sync_logs($current_page, $per_page);
$logs = $result['logs'];
$total = $result['total'];
$total_pages = ceil($total / $per_page);

// Handle log clear
if (isset($_POST['clear_logs']) && check_admin_referer('clear_zoho_logs')) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'zoho_sync_logs';
    
    $wpdb->query("TRUNCATE TABLE {$table_name}");
    
    echo '<div class="updated"><p>Log history cleared successfully.</p></div>';
    
    // Refresh logs after clearing
    $result = get_zoho_sync_logs($current_page, $per_page);
    $logs = $result['logs'];
    $total = $result['total'];
    $total_pages = ceil($total / $per_page);
}

// Get recent log content
$log_content = '';
if (function_exists('gf_zoho_logger')) {
    $logger = gf_zoho_logger();
    if (method_exists($logger, 'get_log_content')) {
        $log_content = $logger->get_log_content(50); // Get last 50 lines
    }
}
?>

<div class="wrap zoho-sync-container">
    <h1 class="zoho-sync-title">Zoho Sync History</h1>
    
    <div class="zoho-sync-card">
        <h2>Sync Log History</h2>
        <p>View the history of form submissions synced to Zoho CRM.</p>
        
        <?php if (empty($logs)): ?>
            <div class="zoho-sync-notice">
                No sync records found. Sync history will appear here after forms are submitted and processed.
            </div>
        <?php else: ?>
            <table class="zoho-sync-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Form</th>
                        <th>Entry ID</th>
                        <th>Zoho Module</th>
                        <th>Zoho Record</th>
                        <th>Status</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo esc_html($log->date); ?></td>
                            <td>
                                <?php echo esc_html($log->form_title); ?>
                                <div class="row-actions">
                                    <span class="edit">
                                        <a href="<?php echo admin_url('admin.php?page=gf_edit_forms&id=' . $log->form_id); ?>">Edit Form</a>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <?php echo esc_html($log->entry_id); ?>
                                <div class="row-actions">
                                    <span class="edit">
                                        <a href="<?php echo admin_url('admin.php?page=gf_entries&view=entry&id=' . $log->form_id . '&lid=' . $log->entry_id); ?>">View Entry</a>
                                    </span>
                                </div>
                            </td>
                            <td><?php echo esc_html($log->zoho_module); ?></td>
                            <td><?php echo esc_html($log->zoho_record_id); ?></td>
                            <td>
                                <span class="status-<?php echo esc_attr(strtolower($log->status)); ?>">
                                    <?php echo esc_html(ucfirst($log->status)); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($log->message); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <?php if ($total_pages > 1): ?>
                <div class="tablenav">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo esc_html($total); ?> items</span>
                        <span class="pagination-links">
                            <?php
                            $first_page_url = add_query_arg('paged', 1);
                            $prev_page = max(1, $current_page - 1);
                            $prev_page_url = add_query_arg('paged', $prev_page);
                            $next_page = min($total_pages, $current_page + 1);
                            $next_page_url = add_query_arg('paged', $next_page);
                            $last_page_url = add_query_arg('paged', $total_pages);
                            ?>
                            
                            <a class="first-page <?php echo $current_page <= 1 ? 'disabled' : ''; ?>" href="<?php echo esc_url($first_page_url); ?>" aria-label="First page">«</a>
                            <a class="prev-page <?php echo $current_page <= 1 ? 'disabled' : ''; ?>" href="<?php echo esc_url($prev_page_url); ?>" aria-label="Previous page">‹</a>
                            <span class="paging-input">
                                <?php echo esc_html($current_page); ?> of <span class="total-pages"><?php echo esc_html($total_pages); ?></span>
                            </span>
                            <a class="next-page <?php echo $current_page >= $total_pages ? 'disabled' : ''; ?>" href="<?php echo esc_url($next_page_url); ?>" aria-label="Next page">›</a>
                            <a class="last-page <?php echo $current_page >= $total_pages ? 'disabled' : ''; ?>" href="<?php echo esc_url($last_page_url); ?>" aria-label="Last page">»</a>
                        </span>
                    </div>
                </div>
            <?php endif; ?>
            
            <form method="post" action="">
                <?php wp_nonce_field('clear_zoho_logs'); ?>
                <input type="submit" name="clear_logs" class="zoho-sync-button zoho-sync-button-secondary" value="Clear Log History" onclick="return confirm('Are you sure you want to clear all sync history?');">
            </form>
        <?php endif; ?>
    </div>
    
    <div class="zoho-sync-card">
        <h2>Recent Log Entries</h2>
        <p>View the most recent detailed log entries for debugging purposes.</p>
        
        <div style="background:#f8f8f8; padding:10px; border:1px solid #ddd; max-height:400px; overflow:auto;">
            <pre style="margin:0; white-space:pre-wrap;"><?php 
                if (!empty($log_content)) {
                    echo esc_html($log_content);
                } else {
                    echo "No log entries found.";
                }
            ?></pre>
        </div>
        
        <p class="description">
            Logs are stored in: <?php echo esc_html(GF_ZOHO_SYNC_LOGS_DIR); ?>
        </p>
    </div>
</div>