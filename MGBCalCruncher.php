<?php
namespace MGB\MGBCalCruncher;

use REDCap;
use Calculate;
use Records;
use Logging;
use Project;

class MGBCalCruncher extends \ExternalModules\AbstractExternalModule {
    /**
     * This should execute on
     * - redcap_survey_complete - for surveys
     * - redcap_save_record - for data entry
     */

    /**
     * Save Record function
     * Only do work here IF the form is being saved as a data entry form. Surveys will be handled by the redcap_survey_complete function
     * @param int $project_id
     * @param string|NULL $record
     * @param string $instrument
     * @param int $event_id
     * @param int|NULL $group_id
     * @param string|NULL $survey_hash
     * @param int|NULL $response_id
     * @param int|null $repeat_instance
     * @return void
     */
    public function redcap_save_record( int $project_id,  string $record = NULL, string $instrument, int $event_id, int $group_id = NULL,
                                        string $survey_hash = NULL, int $response_id = NULL, int $repeat_instance = null ) {
        // DO NOT execute on a survey screen
        if ( strpos(strtolower(PAGE), strtolower("surveys/index.php") ) !== false ) {
            // This is a survey page - do nothing
            return;
        }

        $this->crunch_calcs ( $project_id, $record , $instrument, $event_id, $repeat_instance  ) ;
    }


    /**
     * Handle the REDCap Survey Complete
     * @param $project_id
     * @param $record
     * @param $instrument
     * @param $event_id
     * @param $group_id
     * @param $survey_hash
     * @param $response_id
     * @param $repeat_instance
     * @return void
     */
    public function redcap_survey_complete ( $project_id, $record = NULL, $instrument, $event_id, $group_id = NULL, $survey_hash, $response_id = NULL, $repeat_instance = 1 ) {
        $this->crunch_calcs ( $project_id, $record , $instrument, $event_id, $repeat_instance  ) ;
    }

    /**
     * Handle the calculations that need to be performed
     * @return void
     */
    private function crunch_calcs ( $project_id, $record = NULL, $instrument, $event_id, $repeat_instance = 1 ) {
        global $Proj;

        // 1. Get the module configurations - what forms should we be running this against
        $selected_surveys_config = is_array($this->getProjectSetting('calcruncher-selected-surveys-form')) ? $this->getProjectSetting('calcruncher-selected-surveys-form') : array();
        $selected_surveys_status_configs = is_array($this->getProjectSetting('calcruncher-selected-surveys-form-status')) ? $this->getProjectSetting('calcruncher-selected-surveys-form-status') : array();
        $selected_surveys = array(); // This is a list of the de-duplicated forms
        $valid_surveys = array(); // These are the surveys that actually belong to this event
        $selected_surveys_status = array(); // The numerical status for the form - form_name => status (ex: additional_tests => 1   for UNVERIFIED)

        // Form a de-duplicated list of forms
        foreach ( $selected_surveys_config as $cfg_i => $cfg ) {
            $cfg = strtolower(trim(strip_tags(htmlspecialchars($cfg, ENT_QUOTES, 'UTF-8'))));

            if ( array_key_exists($cfg, $selected_surveys) )
                continue; // Don't do anything - the array key exists already

            if ( strlen($cfg)>0 ) {
                $selected_surveys[$cfg] = $cfg; // add it to an associative array so we can easily de-duplicate

                // See if we need to set a survey status for this form
                if ( isset($selected_surveys_status_configs[$cfg_i]) && !is_null($selected_surveys_status_configs[$cfg_i])) {
                    $selected_surveys_status[$cfg] = $selected_surveys_status_configs[$cfg_i];
                }
            }
        }

        if ( count($selected_surveys) < 1 ) {
            // Nothing to do - there are no forms configured
            return;
        }

        // Loop through the forms
        // Make sure that the forms belong to the current event
        $event_forms = $Proj->eventsForms;
        foreach ( $selected_surveys as $sname ) {
            if ( isset($event_forms[$event_id]) && is_array($event_forms[$event_id]) ) {
                // The specified Event ID exists, check to see if the form is assigned to it
                if ( in_array($sname, $event_forms[$event_id]) !== false ) {
                    $valid_surveys[$sname] = $sname; // This is a valid form for this event - add it to the list!
                }
            }
        }

        if ( count($valid_surveys) < 1 )
            return; // Nothing to do


        // 2. Get all of the fields for this specific form that are calculated fields
        $calc_fields_list = array();
        $form_status_fields = array();
        $form_status_fields_status = array(); // only valid surveys statues here
        foreach ( $valid_surveys as $vform ) {
            $this->getAllCalcFields( $vform, $calc_fields_list );

            if ( isset($selected_surveys_status[$vform]) && !is_null($selected_surveys_status[$vform]) ) {
                $form_status_fields[]=$vform."_complete";
                $form_status_fields_status[$vform] = $selected_surveys_status[$vform]; // Form -> Status
            }
        }

        // calculateMultipleFields($records=array(), $calcFields=array(), $returnIncorrectValuesOnly=false,
        //												   $current_event_id=null, $group_id=null, $Proj2=null, $bypassFunctionCache=true)
        // Maybe if we need it we can pre-run the calcs
        //$preview_data = Calculate::calculateMultipleFields($record, $calc_fields_list, true, $event_id);

        // See if any fields are excluded for this record
        $excluded_fields = $this->get_excluded_fields( $record, $event_id );

        // saveCalcFields($records=array(), $calcFields=array(), $current_event_id='all',
        //										  $excludedRecordEventFields=array(), $Proj2=null, $dataLogging=true, $group_id = null, $bypassFunctionCache=true)

        // Update the specified fields
        $result = Calculate::saveCalcFields($record, $calc_fields_list, $event_id, $excluded_fields);

        if ( $result > 0 ) {
            // Log this action some more than what it's already logged
            Logging::logEvent(NULL, "", "OTHER", $record, "Updated $result fields in ".db_escape(implode(', ', $valid_surveys))."\n For Complete list of changes - look for the corresponding \n\"(Auto calculation)\"\n log entry below", "EM: CalCruncher", "", "", "", true, null, null, false);

            // check to see if we need to update the form status
            if ( count($form_status_fields) >0 ) {
                $this->update_form_status($project_id, $record, $event_id, $repeat_instance, $form_status_fields_status);
            }
        }
    }

