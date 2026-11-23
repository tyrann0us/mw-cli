<?php
// Merges the raw pcov files from the Behat run into build/behat-clover.xml.

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;
use SebastianBergmann\CodeCoverage\Driver\Driver;
use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\Report\Clover;

$root = dirname( __DIR__, 2 );
require "$root/vendor/autoload.php";

$filter = new Filter();
$filter->includeFiles( [ "$root/bin/mw", ...glob( "$root/src/{,*/}*.php", GLOB_BRACE ) ] );

// The data was collected in the container; this driver only exists to satisfy the constructor.
$coverage = new CodeCoverage( new class extends Driver {
	public function nameAndVersion(): string {
		return 'pcov (container)';
	}

	public function start(): void {
	}

	public function stop(): RawCodeCoverageData {
		return RawCodeCoverageData::fromXdebugWithoutPathCoverage( [] );
	}
}, $filter );

foreach ( glob( "$root/build/coverage/*.json" ) as $file ) {
	$data = [];
	foreach ( json_decode( file_get_contents( $file ), true ) as $path => $lines ) {
		$data[$root . substr( $path, strlen( '/mw-cli' ) )] = $lines;
	}
	$coverage->append( RawCodeCoverageData::fromXdebugWithoutPathCoverage( $data ), basename( $file ) );
}

( new Clover() )->process( $coverage, "$root/build/behat-clover.xml" );
