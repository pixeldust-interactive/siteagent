<?php
declare(strict_types=1);

function site_agent_manifest_hash( string $path ): string {
	$contents = file_get_contents( $path );
	if ( false === $contents ) {
		throw new RuntimeException( 'Could not read manifest source: ' . $path );
	}

	return hash( 'sha256', str_replace( array( "\r\n", "\r" ), "\n", $contents ) );
}

$root = dirname( __DIR__ );
$check = in_array( '--check', $argv, true );
$version_source = (string) file_get_contents( $root . '/site-agent.php' );
if ( ! preg_match( '/^\s*\*\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)/m', $version_source, $match ) ) {
	fwrite( STDERR, "Could not read the Site Agent version." . PHP_EOL );
	exit( 1 );
}

$paths = array( 'README.md', 'assets', 'includes', 'readme.txt', 'site-agent.php', 'uninstall.php' );
$files = array();
foreach ( $paths as $path ) {
	$absolute = $root . '/' . $path;
	if ( is_file( $absolute ) ) {
		$files[ $path ] = site_agent_manifest_hash( $absolute );
		continue;
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $absolute, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() ) {
			continue;
		}
		$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
		$files[ $relative ] = site_agent_manifest_hash( $file->getPathname() );
	}
}
ksort( $files, SORT_STRING );

$manifest = json_encode(
	array(
		'schema'     => 1,
		'release_id' => 'site-agent-' . $match[1],
		'version'    => $match[1],
		'files'      => $files,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . "\n";
$target = $root . '/build-manifest.json';

if ( $check ) {
	$current = is_file( $target ) ? (string) file_get_contents( $target ) : '';
	$current_data = json_decode( $current, true );
	$expected_data = json_decode( $manifest, true );
	if ( ! is_array( $current_data ) || $current_data != $expected_data ) {
		fwrite( STDERR, "build-manifest.json is stale; run php bin/generate-build-manifest.php and commit the result." . PHP_EOL );
		exit( 1 );
	}
	echo "Build manifest is current." . PHP_EOL;
	exit( 0 );
}

file_put_contents( $target, $manifest );
echo "Updated build-manifest.json" . PHP_EOL;
