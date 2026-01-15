<?php
/**
 * Cache utility with WP Object Cache + transient fallback
 *
 * @package CIG
 * @since 3.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CIG_Cache {

    private $group;

    public function __construct($group = CIG_CACHE_GROUP) {
        $this->group = $group;
    }

    /**
     * Build namespaced key
     */
    private function key($key) {
        return 'cig_' . md5($key);
    }

    /**
     * Get cache
     */
    public function get($key, $default = null) {
        $ns = $this->key($key);
        $val = wp_cache_get($ns, $this->group);
        if ($val !== false) {
            return $val;
        }
        // Transient fallback
        $val = get_transient($ns);
        return ($val === false) ? $default : $val;
    }

    /**
     * Set cache
     */
    public function set($key, $value, $ttl = CIG_CACHE_EXPIRY) {
        $ns = $this->key($key);
        wp_cache_set($ns, $value, $this->group, (int) $ttl);
        set_transient($ns, $value, (int) $ttl);
        return true;
    }

    /**
     * Delete cache
     */
    public function delete($key) {
        $ns = $this->key($key);
        wp_cache_delete($ns, $this->group);
        delete_transient($ns);
        return true;
    }

    /**
     * Remember pattern
     */
    public function remember($key, $ttl, callable $callback) {
        $existing = $this->get($key, null);
        if ($existing !== null) {
            return $existing;
        }
        $value = call_user_func($callback);
        $this->set($key, $value, $ttl);
        return $value;
    }
}