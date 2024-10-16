<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses />.
/**
 * zoomonline block caps.
 *
 * @package block_zoomonline
 * @copyright Ciaran Mac Donncha
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
global $CFG;

defined('MOODLE_INTERNAL') || die();

include_once($CFG->dirroot . '/repository/s3/S3.php');

//include_once($CFG->dirroot . '/blocks/zoomonline/classes/S3.php');

require_once($CFG->dirroot . '/blocks/zoomonline/classes/ZoomHelper.php');



class block_zoomonline extends block_base
{
	function init()
	{
		global $COURSE;
		$this->title = get_string('pluginname', 'block_zoomonline');
	}

    // Helper method to save video to a local folder
    private function saveToLocalFolder($tempFilePath, $videoId)
    {
        global $CFG;
        $localDir = $CFG->dataroot . '/zoom_videos';
        if (!file_exists($localDir)) {
            mkdir($localDir, 0777, true);
        }
        $localFilePath = $localDir . '/' . $videoId . '.mp4';
        rename($tempFilePath, $localFilePath);
        return $CFG->wwwroot . '/pluginfile.php/' . $localFilePath;
    }


    function processRecordings($meetingId, $firstName = '', $lastName = '', $crsId = null, $lecId = null)
    {
        global $CFG, $COURSE, $DB;

        $embedtype = get_config('block_zoomonline', 'embedtype');

        $storagetype = get_config('block_zoomonline', 'storagetype');

        // Retrieve lecturer details if provided
        if ($lecId) {
            $lecturer = $DB->get_record('user', ['id' => $lecId], 'firstname, lastname');
            $firstName = $lecturer->firstname ?? $firstName;
            $lastName = $lecturer->lastname ?? $lastName;
        }

        $courseId = $crsId ?: $COURSE->id;

        if ($storagetype === 'aws') {
            // require_once($CFG->dirroot . '/local/aws/aws-autoloader.php');
            // Set up AWS S3 client with credentials

            $s3 = new Aws\S3\S3Client([
            'version'     => 'latest',
            'region'      => get_config('block_zoomonline', 'aws_region'),
            'credentials' => [
                'key'    => get_config('block_zoomonline', 'aws_key'),
                'secret' => get_config('block_zoomonline', 'aws_secret'),
            ],
        ]);

        $awsBucket = get_config('block_zoomonline', 'aws_bucket');

        }

        $accessKey = ZoomHelper::getCurrentAccessKey();
        $recordings = ZoomHelper::getMeetingRecordings($meetingId);

        foreach ($recordings as $rec) {

            if ($rec['file_type'] !== 'MP4') continue;

            $videoId = trim($rec['id']);
            $downloadUrl = $rec['download_url'];
            $recordingDate = $this->formatRecordingDate($rec['recording_start']);
            $shareUrl = $rec['play_url'];
            $vID = trim("$firstName $lastName");

            if ($embedtype == 'book') {
                // Create section and book

                $scid = $this->createSection();
                $bkID = $this->createBook($vID, $courseId, $scid);
                // Check if the chapter already exists
                if ($this->chapterExists($bkID, $recordingDate)) {
                    continue;
                };
            }
            // Download and upload video, and process further only if successful
            $tempFilePath = ZoomHelper::downloadVideo($downloadUrl, $accessKey, $videoId);

            if ($tempFilePath && filesize($tempFilePath) >= 10) {

                if ($storagetype === 'aws') {

                $s3FileUrl = $this->uploadToS3($s3, $tempFilePath, $awsBucket, $videoId);

                unlink($tempFilePath);

                }
                elseif ($storagetype === 'local') {
                    // Save to local Moodle directory
                    $localFileUrl = $this->saveToLocalFolder($tempFilePath, $videoId);
                }

                if (isset($s3FileUrl) || isset($localFileUrl)) {

                    ZoomHelper::deleteZoomRecording($meetingId, $videoId);
                    $fileUrl = $storagetype === 'aws' ? $s3FileUrl : $localFileUrl;
                    if ($embedtype == 'book') {
                        // Code to embed video in Book.
                        $bkID = $this->addChapterToBook($bkID, $s3FileUrl, $shareUrl, $firstName, $lastName, $recordingDate, $vID);
                        // Continue with embedding logic in Book.
                    } elseif ($embedtype == 'label') {
                        // Code to embed video in Label.
                        $this->addVideoToMoodle($courseId, $videoId, $s3FileUrl, $firstName, $lastName, $recordingDate);
                        // Continue with embedding logic in Label.
                    }


                }
            }
        }
    }

    public static function __callStatic($name, $arguments)
    {
        // TODO: Implement __callStatic() method.
    }// Helper function to check if a chapter already exists
    private function chapterExists($bookId, $recordingDate)
    {
        global $DB;
        return $DB->record_exists('book_chapters', ['bookid' => $bookId, 'title' => $recordingDate]);
    }

// Helper function to add a chapter to the book
    private function addChapterToBook($bookId, $s3FileUrl, $shareUrl, $firstName, $lastName, $recordingDate, $vID)
    {
        global $DB;

        $content = "<video width='300' height='175' oncontextmenu='return false;' controls='controls' controlsList='nodownload'>
                    <source src='$s3FileUrl' type='video/mp4'>$vID
                </video>
                <p>$firstName $lastName - $recordingDate</p>
                <p><a target='_blank' href='$shareUrl'>Click here to play video in Zoom with transcript, you must be signed into Zoom</a></p>";

        $chapter = (object) [
            'bookid' => $bookId,
            'content' => $content,
            'title' => $recordingDate,
            'hidden' => 1
        ];
        $DB->insert_record('book_chapters', $chapter);
    }

    function createSection() {
        global $COURSE, $DB,$USER;

        $cid = $COURSE->id;
        $sectionid = 0;
        $sqlsectionexists = "SELECT course,section FROM mdl_course_sections where course = $cid AND name = 'ONLINE RECORDINGS' limit 1";
        $sectionexists = $DB->get_records_sql($sqlsectionexists);

        if(!$sectionexists) {
            $recsection = new stdClass();
            $recsection->course = $COURSE->id;
            $sqlsectop = "SELECT course,section FROM mdl_course_sections where course = $cid order by section desc limit 1";
            $secTop = $DB->get_records_sql($sqlsectop);
            $sectionid  = $secTop[$cid]->section + 1;
            $recsection->section = $sectionid;
            $recsection->name = 'ONLINE RECORDINGS';
            $recsection->summaryformat = FORMAT_HTML;
            $recsection->sequence = '';

            $id = $DB->insert_record("course_sections", $recsection);
        } else {
            $sectionid = $sectionexists[$cid]->section;
        }
        return $sectionid;
    }

    // Format the recording date to 'd/m/Y H:i'.
    private function formatRecordingDate($stringTime)
    {
        $timestamp = strtotime($stringTime);
        $date = new DateTime();
        $date->setTimestamp($timestamp);
        $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        return $date->format('d/m/Y H:i');
    }


    // Upload the video to S3 and return the S3 URL.
    private function uploadToS3($s3, $filePath, $bucket, $fileName)
    {
        try {
            $s3->putObject([
                'Bucket' => $bucket,
                'Key'    => $fileName . '.mp4',
                'Body'   => fopen($filePath, 'r'),
                'ACL'    => 'public-read',
            ]);
            return "https://s3.$bucket.amazonaws.com/$fileName.mp4";
        } catch (Exception $e) {
            error_log('S3 Upload failed: ' . $e->getMessage());
            return null;
        }
    }


    function createBook($vID, $cid, $sectionid) {
        global $CFG, $COURSE, $DB, $USER;

        try {
            $origname = $vID;
            $vID = $DB->sql_like_escape($vID); // Escape special characters for LIKE clause
            $bookExists = $DB->get_record_sql("SELECT id FROM {book} WHERE course = ? AND name LIKE ?", [$cid, "$vID%"]);

            if (!$bookExists) {
                $book = new stdClass();
                $book->course = $cid;
                $book->visible = 1;
                $book->section = $sectionid;
                $book->module = $DB->get_field('modules', 'id', ['name' => 'book']);
                $book->modulename = 'book';
                $book->intro = "Recordings for '$COURSE->shortname'";
                $book->introformat = FORMAT_HTML;
                $book->name = $origname;

                // Include required libraries for adding the module
                require_once($CFG->dirroot . '/mod/label/lib.php');
                require_once($CFG->dirroot . '/course/modlib.php');

                $moduleInstance = add_moduleinfo($book, $COURSE);
                return $moduleInstance->instance;
            } else {
                return $bookExists->id;
            }
        } catch (Exception $ex) {
            if ($USER->id == 66098) {
                var_dump($ex);
            }
            return null; // Optional: return null or an error indicator
        }
    }



    function checkLiveMeeting($meetingid, $courseid)
    {
        global $CFG, $DB;

        // Retrieve meeting status and details.
        $meetingData = ZoomHelper::getMeetingStatus($meetingid);
        if ($meetingData['status'] !== "started") {
            return; // If meeting is not started, exit.
        }

        // Retrieve meeting metrics.
        $meetingMetrics = ZoomHelper::getMeetingMetrics($meetingid);
        $meetingStartTime = $this->getRoundedMeetingStartTime($meetingMetrics['start_time']);
        if (!$meetingStartTime) {
            return; // If no valid start time, exit.
        }

        // Check for existing attendance records.
        $attInstanceId = $this->getAttendanceInstance($courseid);

        if ($attInstanceId) {
            $this->checkOrCreateAttendanceSession($attInstanceId, $meetingStartTime, $courseid, $meetingMetrics['topic']);
        } else {
            // Create a new attendance module if it doesn't exist.
            $attInstanceId = $this->createNewAttendanceModule($courseid);
            $this->checkOrCreateAttendanceSession($attInstanceId, $meetingStartTime, $courseid, $meetingMetrics['topic']);
        }
    }

    /**
     * Get the rounded meeting start time.
     *
     * @param string $start_time
     * @return int Rounded meeting start timestamp.
     */
    private function getRoundedMeetingStartTime($start_time)
    {
        $meeting_start_e = strtotime($start_time);
        return $meeting_start_e - ($meeting_start_e % 60); // Round to nearest minute.
    }

    /**
     * Get the attendance instance for the course.
     *
     * @param int $courseid
     * @return int|null The attendance instance ID or null if not found.
     */
    private function getAttendanceInstance($courseid)
    {
        global $DB;
        $attendanceRecord = $DB->get_record('attendance', ['course' => $courseid]);
        return $attendanceRecord ? $attendanceRecord->id : null;
    }

    /**
     * Check if an attendance session exists for a given time, and create it if not.
     *
     * @param int $attInstanceId
     * @param int $meetingStartTime
     * @param int $courseid
     * @param string $topic
     */
    private function checkOrCreateAttendanceSession($attInstanceId, $meetingStartTime, $courseid, $topic)
    {
        global $DB;

        // Check if attendance session already exists.
        $attSessExists = $DB->record_exists('attendance_sessions', [
            'attendanceid' => $attInstanceId,
            'sessdate' => $meetingStartTime
        ]);

        // If no session exists, create one.
        if (!$attSessExists) {
            $status = "P"; // Default status is "Present".
            $this->createAttSess($attInstanceId, $meetingStartTime, 3600, $status, $courseid, "None", $topic, true);
        }
    }

    /**
     * Create a new attendance module for the course.
     *
     * @param int $courseid
     * @return int The created attendance instance ID.
     */
    private function createNewAttendanceModule($courseid)
    {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/attendance/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');

        // Create new attendance instance.
        $attendance = (object)[
            'course' => $courseid,
            'name' => 'Attendance',
            'grade' => 100,
            'timemodified' => time(),
            'intro' => '',
            'introformat' => 1,
            'subnet' => '',
            'sessiondetailspos' => 'left',
            'showsessiondetails' => 1,
            'showextrauserdetails' => 1
        ];
        $attInstanceId = $DB->insert_record('attendance', $attendance);

        // Add the attendance module to the course.
        $moduleId = $DB->get_field('modules', 'id', ['name' => 'attendance']);
        $courseModule = (object)[
            'course' => $courseid,
            'module' => $moduleId,
            'instance' => $attInstanceId,
            'section' => 0,
            'added' => time()
        ];
        $cmId = $DB->insert_record('course_modules', $courseModule);

        // Add module to course section and rebuild cache.
        course_add_cm_to_section($courseid, $cmId, 3);
        rebuild_course_cache($courseid, true);

        return $attInstanceId;
    }


    function processAttendance($meetingid, $courseid)
    {
        global $DB;

        // Fetch meeting data.
        $meetingData = ZoomHelper::getMeetingData($meetingid);

        if (!$meetingData) {
            return false; // Handle meeting retrieval error
        }

        $meeting_start_wtz = $meetingData['start_time'];
        $meeting_duration = $meetingData['duration'];
        $topic = $meetingData['topic'];
        $meeting_start_e_c = $this->getRoundedMeetingStart($meeting_start_wtz);

        $meeting_start = date('Y/m/d h:i:s', $meeting_start_e_c);

        // Check if attendance already exists.
        if (!$this->attendanceExists($meetingid, $meeting_start) && $meeting_start_e_c) {
            // Retrieve all participants.
            $attendees = $this->getAllMeetingParticipants($meetingid, $meeting_duration);
            if (empty($attendees)) {
                return false; // No attendees to process
            }

            // Consolidate attendance data.
            $consolidatedAttendees = $this->consolidateAttendanceData($attendees);

            // Process attendance for each student.
            $this->processFinalAttendance($consolidatedAttendees, $meeting_start_wtz, $meeting_duration, $courseid, $topic);

            // Record that attendance was processed for this meeting.
            $this->markAttendanceProcessed($meetingid, $meeting_start);
        } else {
            return false; // Attendance already processed or invalid.
        }
    }



    /**
     * Check if attendance already exists for a meeting.
     *
     * @param string $meetingid
     * @param string $meeting_start
     * @return bool
     */
    private function attendanceExists($meetingid, $meeting_start)
    {
        global $DB;
        return $DB->record_exists('block_zoomonline_att_check', ['zoom_meeting_id' => $meetingid, 'start_date' => $meeting_start]);
    }

    /**
     * Get the rounded meeting start time.
     *
     * @param string $meeting_start_wtz
     * @return int Rounded meeting start timestamp.
     */
    private function getRoundedMeetingStart($meeting_start_wtz)
    {
        $meeting_start_e = strtotime($meeting_start_wtz);
        return $meeting_start_e - ($meeting_start_e % 60); // Round to nearest minute.
    }

    /**
     * Get all meeting participants from Zoom API.
     *
     * @param string $meetingid
     * @param int $meeting_duration
     * @return array List of participants.
     */
    private function getAllMeetingParticipants($meetingid, $meeting_duration)
    {
        $attendees = [];
        $nextpagetoken = "";  // Start with an empty next_page_token

        while (true) {
            // Call the getParticipants method with the correct next_page_token
            $response = ZoomHelper::getParticipants($meetingid, $nextpagetoken);

            if (!$response) {
                break;
            }

            $data = json_decode($response, true);

            // Check if the response contains participants
            if (isset($data['participants'])) {
                foreach ($data['participants'] as $user) {
                    // Only consider meetings longer than 5 minutes
                    if ($meeting_duration >= 5) {
                        $attUser = $this->createAttUserObject($user);
                        if ($attUser) {
                            $attendees[] = $attUser;  // Collect the participants
                        }
                    }
                }
            }

            // Check if there's a next page to fetch
            if (empty($data['next_page_token'])) {
                break;  // No more pages, exit the loop
            } else {
                $nextpagetoken = $data['next_page_token'];  // Set the next_page_token for the next iteration
            }
        }

        return $attendees;
    }


    /**
     * Create an attendance user object from Zoom participant data.
     *
     * @param array $user
     * @return stdClass|null
     */
    private function createAttUserObject($user)
    {
        $stuid = filter_var($user['name'], FILTER_SANITIZE_NUMBER_INT);
        if ($stuid > 0) {
            $attUser = new stdClass();
            $attUser->name = $stuid;
            $attUser->join_time = $user['join_time'];
            $attUser->leave_time = $user['leave_time'];
            $attUser->duration = $user['duration'];
            $attUser->email = $user['user_email'];
            return $attUser;
        }
        return null;
    }

    /**
     * Consolidate multiple attendance records into a single one.
     *
     * @param array $attendees
     * @return array Consolidated attendees.
     */
    private function consolidateAttendanceData($attendees)
    {
        $consolidated = [];

        foreach ($attendees as $att) {
            $nameToFind = $att->name;
            if ($nameToFind) {
                $existing = $this->findInArray($consolidated, 'name', $nameToFind);

                if (!$existing) {
                    $groupedAttendees = array_filter($attendees, function ($attone) use ($nameToFind) {
                        return $attone->name === $nameToFind;
                    });

                    usort($groupedAttendees, function ($a, $b) {
                        return strtotime($a->join_time) <=> strtotime($b->join_time);
                    });

                    $attStud = new stdClass();
                    $attStud->name = $nameToFind;
                    $attStud->join_time = end($groupedAttendees)->join_time;
                    $attStud->leave_time = reset($groupedAttendees)->leave_time;
                    $attStud->duration = array_reduce($groupedAttendees, function ($carry, $item) {
                        return $carry + $item->duration;
                    }, 0);

                    $consolidated[] = $attStud;
                }
            }
        }

        return $consolidated;
    }

    /**
     * Find an element in an array by a key-value pair.
     *
     * @param array $array
     * @param string $key
     * @param mixed $value
     * @return mixed|null
     */
    private function findInArray($array, $key, $value)
    {
        foreach ($array as $element) {
            if ($element->$key === $value) {
                return $element;
            }
        }
        return null;
    }

    /**
     * Process final attendance data and update logs.
     *
     * @param array $attendees
     * @param string $meeting_start_wtz
     * @param int $meeting_duration
     * @param int $courseid
     * @param string $topic
     */
    private function processFinalAttendance($attendees, $meeting_start_wtz, $meeting_duration, $courseid, $topic)
    {
        $length = count($attendees);
        $x = 1;

        foreach ($attendees as $attendee) {
            $close = ($x === $length) ? 1 : 0;
            $this->updateAtt($attendee, $meeting_start_wtz, $meeting_duration, $courseid, $close, $topic);
            $x++;
        }
    }

    /**
     * Mark the attendance as processed for a meeting.
     *
     * @param string $meetingid
     * @param string $meeting_start
     */
    private function markAttendanceProcessed($meetingid, $meeting_start)
    {
        global $DB;

        $record = (object)[
            'zoom_meeting_id' => $meetingid,
            'start_date' => $meeting_start
        ];
        $DB->insert_record("block_zoomonline_att_check", $record);
    }




    function updateAtt($finStuAtt, $meeting_start, $meeting_duration, $zcrsid, $close, $topic)
    {
        global $CFG, $DB;

        $status = $this->calculateStudentStatus($finStuAtt, $meeting_start, $meeting_duration);
        $studentId = $this->getStudentIdByUsername($finStuAtt->name);

        if (!$studentId) {
            return; // Invalid student ID
        }

        $attendanceRecord = $this->getAttendanceRecord($zcrsid);

        if ($attendanceRecord) {
            $sessionExists = $this->processAttendanceSession($attendanceRecord, $meeting_start, $meeting_duration, $status, $zcrsid, $studentId, $close);

            if (!$sessionExists) {
                $this->createAttSess($attendanceRecord->id, $this->getRoundedMeetingStartTime($meeting_start), $meeting_duration * 60, $status, $zcrsid, $studentId, $topic, false);
            }
        } else {
            $this->createNewAttendanceInstance($zcrsid, $meeting_start, $meeting_duration, $status, $studentId, $topic);
        }
    }

    /**
     * Calculate the student's status based on their attendance and meeting details.
     *
     * @param object $finStuAtt
     * @param string $meeting_start
     * @param int $meeting_duration
     * @return string The student's attendance status (P, L).
     */
    private function calculateStudentStatus($finStuAtt, $meeting_start, $meeting_duration)
    {
        $status = "P"; // Default to "Present"
        $latetime = 900; // 15 minutes late threshold
        $meetingStartTime = strtotime($meeting_start);
        $meeting_start_e_c = $meetingStartTime - ($meetingStartTime % 60); // Round to nearest minute
        $studentJoinTime = strtotime($finStuAtt->join_time);

        $studentLate = max(0, $studentJoinTime - $meeting_start_e_c);
        $duration = $meeting_duration * 60;
        $attendedPercentage = ($finStuAtt->duration * 100) / $duration;

        if ($attendedPercentage < 70 || $studentLate > $latetime) {
            $status = "L"; // Late
        }

        if ($duration > 1800) {
            $sessionEndTime = $meeting_start_e_c + $duration;
            $studentEndTime = $studentJoinTime + $finStuAtt->duration;

            if (($studentEndTime - $sessionEndTime) > 900) {
                $status = "L"; // Late if student leaves too early
            }
        }

        return $status;
    }

    /**
     * Get the student's ID by their username.
     *
     * @param string $username
     * @return int|null The student's ID or null if not found.
     */
    private function getStudentIdByUsername($username)
    {
        global $DB;
        $student = $DB->get_record('user', ['username' => $username]);
        return $student ? $student->id : null;
    }

    /**
     * Retrieve the attendance record for the course.
     *
     * @param int $zcrsid The course ID.
     * @return object|null The attendance record or null if not found.
     */
    private function getAttendanceRecord($zcrsid)
    {
        global $DB;
        return $DB->get_record('attendance', ['course' => $zcrsid]);
    }

    /**
     * Process the attendance session, updating or creating a log as necessary.
     *
     * @param object $attendanceRecord
     * @param string $meeting_start
     * @param int $meeting_duration
     * @param string $status
     * @param int $zcrsid
     * @param int $studentId
     * @param int $close
     * @return bool True if session exists, false otherwise.
     */
    private function processAttendanceSession($attendanceRecord, $meeting_start, $meeting_duration, $status, $zcrsid, $studentId, $close)
    {
        global $DB;

        $meetingStartTime = strtotime($meeting_start);
        $duration = $meeting_duration * 60;
        $attSessSql = "SELECT attendanceid, id, duration 
                   FROM {attendance_sessions} 
                   WHERE attendanceid = ? AND sessdate = ? AND (duration = ? OR duration = '3600')";
        $attSess = $DB->get_records_sql($attSessSql, [$attendanceRecord->id, $meetingStartTime, $duration]);

        if ($attSess) {
            $attSessID = reset($attSess)->id;

            // Adjust session duration if necessary.
            if (reset($attSess)->duration == '3600' && $duration != 3600) {
                $DB->execute("UPDATE {attendance_sessions} SET duration = ? WHERE attendanceid = ? AND sessdate = ? AND duration = '3600'", [$duration, $attendanceRecord->id, $meetingStartTime]);
            }

            $stuAttSessSql = "SELECT sessionid, studentid, statusid, remarks, id 
                          FROM {attendance_log} 
                          WHERE sessionid = ? AND studentid = ?";
            $stuAttSess = $DB->get_records_sql($stuAttSessSql, [$attSessID, $studentId]);

            $attStatus = $DB->get_record('attendance_statuses', ['acronym' => $status, 'attendanceid' => $attendanceRecord->id]);

            if (!$stuAttSess || $close == 1) {
                $this->createAttLog($attSessID, $attStatus->id, $studentId, $meetingStartTime, $zcrsid);
            } else {
                $this->updateAttendanceLogIfNeeded($stuAttSess[$attSessID], $attStatus->id, $meetingStartTime);
            }

            return true;
        }

        return false;
    }

    /**
     * Update the attendance log if the system previously auto-recorded it.
     *
     * @param object $attendanceLog
     * @param int $attStatusId
     * @param int $meetingStartTime
     */
    private function updateAttendanceLogIfNeeded($attendanceLog, $attStatusId, $meetingStartTime)
    {
        global $DB;

        if ($attendanceLog->remarks == 'system auto recorded') {
            $attendanceLog->timetaken = $meetingStartTime;
            $attendanceLog->statusid = $attStatusId;
            $attendanceLog->remarks = 'Zoom';
            $DB->update_record('attendance_log', $attendanceLog);
        }
    }

    /**
     * Create a new attendance instance and session for the course.
     *
     * @param int $zcrsid
     * @param string $meeting_start
     * @param int $meeting_duration
     * @param string $status
     * @param int $studentId
     * @param string $topic
     */
    private function createNewAttendanceInstance($zcrsid, $meeting_start, $meeting_duration, $status, $studentId, $topic)
    {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/attendance/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');

        // Create a new attendance instance.
        $attendance = (object)[
            'course' => $zcrsid,
            'name' => 'Attendance',
            'grade' => 100,
            'timemodified' => time(),
            'intro' => '',
            'introformat' => 1,
            'subnet' => '',
            'sessiondetailspos' => 'left',
            'showsessiondetails' => 1,
            'showextrauserdetails' => 1
        ];
        $attInstanceId = $DB->insert_record('attendance', $attendance);

        // Add the attendance module to the course.
        $moduleId = $DB->get_field('modules', 'id', ['name' => 'attendance']);
        $courseModule = (object)[
            'course' => $zcrsid,
            'module' => $moduleId,
            'instance' => $attInstanceId,
            'section' => 0,
            'added' => time()
        ];
        $cmId = $DB->insert_record('course_modules', $courseModule);
        course_add_cm_to_section($zcrsid, $cmId, 3);
        rebuild_course_cache($zcrsid, true);

        // Create the attendance session.
        $this->createAttSess($attInstanceId, strtotime($meeting_start), $meeting_duration * 60, $status, $zcrsid, $studentId, $topic, false);
    }




    function createAttSess($attinstanceId, $sessdate, $duration, $status, $zcrsid, $studentId, $topic, $pre = false)
    {
        global $DB;

        // Generate a random 8-character password for the session.
        $password = $this->generateRandomPassword(8);

        // Prepare the attendance session data.
        $attData = (object)[
            'attendanceid' => $attinstanceId,
            'sessdate' => $sessdate,
            'duration' => $duration,
            'lasttaken' => $sessdate,
            'lasttakenby' => 2,
            'timemodified' => $sessdate,
            'descriptionformat' => 1,
            'calendarevent' => 0,
            'studentscanmark' => 1,
            'studentpassword' => $password,
            'includeqrcode' => 0,
            'rotateqrcode' => 1,
            'automark' => 2,
            'description' => $topic
        ];

        // If topic contains group information, link the session to the group.
        $this->assignGroupIfExists($attData, $topic, $zcrsid);

        // Insert the attendance session into the database.
        $sessionId = $DB->insert_record('attendance_sessions', $attData);

        if ($sessionId) {
            // Ensure attendance statuses exist.
            $this->ensureAttendanceStatuses($attinstanceId, $status);

            // If it's not a pre-session, create the attendance log for the student.
            if (!$pre) {
                $attStatus = $DB->get_record('attendance_statuses', ['acronym' => $status, 'attendanceid' => $attinstanceId]);
                $this->createAttLog($sessionId, $attStatus->id, $studentId, $sessdate, $zcrsid);
            }
        }
    }

    /**
     * Generate a random password of the specified length.
     *
     * @param int $length The length of the password.
     * @return string The generated password.
     */
    private function generateRandomPassword($length)
    {
        $chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
        return substr(str_shuffle($chars), 0, $length);
    }

    /**
     * Assign group to attendance session if topic contains group information.
     *
     * @param stdClass $attData The attendance session data object.
     * @param string $topic The topic which may contain group information.
     * @param int $zcrsid The course ID.
     */
    private function assignGroupIfExists(&$attData, $topic, $zcrsid)
    {
        global $DB;

        $namepieces = explode("_", $topic);

        if (count($namepieces) > 1 && $namepieces[0] === "GID") {
            $groupIdNumber = $namepieces[1];
            $group = $DB->get_record_sql("SELECT id FROM {groups} WHERE id = ? AND courseid = ?", [$groupIdNumber, $zcrsid]);

            if ($group) {
                $attData->groupid = $group->id;
            }
        }
    }

    /**
     * Ensure the required attendance statuses exist for the given instance.
     *
     * @param int $attinstanceId The attendance instance ID.
     * @param string $status The status acronym.
     */
    private function ensureAttendanceStatuses($attinstanceId, $status)
    {
        global $DB;

        $existingStatus = $DB->get_record('attendance_statuses', ['acronym' => $status, 'attendanceid' => $attinstanceId]);

        if (!$existingStatus) {
            // Create default attendance statuses.
            $this->createSessStatus($attinstanceId, "P", "Present", "2.00");
            $this->createSessStatus($attinstanceId, "A", "Absent", "0.00");
            $this->createSessStatus($attinstanceId, "L", "Late", "0.00");
            $this->createSessStatus($attinstanceId, "E", "Excused", "2.00");
        }
    }




    function createAttLog($sessid, $attStatus, $studentId, $sessdate, $zcrsid)
    {
        global $DB;

        // Check if the attendance log already exists for the student.
        $existingLog = $DB->record_exists('attendance_log', [
            'sessionid' => $sessid,
            'studentid' => $studentId
        ]);

        // If the log does not exist, insert the new attendance log.
        if (!$existingLog) {
            $newAttData = (object) [
                'sessionid'   => $sessid,
                'studentid'   => $studentId,
                'statusid'    => $attStatus,
                'statusset'   => '9,11,12,10',
                'remarks'     => '',
                'timetaken'   => $sessdate,
                'takenby'     => '2'
            ];
            $DB->insert_record('attendance_log', $newAttData);
        }

    }

    function createSessStatus($attInstanceId, $acronym, $desc, $grade)
    {
        global $DB;

        // Create a new stdClass object for attendance status.
        $attSessE = (object)[
            'attendanceid' => $attInstanceId,
            'acronym'      => $acronym,
            'description'  => $desc,
            'grade'        => $grade,
            'visible'      => 1,
            'deleted'      => 0,
            'setnumber'    => 0,
            'setunmarked'  => ($acronym === 'A') ? 1 : 0 // Automatically set 'setunmarked' for 'A' acronym.
        ];

        // Insert the record into the database.
        $DB->insert_record('attendance_statuses', $attSessE);
    }