    /**
     * Public function to run the configured calculations across multiple records and all events/instances
     * @param $project_id
     * @param $record
     * @param $events
     * @return void
     */
    public function crunch_calcs_multiple ( $project_id, $records = array(), $events = 'all' )
    {
        global $Proj;

        // 1. Get the module configurations - what forms should we be running this against
        $selected_surveys_config = is_array($this->getProjectSetting('calcruncher-selected-surveys-form')) ? $this->getProjectSetting('calcruncher-selected-surveys-form') : array();
        $selected_surveys_status_configs = is_array($this->getProjectSetting('calcruncher-selected-surveys-form-status')) ? $this->getProjectSetting('calcruncher-selected-surveys-form-status') : array();
        $selected_surveys = array(); // This is a list of the de-duplicated forms
        $valid_surveys = array(); // These are the surveys that actually belong to this event
        $selected_surveys_status = array(); // The numerical status for the form - form_name => status (ex: additional_tests => 1   for UNVERIFIED)

        // Form a de-duplicated list of forms
        foreach ($selected_surveys_config as $cfg_i => $cfg) {
            $cfg = strtolower(trim(strip_tags(htmlspecialchars($cfg, ENT_QUOTES, 'UTF-8'))));

            if (array_key_exists($cfg, $selected_surveys))
                continue; // Don't do anything - the array key exists already

            if (strlen($cfg) > 0) {
                $selected_surveys[$cfg] = $cfg; // add it to an associative array so we can easily de-duplicate

                // See if we need to set a survey status for this form
                if (isset($selected_surveys_status_configs[$cfg_i]) && !is_null($selected_surveys_status_configs[$cfg_i])) {
                    $selected_surveys_status[$cfg] = $selected_surveys_status_configs[$cfg_i];
                }
            }
        }

        if (count($selected_surveys) < 1) {
            // Nothing to do - there are no forms configured
            return;
        }

        /**
         * Because we are running for all events, it doesn't matter whether the forms belong to a specific event
         */

        // 2. Get all of the fields for this specific form that are calculated fields
        $calc_fields_list = array();
        $form_status_fields = array();
        $form_status_fields_status = array(); // only valid surveys statues here
        foreach ($selected_surveys as $vform) {
            $this->getAllCalcFields($vform, $calc_fields_list);

            if (isset($selected_surveys_status[$vform]) && !is_null($selected_surveys_status[$vform])) {
                $form_status_fields[] = $vform . "_complete";
                $form_status_fields_status[$vform] = $selected_surveys_status[$vform]; // Form -> Status
            }
        }

        // 3. See if any fields are excluded for this THESE records
        $excluded_fields = $this->get_excluded_fields();

        // 4. Run a preview of the data that is going to change so we can capture the forms that need to be marked as whatever status
        // this function returns:
        // $calcs[$record][$event_id][$repeat_instrument][$repeat_instance][$field]
        $preview_data = Calculate::calculateMultipleFields($records, $calc_fields_list, true);
        $record_form_status_to_update = array(); // record -> event -> instance -> form
        $metadata = $Proj->metadata;

        foreach ($preview_data as $rid => $rid_data) {
            foreach ($rid_data as $eid => $eid_data ) {
                foreach ( $eid_data as $rep => $rep_data ) {
                    foreach ( $rep_data as $rep_inst => $rep_inst_data ) {
                        foreach ( $rep_inst_data as $rep_f => $rep_f_data ) {
                            // This array now has all of the information needed to set the form status AFTER updates
                            $record_form_status_to_update[$rid][$eid][$rep_inst == '' ? 1 : $rep_inst][$metadata[$rep_f]['form_name']] = $form_status_fields_status[$metadata[$rep_f]['form_name']];
                        }
                    }
                }
            }
        }

        unset($preview_data); // we don't need this anymore - clean up

        // 5. Re-execute the calcs
        $result = Calculate::saveCalcFields($records, $calc_fields_list, $events, $excluded_fields);

        if ( $result > 0 ) {
            /**
             * OK - Some fields were updated.
             * Here we need to set the form statuses .. the problem is that we don't know exactly what was updated UNLESS we sift through the log event table
             * BUT sifting the log event table is not easy
             *
             * SOLUTION (not ideal) - use the "preview_data". In theory, the data from the "preview" should be the data that gets updated in the database
             * BUT we also don't have the clear definition of what instrument we need to set the status to in this case
             * --- we have to match-up a field to a form to event to instance
             *
             * DRAWBACK - the "preview data" does NOT take into account excluded records so we MAY accidentally mark a form as partial/completed/etc. I'm OK with this for now
             */

            // Log this action some more than what it's already logged
            Logging::logEvent(NULL, "", "OTHER", "", "(CRON) Updated $result fields in ".db_escape(implode(', ', $selected_surveys))."\n For Complete list of changes - look for the corresponding \n\"(Auto calculation)\"\n log entries below", "EM: CalCruncher", "", "SYSTEM", "", true, null, null, false);

            // check to see if we need to update the form status
            // Step 6 - update form statuses
            if ( count($form_status_fields) >0 ) {
                /**
                 * We need a de-duplicated array that contains
                 * - record -> event_id -> repeat_instance -> form
                 */
                //$record_form_status_to_update[$rid][$eid][$rep_inst == '' ? 1 : $rep_inst][$metadata[$rep_f]['form_name']] = $form_status_fields_status[$metadata[$rep_f]['form_name']];
                foreach ( $record_form_status_to_update as $rid => $rid_data ) {
                    foreach ( $rid_data as $eid => $eid_data ) {
                        foreach ( $eid_data as $rep => $rep_data ) {
                            $this->update_form_status($project_id, $rid, $eid, $rep, $rep_data); // Set the form statuses
                        }
                    }
                }
            }
        }
    }

