<?php
namespace VUMC\GenerateAndDownloadSurveyLinksExternalModule;

use Exception;
use REDCap;
use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;

class GenerateAndDownloadSurveyLinksExternalModule extends AbstractExternalModule
{
    /**
     * This cron will only perform actions once a day between the hours
     * of midnight and 6am, regardless of how often it is scheduled.
     * Scheduling it more often is required to ensure it runs at a predictable time.
     * For example, scheduling it for once every 10 minutes will guarantee that
     * it runs at the first available minute after 12:10am,
     * though it may run as soon as 12am.
     * This produces behavior similar to timed crons.
     * See the API Sync module's per project sync settings for a more advanced
     * example of this same concept.
     */
    function generateDocumentSurveyLinksCron($cronAttributes){
        $hourRange = 6;
        if(date('G') > $hourRange){
            // Only perform actions between 12am and 6am.
            return;
        }
        $thisCron = $cronAttributes['cron_name'];

        ## Old way of checking cron status, check to prevent re-sending on upgrade
        $lastRunSettingName = 'last-cron-run-time';
        $lastRun = (int)$this->getSystemSetting($lastRunSettingName);
        $hoursSinceLastRun = (time()-$lastRun)/60/60;
        if($hoursSinceLastRun < $hourRange){
            // We're already run recently
            return;
        }

        $lastRunCronSettingName = 'last-cron-run-time-'.$thisCron;
        $lastRunThisCron = (int)$this->getSystemSetting($lastRunCronSettingName);
        $hoursSinceLastRun = (time()-$lastRunThisCron)/60/60;
        if($hoursSinceLastRun < $hourRange){
            // We're already run this cron recently
            return;
        }

        ## Immediately log starting in case a second process spawns for this cron
        $this->setSystemSetting($lastRunCronSettingName, time());

        // Perform cron actions here
        foreach ($this->getProjectsWithModuleEnabled() as $project_id){
            try {
                $generate_file = $this->getProjectSetting('generate-file',$project_id);
                if($generate_file) {
                    $custom_instruments = $this->getProjectSetting('custom-instruments',$project_id);
                    $custom_fields = array_merge(['record_id'],$this->getProjectSetting('custom-fields',$project_id));

                    $all_data = \REDCap::getData($project_id, "json-array", null, $custom_fields);

                    $csv_instruments = [];
                    foreach ($custom_instruments as $instrument_index => $instrument_name) {
                        $csv_instruments[$instrument_index] = "survey_link_" . $instrument_name;
                    }

                    /***CSV***/
                    $csv_cols = array_merge($csv_instruments, $custom_fields);

                    $csv_data_user = array_merge($custom_instruments, $custom_fields);
                    $csv_data = trim(implode(",",$csv_cols),",")."\n";
                    foreach ($all_data as $index_record => $record) {
                        $csv_line = [];
                        foreach ($csv_data_user as $fields_index => $field_name){
                            if(in_array($field_name,$custom_instruments)){
                                $passthru_link = $this->resetSurveyAndGetCodes($project_id, $record['record_id'], $instrument_name, "");
                                $value = $this->escape(APP_PATH_WEBROOT_FULL . "/surveys/?s=" . $passthru_link['hash']);
                            }else{
                                $value = $record[$field_name];
                            }
                            array_push($csv_line,$value);
                        }
                        $csv_data .= trim(implode(",",$csv_line),",")."\n";
                    }

                    $filename = 'Generate_and_Download_Survey_Links_'.$project_id.'_'.date("Y-m-d_h-i",time()).".csv";
                    $reportHash = $filename;
                    $storedName = md5($reportHash);
                    $filePath = APP_PATH_TEMP.$storedName;

                    $csv_handler = fopen ($filePath,'w');
                    fwrite ($csv_handler,$csv_data);
                    fclose ($csv_handler);

                    $output = file_get_contents($filePath);
                    $filesize = file_put_contents($filePath, $output);

                    #Save document on DB
                    $docId = \REDCap::storeFile($filePath, $project_id, $filename);
                    unlink($filePath);

                    #Save document in File Repository
                    \REDCap::addFileToRepository($docId, $project_id);

                    $this->setProjectSetting('generate-file', false, $project_id);
                }
            } catch (Throwable $e) {
                \REDCap::email('datacore@vumc.org', 'datacore@vumc.org',"Cron Error", $e->getMessage());
            }
        }
    }

}

?>