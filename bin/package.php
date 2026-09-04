<?php
/**
 * Zip a built plugin directory, honoring .distignore.
 * Usage: php bin/package.php <source_dir> <output_zip> <distignore_path>
 */

if ( $argc < 4 ) {
	fwrite( STDERR, "Usage: php package.php <source_dir> <output_zip> <distignore_path>\n" );
	exit( 1 );
}

[ , $source_dir, $output_zip, $distignore_path ] = $argv;

$source_dir = rtrim( $source_dir, '/' );

if ( ! is_dir( $source_dir ) ) {
	fwrite( STDERR, "Source directory not found: {$source_dir}\n" );
	exit( 1 );
}

$patterns = array();
if ( is_file( $distignore_path ) ) {
	foreach ( file( $distignore_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
		$line = trim( $line );
		if ( '' === $line || 0 === strpos( $line, '#' ) ) {
			continue;
		}
		$patterns[] = trim( $line, '/' );
	}
}

function wpbf_is_ignored( string $relative_path, array $patterns ): bool {
	foreach ( $patterns as $pattern ) {
		if ( $relative_path === $pattern || 0 === strpos( $relative_path, $pattern . '/' ) ) {
			return true;
		}
		if ( fnmatch( $pattern, basename( $relative_path ) ) ) {
			return true;
		}
	}
	return false;
}

if ( file_exists( $output_zip ) ) {
	unlink( $output_zip );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $output_zip, ZipArchive::CREATE ) ) {
	fwrite( STDERR, "Could not create zip: {$output_zip}\n" );
	exit( 1 );
}

$plugin_slug = basename( $source_dir );

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $source_dir, FilesystemIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::SELF_FIRST
);

$file_count = 0;

foreach ( $iterator as $item ) {
	$relative_path = substr( $item->getPathname(), strlen( $source_dir ) + 1 );

	if ( wpbf_is_ignored( $relative_path, $patterns ) ) {
		continue;
	}

	$archive_path = $plugin_slug . '/' . $relative_path;

	if ( $item->isDir() ) {
		$zip->addEmptyDir( $archive_path );
	} else {
		$zip->addFile( $item->getPathname(), $archive_path );
		++$file_count;
	}
}

$zip->close();

echo "Packaged {$file_count} file(s) into {$output_zip}\n";