    /**
     * @param $project_id
     * @param $record
     * @param $instrument
     * @param $event_id
     * @param $repeat_instance
     * @param $fields_list
     * @return void
     */
    private function update_form_status ( $project_id, $record = NULL, $event_id, $repeat_instance = 1, $form_status_fields_status ) {
        global $Proj;
        try {
            // Get the existing survey statuses
            // record => event_id => form_name => instance => status
            // A form that is NOT saved with just have the "instance => status" not being populated
            // ex: array ( 'record1' => array ( event_id_1234 => array ( 'form_name_1' => array ( ), 'form_name_2' => array ( ), ), ), )
            $formStatusValues = Records::getFormStatus($project_id, array($record), null, null, array($event_id => array_keys($form_status_fields_status)));
            $data = array();
            $forms_updated = array();
            foreach ( $form_status_fields_status as $form => $target_form_status ) {
                if ( !$Proj->isRepeatingFormOrEvent($event_id, $form) ) {
                    // Non-repeating
                    if (empty($formStatusValues[$record][$event_id][$form]) || !isset($formStatusValues[$record][$event_id][$form][$repeat_instance])){
                        $data[$record][$event_id][$form."_complete"] = $target_form_status;
                        $forms_updated[] = $form;
                    }
                }
                else {
                    // Repeating
                    if (empty($formStatusValues[$record][$event_id][$form]) || !isset($formStatusValues[$record][$event_id][$form][$repeat_instance])){
                        $data[$record]['repeat_instances'][$event_id][$form][$repeat_instance][$form."_complete"] = $target_form_status;
                        $forms_updated[] = $form;
                    }
                }
            }

            if ( count($data)>0 ){
                // We have to save this data
                $result = REDCap::saveData($Proj->project_id, 'array', $data);
                Logging::logEvent(NULL, "", "OTHER", $record, "Updated Form Status for \n".db_escape(implode(', ', $forms_updated)), "EM: CalCruncher", "", "", "", true, null, null, false);
                return true;
            }
            return false;
        }
        catch ( Exception $ee ) {
            return false;
        }
    }

