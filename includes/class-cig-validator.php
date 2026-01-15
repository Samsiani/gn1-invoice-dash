<?php
/**
 * Validation & sanitization helpers
 *
 * @package CIG
 * @since 3.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CIG_Validator {

    /**
     * Deep sanitize text fields in array/object
     */
    public function sanitize_text_deep($data) {
        if (is_array($data)) {
            return array_map([$this, 'sanitize_text_deep'], $data);
        }
        if (is_object($data)) {
            foreach ($data as $k => $v) {
                $data->$k = $this->sanitize_text_deep($v);
            }
            return $data;
        }
        return is_scalar($data) ? sanitize_text_field($data) : $data;
    }

    public function sanitize_float($val, $decimals = 2) {
        $num = floatval(is_string($val) ? str_replace(',', '.', $val) : $val);
        return round($num, $decimals);
    }

    public function sanitize_bool($val) {
        return filter_var($val, FILTER_VALIDATE_BOOLEAN);
    }

    public function validate_date_range($from, $to) {
        if (!$from || !$to) return false;
        $from_ts = strtotime($from . ' 00:00:00');
        $to_ts   = strtotime($to . ' 23:59:59');
        return $from_ts !== false && $to_ts !== false && $from_ts <= $to_ts;
    }

    public function ensure_invoice_number_format($maybe) {
        // Must be like N########
        if (is_string($maybe) && preg_match('/^[Nn][0-9]{8}$/', $maybe)) {
            return strtoupper($maybe);
        }
        return null;
    }

    public function clamp_reservation_days($days) {
        $d = intval($days);
        if ($d < 1) $d = 1;
        if ($d > CIG_MAX_RESERVATION_DAYS) $d = CIG_MAX_RESERVATION_DAYS;
        return $d;
    }
}