<?php

namespace block_zoomonline\task;

use DateTime;
use Exception;

defined('MOODLE_INTERNAL') || die();

class processmeetings extends \core\task\scheduled_task
{
    public function get_name()
    {
        return "Process Zoom Meetings";
    }

    /**
     * This task processes Zoom meetings: recordings, attendance, and live meeting checks.
     */
    public function execute()
    {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/blocks/zoomonline/block_zoomonline.php');

        $block_zoomonline = new \block_zoomonline();
        $starttime = microtime(true);
        $counter = 0;

        mtrace("Starting to process Zoom meetings...");

        try {
            // Fetch all Zoom meetings once.
            $meetings = $DB->get_records('block_zoomonline');

            if (!$meetings) {
                mtrace("No meetings found to process.");
                return;
            }
            // Get current timestamp for date comparisons.
            $nowts = time();
            // Fetch all course end dates at once to minimize queries inside the loop.
            $course_enddates = $DB->get_records_sql_menu("SELECT id, enddate FROM {course}");

            foreach ($meetings as $meet) {

                $zoomid = $meet->zoom_meeting_id;
                $crsid = $meet->course_id;

                // Skip processing if the course has ended.
                if (isset($course_enddates[$crsid]) && $course_enddates[$crsid] < $nowts) {
                    mtrace("Skipping meeting $zoomid due to course end date.");
                    continue;
                }
                mtrace("Processing meeting: $zoomid (Course ID: $crsid)");
                try {
                    // Process recordings for the meeting.
                    $block_zoomonline->processRecordings($zoomid, null, null);
                } catch (Exception $ex) {
                    mtrace("Error processing recordings for meeting $zoomid: " . $ex->getMessage());
                }


                // Check if the attendance tracking setting is enabled.
                if (get_config('block_zoomonline', 'useattendance')) {
                    // Check if the Attendance module is installed.
                    $installedModules = core_plugin_manager::instance()->get_plugin_list('mod');

                    if (array_key_exists('attendance', $installedModules)) {
                        // The Attendance module is installed, proceed with attendance processing.
                        // Your existing attendance processing logic here.
                        try {
                            // Process attendance for the meeting.
                            $block_zoomonline->processAttendance($zoomid, $crsid);
                        } catch (Exception $ex) {
                            mtrace("Error processing attendance for meeting $zoomid: " . $ex->getMessage());
                        }
                        try {
                            // Check live meeting status.
                            $block_zoomonline->checkLiveMeeting($zoomid, $crsid);
                        } catch (Exception $ex) {
                            mtrace("Error checking live meeting for $zoomid: " . $ex->getMessage());
                        }

                    } else {
                        // The Attendance module is not installed, skip attendance processing.
                        echo "Attendance module is not installed. Skipping attendance processing.";
                        // You could log this event or take an alternative action if necessary.
                    }
                }




                $counter++;
            }
        } catch (Exception $ex) {
            mtrace("General error during meeting processing: " . $ex->getMessage());
        }

        $elapsed = round(microtime(true) - $starttime, 2);
        mtrace("$counter meetings processed (took $elapsed seconds).");
    }
}