    /**
     * This function retrieves a list of the excluded record-event-field details from the data quality rule
     * This code was taken from DataQuality.php -> function executePredefinedRule and modified slightly
     *
     * @param $record
     * @param $event_id
     * @return array
     */
    private function get_excluded_fields ( $record = null, $event_id = null ) {
        global $Proj;
        try {
            $rule_id = "pd-10"; // This is hard-coded rule for calculations
            $hasRepeatingFormsEvents = $Proj->hasRepeatingFormsEvents();

            // EXCLUDED: Get a list of any record-event-field's for this rule that have been excluded (so we know what to exclude)
            $excluded = array();
            $sql_params = array();
            // ORIGINAL
            //$sql = "select record, event_id, field_name, instance from redcap_data_quality_status
			//	where pd_rule_id = " . substr($rule_id, 3) . " and project_id = " . db_escape($Proj->project_id);
            // Parametarized
            $sql = "select record, event_id, field_name, instance from redcap_data_quality_status
				where pd_rule_id = ? and project_id = ?";

            $sql_params[] =  substr($rule_id, 3);
            $sql_params[] =  db_escape($Proj->project_id);

            if ( !is_null($record) ) {
                // ORIG
                //$sql .= " and record = '" . db_escape($record) . "'";
                // Parametarized
                $sql .= " and record = ?";
                $sql_params[] = db_escape($record);
            }
            if ( !is_null($event_id) && is_numeric($event_id) ) {
                // ORIG
                //$sql .= " and event_id = ".db_escape($event_id);
                // PARAMETARIZED
                $sql .= " and event_id = ?";
                $sql_params[] = db_escape($event_id);
            }

            // ORIG
            //$q = db_query($sql);
            // Parametarized
            $q = $this->query($sql, $sql_params);
            while ($row = db_fetch_assoc($q))
            {
                // Repeating forms/events
                $isRepeatEvent = ($hasRepeatingFormsEvents && $Proj->isRepeatingEvent($row['event_id']));
                $isRepeatForm  = $isRepeatEvent ? false : ($hasRepeatingFormsEvents && $Proj->isRepeatingForm($row['event_id'], $Proj->metadata[$row['field_name']]['form_name']));
                $isRepeatEventOrForm = ($isRepeatEvent || $isRepeatForm);
                $repeat_instrument = $isRepeatForm ? $Proj->metadata[$row['field_name']]['form_name'] : "";
                $instance = $isRepeatEventOrForm ? $row['instance'] : 0;
                // Add to excluded array
                $excluded[$row['record']][$row['event_id']][$repeat_instrument][$instance][$row['field_name']] = true;
            }

            return $excluded;
        }
        catch ( Exception $ee ) {
            return array(); // empty
        }
    }

