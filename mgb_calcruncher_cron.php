<?php
namespace MGB\MGBCalCruncher;
/**
 * Run cron for this project right now
 * In essence, trigger the cron jobs secheduled for this project
 * This is going to be called either from a single project OR as NOAUTH from the cron process itself
 */

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use REDCap;
use Calculate;
use Records;
use Logging;
use Project;

if (is_null($module)) { exit(); }
if ( strpos(get_class($module),"MGBCalCruncher") == false ) { exit(); }

// Get the PID
if ( !isset($_GET['pid']) || !is_numeric($_GET['pid']) ) exit();
$pid = trim(strip_tags(html_entity_decode($_GET['pid'], ENT_QUOTES)));
$pid = htmlspecialchars($pid, ENT_QUOTES, 'UTF-8'); // Ensure it is encoded once, before using it
if ( !is_numeric($pid) ) {
    exit(); // Second check We need a PID that is numeric
}


if ( isset($_GET['ui']) && is_numeric( trim(strip_tags(html_entity_decode($_GET['ui'], ENT_QUOTES))) ) ) {
    // include the header
    require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
}

$forcerun = 0;
if ( isset($_GET['forcerun']) && is_numeric( trim(strip_tags(html_entity_decode($_GET['forcerun'], ENT_QUOTES))) ) ) {
    $forcerun=1;
}

global $Proj;
if ( isset($Proj) && isset($Proj->project_id) && is_numeric($Proj->project_id) ) {
    // This is a UI run
}
else {
    // This is a cron run
    $Proj = new Project($pid); // Initialize the PID object
}

$framework = ExternalModules::getFrameworkInstance($module->PREFIX);

// Check to see if the module is enabled on this project
$projects_with_em_enabled = $framework->getProjectsWithModuleEnabled();
if ( !in_array($Proj->project_id, $projects_with_em_enabled) )
    exit(1); // Module is not enabled on this project

$cron_enabled       = $module->getProjectSetting('calcruncher-cron-enabled');
if ( isset ($cron_enabled) && ($cron_enabled == 1 || $cron_enabled == true) ) {
    // Cron is enabled on this project
    $cron_frequency     = $module->getProjectSetting('calcruncher-cron-frequency');
    $cron_records_setting = $module->getProjectSetting('calcruncher-cron-records');

    if ( is_null($cron_frequency) || !is_numeric($cron_frequency)) {
        $cron_frequency = 1; // this is the default - enabled and running once per day at 6 AM
    }
    else {
        if ( $cron_frequency != 1 && $cron_frequency != 2)
            $cron_frequency = 1; // Somehow we have an unexpected value here - default it to 1 - enabled and running once per day at 6 AM
    }

    if ( is_null($cron_records_setting) || !is_numeric($cron_records_setting)) {
        $cron_records_setting = 1; // Default to ALL RECORDS
    }
    else {
        if ( $cron_records_setting != 1 && $cron_records_setting != 2 && $cron_records_setting != 3 )
            $cron_records_setting = 1; // Somehow we have an unexpected value here - default to 1
    }

    // Determine if we should be running right now
    $should_we_run = false;
    $today = new \DateTime();
    $current_hour = $today->format('G'); // 24 hour format - no leading zero
    if ( $cron_frequency == 1 && $current_hour == 6 ) {
        // we're in the 6AM hour - time to run!
        $should_we_run = true;
    }
    elseif ( $cron_frequency == 2 && ($current_hour == 6 || $current_hour == 18 ) ) {
        // we're in the 6 AM or 6 PM (18) hour - time to run!
        $should_we_run = true;
    }
    else {
        $should_we_run = false; // It's not time to run
    }

    // Force Run!
    if ( $forcerun == 1 )
        $should_we_run = true; // Force RUN regardless of time

    if ( $should_we_run ) {
        switch ( $cron_records_setting ) {
            case 1: // Run for ALL RECORDS
                // Get all records as an array
                $records = Records::getRecordList($pid);
                $module->crunch_calcs_multiple($pid, $records);

                break;
            case 2: // Run for the last 24 hours
                // Get all records from the last 24 hours as an array
                $records = $module->get_recently_updated_records( $pid, 1);
                $module->crunch_calcs_multiple($pid, $records);

                break;
            case 3: // Run for the last 7 days
                // Get all records for the last 7 days as an array
                $records = $module->get_recently_updated_records( $pid, 7);
                $module->crunch_calcs_multiple($pid, $records);

                break;
            default:
                // Log Error - we should never get here.. like it should really be impossible to get here
                \REDCap::logEvent($this->PREFIX . " (EM) error - incorrect setting for Cron Records!", '', '', null, null, $pid);
                break;
        }
    }
}

if ( isset($_GET['ui']) && is_numeric( trim(strip_tags(html_entity_decode($_GET['ui'], ENT_QUOTES))) ) ) {
    // include the header
    require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
}