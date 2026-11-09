<?php
/**
 * Minimal stand-ins for the WordPress functions and classes the plugin touches while loading and
 * registering routes. Shared by the tests; this is not a WordPress emulator.
 */

define( 'ABSPATH', '/' );

class WP_Error {
    public $code; public $message; public $data;
    public function __construct( $code = '', $message = '', $data = [] ) {
        $this->code = $code; $this->message = $message; $this->data = $data;
    }
}

class WP_REST_Request {
    private $headers;
    public function __construct( array $headers = [] ) { $this->headers = array_change_key_case( $headers, CASE_LOWER ); }
    public function get_header( $name ) { return $this->headers[ strtolower( $name ) ] ?? null; }
    public function get_param( $name ) { return null; }
}

$GLOBALS['wseb_test_options'] = [];
$GLOBALS['wseb_test_routes']  = [];
$GLOBALS['wseb_test_actions'] = [];

function get_option( $key, $default = false ) { return $GLOBALS['wseb_test_options'][ $key ] ?? $default; }
function update_option( $key, $value ) { $GLOBALS['wseb_test_options'][ $key ] = $value; return true; }
function register_activation_hook() {}
function add_action( $hook = '', $callback = null ) { $GLOBALS['wseb_test_actions'][] = $hook; }
function add_filter() {}
function register_rest_route( $namespace, $route, $args ) { $GLOBALS['wseb_test_routes'][] = [ $route, $args ]; }
function rest_ensure_response( $data ) { return $data; }
