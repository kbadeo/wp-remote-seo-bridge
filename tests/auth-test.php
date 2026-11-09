<?php
/**
 * Auth regression test for the REST routes. Runs without WordPress: it stubs the few WordPress
 * functions the plugin touches (tests/wp-stubs.php), loads the plugin, and calls each route's
 * `permission_callback` with seven request cases.
 *
 * Covers the permission check only. It never runs a route handler, activation, the admin page or
 * the exporter, and `add_action` is a recording stub, so no hook callbacks run.
 *
 * Run:  php tests/auth-test.php
 * Exits 0 when every check passes, 1 otherwise.
 */

require __DIR__ . '/wp-stubs.php';

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

    // Convention: the endpoint toggle key is the route path with dashes turned into underscores.
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


// A copy of the plugin with a module missing must stay inactive with an admin notice, not fatal.
// Runs in a child process because a fatal error would end this script.
$copy = sys_get_temp_dir() . '/wseb-guard-' . getmypid();
mkdir( $copy . '/includes', 0777, true );
copy( dirname( __DIR__ ) . '/wp-remote-seo-bridge.php', $copy . '/wp-remote-seo-bridge.php' );
foreach ( glob( dirname( __DIR__ ) . '/includes/*.php' ) as $module ) {
    if ( basename( $module ) !== 'helpers.php' ) copy( $module, $copy . '/includes/' . basename( $module ) );
}
$child = 'require ' . var_export( __DIR__ . '/wp-stubs.php', true ) . ';'
       . 'require ' . var_export( $copy . '/wp-remote-seo-bridge.php', true ) . ';'
       . 'echo in_array( "admin_notices", $GLOBALS["wseb_test_actions"], true ) ? "NOTICE" : "NO-NOTICE";'
       . 'echo function_exists( "wseb_register_routes" ) ? " LOADED" : " INACTIVE";';
exec( escapeshellarg( PHP_BINARY ) . ' -d display_errors=0 -r ' . escapeshellarg( $child ) . ' 2>&1', $out, $code );
$report( $code === 0 && implode( '', $out ) === 'NOTICE INACTIVE', 'missing includes/helpers.php: plugin stays inactive and shows an admin notice (got: ' . implode( '', $out ) . ')' );
foreach ( glob( $copy . '/includes/*.php' ) as $f ) unlink( $f );
rmdir( $copy . '/includes' ); unlink( $copy . '/wp-remote-seo-bridge.php' ); rmdir( $copy );

echo $failures === 0 ? "OK: $checks checks passed\n" : "$failures of $checks checks FAILED\n";
exit( $failures === 0 ? 0 : 1 );
