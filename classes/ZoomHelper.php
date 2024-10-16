<?php

defined('MOODLE_INTERNAL') || die();

class ZoomHelper
{

    private static $cachedToken = null;
    private static $tokenExpiryTime = 3500; // Token is valid for 1 hour, subtract a buffer

    private static function getZoomCredentials()
    {
        $clientId = get_config('block_zoomonline', 'client_id');
        $clientSecret = get_config('block_zoomonline', 'client_secret');
        $accountId = get_config('block_zoomonline', 'account_id');

        if (empty($clientId) || empty($clientSecret) || empty($accountId)) {
            self::handleApiError('Missing Zoom API credentials');
        }

        return [$clientId, $clientSecret, $accountId];
    }
    public static function handleApiError($errorMessage, $response = [], $throwException = true)
    {
        // Log the error message to Moodle's error log.
        error_log('Zoom API Error: ' . $errorMessage);

        // Optionally, log more detailed information from the API response if available.
        if (!empty($response)) {
            error_log('Zoom API Response: ' . json_encode($response));
        }

        // You can notify administrators here if necessary (optional):
        // For example, you can send an email to admins when a critical error occurs.

        // Prepare a user-friendly error message for Moodle.
        $friendlyErrorMessage = get_string('zoomapierror', 'block_zoomonline', $errorMessage);

        // Optionally, display an error message to the user in the Moodle interface.
        if ($throwException) {
            throw new moodle_exception('zoomapierror', 'block_zoomonline', '', null, $friendlyErrorMessage);
        }
    }

