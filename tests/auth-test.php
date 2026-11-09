<?php
/**
 * Auth regression test for the REST routes. Runs without WordPress: it stubs the few WordPress
 * functions the plugin touches, loads the plugin, and checks what each route lets through.
 *
 * Run:  php tests/auth-test.php
 * Exits 0 when every check passes, 1 otherwise.
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

function get_option( $key, $default = false ) { return $GLOBALS['wseb_test_options'][ $key ] ?? $default; }
function update_option( $key, $value ) { $GLOBALS['wseb_test_options'][ $key ] = $value; return true; }
function register_activation_hook() {}
function add_action() {}
function add_filter() {}
function register_rest_route( $namespace, $route, $args ) { $GLOBALS['wseb_test_routes'][] = [ $route, $args ]; }
function rest_ensure_response( $data ) { return $data; }

require dirname( __DIR__ ) . '/wp-remote-seo-bridge.php';
wseb_register_routes();

const TEST_TOKEN = 'secret-token-123';

/**
 * Settings and request headers for each scenario, and what every route must answer.
 * 'allow' means the request gets past the permission check; otherwise the expected 401 message.
 */
$scenarios = [
    'no token'            => [ 'headers' => [],                                'settings' => [ 'token' => TEST_TOKEN ],                                               'expect' => 'Unauthorized' ],
    'wrong token'         => [ 'headers' => [ 'X-WSEB-Token' => 'nope' ],      'settings' => [ 'token' => TEST_TOKEN ],                                               'expect' => 'Unauthorized' ],
    'right token'         => [ 'headers' => [ 'X-WSEB-Token' => TEST_TOKEN ],  'settings' => [ 'token' => TEST_TOKEN ],                                               'expect' => 'allow' ],
    'legacy header alias' => [ 'headers' => [ 'X-SEO-Bridge-Token' => TEST_TOKEN ], 'settings' => [ 'token' => TEST_TOKEN ],                                        'expect' => 'allow' ],
    'no token configured' => [ 'headers' => [ 'X-WSEB-Token' => TEST_TOKEN ],  'settings' => [],                                                                     'expect' => 'Unauthorized' ],
    'endpoint disabled'   => [ 'headers' => [ 'X-WSEB-Token' => TEST_TOKEN ],  'settings' => [ 'token' => TEST_TOKEN ], 'enable' => false,                          'expect' => 'Endpoint disabled' ],
    'expired token'       => [ 'headers' => [ 'X-WSEB-Token' => TEST_TOKEN ],  'settings' => [ 'token' => TEST_TOKEN, 'expiry_hours' => 1, 'token_created' => time() - 7200 ], 'expect' => 'Unauthorized' ],
];

$failures = 0;
$checks   = 0;
$report   = function ( $ok, $label ) use ( &$failures, &$checks ) {
    $checks++;
    if ( ! $ok ) { $failures++; echo "FAIL  $label\n"; }
};

$report( count( $GLOBALS['wseb_test_routes'] ) === 13, 'plugin registers 13 REST routes (update this number when you add one)' );

foreach ( $GLOBALS['wseb_test_routes'] as [ $route, $args ] ) {
    $label = $route . ' ' . $args['methods'];
    $permission = $args['permission_callback'] ?? null;

    $report( $permission instanceof Closure, "$label: permission_callback is the shared check, not __return_true" );
    if ( ! ( $permission instanceof Closure ) ) continue;

    $key = trim( str_replace( '-', '_', $route ), '/' );
    foreach ( $scenarios as $name => $scenario ) {
        $settings = $scenario['settings'];
        if ( $scenario['enable'] ?? true ) $settings[ 'ep_' . $key ] = 1;
        $GLOBALS['wseb_test_options'][ WSEB_OPTION ] = $settings;

        $result = $permission( new WP_REST_Request( $scenario['headers'] ) );
        if ( $scenario['expect'] === 'allow' ) {
            $report( $result === true, "$label [$name]: should be allowed" );
        } else {
            $ok = $result instanceof WP_Error && $result->data['status'] === 401 && $result->message === $scenario['expect'];
            $report( $ok, "$label [$name]: should be 401 \"{$scenario['expect']}\"" );
        }
    }
}

echo $failures === 0 ? "OK: $checks checks passed\n" : "$failures of $checks checks FAILED\n";
exit( $failures === 0 ? 0 : 1 );
