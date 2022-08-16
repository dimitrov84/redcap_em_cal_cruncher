<?php
namespace MGB\MGBCalCruncher;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use REDCap;
use Project;
use DataExport;

require APP_PATH_DOCROOT . "ControlCenter/header.php";
?>

    <script type="text/javascript" >
        function run_cruncher_cron(url, id, pid_title) {
            $("#btn_cron_"+id).prop("disabled",true);
            $.ajax({
                url: url,
                error: function(){
                    $("#crunch_status").html("CRON Job Submitted for "+pid_title+"! Check project logs for details.");
                    $("#crunch_status").css("display","block");

                    $("#btn_cron_"+id).prop("disabled",false);
                },
                success: function(){
                    $("#crunch_status").html("CRON Job Submitted for "+pid_title+"! Check project logs for details.");
                    $("#crunch_status").css("display","block");

                    $("#btn_cron_"+id).prop("disabled",false);
                },
               timeout: 5000,
            });
        }
    </script>

<div class="container">
    <h4>
        <i class="fas fa-cat"></i> <?php echo $module->getModuleName() ?> EM Admin Screen
    </h4>
    <p>
        The purpose of this external module is to automatically re-run and re-save the calculations on Calucated fields and fields that employ the @CAL* action tag.</br>
        This module is useful for projects that have calculated fields that you would like to have updated frequently without having to manually run Rule H.</br></br>

        This screen shows what projects the module is enabled on and allows you to trigger their "cron" job on-demand
    </p>
    <hr>
    <div id="crunch_status" name="crunch_status" class="green" style="max-width: 800px; padding: 15px 25px; margin: 20px 0px; text-align: center; display: none">
    </div>
<?php
// Loop through all of the projects that have this module enabled
$framework = \ExternalModules\ExternalModules::getFrameworkInstance($module->PREFIX);
$projects = $framework->getProjectsWithModuleEnabled();

if (count($projects) > 0) {
    // Loop throught the projects and call the CRON endpoint
    print "<table class='dataTable cell-border' >";
    print "<thead>
<tr>
<th>Project Name</th>
<th>CRON Configuration</th>
<th>ACTIONS</th>
</tr>
</thead>
<tbody>";
    $i=0;
    foreach ($projects as $project_id) {

        $project = new Project($project_id);
        $cron_enabled           = \ExternalModules\ExternalModules::getProjectSetting($module->PREFIX,$project_id,'calcruncher-cron-enabled');
        $cron_frequency         = \ExternalModules\ExternalModules::getProjectSetting($module->PREFIX,$project_id, 'calcruncher-cron-frequency');
        $cron_records_setting   = \ExternalModules\ExternalModules::getProjectSetting($module->PREFIX,$project_id, 'calcruncher-cron-records');

        // Enabled?
        if ( isset ($cron_enabled) && ($cron_enabled == 1 || $cron_enabled == true) ) {
            $cron_enabled = true;
        }
        else {
            $cron_enabled = false;
        }

        // Frequency
        if ( is_null($cron_frequency) || !is_numeric($cron_frequency)) {
            $cron_frequency = 1; // this is the default - enabled and running once per day at 6 AM
        }
        else {
            if ( $cron_frequency != 1 && $cron_frequency != 2)
                $cron_frequency = 1; // Somehow we have an unexpected value here - default it to 1 - enabled and running once per day at 6 AM
        }

        // What records
        if ( is_null($cron_records_setting) || !is_numeric($cron_records_setting)) {
            $cron_records_setting = 1; // Default to ALL RECORDS
        }
        else {
            if ( $cron_records_setting != 1 && $cron_records_setting != 2 && $cron_records_setting != 3 )
                $cron_records_setting = 1; // Somehow we have an unexpected value here - default to 1
        }

        $crs = -1;
        switch ( $cron_records_setting ){
            case 1: $csr = "Run for ALL records"; break;
            case 2: $csr = "Run ONLY for records from the last 24 hours"; break;
            case 3: $csr = "Run ONLY for records from the last 7 days"; break;
        }

        $module_cron_url = \ExternalModules\ExternalModules::getUrl($module->PREFIX, 'mgb_calcruncher_cron.php', $project_id, true, false);
        $module_cron_url .= "&forcerun=1";

        $odd_even = $i % 2 == 0 ? 'odd' : 'even';
        print "<tr class='$odd_even'>";
        print "<td>".$project->project['app_title']."</td>";
        print "<td>".
            ($cron_enabled == true ?
            "<i class=\"fas fa-check-circle\"></i> Project CRON: Enabled</br>".
            "<span>Frequency: ".($cron_frequency == 1 ? "Runs once per day at 6 AM" : "Run twice per day 6 AM and 6 PM")."</span></br>".
            "<span>Records Setting: $csr</span></br>"
            :
            "<i class=\"fas fa-window-close\"></i> Project CRON: Disabled")
        ."</td>";
        print "<td>".
            "<button class=\"btn btn-primaryrc\" id='btn_cron_$i' onclick='run_cruncher_cron(\"".$module_cron_url."\", $i, \"".$project->project['app_title']."\")'>Run CRON</button>"
        ."</td>";
        print "</tr>";

        unset($project);
        $i++;
    }
    print "</tbody></table>";
}
else {
?>
    <h5>
        <i class="fas fa-business-time"></i> No projects are currently using this module!
    </h5>
<?php
}


require APP_PATH_DOCROOT . "ControlCenter/footer.php";