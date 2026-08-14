<?php
declare(strict_types=1);

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
		$files[ $path ] = hash_file( 'sha256', $absolute );
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
		$files[ $relative ] = hash_file( 'sha256', $file->getPathname() );
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
) . PHP_EOL;
$target = $root . '/build-manifest.json';

if ( $check ) {
	$current = is_file( $target ) ? (string) file_get_contents( $target ) : '';
	if ( ! hash_equals( hash( 'sha256', $manifest ), hash( 'sha256', $current ) ) ) {
		fwrite( STDERR, "build-manifest.json is stale; run php bin/generate-build-manifest.php and commit the result." . PHP_EOL );
		exit( 1 );
	}
	echo "Build manifest is current." . PHP_EOL;
	exit( 0 );
}

file_put_contents( $target, $manifest );
echo "Updated build-manifest.json" . PHP_EOL;
