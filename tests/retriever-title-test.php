<?php
define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__ ) . '/includes/class-retriever.php';

$method = new ReflectionMethod( Site_Agent_Retriever::class, 'display_title' );
$method->setAccessible( true );

$cases = array(
	'AI Research &#038; Publish' => 'AI Research & Publish',
	'AI Research &amp; Publish' => 'AI Research & Publish',
	'Maisy&#8217;s Guide' => 'Maisy’s Guide',
	'Maisy&rsquo;s Guide' => 'Maisy’s Guide',
	'R&D' => 'R&D',
	'AI Research &amp;#038; Publish' => 'AI Research &#038; Publish',
);

foreach ( $cases as $stored => $expected ) {
	$actual = $method->invoke( null, $stored );
	if ( $expected !== $actual ) {
		fwrite( STDERR, "Title decode failed for {$stored}: expected {$expected}, got {$actual}\n" );
		exit( 1 );
	}
}

echo "PASS Knowledge display titles decode exactly one entity layer\n";
