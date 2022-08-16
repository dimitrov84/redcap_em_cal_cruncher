# CalCruncher - REDCap External Module
The purpose of this external module is to automatically re-run and re-save the calculations on Calculated fields and fields that employ the @CAL* action tag.

This module is useful for projects that have calculated fields that you would like to have updated frequently without having to manually run Rule H. 

## General Configuration and usage
This module should be enabled on the project-level. Once enabled, the module can be configured through the Configure screen (under External Modules -> Manage)

In the Configuration screen there will be the following settings:
- **Specify Form** - Specify the form that should be re-calculated. This is a repeating setting and you can provide more than one.
- **Set Form Status** - (optional setting) If you want, you can automatically set the form status of the re-calculated form to one of the standard statuses - Incomplete, Unverified, or Complete. NOTE: A form status will be set ONLY if no previous form status exists. The module will NOT overwrite an existing form status.
- **Enable CRON** - Enable automated recurring execution of the re-calculations
- **CRON Frequency** - If the CRON is enabled, the module can be set to execute once or twice per day
- **CRON Records** - If the CRON is enabled, should the cron execute on all records every time it runs, or only on a subset of recently updated records

## Version 1.0.0
Execute on "redcap_save_record" action. This does NOT trigger on Survey screens.

Execute on "redcap_survey_complete" action. This is meant to trigger at the end of a survey. It's meant to account for multi-step surveys and only execute when all of the data is available.

Have the ability to specify the form status on the re-calculated forms.

The module **honors** record-event-field exclusions. If you run Rule H and click on the "Exclude" link/button for a specific record-event-field, then the module will honor that exclusion and NOT execute the calculation on that.

Version 2.0.0 introduces CRON functionality (see below)!

## Version 2.0.0
In Version 2.0.0 we're introducing the ability to run CalCruncer as a cron job.

Because this module was developed to deal primarily with projects that have a lot of calculated fields AND a lot of records, the CRON is only configured to run:
- At 6AM or at 6PM 

I.e. REDCap will trigger the module's CRON job at 6AM and 6PM (globally for the whole instance)

On the project level, you can set the following configurations:
- Enable CRON (automatic timed runs) - checkbox - if it's checked then the CRON component is enabled for this specific project. DEFAULT is DISABLED (unchecked)
- CRON Frequency (if enabled) - Radio buttons - You can select whether you want the CRON for this project to run once at 6AM or to run both at 6AM and 6PM
- CRON records (if enabled) - Created or Updated ONLY - specify what records should be considered. Available options are:
  - Runs for ALL records
  - Run ONLY for records from the last 24 hours
  - Run ONLY for records from the last 7 days

NOTE: The definition of "records from last X-hours/days" is any record that has been Created or Updated in the last X-time period WITH THE EXCEPTION of records that are listed as "(Auto Calcuation)". This is done to avoid picking up the records that this module itself updates when it runs.
ANOTHER NOTE: The CRON job itself (on the server) triggers every hour, BUT it's hard-coded to ONLY do work in the 6AM or 6PM (18) hour. 

## Version 2.1.0
Introduced a screen on the Control Center to manually run individual project CRONs. This will FORCE the cron job to execute regardless of the time.

## Version 2.1.1
Minor updates, code cleaning;


--- 
Developed and tested against REDCap version 12.2.x with PHP 7.4+

The module has no external library dependencies. It utilizes REDCap core classes.