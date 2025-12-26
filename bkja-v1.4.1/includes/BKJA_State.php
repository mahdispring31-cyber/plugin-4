<?php
if (!defined('ABSPATH')) exit;

class BKJA_State {

    public static function load() {
        if (!session_id()) session_start();
        return $_SESSION['bkja_state'] ?? self::default();
    }

    public static function save($state) {
        if (!session_id()) session_start();
        $_SESSION['bkja_state'] = $state;
    }

    public static function default() {
        return [
            'job_id' => null,
            'offset' => 0,
            'closed' => false
        ];
    }
}
