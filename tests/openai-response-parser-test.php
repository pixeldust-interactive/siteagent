<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ );

function __( string $text, string $domain = '' ): string { return $text; }
function sanitize_key( string $key ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $key ) ?? '' ); }
function sanitize_text_field( string $text ): string { return trim( strip_tags( $text ) ); }
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }

final class WP_Error {
	private array $data = array();
	public function __construct( private string $code, private string $message, mixed $data = null ) { if ( null !== $data ) { $this->data[ $code ] = $data; } }
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): mixed { return $this->data[ $this->code ] ?? null; }
	public function add_data( mixed $data ): void { $this->data[ $this->code ] = $data; }
}

final class Site_Agent_Redactor {
	public static function redact_string( string $text ): string { return $text; }
	public static function redact( mixed $value ): mixed { return $value; }
}

require dirname( __DIR__ ) . '/includes/class-openai-client.php';

$valid = '{"answer":"Version 0.2.1 is installed.","read_calls":[],"write_actions":[],"needs_clarification":false,"clarification_question":""}';
$parse = new ReflectionMethod( Site_Agent_OpenAI_Client::class, 'parse_response' );
$parse->setAccessible( true );

$cases = array(
	'valid structured output'   => array( response( $valid ), false, false ),
	'fenced JSON'               => array( response( "```json\n{$valid}\n```" ), false ),
	'leading and trailing text' => array( response( "Planning result:\n{$valid}\nEnd." ), false ),
	'read plan without answer'  => array( response( '{"read_calls":[{"name":"site.summary","args":{}}],"write_actions":[],"needs_clarification":false,"clarification_question":""}' ), false, false ),
	'read plan as final answer' => array( response( '{"read_calls":[{"name":"site.summary","args":{}}],"write_actions":[],"needs_clarification":false,"clarification_question":""}' ), 'provider_schema_error', true ),
	'aliased read tool'         => array( response( '{"read_calls":[{"tool":"site.summary","arguments":"{}"}],"write_actions":[],"needs_clarification":false,"clarification_question":""}' ), false, false, 'site.summary' ),
	'named read tool map'       => array( response( '{"read_calls":[{"site.summary":{}}],"write_actions":[],"needs_clarification":false,"clarification_question":""}' ), false, false, 'site.summary' ),
	'write plan without answer' => array( response( '{"read_calls":[],"write_actions":[{"name":"option.update","args":{"name":"blogdescription","value":"Test"}}],"needs_clarification":false,"clarification_question":""}' ), false ),
	'malformed JSON'            => array( response( '{"answer":' ), 'provider_parse_error' ),
	'missing fields'            => array( response( '{"answer":"Hello"}' ), 'provider_schema_error' ),
	'missing answer without plan'=> array( response( '{"read_calls":[],"write_actions":[],"needs_clarification":false,"clarification_question":""}' ), 'provider_schema_error' ),
	'null answer with read plan'=> array( response( '{"answer":null,"read_calls":[{"name":"site.summary","args":{}}],"write_actions":[],"needs_clarification":false,"clarification_question":""}' ), 'provider_schema_error' ),
	'empty answer'              => array( response( '{"answer":"","read_calls":[],"write_actions":[],"needs_clarification":false,"clarification_question":""}' ), 'provider_schema_error' ),
	'clarification question'    => array( response( '{"answer":"","read_calls":[],"write_actions":[],"needs_clarification":true,"clarification_question":"Which page should I update?"}' ), false ),
	'missing clarification'     => array( response( '{"answer":"I need more detail.","read_calls":[],"write_actions":[],"needs_clarification":true,"clarification_question":""}' ), 'provider_schema_error' ),
	'provider refusal'          => array( array( 'status' => 'completed', 'output' => array( array( 'content' => array( array( 'type' => 'refusal', 'refusal' => 'not included in diagnostics' ) ) ) ) ), 'provider_refusal' ),
	'incomplete response'       => array( array( 'status' => 'incomplete', 'incomplete_details' => array( 'reason' => 'max_output_tokens' ), 'output' => array() ), 'provider_incomplete_response' ),
	'provider failure'          => array( array( 'status' => 'failed', 'error' => array( 'message' => 'The model failed to generate a response.' ), 'output' => array() ), 'provider_generation_failed' ),
);

$failures = 0;
foreach ( $cases as $name => $case ) {
	list( $fixture, $expected_error ) = $case;
	$require_answer = $case[2] ?? false;
	$result = $parse->invoke( null, $fixture, $require_answer );
	$actual_error = is_wp_error( $result ) ? $result->get_error_code() : false;
	$expected_call = $case[3] ?? null;
	$actual_call = ! is_wp_error( $result ) ? ( $result['read_calls'][0]['name'] ?? null ) : null;
	if ( $actual_error !== $expected_error || ( null !== $expected_call && $actual_call !== $expected_call ) ) {
		$failures++;
		fwrite( STDERR, "FAIL {$name}: expected error/call " . var_export( array( $expected_error, $expected_call ), true ) . ', got ' . var_export( array( $actual_error, $actual_call ), true ) . PHP_EOL );
	} else {
		echo "PASS {$name}" . PHP_EOL;
	}
}

$enforce = new ReflectionMethod( Site_Agent_OpenAI_Client::class, 'enforce_write_plan' );
$enforce->setAccessible( true );
$missing_plan = $enforce->invoke(
	null,
	array( 'answer' => 'I would update the setting.', 'write_actions' => array(), 'needs_clarification' => false ),
	true
);
if ( ! is_wp_error( $missing_plan ) || 'provider_write_plan_missing' !== $missing_plan->get_error_code() ) {
	$failures++;
	fwrite( STDERR, "FAIL explicit write request accepts prose-only plan" . PHP_EOL );
} else {
	echo "PASS explicit write request rejects prose-only plan" . PHP_EOL;
}
$clarification = $enforce->invoke(
	null,
	array( 'answer' => '', 'write_actions' => array(), 'needs_clarification' => true ),
	true
);
if ( is_wp_error( $clarification ) ) {
	$failures++;
	fwrite( STDERR, "FAIL explicit write request rejects clarification" . PHP_EOL );
} else {
	echo "PASS explicit write request permits clarification" . PHP_EOL;
}

exit( $failures > 0 ? 1 : 0 );

function response( string $text ): array {
	return array(
		'status' => 'completed',
		'output' => array(
			array(
				'type' => 'message',
				'content' => array( array( 'type' => 'output_text', 'text' => $text ) ),
			),
		),
	);
}