// Add video as a label in Moodle.
    private function addVideoToMoodle($courseId, $videoId, $videoUrl, $firstName, $lastName, $recordingDate)
    {
        global $CFG, $DB, $COURSE;

        $label = new stdClass();
        $label->course = $courseId;
        $label->visible = 0;

        // Get the section for the course.
        $section = $DB->get_record_sql("SELECT MAX(section) AS section FROM {course_sections} WHERE course = ?", [$courseId]);
        $label->section = $section->section;

        // Get module ID for label.
        $moduleId = $DB->get_field('modules', 'id', ['name' => 'label']);
        $label->module = $moduleId;

        // Video HTML content.
        $label->intro = "<video width='300' height='175' controls='controls' controlsList='nodownload'>
                        <source src='$videoUrl' type='video/mp4'>$videoId</video>
                     <p>$firstName $lastName - $recordingDate</p>";
        $label->introformat = FORMAT_HTML;
        $label->name = $videoId;

        require_once($CFG->dirroot . '/mod/label/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');

        // Add the label to the course.
        add_moduleinfo($label, $COURSE);
    }
    function get_content()
    {
        global $USER, $COURSE, $DB;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->text = '';
        $courseContext = context_course::instance($COURSE->id);

        $clientId = get_config('block_zoomonline', 'client_id');
        $clientSecret = get_config('block_zoomonline', 'client_secret');
        $accountId = get_config('block_zoomonline', 'account_id');

        // Check if Zoom API credentials are set
        if (empty($clientId) || empty($clientSecret) || empty($accountId)) {
            $this->content->text = has_capability('moodle/course:manageactivities', $courseContext, $USER->id)
                ? "Zoom API client_id, client_secret or account_id missing, please check plugin config"
                : "";
            return $this->content;
        }
        // Check if the course has ended
        if ($this->courseHasEnded($COURSE->enddate)) {
            $this->content->text = has_capability('moodle/course:manageactivities', $courseContext, $USER->id)
                ? "This course's end date is in the past, so this will not generate links."
                : "There are no more online classes for this module.";
            return $this->content;
        }

        $teachers = $this->getCourseTeachers();
        $groups = $this->getCourseGroups();
        $this->content->text = $this->generateMeetingHtml($teachers, $groups);

        return $this->content;
    }

    private function courseHasEnded($courseEndDate)
    {
        return time() > $courseEndDate;
    }

    private function getCourseTeachers()
    {
        global $COURSE, $DB;
        $role = $DB->get_record('role', ['shortname' => 'editingteacher']);
        $context = context_course::instance($COURSE->id);
        return get_role_users($role->id, $context);
    }

    private function getCourseGroups()
    {
        global $COURSE, $DB;
        return $DB->get_records('groups', ['courseid' => $COURSE->id]) ?: [(object)['id' => 0, 'name' => '']];
    }

    private function generateMeetingHtml($teachers, $groups)
    {
        $html = "<div>";
        foreach ($teachers as $teacher) {
            foreach ($groups as $group) {
                $html .= $this->processGroupForTeacher($teacher, $group);
            }
        }
        return $html . "</div>" . $this->generateStyleAndScript();
    }

    private function processGroupForTeacher($teacher, $group)
    {
        global $DB, $COURSE, $USER;
        $groupId = $group->id;
        $zoomData = $DB->get_record('block_zoomonline', [
            'course_id' => $COURSE->id,
            'lecturer_id' => $teacher->id,
            'groupid' => $groupId
        ]);

        $meetingId = $this->manageZoomMeeting($zoomData, $teacher, $group);

        if ($meetingId) {
            $this->processZoomMeeting($meetingId, $teacher);
        }

        return $meetingId ? $this->generateMeetingLink($teacher, $group, $meetingId, $zoomData->visible ?? 1) : '';
    }


    private function processZoomMeeting($meetingId, $teacher)
    {
        global $COURSE;

        $this->processRecordings($meetingId, $teacher->firstname, $teacher->lastname);

        // Check if the attendance tracking setting is enabled.
        if (get_config('block_zoomonline', 'useattendance')) {
            // Check if the Attendance module is installed.
            $installedModules = core_plugin_manager::instance()->get_plugin_list('mod');

            if (array_key_exists('attendance', $installedModules)) {
                    // The Attendance module is installed, proceed with attendance processing.
                    // Your existing attendance processing logic here.
                    $this->processAttendance($meetingId, $COURSE->id);
                    $this->checkLiveMeeting($meetingId, $COURSE->id);
            } else {
                // The Attendance module is not installed, skip attendance processing.
                echo "Attendance module is not installed. Skipping attendance processing.";
                // You could log this event or take an alternative action if necessary.
            }
        }
    }

    private function manageZoomMeeting($zoomData, $teacher, $group)
    {
        global $DB, $USER;
        $meetingId = $zoomData->zoom_meeting_id ?? '';
        $hostEmail = $teacher->email;

        if ($meetingId && !ZoomHelper::checkMeetingExists($meetingId, $hostEmail)) {
            $this->deleteInvalidMeeting($zoomData);
            $meetingId = '';
        }


      if (!$meetingId && $USER->id === $teacher->id) {
            $meetingId = $this->createZoomMeeting($teacher, $group);
        }

        return $meetingId;
    }

    private function deleteInvalidMeeting($zoomData)
    {
        global $DB;
        $DB->delete_records('block_zoomonline', ['id' => $zoomData->id]);
    }

    private function createZoomMeeting($teacher, $group)
    {
        global $COURSE, $DB;
        $meetingTopic = $group->id ? "GID_{$group->id}_{$COURSE->idnumber}_{$group->name}" : $COURSE->idnumber;
        $meetingId = ZoomHelper::createMeeting($teacher->email, $meetingTopic);

        if ($meetingId) {
            $zoomData = (object)[
                'course_id' => $COURSE->id,
                'crscode' => $COURSE->idnumber,
                'lecturer_id' => $teacher->id,
                'zoom_meeting_id' => $meetingId,
                'groupid' => $group->id
            ];
            $DB->insert_record('block_zoomonline', $zoomData);
        }

        return $meetingId;
    }

    private function generateMeetingLink($teacher, $group, $meetingId, $visibility)
    {
        global $USER, $COURSE, $DB;
        $linkRoot = "https://@zoom.us/j/";
        $isVisible = $visibility != 2;
        $userHasAccess = $this->userHasAccess($teacher);

        if ($userHasAccess) {
            $eyeIcon = $isVisible
                ? "<span class='live_join_class_eye' data-id='{$meetingId}' data-visible='2'><i class='icon fa fa-eye fa-fw' aria-hidden='true'></i></span>"
                : "<span class='live_join_class_eye' data-id='{$meetingId}' data-visible='1'><i class='icon fa fa-eye-slash fa-fw' aria-hidden='true'></i></span>";

            return "
            <div class='mb-3 d-inline-block w-100 live_join_class_conn " . (!$isVisible ? 'eye_disabled' : '') . "'>
                <span style='line-height: 36px;'>{$teacher->firstname} {$teacher->lastname}: {$group->name}</span>
                $eyeIcon
                <a href='{$linkRoot}{$meetingId}' target='_blank' class='btn btn-primary float-right'>Join</a>
            </div>";
        }

        if ($isVisible && $this->userIsInGroup($group)) {
            return "
            <div class='mb-3 d-inline-block w-100 live_join_class_conn'>
                <span style='line-height: 36px;'>{$teacher->firstname} {$teacher->lastname}: {$group->name}</span>
                <a href='{$linkRoot}{$meetingId}' target='_blank' class='btn btn-primary float-right'>Join</a>
            </div>";
        }

        return '';
    }

    private function userHasAccess($teacher)
    {
        global $USER;
        return $USER->id === $teacher->id || is_siteadmin($USER);
    }

    private function userIsInGroup($group)
    {
        global $DB, $USER;
        return $group->id == 0 || $DB->record_exists('groups_members', [
                'userid' => $USER->id,
                'groupid' => $group->id
            ]);
    }

    private function generateStyleAndScript()
    {
        return "
        <style>
            .live_join_class_conn { border: 1px solid #717171; padding: 6px; }
            .live_join_class_conn.eye_disabled { border-color: #989898; color: #989898; }
            .live_join_class_eye:not(.eye_disabled) .icon.fa { cursor: pointer; }
        </style>
        <script>
            setTimeout(function() {
                $('.live_join_class_eye').click(function() {
                    var meetingId = $(this).data('id');
                    $.post('/blocks/zoomonline/ajax.php', {
                        id: meetingId,
                        visible: $(this).data('visible')
                    }, function(data) {
                        window.location.reload();
                    });
                });
            }, 4000);
        </script>";
    }





    public function applicable_formats()
	{
		return array(
			'all' => false,
			'site' => true,
			'site-index' => true,
			'course-view' => true,
			'course-view-social' => false,
			'mod' => true,
			'mod-quiz' => false
		);
	}

	public function instance_allow_multiple()
	{
		return true;
	}

	function has_config()
	{
		return true;
	}
}
