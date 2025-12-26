<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BKJA_State {
    const TYPE_A = 'TYPE_A';
    const TYPE_B = 'TYPE_B';
    const TYPE_C = 'TYPE_C';

    protected $type;
    protected $context;
    protected $meta;

    public function __construct( $type = self::TYPE_A, $context = array(), $meta = array() ) {
        $this->type    = $type;
        $this->context = is_array( $context ) ? $context : array();
        $this->meta    = is_array( $meta ) ? $meta : array();
    }

    public function get_type() {
        return $this->type;
    }

    public function get_context() {
        return $this->context;
    }

    public function get_meta() {
        return $this->meta;
    }

    public function is_type( $type ) {
        return $this->type === $type;
    }
}
