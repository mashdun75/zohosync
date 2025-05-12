<?php
/**
 * GF Zoho Sync Logging Class
 * Manages detailed logging for the Zoho integration
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class GF_Zoho_Logger {
    // Log levels
    const ERROR = 'ERROR';
    const WARNING = 'WARNING';
    const INFO = 'INFO';
    const DEBUG = 'DEBUG';
    
    // Log file
    private $log_file;
    private $enabled = true;
    private $max_file_size = 5 * 1024 * 1024; // 5 MB
    
    /**
     * Constructor - set up logging
     */
    public function __construct() {
        // Create logs directory if it doesn't exist
        $upload_dir = wp_upload_dir();
        $logs_dir = trailingslashit($upload_dir['basedir']) . 'gf-zoho-logs';
        
        if (!file_exists($logs_dir)) {
            wp_mkdir_p($logs_dir);
            
            // Add .htaccess to protect logs
            $htaccess = "# Disable directory browsing\nOptions -Indexes\n\n# Deny access to all files\n<FilesMatch \".*\">\nOrder Allow,Deny\nDeny from all\n</FilesMatch>";
            @file_put_contents($logs_dir . '/.htaccess', $htaccess);
        }
        
        // Set log file
        $this->log_file = $logs_dir . '/zoho-sync.log';
        
        // Check if logging is enabled
        $this->enabled = apply_filters('gf_zoho_logging_enabled', get_option('gf_zoho_enable_logging', true));
        
        // Create log file if it doesn't exist
        if ($this->enabled && !file_exists($this->log_file)) {
            @file_put_contents($this->log_file, "# GF Zoho Sync Log\n# Created: " . date('Y-m-d H:i:s') . "\n\n");
        }
        
        // Rotate log if too large
        $this->maybe_rotate_logs();
    }
    
    /**
     * Log a message
     *
     * @param string $message The message to log
     * @param string $level The log level
     * @param array $context Additional context data
     */
    public function log($message, $level = self::INFO, $context = array()) {
        if (!$this->enabled) {
            return;
        }
        
        // Format the log entry
        $timestamp = date('Y-m-d H:i:s');
        $entry = "[{$timestamp}] [{$level}] {$message}";
        
        // Add context if available
        if (!empty($context)) {
            $context_str = json_encode($context, JSON_PRETTY_PRINT);
            $entry .= " | Context: {$context_str}";
        }
        
        // Add entry to log file
        @file_put_contents($this->log_file, $entry . PHP_EOL, FILE_APPEND);
        
        // Also write to WP debug log if enabled
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log("Zoho Sync [{$level}]: {$message}");
        }
    }
    
    /**
     * Log an error message
     */
    public function error($message, $context = array()) {
        $this->log($message, self::ERROR, $context);
    }
    
    /**
     * Log a warning message
     */
    public function warning($message, $context = array()) {
        $this->log($message, self::WARNING, $context);
    }
    
    /**
     * Log an info message
     */
    public function info($message, $context = array()) {
        $this->log($message, self::INFO, $context);
    }
    
    /**
     * Log a debug message
     */
    public function debug($message, $context = array()) {
        $this->log($message, self::DEBUG, $context);
    }
    
    /**
     * Rotate logs if the file gets too large
     */
    private function maybe_rotate_logs() {
        if (!file_exists($this->log_file)) {
            return;
        }
        
        if (filesize($this->log_file) > $this->max_file_size) {
            $backup_file = $this->log_file . '.' . date('Y-m-d-H-i-s') . '.bak';
            @rename($this->log_file, $backup_file);
            @file_put_contents($this->log_file, "# GF Zoho Sync Log\n# Rotated: " . date('Y-m-d H:i:s') . "\n\n");
            
            // Keep only 5 most recent backup logs
            $upload_dir = wp_upload_dir();
            $logs_dir = trailingslashit($upload_dir['basedir']) . 'gf-zoho-logs';
            $backup_files = glob($logs_dir . '/*.bak');
            
            if (count($backup_files) > 5) {
                usort($backup_files, function($a, $b) {
                    return filemtime($a) - filemtime($b);
                });
                
                $to_delete = array_slice($backup_files, 0, count($backup_files) - 5);
                foreach ($to_delete as $file) {
                    @unlink($file);
                }
            }
        }
    }
    
    /**
     * Get the log content for display in admin
     *
     * @param int $lines Number of lines to retrieve
     * @return string Log content
     */
    public function get_log_content($lines = 200) {
        if (!file_exists($this->log_file)) {
            return 'No log file found.';
        }
        
        // Get the last X lines of the log file
        $log_content = '';
        
        if (function_exists('shell_exec')) {
            // Try using tail command
            $log_content = @shell_exec('tail -n ' . intval($lines) . ' ' . escapeshellarg($this->log_file));
        }
        
        if (empty($log_content)) {
            // Fallback to PHP implementation
            $file = new SplFileObject($this->log_file, 'r');
            $file->seek(PHP_INT_MAX); // Seek to end of file
            $total_lines = $file->key(); // Get last line number
            
            $start_line = max(0, $total_lines - $lines);
            $log_lines = array();
            
            $file->seek($start_line); // Seek to start line
            while (!$file->eof()) {
                $log_lines[] = $file->current();
                $file->next();
            }
            
            $log_content = implode('', $log_lines);
        }
        
        return $log_content;
    }
    
    /**
     * Clear the log file
     *
     * @return bool Success
     */
    public function clear_log() {
        if (!file_exists($this->log_file)) {
            return true;
        }
        
        $result = @file_put_contents($this->log_file, "# GF Zoho Sync Log\n# Cleared: " . date('Y-m-d H:i:s') . "\n\n");
        return ($result !== false);
    }
}

// Initialize logger
function gf_zoho_logger() {
    static $logger = null;
    
    if ($logger === null) {
        $logger = new GF_Zoho_Logger();
    }
    
    return $logger;
}