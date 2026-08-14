<?php
define( 'ABSPATH', __DIR__ . '/' );

function strip_shortcodes( string $content ): string {
	return preg_replace( '/\[[^\]]+\]/', ' ', $content ) ?? $content;
}

function wp_strip_all_tags( string $content ): string {
	return strip_tags( $content );
}

require_once dirname( __DIR__ ) . '/includes/class-indexer.php';

$method = new ReflectionMethod( Site_Agent_Indexer::class, 'readable_post_content' );
$method->setAccessible( true );

$fixture = '<header><nav>WP MAISYHomeGuideSite Agent</nav></header>'
	. '<main><div>First readable block.</div><section>The homepage helps visitors choose the right next step.</section></main>'
	. '<footer>WP MAISYHomeGuideSite Agent</footer>';
$actual = $method->invoke( null, $fixture );

if ( str_contains( $actual, 'WP MAISYHomeGuide' ) ) {
	fwrite( STDERR, "FAIL navigation or footer boilerplate remained\n" );
	exit( 1 );
}
if ( ! str_contains( $actual, 'First readable block. The homepage helps visitors' ) ) {
	fwrite( STDERR, "FAIL block boundaries were not preserved: {$actual}\n" );
	exit( 1 );
}

echo "PASS indexed post content preserves readable boundaries and removes navigation boilerplate\n";
