<?php
// Loads MediaWiki the way maintenance/run.php does, but runs MwCli\MwMaintenance
// instead of a script from maintenance/. Required from bin/mw at file scope.

use MediaWiki\Maintenance\MaintenanceRunner;
use MediaWiki\Settings\SettingsBuilder;

/** @var string $mwPath */
/** @var MwCli\Runner $mwCli */

putenv( 'MW_INSTALL_PATH=' . $mwPath );
require_once "$mwPath/maintenance/Maintenance.php";
require_once "$mwPath/maintenance/includes/MaintenanceRunner.php";
require_once "$mwPath/maintenance/includes/MaintenanceParameters.php";

define( 'MEDIAWIKI', true );
$IP = wfDetectInstallPath();
require_once "$IP/includes/AutoLoader.php";

MwCli\MwMaintenance::$cli = $mwCli;
// phpcs:ignore MediaWiki.NamingConventions.ValidGlobalName.allowedPrefix -- same name as run.php
$runner = new MaintenanceRunner();
$runner->initForClass( MwCli\MwMaintenance::class, [ 'mw' ] );
$runner->defineSettings();

if ( !defined( 'MW_FINAL_SETUP_CALLBACK' ) ) {
	function wfMwCliSetup( SettingsBuilder $settingsBuilder ): void {
		$GLOBALS['runner']->setup( $settingsBuilder );
	}

	define( 'MW_FINAL_SETUP_CALLBACK', 'wfMwCliSetup' );
}

require_once "$IP/includes/Setup.php";

exit( $runner->run() ? 0 : max( 1, MwCli\MwMaintenance::$code ) );
