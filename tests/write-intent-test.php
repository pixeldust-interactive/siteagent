<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
define( 'SITE_AGENT_VERSION', '0.2.7-test' );

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
