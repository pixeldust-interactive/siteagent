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
	'valid structured output'   => array( response( $valid ), false ),
	'fenced JSON'               => array( response( "```json\n{$valid}\n```" ), false ),
	'leading and trailing text' => array( response( "Planning result:\n{$valid}\nEnd." ), false ),
	'malformed JSON'            => array( response( '{"answer":' ), 'provider_parse_error' ),
	'missing fields'            => array( response( '{"answer":"Hello"}' ), 'provider_schema_error' ),
	'empty answer'              => array( response( '{"answer":"","read_calls":[],"write_actions":[],"needs_clarification":false,"clarification_question":""}' ), 'provider_schema_error' ),
	'clarification question'    => array( response( '{"answer":"I need the target page before proposing a change.","read_calls":[],"write_actions":[],"needs_clarification":true,"clarification_question":"Which page should I update?"}' ), false ),
	'missing clarification'     => array( response( '{"answer":"I need more detail.","read_calls":[],"write_actions":[],"needs_clarification":true,"clarification_question":""}' ), 'provider_schema_error' ),
	'provider refusal'          => array( array( 'status' => 'completed', 'output' => array( array( 'content' => array( array( 'type' => 'refusal', 'refusal' => 'not included in diagnostics' ) ) ) ) ), 'provider_refusal' ),
	'incomplete response'       => array( array( 'status' => 'incomplete', 'incomplete_details' => array( 'reason' => 'max_output_tokens' ), 'output' => array() ), 'provider_incomplete_response' ),
	'provider failure'          => array( array( 'status' => 'failed', 'error' => array( 'message' => 'The model failed to generate a response.' ), 'output' => array() ), 'provider_generation_failed' ),
);

$failures = 0;
foreach ( $cases as $name => $case ) {
	list( $fixture, $expected_error ) = $case;
	$result = $parse->invoke( null, $fixture );
	$actual_error = is_wp_error( $result ) ? $result->get_error_code() : false;
	if ( $actual_error !== $expected_error ) {
		$failures++;
		fwrite( STDERR, "FAIL {$name}: expected " . var_export( $expected_error, true ) . ', got ' . var_export( $actual_error, true ) . PHP_EOL );
	} else {
		echo "PASS {$name}" . PHP_EOL;
	}
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
