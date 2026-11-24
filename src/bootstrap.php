<?php

declare(strict_types=1);

// Loads MediaWiki the way maintenance/run.php does, but runs MwCli\MwMaintenance
// instead of a script from maintenance/. Required from bin/mw at file scope.

// bin/mw sets $mwPath and $mwCli before it requires this file.
// phpcs:disable VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable

use MediaWiki\Maintenance\MaintenanceRunner;

/** @var string $mwPath */
/** @var MwCli\Runner $mwCli */

putenv('MW_INSTALL_PATH=' . $mwPath);
require_once "$mwPath/maintenance/Maintenance.php";
require_once "$mwPath/maintenance/includes/MaintenanceRunner.php";
require_once "$mwPath/maintenance/includes/MaintenanceParameters.php";

// phpcs:ignore Syde.PHP.DisallowTopLevelDefine.Found -- run.php defines it at runtime, too.
define('MEDIAWIKI', true);
// phpcs:ignore Syde.NamingConventions -- MediaWiki's own global name.
$IP = wfDetectInstallPath();
require_once "$IP/includes/AutoLoader.php";

MwCli\MwMaintenance::useRunner($mwCli);
$runner = new MaintenanceRunner();
$runner->initForClass(MwCli\MwMaintenance::class, ['mw']);
$runner->defineSettings();

if (!defined('MW_FINAL_SETUP_CALLBACK')) {
    define('MW_FINAL_SETUP_CALLBACK', 'MwCli\MwMaintenance::setupCallback');
}

require_once "$IP/includes/Setup.php";

exit($runner->run() ? 0 : max(1, MwCli\MwMaintenance::exitCode()));
