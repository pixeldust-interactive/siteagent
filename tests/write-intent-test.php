<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
define( 'SITE_AGENT_VERSION', '0.3.0-test' );

function __( string $text, string $domain = '' ): string { return $text; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }

require dirname( __DIR__ ) . '/includes/class-action-registry.php';
require dirname( __DIR__ ) . '/includes/class-agent.php';

$method = new ReflectionMethod( Site_Agent_Agent::class, 'is_explicit_write_request' );
$method->setAccessible( true );

$cases = array(
	'change WordPress tagline to Test' => true,
	'Create a temporary draft post titled Test' => true,
	'Could you deactivate akismet/akismet.php?' => true,
	'I want you to update post 42' => true,
	'I would like to add a section to the home page' => true,
	'What changed on my site this week?' => false,
	'What could break if I remove a plugin?' => false,
	'Which pages need attention?' => false,
);

$failures = 0;
foreach ( $cases as $prompt => $expected ) {
	$actual = (bool) $method->invoke( null, $prompt );
	if ( $actual !== $expected ) {
		$failures++;
		fwrite( STDERR, 'FAIL write-intent classification: ' . $prompt . PHP_EOL );
	} else {
		echo 'PASS write-intent classification: ' . $prompt . PHP_EOL;
	}
}

$prompt_method = new ReflectionMethod( Site_Agent_Agent::class, 'system_prompt' );
$prompt_method->setAccessible( true );
$system = (string) $prompt_method->invoke( null, 'site_administrator' );
foreach ( array( 'write_actions', 'Exact write-action argument contracts', 'blogdescription', 'option' ) as $needle ) {
	if ( ! str_contains( $system, $needle ) ) {
		$failures++;
		fwrite( STDERR, 'FAIL system prompt is missing: ' . $needle . PHP_EOL );
	} else {
		echo 'PASS system prompt contains: ' . $needle . PHP_EOL;
	}
}

$homepage_method = new ReflectionMethod( Site_Agent_Agent::class, 'is_homepage_change_request' );
$homepage_method->setAccessible( true );
$homepage_cases = array(
	'I would like to add a section to the home page' => true,
	'Please improve the homepage content' => true,
	'Create a new section on the front page' => true,
	'What is currently on the home page?' => false,
	'Add a section to the contact page' => false,
);
foreach ( $homepage_cases as $prompt => $expected ) {
	$actual = (bool) $homepage_method->invoke( null, $prompt );
	if ( $actual !== $expected ) {
		$failures++;
		fwrite( STDERR, 'FAIL homepage intent classification: ' . $prompt . PHP_EOL );
	} else {
		echo 'PASS homepage intent classification: ' . $prompt . PHP_EOL;
	}
}

$homepage_system = (string) $prompt_method->invoke( null, 'site_administrator', 'I would like to add a section to the home page' );
foreach (
	array(
		'configured homepage and its editing system',
		'What should the new section help visitors understand or do?',
		'help visitors understand what you offer and choose the right next step',
		'without a page ID or URL',
		'below the introduction',
		'nothing changes until the user approves the exact preview',
	) as $needle
) {
	if ( ! str_contains( $homepage_system, $needle ) ) {
		$failures++;
		fwrite( STDERR, 'FAIL homepage guidance is missing: ' . $needle . PHP_EOL );
	} else {
		echo 'PASS homepage guidance contains: ' . $needle . PHP_EOL;
	}
}
if ( str_contains( $system, 'What should the new section help visitors understand or do?' ) ) {
	$failures++;
	fwrite( STDERR, 'FAIL homepage-only guidance leaked into unrelated conversations' . PHP_EOL );
} else {
	echo 'PASS homepage guidance stays scoped to homepage change conversations' . PHP_EOL;
}

$discovery_method = new ReflectionMethod( Site_Agent_Agent::class, 'ensure_discovery_read_calls' );
$discovery_method->setAccessible( true );
$discovery = (array) $discovery_method->invoke( null, array(), 'I would like to add a section to the home page' );
if ( 'site.search' !== ( $discovery[0]['name'] ?? '' )
	|| 12 !== ( $discovery[0]['args']['limit'] ?? 0 )
	|| ! str_contains( (string) ( $discovery[0]['args']['query'] ?? '' ), 'configured homepage' ) ) {
	$failures++;
	fwrite( STDERR, 'FAIL homepage discovery does not inject the bounded site search' . PHP_EOL );
} else {
	echo 'PASS homepage discovery injects the bounded site search' . PHP_EOL;
}

$existing = array( array( 'name' => 'site.search', 'args' => array( 'query' => 'home' ) ) );
$deduplicated = (array) $discovery_method->invoke( null, $existing, 'Improve the homepage content' );
if ( 1 !== count( $deduplicated ) || 'site.search' !== ( $deduplicated[0]['name'] ?? '' ) ) {
	$failures++;
	fwrite( STDERR, 'FAIL homepage discovery duplicates an existing site search' . PHP_EOL );
} else {
	echo 'PASS homepage discovery preserves an existing site search' . PHP_EOL;
}

$unrelated = array( array( 'name' => 'plugins.list', 'args' => array() ) );
$unchanged = (array) $discovery_method->invoke( null, $unrelated, 'Which plugins are active?' );
if ( $unrelated !== $unchanged ) {
	$failures++;
	fwrite( STDERR, 'FAIL homepage discovery changed an unrelated read plan' . PHP_EOL );
} else {
	echo 'PASS homepage discovery leaves unrelated read plans unchanged' . PHP_EOL;
}

$live_answer_method = new ReflectionMethod( Site_Agent_Agent::class, 'authoritative_live_answer' );
$live_answer_method->setAccessible( true );
$live_answer = $live_answer_method->invoke( null, 'What version of Site Agent is installed right now?' );
if ( ! is_array( $live_answer ) || ! str_contains( (string) ( $live_answer['answer'] ?? '' ), SITE_AGENT_VERSION ) || array( 'plugins.list' ) !== ( $live_answer['read_tools'] ?? array() ) ) {
	$failures++;
	fwrite( STDERR, 'FAIL current Site Agent version does not use authoritative live status' . PHP_EOL );
} else {
	echo 'PASS current Site Agent version uses authoritative live status' . PHP_EOL;
}

$not_live = $live_answer_method->invoke( null, 'What changed on my site this week?' );
if ( null !== $not_live ) {
	$failures++;
	fwrite( STDERR, 'FAIL unrelated prompts are incorrectly short-circuited' . PHP_EOL );
} else {
	echo 'PASS unrelated prompts keep the normal planning path' . PHP_EOL;
}

exit( $failures > 0 ? 1 : 0 );
