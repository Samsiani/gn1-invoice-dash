<?php
/**
 * Simple logger utility
 *
 * @package CIG
 * @since 3.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CIG_Logger {

    /**
     * Whether debug is enabled
     *
     * @return bool
     */
    protected function is_debug() {
        $opt = get_option('cig_debug', null);
        if ($opt === null) {
            return defined('WP_DEBUG') && WP_DEBUG;
        }
        return (bool) $opt;
    }

    /**
     * Log with level
     *
     * @param string $level
     * @param string $message
     * @param array  $context
     */
    protected function log($level, $message, array $context = []) {
        if (!$this->is_debug() && $level === 'debug') {
            return;
        }

        $line = sprintf('[CIG][%s] %s', strtoupper($level), $message);
        if (!empty($context)) {
            $line .= ' ' . wp_json_encode($context);
        }

        if (class_exists('WC_Logger')) {
            $wc_logger = wc_get_logger();
            $wc_logger->log($level, $line, ['source' => 'cig']);
        } else {
            error_log($line);
        }
    }

    public function debug($message, array $context = []) { $this->log('debug', $message, $context); }
    public function info($message, array $context = [])  { $this->log('info', $message, $context); }
    public function warning($message, array $context = []) { $this->log('warning', $message, $context); }
    public function error($message, array $context = [])  { $this->log('error', $message, $context); }
}