    /**
     * CRON
     * @param $cronInfo
     * @return void
     */
    public function run_calc_crunch_cron ($cronInfo) {
        try {
            /**
             * This module should only ever run at 6AM or 6PM. If it's NOT 6AM or 6PM - do nothing and just return
             */
            $today = new \DateTime();
            $current_hour = $today->format('G'); // 24 hour format - no leading zero
            if ( $current_hour != 6 && $current_hour != 18 )
                return; // It's NOT time to run! Do nothing

                // Get a list of the projects that have this EM enabled on them
            $framework = \ExternalModules\ExternalModules::getFrameworkInstance($this->PREFIX);
            $projects = $framework->getProjectsWithModuleEnabled();

            if (count($projects) > 0) {
                // Loop throught the projects and call the CRON endpoint
                foreach ($projects as $project_id) {
                    try {
                        $Proj = new Project($project_id);
                        // Get the URL for the cron listener
                        $module_cron_url = \ExternalModules\ExternalModules::getUrl($this->PREFIX, 'mgb_calcruncher_cron.php', $Proj->project_id, true, false);

                        // do a GET to the curl with a very short timeout
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $module_cron_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_VERBOSE, 0);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                        curl_setopt($ch, CURLOPT_AUTOREFERER, true);
                        curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
                        //curl_setopt($ch, CURLOPT_SSLVERSION, 6); // This is TLS 1.2
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
                        curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Set a small timeout
                        $output = curl_exec($ch);
                        curl_close($ch);


                    } catch (Exception $ee) {
                        \REDCap::logEvent($this->PREFIX . " exception: " . $ee->getMessage(), '', '', null, null, $project_id);
                    }
                }
            }
        }
        catch ( Exception $ee ) {

        }
    }

    /**
     * Get a list of the fields that have calculations in them
     * @param $form_name - NULL for all forms; form_name for a specific form
     * @param $calc_fields_list - a reference array to put all of the calculated fields in
     * @return array
     */
    private function getAllCalcFields( $form_name = null, &$calc_fields_list )
    {
        global $Proj;
        //$fields = [];
        foreach ($Proj->metadata as $attr) {
            $actionTag = strtoupper($attr['misc'] ?? "");
            if ( !is_null($form_name) && strlen($form_name)>1 && $form_name !== $attr['form_name'] ) continue; // We're looking for a specific form and this is not that form
            if ( $attr['element_type'] == 'calc' || strpos($actionTag, "@CALC") !== false ) {
                $calc_fields_list[] = $attr['field_name']; // add it to the array
            }
        }
        //return $fields;
    }

    /**
     * This function returns a list of records that have been recently updated.
     * This means any records that have had CREATE
     * @param $project_id
     * @return void
     */
    public function get_recently_updated_records ( $project_id, $days_filter = 1 ) {

        if ( !is_numeric($days_filter) )
            return false; // We don't have a valid days filter
        // Get the project's log table
        $log_table_name = Logging::getLogEventTable($project_id);

        // What are the filters we need to apply?
        // REDCap's function is: Logging::setEventFilterSql

        $sql_params = array();

        $filter = " object_type = 'redcap_data' AND event in ('INSERT','UPDATE') AND description not like \"%(Auto calculation)\" and project_id = ? ";
        $sql_params[] = db_escape($project_id);

        $today1 = new \DateTime();
        $today1->add(\DateInterval::createFromDateString('+5 minutes')); // add 5 minutes to this
        $today_ts = "ts<=?";

        $interval_string = "P".$days_filter."D";
        $today2 = new \DateTime();
        $lastXdays   = $today2->sub(new \DateInterval($interval_string)); // X days ago
        $ts_filter = " AND ts>=? AND ts<=?";
        $sql_params[] = $lastXdays->format('YmdHi')."00";// AND ".$today_ts;
        $sql_params[] = $today1->format('YmdHi')."00";

        $record_sql = "SELECT pk FROM ".$log_table_name." WHERE ".$filter.$ts_filter." group by pk";

        $records = array();
        //$q = db_query($record_sql);
        $q = $this->query($record_sql, $sql_params);
        while ($row = db_fetch_assoc($q)) {
            $records[] = $row['pk'];
        }

        return $records;
    }
}