    /**
     * Generate a new Zoom access token.
     *
     * @throws moodle_exception
     * @return string The new access token.
     */
    public static function generateNewToken()
    {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/local/guzzle/extlib/vendor/autoload.php');

        [$clientId, $clientSecret, $accountId] = self::getZoomCredentials();

        try {
            // Request a new access token using Guzzle HTTP client.
            $client = new \GuzzleHttp\Client(['base_uri' => 'https://zoom.us']);
            $response = $client->request('POST', '/oauth/token', [
                'query' => [
                    'grant_type' => 'account_credentials',
                    'account_id' => $accountId
                ],
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode("$clientId:$clientSecret"),
                    'Content-Type' => 'application/json'
                ]
            ]);

            $contents = json_decode($response->getBody()->getContents());
            $accessToken = $contents->access_token ?? null;
            $currentTime = time();

            if (!empty($accessToken)) {
                // Store the token in the database.
                $record = new stdClass();
                $record->access_token = $accessToken;
                $record->current = $currentTime;
                $DB->insert_record('block_zoomtoken', $record);

                // Update the cached token with the new token.
                self::$cachedToken = ['access_token' => $accessToken, 'timestamp' => $currentTime];

                return $accessToken;
            } else {
                throw new moodle_exception('invalidresponse', 'block_zoomonline', '', null, 'Invalid token response from Zoom API');
            }
        } catch (\Exception $e) {
            error_log('Zoom API token generation failed: ' . $e->getMessage());
            throw new moodle_exception('tokenerror', 'block_zoomonline', '', null, $e->getMessage());
        }
    }

    /**
     * Get the current Zoom access token, regenerating if expired.
     *
     * @throws moodle_exception
     * @return string The current access token.
     */
    public static function getCurrentAccessKey()
    {
        global $DB, $CFG;

        // Check if we already have a cached token within the execution context.
        if (self::$cachedToken !== null && (time() - self::$cachedToken['timestamp']) < self::$tokenExpiryTime) {
            return self::$cachedToken['access_token'];
        }

        // Fetch the latest access token from the database.
        $sqlSelect = "SELECT id, current, access_token FROM {$CFG->dbname}.mdl_block_zoomtoken ORDER BY id DESC LIMIT 1";
        $record = $DB->get_record_sql($sqlSelect);

        // If the token is expired or not found, generate a new one.
        if (!$record || (time() - $record->current > self::$tokenExpiryTime)) {
            $newToken = self::generateNewToken();
            self::$cachedToken = ['access_token' => $newToken, 'timestamp' => time()];
            return $newToken;
        }

        // Cache the token for the duration of the request and return it.
        self::$cachedToken = ['access_token' => $record->access_token, 'timestamp' => $record->current];
        return $record->access_token;
    }

    /**
     * Create a new Zoom meeting.
     *
     * @param string $hostEmail The host email for the meeting.
     * @param string $name The name of the meeting.
     * @return string|null The Zoom meeting ID.
     * @throws moodle_exception
     */
    public static function createMeeting($hostEmail, $name)
    {
        $accessKey = self::getCurrentAccessKey();
        $postData = [
            "topic" => $name,
            "type" => "3",
            "timezone" => "Europe/Dublin",
            "schedule_for" => $hostEmail,
            "agenda" => $name,
            "settings" => [
                "use_pmi" => false,
                "auto_recording" => "cloud"
            ]
        ];

        $postFields = json_encode($postData);

        do {
            $url = "users/$hostEmail/meetings";
            $response = self::executeZoomApiRequest($url, 'POST', $accessKey, $postFields);

            // If token has expired, generate a new one and retry.
            if (isset($response['error']) && $response['error'] == '401') {
                $accessKey = self::generateNewToken();
            } else {
                // Break loop if success or any other error occurs.
                break;
            }
        } while (true);

        if (isset($response['id'])) {
            return $response['id'];
        }

        error_log('Error in Zoom meeting creation response: ' . json_encode($response));

//	var_dump($response);

//die();
 
//       throw new moodle_exception('zoomapierror', 'block_zoomonline', '', null, 'Error in Zoom API response');
    }

    /**
     * Check if a Zoom meeting exists.
     *
     * @param string $meetingId The Zoom meeting ID.
     * @param string $hostEmail The host email for the meeting.
     * @return bool True if the meeting exists, false otherwise.
     */
    public static function checkMeetingExists($meetingId, $hostEmail)
    {
        $accessKey = self::getCurrentAccessKey();
        $response = self::executeZoomApiRequest("meetings/$meetingId", 'GET', $accessKey);

        if ($response && isset($response['id']) && isset($response['host_email'])) {
            return (trim($response['id']) === trim($meetingId) &&
                strtolower(trim($response['host_email'])) === strtolower(trim($hostEmail)));
        }

        return false;
    }

    /**
     * Get meeting data from Zoom API.
     *
     * @param string $meetingid
     * @return array|null Meeting data or null if error.
     */
    public static  function getMeetingData($meetingid)
    {
        $accessKey = self::getCurrentAccessKey();

        $response = self::executeZoomApiRequest("report/meetings/$meetingid", 'GET', $accessKey);

        return $response ? json_decode($response, true) : null;
    }



    public static function getParticipants($meetingId, $nextpagetoken = "")
    {
        $accessKey = self::getCurrentAccessKey();

        // Construct the URL with the next_page_token if it exists
        $url = "report/meetings/$meetingId/participants";

        if (!empty($nextpagetoken)) {
            $url .= "?next_page_token=" . urlencode($nextpagetoken); // Properly append next_page_token
        }

        // Execute the Zoom API request
        $response = self::executeZoomApiRequest($url, 'GET', $accessKey);

        return $response;
    }

    /**
     * Fetch Zoom meeting recordings.
     *
     * @param string $meetingId The Zoom meeting ID.
     * @return array The list of recording files.
     */
    public static function getMeetingRecordings($meetingId)
    {
        $accessKey = self::getCurrentAccessKey();
        $response = self::executeZoomApiRequest("meetings/$meetingId/recordings", 'GET', $accessKey);

        return $response['recording_files'] ?? [];
    }

    /**
     * Delete Zoom meeting recording.
     *
     * @param string $meetingId The Zoom meeting ID.
     * @param string $videoId The recording video ID.
     */
    public static function deleteZoomRecording($meetingId, $videoId)
    {
        $accessKey = self::getCurrentAccessKey();
        self::executeZoomApiRequest("meetings/$meetingId/recordings/$videoId?action=trash", 'DELETE', $accessKey);
    }

    /**
     * Check the status of a Zoom meeting.
     *
     * @param string $meetingId The Zoom meeting ID.
     * @return string|null The status of the meeting (e.g., 'started', 'waiting').
     */
    public static function getMeetingStatus($meetingId)
    {
        $accessKey = self::getCurrentAccessKey();
        $response = self::executeZoomApiRequest("/meetings/$meetingId", 'GET', $accessKey);

        return $response ?? null;
    }

    public static function getMeetingMetrics($meetingId)
    {
        $accessKey = self::getCurrentAccessKey();
        $response = self::executeZoomApiRequest("/metrics/meetings/$meetingId", 'GET', $accessKey);

        return $response ?? null;
    }

    /**
     * Download a Zoom video file.
     *
     * @param string $url The download URL.
     * @param string $accessKey The Zoom access token.
     * @param string $videoId The video ID.
     * @return string|null The path to the downloaded file, or null if download failed.
     */
    public static function downloadVideo($url, $accessKey, $videoId)
    {
        $localfolder = get_config('block_zoomonline', 'localfolder');
        $tempDir = $localfolder  ?: sys_get_temp_dir();
        $tempFilePath = $tempDir . $videoId . ".mp4";

        $fp = fopen($tempFilePath, 'wb');

        $params = ['access_token' => $accessKey];

        $curlUrl = $url . '?' . http_build_query($params);



        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $curlUrl);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        curl_close($ch);
        fclose($fp);

        return file_exists($tempFilePath) ? $tempFilePath : null;
    }

    /**
     * Set cURL options and execute the Zoom API request.
     *
     * @param string $url API URL.
     * @param string $method HTTP method (GET, POST, etc.).
     * @param string $accessKey Access token.
     * @param mixed|null $postOptions POST data if applicable.
     * @return array The decoded response from the Zoom API.
     * @throws moodle_exception
     */
    private static function executeZoomApiRequest($url, $method, $accessKey, $postOptions = null)
    {
        $curl = curl_init();
        self::setCurlOptions($url, $curl, $method, $accessKey, $postOptions);
        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            throw new moodle_exception('zoomapierror', 'block_zoomonline', '', null, $err);
        }

        return json_decode($response, true);
    }

    /**
     * Set cURL options for a Zoom API request.
     *
     * @param string $url The API endpoint URL.
     * @param resource $curl The cURL resource.
     * @param string $method The HTTP method.
     * @param string $accessKey The Zoom access token.
     * @param mixed|null $postOptions POST data if applicable.
     */
    private static function setCurlOptions($url, $curl, $method, $accessKey, $postOptions = null)
    {
        $curlOptions = [
            CURLOPT_URL => "https://api.zoom.us/v2/$url",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer $accessKey",
                "Content-Type: application/json",
            ],
        ];

        if ($postOptions) {
            $curlOptions[CURLOPT_POSTFIELDS] = $postOptions;
        }

        curl_setopt_array($curl, $curlOptions);
    }
}
