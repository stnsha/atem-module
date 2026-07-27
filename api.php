<?php
date_default_timezone_set('Asia/Kuala_Lumpur');

// Only set JSON header if this file is accessed directly (not included)
if (!defined('API_JWT_INCLUDED')) {
    header('Content-Type: application/json');
}

// header.php normally defines this, but api.php is also hit directly as a
// standalone POST endpoint (no header.php in that request) - mailer.php's
// buildAtemCardLink() needs it, so define it here too if not already set.
if (!defined('ATEM_BASE')) {
    define('ATEM_BASE', '/odb/' . basename(dirname(__FILE__)) . '/');
}

// Start session if not already started (PHP 5.3 compatible)
if (session_id() == '') {
    session_start();
}

// Include database connection (reuse the existing one when included)
if (!isset($conn)) {
    $connect = 1;
    include(__DIR__ . '/../common/index_adv.php');
}

if (!isset($conn)) {
    die(json_encode(array("status" => 500, "message" => "Database connection error")));
}

require_once __DIR__ . '/mailer.php';

// Get staff information from session
$staff_id = null;
$department = null;
$outlet = null;
$nama_staff = null;

if (isset($_SESSION["myusername"])) {
    $username = $_SESSION["myusername"];
    $query = "select * from staff where username = '$username' and recycle!=1";
    $result = $conn->query($query);

    if ($result && $result->num_rows > 0) {
        while ($rows = $result->fetch_assoc()) {
            $staff_id   = stripslashes((string)$rows['id']);
            $department = stripslashes((string)$rows['department']);
            $outlet     = stripslashes((string)$rows['outlet']);
            $nama_staff = stripslashes((string)$rows['nama_staff']);
            $atem_flag  = isset($rows['atem']) ? (int)$rows['atem'] : 0;
        }
    }
}

// Dev department-view override (mirrors header.php): when api.php is hit
// directly over AJAX (dashboard-stats, get-performance-list, etc.) header.php
// hasn't run in this request, and even when api.php is included after
// header.php (view.php), the fresh DB query above would otherwise clobber
// header.php's own override - so this needs to run again here.
if (isset($_SESSION['atem_dev_role_override']) && isset($_SESSION['atem_dev_view_override'])) {
    if ($_SESSION['atem_dev_view_override'] === 'outlet') {
        $department = '1';
    } elseif ($_SESSION['atem_dev_view_override'] === 'hq') {
        $_hq_dept_ids = array();
        foreach (explode(',', (string)$department) as $_hd) {
            $_hd = (int)trim($_hd);
            if ($_hd > 0 && $_hd !== 1) {
                $_hq_dept_ids[] = $_hd;
            }
        }
        if (empty($_hq_dept_ids)) {
            $_hq_fallback_r = mysqli_query($conn, "SELECT id FROM staff_department WHERE id != 1 ORDER BY id ASC LIMIT 1");
            if ($_hq_fallback_r && ($_hq_fallback_row = mysqli_fetch_assoc($_hq_fallback_r))) {
                $_hq_dept_ids[] = (int)$_hq_fallback_row['id'];
            }
        }
        $department = implode(',', $_hq_dept_ids);
    }
}

// Mirrors header.php logic: dev override suppresses SuperAdmin.
$is_api_superadmin = (!isset($_SESSION['atem_dev_role_override']) && !empty($atem_flag) && (int)$atem_flag === 1);

/**
 * Log JWT API operations for monitoring and debugging
 * @param string $operation Operation name (e.g., 'getJWTToken')
 * @param string $message Log message describing the event
 * @param mixed $data Optional context data (will be JSON encoded if array)
 * @param string $level Log level: INFO, WARNING, ERROR
 * @return bool Success status of log write operation
 */
function logJWTOperation($operation, $message, $data = null, $level = 'INFO')
{
    $log_dir = __DIR__ . '/logs';
    $log_file = $log_dir . '/jwt_operations.log';

    // Create logs directory if it doesn't exist
    if (!is_dir($log_dir)) {
        if (!@mkdir($log_dir, 0755, true)) {
            @mkdir($log_dir, 0755);
        }
    }

    // Verify logs directory exists
    if (!is_dir($log_dir)) {
        return false;
    }

    // Ensure directory is writable
    if (!is_writable($log_dir)) {
        @chmod($log_dir, 0755);
    }

    // Build log message
    $timestamp = date('Y-m-d H:i:s');
    $env = getEnvironment();
    $log_message = "[$timestamp] [$env.$level] [$operation] $message";

    // Append data if provided
    if ($data !== null) {
        if (is_array($data)) {
            $log_message .= ' | ' . json_encode($data);
        } else {
            $log_message .= ' | ' . $data;
        }
    }

    $log_message .= "\n";

    // Write to log file with fallback
    $result = @file_put_contents($log_file, $log_message, FILE_APPEND);

    // If write failed and file doesn't exist, create it
    if ($result === false && !file_exists($log_file)) {
        @touch($log_file);
        @chmod($log_file, 0644);
        $result = @file_put_contents($log_file, $log_message, FILE_APPEND);
    }

    return $result !== false;
}

/**
 * Get current environment (local or production)
 * @return string Environment name ('local' or 'production')
 */
function getEnvironment()
{
    // Check if running on localhost (PHP 5.3 compatible)
    $serverName = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : '';
    $httpHost = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';

    $isLocal = in_array($serverName, array('localhost', '127.0.0.1')) ||
        strpos($serverName, 'localhost') !== false ||
        strpos($httpHost, 'localhost') !== false ||
        strpos($httpHost, '127.0.0.1') !== false;

    return $isLocal ? 'local' : 'staging';
}

/**
 * Get API host based on environment (auto-detect)
 * @return string API host URL
 */
function getApiHost()
{
    $env = getEnvironment();

    if ($env === 'local') {
        return 'http://127.0.0.1:8000/api/';
    } else {
        return 'http://mytotalhealth.com.my/atem-staging/public/api/';
    }
}

/**
 * Get the service account credentials used to obtain a JWT.
 * A dedicated service account is used for now; the auth model will be
 * specialised later.
 * @return array Credentials (email, password)
 */
function getServiceCredentials()
{
    return array(
        'email'    => 'atem-service@local',
        'password' => 'atem-service-local'
    );
}

/**
 * Get staff information for the current user from the odb database.
 * @param int $staff_id Staff ID from session
 * @return array Staff data or null if not found
 */
function getStaffAuthData($staff_id)
{
    global $conn;

    $staff_id = mysqli_real_escape_string($conn, $staff_id);

    logJWTOperation(
        'getStaffAuthData',
        'Retrieving staff data',
        array('staff_id' => $staff_id),
        'INFO'
    );

    $query = "SELECT s.id, s.nama_staff, s.department, d.depart_name
              FROM staff s
              LEFT JOIN staff_department d ON s.department = d.id
              WHERE s.id = $staff_id";
    $result = mysqli_query($conn, $query);

    if (!$result) {
        logJWTOperation(
            'getStaffAuthData',
            'Database query failed',
            array('staff_id' => $staff_id, 'error' => mysqli_error($conn)),
            'ERROR'
        );
        return null;
    }

    $row = mysqli_fetch_assoc($result);
    if (!$row) {
        logJWTOperation(
            'getStaffAuthData',
            'Staff not found',
            array('staff_id' => $staff_id),
            'WARNING'
        );
        return null;
    }

    return array(
        'staff_id'        => (int)$row['id'],
        'staff_name'      => $row['nama_staff'],
        'staff_dept_id'   => $row['department'] !== null ? (int)$row['department'] : null,
        'department_name' => $row['depart_name']
    );
}

/**
 * Resolve a staff_id to their email + display name (for notification emails).
 */
function getStaffEmail($staff_id)
{
    global $conn;

    $staff_id = (int)$staff_id;
    if (!$staff_id) {
        return null;
    }

    $result = mysqli_query($conn, "SELECT email, nama_staff FROM staff WHERE id = $staff_id AND recycle != 1");
    if (!$result) {
        return null;
    }

    $row = mysqli_fetch_assoc($result);
    if (!$row || empty($row['email'])) {
        return null;
    }

    return array(
        'email' => $row['email'],
        'name'  => $row['nama_staff']
    );
}

/**
 * Get JWT token from the atem-api service using the service account
 * @param string $email Service account email
 * @param string $password Service account password
 * @return string|null JWT token or null on failure
 */
function getJWTToken($email, $password)
{
    logJWTOperation(
        'getJWTToken',
        'Requesting new JWT token',
        array('email' => $email),
        'INFO'
    );

    $host = getApiHost();
    $url = $host . 'login';

    $authData = array(
        'email'    => $email,
        'password' => $password
    );

    $headers = array(
        'Accept: application/json',
        'Content-Type: application/json'
    );

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($authData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        logJWTOperation(
            'getJWTToken',
            'Failed to obtain JWT token',
            array('httpCode' => $httpCode, 'error' => $error, 'response' => $response),
            'ERROR'
        );

        error_log("ATEM JWT Auth failed: HTTP $httpCode, Error: $error, Response: $response");
        return null;
    }

    logJWTOperation(
        'getJWTToken',
        'JWT token obtained successfully',
        array('httpCode' => $httpCode),
        'INFO'
    );

    $decoded = json_decode($response, true);

    // Cache the token TTL alongside the token for getAuthToken to use.
    if (isset($decoded['data']['expires_in'])) {
        $_SESSION['jwt_expires_in'] = (int)$decoded['data']['expires_in'];
    }

    return isset($decoded['data']['access_token']) ? $decoded['data']['access_token'] : null;
}

/**
 * Get or refresh JWT token with caching
 * @param int $staff_id Staff ID from session
 * @return string|null JWT token or null on failure
 */
function getAuthToken($staff_id)
{
    // Check if token exists in session and is still valid (basic check)
    if (
        isset($_SESSION['jwt_token']) && isset($_SESSION['jwt_expires']) &&
        time() < $_SESSION['jwt_expires']
    ) {
        logJWTOperation(
            'getAuthToken',
            'Using cached token',
            array('staff_id' => $staff_id, 'expiry' => date('Y-m-d H:i:s', $_SESSION['jwt_expires'])),
            'INFO'
        );
        return $_SESSION['jwt_token'];
    } else if (isset($_SESSION['jwt_expires'])) {
        logJWTOperation(
            'getAuthToken',
            'Cached token expired, refreshing',
            array('staff_id' => $staff_id, 'expired_at' => date('Y-m-d H:i:s', $_SESSION['jwt_expires'])),
            'WARNING'
        );
    }

    // Obtain a token using the service account credentials
    $creds = getServiceCredentials();
    $token = getJWTToken($creds['email'], $creds['password']);

    if ($token) {
        // Store token in session using the TTL returned by the API (default 1 hour)
        $ttl = isset($_SESSION['jwt_expires_in']) ? (int)$_SESSION['jwt_expires_in'] : 3600;
        $_SESSION['jwt_token'] = $token;
        $_SESSION['jwt_expires'] = time() + $ttl;
        $_SESSION['jwt_staff_id'] = $staff_id;

        logJWTOperation(
            'getAuthToken',
            'New token cached',
            array('staff_id' => $staff_id, 'expiry' => date('Y-m-d H:i:s', $_SESSION['jwt_expires'])),
            'INFO'
        );
    } else {
        logJWTOperation(
            'getAuthToken',
            'Failed to get auth token',
            array('staff_id' => $staff_id),
            'ERROR'
        );
    }

    return $token;
}

/**
 * Make API call with JWT authentication
 * @param string $endpoint API endpoint
 * @param array|null $data Request data
 * @param string $method HTTP method
 * @param int $staff_id Staff ID for authentication
 * @return array API response
 */
function getApiDataWithJWT($endpoint, $data = null, $method = 'GET', $staff_id = null, $curlTimeout = 30)
{
    logJWTOperation(
        'getApiDataWithJWT',
        'Starting API call',
        array(
            'endpoint' => $endpoint,
            'method' => $method,
            'staff_id' => $staff_id,
            'has_data' => $data !== null
        ),
        'INFO'
    );

    $host = getApiHost();
    $url = $host . $endpoint;

    // Get JWT token
    $token = getAuthToken($staff_id);
    if (!$token) {
        logJWTOperation(
            'getApiDataWithJWT',
            'Authentication failed - no token',
            array('endpoint' => $endpoint, 'staff_id' => $staff_id),
            'ERROR'
        );

        return array(
            'success' => false,
            'error' => 'Authentication failed - could not get JWT token',
            'response' => json_encode(array('error' => 'Authentication failed')),
            'httpCode' => 401
        );
    }

    $method = strtoupper($method);

    $headers = array(
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'Content-Type: application/json'
    );

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, $curlTimeout);

    if ($method === 'GET') {
        curl_setopt($ch, CURLOPT_URL, $url);
    } elseif ($method === 'POST') {
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'PATCH') {
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    }

    // Log the outgoing request
    logJWTOperation(
        'getApiDataWithJWT',
        'Sending request to API',
        array(
            'url' => $url,
            'method' => $method,
            'data' => $data
        ),
        'INFO'
    );

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    // If unauthorized, clear token and retry once
    if ($httpCode === 401 && isset($_SESSION['jwt_token'])) {
        unset($_SESSION['jwt_token']);
        unset($_SESSION['jwt_expires']);

        // Get new token and retry
        $token = getAuthToken($staff_id);
        if ($token) {
            $headers[0] = 'Authorization: Bearer ' . $token; // Update Authorization in request headers array
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
        }
    }

    curl_close($ch);

    // Handle HTTP status codes
    if ($response === false) {
        return array(
            'success' => false,
            'error' => 'API Request Failed',
            'message' => 'cURL error: ' . $error,
            'response' => json_encode(array('error' => 'No response')),
            'httpCode' => 0
        );
    }

    // Success codes: 200, 201, 204
    if ($httpCode === 200 || $httpCode === 201 || $httpCode === 204) {
        logJWTOperation(
            'getApiDataWithJWT',
            'API call successful',
            array(
                'endpoint' => $endpoint,
                'httpCode' => $httpCode,
                'response_length' => strlen($response)
            ),
            'INFO'
        );

        return array(
            'success' => true,
            'response' => $response,
            'httpCode' => $httpCode
        );
    }

    // Handle error codes
    $decodedError = json_decode($response, true);
    $errorMessage = isset($decodedError['message']) ? $decodedError['message'] : 'API Request Failed';

    logJWTOperation(
        'getApiDataWithJWT',
        'API call failed',
        array(
            'endpoint' => $endpoint,
            'httpCode' => $httpCode,
            'message' => $errorMessage,
            'response' => $response
        ),
        'ERROR'
    );

    return array(
        'success' => false,
        'error' => $errorMessage,
        'message' => $errorMessage,
        'response' => $response,
        'httpCode' => $httpCode,
        'details' => $decodedError
    );
}

/**
 * Get ATEM lookups (levels, rules, statuses)
 * @param int $staff_id Staff ID for authentication
 * @return array Lookups data
 */
function getAtemLookups($staff_id)
{
    $result = getApiDataWithJWT('atem/lookups', null, 'GET', $staff_id);
    $httpCode = $result['httpCode'];
    $decoded = json_decode($result['response'], true);

    if ($httpCode == 200) {
        return array(
            'success' => true,
            'data' => isset($decoded['data']) ? $decoded['data'] : $decoded
        );
    } else {
        return array(
            'success' => false,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'Failed to retrieve lookups'
        );
    }
}

/**
 * Create a draft ATEM card
 * @param array $data Issuer snapshot data
 * @param int $staff_id Staff ID for authentication
 * @return array Creation result
 */
function createAtemDraft($data, $staff_id)
{
    $result = getApiDataWithJWT('atem', $data, 'POST', $staff_id);
    $httpCode = $result['httpCode'];
    $decoded = json_decode($result['response'], true);

    if ($httpCode == 200 || $httpCode == 201) {
        return array(
            'success' => true,
            'data' => isset($decoded['data']) ? $decoded['data'] : null,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'Draft created successfully'
        );
    } else {
        return array(
            'success' => false,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'Failed to create draft',
            'errors' => isset($decoded['errors']) ? $decoded['errors'] : null
        );
    }
}

/**
 * Persist a whole ATEM card (fields + ARCI + reference links) in one call.
 * Forwards to the atem-api bulk store (POST /atem). mode=final|draft controls
 * the resulting record_state.
 * @param array $data Full card payload
 * @param int $staff_id Staff ID for authentication
 * @return array Result with the new {id, record_state}
 */
function saveAtemCard($data, $staff_id)
{
    $result = getApiDataWithJWT('atem', $data, 'POST', $staff_id);
    $httpCode = $result['httpCode'];
    $decoded = json_decode($result['response'], true);

    if ($httpCode == 200 || $httpCode == 201) {
        return array(
            'success' => true,
            'data' => isset($decoded['data']) ? $decoded['data'] : null,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'ATEM saved successfully'
        );
    } else {
        return array(
            'success' => false,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'Failed to save ATEM',
            'errors' => isset($decoded['errors']) ? $decoded['errors'] : null
        );
    }
}

/**
 * Get the list of ATEM cards for the listing page.
 * @param int $staff_id Staff ID for authentication
 * @param bool $include_deleted Whether to include soft-deleted cards (grade 4+/SA only)
 * @return array Result with the atem rows
 */
function getAtemList($staff_id, $include_deleted = false)
{
    $endpoint = $include_deleted ? 'atem?include_deleted=1' : 'atem';
    $result = getApiDataWithJWT($endpoint, null, 'GET', $staff_id);
    $httpCode = $result['httpCode'];
    $decoded = json_decode($result['response'], true);

    if ($httpCode == 200) {
        return array(
            'success' => true,
            'data' => isset($decoded['data']) ? $decoded['data'] : array()
        );
    } else {
        return array(
            'success' => false,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'Failed to retrieve ATEM list',
            'data' => array()
        );
    }
}

/**
 * Get ATEM list for a specific staff member (admin use).
 * Falls back to PHP-side filtering if backend ignores the staff_id param.
 * @param int $target_staff_id The staff whose ATEMs to fetch
 * @param int $staff_id Current user's staff ID for JWT auth
 * @return array Result with data array
 */
function getStaffAtemList($target_staff_id, $staff_id)
{
    $result = getApiDataWithJWT('atem?staff_id=' . (int)$target_staff_id, null, 'GET', $staff_id);
    $httpCode = $result['httpCode'];
    $decoded  = json_decode($result['response'], true);
    if ($httpCode == 200) {
        return array('success' => true, 'data' => isset($decoded['data']) ? $decoded['data'] : array());
    }
    return array('success' => false, 'message' => isset($decoded['message']) ? $decoded['message'] : 'Failed to retrieve ATEM list', 'data' => array());
}

/**
 * Get a single ATEM card by ID
 * @param int $id ATEM ID
 * @param int $staff_id Staff ID for authentication
 * @return array ATEM data
 */
function getAtem($id, $staff_id)
{
    $result = getApiDataWithJWT('atem/' . (int)$id, null, 'GET', $staff_id);
    $httpCode = $result['httpCode'];
    $decoded = json_decode($result['response'], true);

    if ($httpCode == 200) {
        return array(
            'success' => true,
            'data' => isset($decoded['data']) ? $decoded['data'] : $decoded
        );
    } else {
        return array(
            'success' => false,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'Failed to retrieve ATEM'
        );
    }
}

/**
 * Update an existing ATEM card
 * @param int $id ATEM ID
 * @param array $data Updated ATEM data
 * @param int $staff_id Staff ID for authentication
 * @return array Update result
 */
function updateAtem($id, $data, $staff_id)
{
    $result = getApiDataWithJWT('atem/' . (int)$id, $data, 'PUT', $staff_id);
    $httpCode = $result['httpCode'];
    $decoded = json_decode($result['response'], true);

    if ($httpCode == 200) {
        return array(
            'success' => true,
            'data' => isset($decoded['data']) ? $decoded['data'] : null,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'ATEM updated successfully'
        );
    } else {
        return array(
            'success' => false,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'Failed to update ATEM',
            'errors' => isset($decoded['errors']) ? $decoded['errors'] : null
        );
    }
}

/**
 * Update only Title and Description while an ATEM card is Suspended (Issuer only).
 * @param int $id ATEM ID
 * @param array $data title/description payload
 * @param int $staff_id Staff ID for authentication
 * @return array Update result
 */
function updateAtemSuspendedFields($id, $data, $staff_id)
{
    $result = getApiDataWithJWT('atem/' . (int)$id . '/suspended-fields', $data, 'PUT', $staff_id);
    $httpCode = $result['httpCode'];
    $decoded = json_decode($result['response'], true);

    if ($httpCode == 200) {
        return array(
            'success' => true,
            'data' => isset($decoded['data']) ? $decoded['data'] : null,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'ATEM updated successfully'
        );
    } else {
        return array(
            'success' => false,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'Failed to update ATEM'
        );
    }
}

/**
 * Soft-delete a Draft, Active, or Suspended ATEM card (Issuer or SuperAdmin)
 * @param int $id ATEM ID
 * @param int $staff_id Staff ID for authentication
 * @param string $remarks Mandatory deletion remark
 * @param bool $is_superadmin Whether the requester is a real SuperAdmin
 * @return array Result
 */
function deleteAtem($id, $staff_id, $remarks, $is_superadmin = false)
{
    $endpoint = 'atem/' . (int)$id;
    $payload  = array('actor_id' => (int)$staff_id, 'remarks' => $remarks);
    if ($is_superadmin) {
        $payload['superadmin_override'] = 1;
    }
    $result   = getApiDataWithJWT($endpoint, $payload, 'DELETE', $staff_id);
    $httpCode = $result['httpCode'];
    $decoded  = json_decode($result['response'], true);
    if ($httpCode >= 200 && $httpCode < 300 && !empty($decoded['success'])) {
        return array('success' => true);
    }
    $msg = (!empty($decoded['message'])) ? $decoded['message'] : 'Failed to delete ATEM.';
    return array('success' => false, 'message' => $msg);
}

function suspendAtem($id, $staff_id, $remarks)
{
    $endpoint = 'atem/' . (int)$id . '/suspend';
    $result   = getApiDataWithJWT($endpoint, array('actor_id' => (int)$staff_id, 'remarks' => $remarks), 'POST', $staff_id);
    $httpCode = $result['httpCode'];
    $decoded  = json_decode($result['response'], true);
    if ($httpCode >= 200 && $httpCode < 300 && !empty($decoded['success'])) {
        return array('success' => true);
    }
    $msg = (!empty($decoded['message'])) ? $decoded['message'] : 'Failed to suspend ATEM.';
    return array('success' => false, 'message' => $msg);
}

function appealAtem($id, $staff_id, $remarks)
{
    $endpoint = 'atem/' . (int)$id . '/appeal';
    $result   = getApiDataWithJWT($endpoint, array('actor_id' => (int)$staff_id, 'remarks' => $remarks), 'POST', $staff_id);
    $httpCode = $result['httpCode'];
    $decoded  = json_decode($result['response'], true);
    if ($httpCode >= 200 && $httpCode < 300 && !empty($decoded['success'])) {
        return array('success' => true, 'data' => isset($decoded['data']) ? $decoded['data'] : null);
    }
    $msg = (!empty($decoded['message'])) ? $decoded['message'] : 'Failed to submit appeal.';
    return array('success' => false, 'message' => $msg);
}

function unsuspendAtem($id, $staff_id)
{
    $endpoint = 'atem/' . (int)$id . '/unsuspend';
    $result   = getApiDataWithJWT($endpoint, array('actor_id' => (int)$staff_id), 'POST', $staff_id);
    $httpCode = $result['httpCode'];
    $decoded  = json_decode($result['response'], true);
    if ($httpCode >= 200 && $httpCode < 300 && !empty($decoded['success'])) {
        return array('success' => true);
    }
    $msg = (!empty($decoded['message'])) ? $decoded['message'] : 'Failed to unsuspend ATEM.';
    return array('success' => false, 'message' => $msg);
}

function updatePayoutStatus($id, $status, $remarks, $staff_id)
{
    $endpoint = 'atem/' . (int)$id . '/payout-status';
    $result   = getApiDataWithJWT($endpoint, array(
        'actor_id'      => (int)$staff_id,
        'payout_status' => $status,
        'remarks'       => $remarks,
    ), 'PATCH', $staff_id);
    $httpCode = $result['httpCode'];
    $decoded  = json_decode($result['response'], true);
    if ($httpCode >= 200 && $httpCode < 300 && !empty($decoded['success'])) {
        return array('success' => true, 'data' => isset($decoded['data']) ? $decoded['data'] : null);
    }
    $msg = (!empty($decoded['message'])) ? $decoded['message'] : 'Failed to update payout status.';
    return array('success' => false, 'message' => $msg);
}

/**
 * Bulk lock/unlock payout status for a set of ATEM ids.
 * @param array $ids ATEM ids to act on
 * @param string $remarks Required remark for the batch
 * @param int $staff_id Current user's staff ID (JWT auth + actor_id)
 * @param bool $unlock false = bulk-lock (Close), true = bulk-unlock (reopen)
 * @param bool $is_superadmin Required true for unlock; forwarded for the atem-api's own check
 * @return array {success, locked|unlocked, skipped} or {success:false, message}
 */
function bulkUpdatePayoutStatus($ids, $remarks, $staff_id, $unlock = false, $is_superadmin = false)
{
    $endpoint = $unlock ? 'atem/payout-status/bulk-unlock' : 'atem/payout-status/bulk-lock';
    $payload  = array(
        'ids'       => array_values(array_map('intval', $ids)),
        'remarks'   => $remarks,
        'actor_id'  => (int)$staff_id,
    );
    if ($unlock) {
        $payload['is_superadmin'] = $is_superadmin ? 1 : 0;
    }
    $result   = getApiDataWithJWT($endpoint, $payload, 'PATCH', $staff_id);
    $httpCode = $result['httpCode'];
    $decoded  = json_decode($result['response'], true);
    if ($httpCode >= 200 && $httpCode < 300 && !empty($decoded['success'])) {
        return $unlock
            ? array('success' => true, 'unlocked' => (int)$decoded['unlocked'], 'skipped' => (int)$decoded['skipped'])
            : array('success' => true, 'locked'   => (int)$decoded['locked'],   'skipped' => (int)$decoded['skipped']);
    }
    $msg = (!empty($decoded['message'])) ? $decoded['message'] : 'Failed to update payout status.';
    return array('success' => false, 'message' => $msg);
}

/**
 * Add an ARCI member to an ATEM card
 * @param int $id ATEM ID
 * @param array $data Member data (staff_id, staff_name, staff_dept_id, department_name, role, assigned_by)
 * @param int $staff_id Staff ID for authentication
 * @return array Result with grouped members
 */
function addAtemArci($id, $data, $staff_id)
{
    $result = getApiDataWithJWT('atem/' . (int)$id . '/arci', $data, 'POST', $staff_id);
    $httpCode = $result['httpCode'];
    $decoded = json_decode($result['response'], true);

    if ($httpCode == 200 || $httpCode == 201) {
        return array(
            'success' => true,
            'data' => isset($decoded['data']) ? $decoded['data'] : null,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'Member added successfully'
        );
    } else {
        return array(
            'success' => false,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'Failed to add member'
        );
    }
}

/**
 * Remove a single ARCI member from an ATEM card
 * @param int $id ATEM ID
 * @param int $member_staff_id Staff ID of the member to remove
 * @param string $role Role of the member
 * @param int $staff_id Staff ID for authentication
 * @return array Result with grouped members
 */
function removeAtemArci($id, $member_staff_id, $role, $staff_id)
{
    $endpoint = 'atem/' . (int)$id . '/arci?staff_id=' . (int)$member_staff_id . '&role=' . urlencode($role);
    $result = getApiDataWithJWT($endpoint, array('actor_id' => (int)$staff_id), 'DELETE', $staff_id);
    $httpCode = $result['httpCode'];
    $decoded = json_decode($result['response'], true);

    if ($httpCode == 200 || $httpCode == 204) {
        return array(
            'success' => true,
            'data' => isset($decoded['data']) ? $decoded['data'] : null,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'Member removed successfully'
        );
    } else {
        return array(
            'success' => false,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'Failed to remove member'
        );
    }
}

/**
 * Remove all ARCI members of a role from an ATEM card
 * @param int $id ATEM ID
 * @param string $role Role to clear
 * @param int $staff_id Staff ID for authentication
 * @return array Result with grouped members
 */
function removeAtemArciByRole($id, $role, $staff_id)
{
    $endpoint = 'atem/' . (int)$id . '/arci/role/' . urlencode($role);
    $result = getApiDataWithJWT($endpoint, array('actor_id' => (int)$staff_id), 'DELETE', $staff_id);
    $httpCode = $result['httpCode'];
    $decoded = json_decode($result['response'], true);

    if ($httpCode == 200 || $httpCode == 204) {
        return array(
            'success' => true,
            'data' => isset($decoded['data']) ? $decoded['data'] : null,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'Members removed successfully'
        );
    } else {
        return array(
            'success' => false,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'Failed to remove members'
        );
    }
}

/**
 * Toggle the is_incentivised flag on a single ARCI member.
 * @param int $id ATEM ID
 * @param int $arci_id AtemArci row ID
 * @param bool $is_incentivised New flag value
 * @param int $staff_id Staff ID for authentication
 * @return array Result with grouped ARCI data
 */
function updateAtemArciIncentivised($id, $arci_id, $is_incentivised, $staff_id)
{
    $result = getApiDataWithJWT(
        'atem/' . (int)$id . '/arci/' . (int)$arci_id,
        array('is_incentivised' => (bool)$is_incentivised),
        'PATCH',
        $staff_id
    );
    $httpCode = $result['httpCode'];
    $decoded  = json_decode($result['response'], true);

    if ($httpCode == 200) {
        return array(
            'success' => true,
            'data'    => isset($decoded['data']) ? $decoded['data'] : null
        );
    }
    return array(
        'success' => false,
        'message' => isset($decoded['message']) ? $decoded['message'] : 'Failed to update incentivised flag'
    );
}

/**
 * List the reference links for an ATEM card
 * @param int $id ATEM ID
 * @param int $staff_id Staff ID for authentication
 * @return array Result with the link collection
 */
function getAtemReferenceLinks($id, $staff_id)
{
    $result = getApiDataWithJWT('atem/' . (int)$id . '/reference-links', null, 'GET', $staff_id);
    $httpCode = $result['httpCode'];
    $decoded = json_decode($result['response'], true);

    if ($httpCode == 200) {
        return array(
            'success' => true,
            'data' => isset($decoded['data']) ? $decoded['data'] : array()
        );
    } else {
        return array(
            'success' => false,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'Failed to retrieve reference links'
        );
    }
}

/**
 * Add a reference link to an ATEM card
 * @param int $id ATEM ID
 * @param array $data Link data (name, url, added_by)
 * @param int $staff_id Staff ID for authentication
 * @return array Result with the updated link collection
 */
function addAtemReferenceLink($id, $data, $staff_id)
{
    $result = getApiDataWithJWT('atem/' . (int)$id . '/reference-links', $data, 'POST', $staff_id);
    $httpCode = $result['httpCode'];
    $decoded = json_decode($result['response'], true);

    if ($httpCode == 200 || $httpCode == 201) {
        return array(
            'success' => true,
            'data' => isset($decoded['data']) ? $decoded['data'] : null,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'Reference link added successfully'
        );
    } else {
        return array(
            'success' => false,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'Failed to add reference link',
            'errors' => isset($decoded['errors']) ? $decoded['errors'] : null
        );
    }
}

/**
 * Remove a reference link from an ATEM card
 * @param int $id ATEM ID
 * @param int $link_id Reference link ID
 * @param int $staff_id Staff ID for authentication
 * @return array Result with the updated link collection
 */
function removeAtemReferenceLink($id, $link_id, $staff_id)
{
    $endpoint = 'atem/' . (int)$id . '/reference-links/' . (int)$link_id;
    $result = getApiDataWithJWT($endpoint, array('actor_id' => (int)$staff_id), 'DELETE', $staff_id);
    $httpCode = $result['httpCode'];
    $decoded = json_decode($result['response'], true);

    if ($httpCode == 200 || $httpCode == 204) {
        return array(
            'success' => true,
            'data' => isset($decoded['data']) ? $decoded['data'] : null,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'Reference link removed successfully'
        );
    } else {
        return array(
            'success' => false,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'Failed to remove reference link'
        );
    }
}

/**
 * List the attachments for an ATEM card
 * @param int $id ATEM ID
 * @param int $staff_id Staff ID for authentication
 * @return array Result with the attachment collection
 */
function getAtemAttachments($id, $staff_id)
{
    $result = getApiDataWithJWT('atem/' . (int)$id . '/attachments', null, 'GET', $staff_id);
    $httpCode = $result['httpCode'];
    $decoded = json_decode($result['response'], true);

    if ($httpCode == 200) {
        return array(
            'success' => true,
            'data' => isset($decoded['data']) ? $decoded['data'] : array()
        );
    } else {
        return array(
            'success' => false,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'Failed to retrieve attachments'
        );
    }
}

/**
 * Upload a single attachment to an ATEM card.
 * Uses a dedicated multipart curl call (CURLFile) since getApiDataWithJWT only
 * forwards JSON payloads.
 * @param int $id ATEM ID
 * @param array $file_info A single $_FILES entry (name, type, tmp_name, error, size)
 * @param int $staff_id Staff ID for authentication
 * @return array Upload result with the updated attachment collection
 */
function uploadAtemAttachment($id, $file_info, $staff_id)
{
    if (!isset($file_info['tmp_name']) || $file_info['error'] !== UPLOAD_ERR_OK) {
        return array('success' => false, 'message' => 'No valid file received for upload');
    }

    $token = getAuthToken($staff_id);
    if (!$token) {
        return array('success' => false, 'message' => 'Authentication failed - could not get JWT token');
    }

    $host = getApiHost();
    $url = $host . 'atem/' . (int)$id . '/attachments';

    $cfile = new CURLFile($file_info['tmp_name'], $file_info['type'], $file_info['name']);
    $postFields = array(
        'file'        => $cfile,
        'uploaded_by' => (int)$staff_id
    );

    // Note: do not set Content-Type; curl adds the multipart boundary itself.
    $headers = array(
        'Authorization: Bearer ' . $token,
        'Accept: application/json'
    );

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($response, true);

    if ($httpCode == 200 || $httpCode == 201) {
        return array(
            'success' => true,
            'data' => isset($decoded['data']) ? $decoded['data'] : null,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'File uploaded successfully'
        );
    } else {
        return array(
            'success' => false,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'Failed to upload file',
            'errors' => isset($decoded['errors']) ? $decoded['errors'] : null
        );
    }
}

/**
 * Remove an attachment from an ATEM card
 * @param int $id ATEM ID
 * @param int $att_id Attachment ID
 * @param int $staff_id Staff ID for authentication
 * @return array Result with the updated attachment collection
 */
function removeAtemAttachment($id, $att_id, $staff_id)
{
    $endpoint = 'atem/' . (int)$id . '/attachments/' . (int)$att_id;
    $result = getApiDataWithJWT($endpoint, array('actor_id' => (int)$staff_id), 'DELETE', $staff_id);
    $httpCode = $result['httpCode'];
    $decoded = json_decode($result['response'], true);

    if ($httpCode == 200 || $httpCode == 204) {
        return array(
            'success' => true,
            'data' => isset($decoded['data']) ? $decoded['data'] : null,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'Attachment removed successfully'
        );
    } else {
        return array(
            'success' => false,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'Failed to remove attachment'
        );
    }
}

/**
 * Resolve created_by IDs to staff names in a progress list.
 */
function resolveProgressCreatorNames($progressList, $conn)
{
    if (!is_array($progressList) || empty($progressList)) {
        return $progressList;
    }
    $ids = array();
    foreach ($progressList as $p) {
        if (!empty($p['created_by'])) {
            $ids[] = (int) $p['created_by'];
        }
    }
    if (empty($ids)) {
        return $progressList;
    }
    $ids_str = implode(',', array_unique($ids));
    $names = array();
    $res = mysqli_query($conn, "SELECT id, nama_staff FROM staff WHERE id IN ($ids_str) AND recycle != 1");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $names[(int) $row['id']] = $row['nama_staff'];
        }
    }
    foreach ($progressList as $k => $p) {
        $cid = isset($p['created_by']) ? (int) $p['created_by'] : 0;
        $progressList[$k]['created_by_name'] = ($cid && isset($names[$cid])) ? $names[$cid] : '';
    }
    return $progressList;
}

/**
 * List progress updates for an ATEM card
 */
function getAtemProgress($id, $staff_id)
{
    $result = getApiDataWithJWT('atem/' . (int)$id . '/progress', null, 'GET', $staff_id);
    $httpCode = $result['httpCode'];
    $decoded = json_decode($result['response'], true);

    if ($httpCode == 200) {
        return array(
            'success' => true,
            'data' => isset($decoded['data']) ? $decoded['data'] : array()
        );
    } else {
        return array(
            'success' => false,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'Failed to retrieve progress updates'
        );
    }
}

/**
 * Add a progress update to an ATEM card
 */
function addAtemProgress($id, $data, $staff_id)
{
    $result = getApiDataWithJWT('atem/' . (int)$id . '/progress', $data, 'POST', $staff_id);
    $httpCode = $result['httpCode'];
    $decoded = json_decode($result['response'], true);

    if ($httpCode == 200 || $httpCode == 201) {
        return array(
            'success' => true,
            'data' => isset($decoded['data']) ? $decoded['data'] : null,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'Progress update added successfully'
        );
    } else {
        return array(
            'success' => false,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'Failed to add progress update',
            'errors' => isset($decoded['errors']) ? $decoded['errors'] : null
        );
    }
}

/**
 * Update an existing progress update on an ATEM card
 */
function updateAtemProgress($id, $progress_id, $data, $staff_id)
{
    $data['actor_id'] = (int)$staff_id;
    $endpoint = 'atem/' . (int)$id . '/progress/' . (int)$progress_id;
    $result = getApiDataWithJWT($endpoint, $data, 'PUT', $staff_id);
    $httpCode = $result['httpCode'];
    $decoded = json_decode($result['response'], true);

    if ($httpCode == 200) {
        return array(
            'success' => true,
            'data' => isset($decoded['data']) ? $decoded['data'] : null,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'Progress update saved successfully'
        );
    } else {
        return array(
            'success' => false,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'Failed to update progress update',
            'errors' => isset($decoded['errors']) ? $decoded['errors'] : null
        );
    }
}

/**
 * Remove a progress update from an ATEM card
 */
function removeAtemProgress($id, $progress_id, $staff_id)
{
    $endpoint = 'atem/' . (int)$id . '/progress/' . (int)$progress_id;
    $result = getApiDataWithJWT($endpoint, array('actor_id' => (int)$staff_id), 'DELETE', $staff_id);
    $httpCode = $result['httpCode'];
    $decoded = json_decode($result['response'], true);

    if ($httpCode == 200 || $httpCode == 204) {
        return array(
            'success' => true,
            'data' => isset($decoded['data']) ? $decoded['data'] : null,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'Progress update removed successfully'
        );
    } else {
        return array(
            'success' => false,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'Failed to remove progress update'
        );
    }
}

/**
 * Resolve sender_staff_id -> sender_name for a list of chat messages.
 * Mirrors resolveProgressCreatorNames() above.
 */
function resolveMessageSenderNames($messages, $conn)
{
    if (!is_array($messages) || empty($messages)) {
        return $messages;
    }
    $ids = array();
    foreach ($messages as $m) {
        if (!empty($m['sender_staff_id'])) {
            $ids[] = (int) $m['sender_staff_id'];
        }
    }
    if (empty($ids)) {
        return $messages;
    }
    $ids_str = implode(',', array_unique($ids));
    $names = array();
    $res = mysqli_query($conn, "SELECT id, nama_staff FROM staff WHERE id IN ($ids_str) AND recycle != 1");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $names[(int) $row['id']] = $row['nama_staff'];
        }
    }
    foreach ($messages as $k => $m) {
        $sid = isset($m['sender_staff_id']) ? (int) $m['sender_staff_id'] : 0;
        $messages[$k]['sender_name'] = ($sid && isset($names[$sid])) ? $names[$sid] : ('Staff #' . $sid);
    }
    return $messages;
}

/**
 * Get the full chat thread for an ATEM card. The frontend polls this on an
 * interval and fully resyncs its local copy, so edits/unsends on existing
 * messages are picked up too (not just newly-added ones).
 */
function getAtemMessages($id, $staff_id)
{
    $result = getApiDataWithJWT('atem/' . (int)$id . '/messages', null, 'GET', $staff_id);
    $httpCode = $result['httpCode'];
    $decoded = json_decode($result['response'], true);

    if ($httpCode == 200) {
        return array(
            'success' => true,
            'data' => isset($decoded['data']) ? $decoded['data'] : array()
        );
    } else {
        return array(
            'success' => false,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'Failed to retrieve messages'
        );
    }
}

/**
 * Post a chat message to an ATEM card. Caller must already have verified
 * send permission via userCanPostAtemChat() before calling this.
 */
function addAtemMessage($id, $data, $staff_id)
{
    $result = getApiDataWithJWT('atem/' . (int)$id . '/messages', $data, 'POST', $staff_id);
    $httpCode = $result['httpCode'];
    $decoded = json_decode($result['response'], true);

    if ($httpCode == 200 || $httpCode == 201) {
        return array(
            'success' => true,
            'data' => isset($decoded['data']) ? $decoded['data'] : null
        );
    } else {
        return array(
            'success' => false,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'Failed to send message',
            'errors' => isset($decoded['errors']) ? $decoded['errors'] : null
        );
    }
}

/**
 * Edit a chat message. Ownership + the 60s edit window are enforced backend-side
 * against the message's own sender_staff_id/created_at, not trusted from the client.
 */
function updateAtemMessage($id, $message_id, $data, $staff_id)
{
    $endpoint = 'atem/' . (int)$id . '/messages/' . (int)$message_id;
    $result = getApiDataWithJWT($endpoint, $data, 'PATCH', $staff_id);
    $httpCode = $result['httpCode'];
    $decoded = json_decode($result['response'], true);

    if ($httpCode == 200) {
        return array(
            'success' => true,
            'data' => isset($decoded['data']) ? $decoded['data'] : null
        );
    } else {
        return array(
            'success' => false,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'Failed to edit message'
        );
    }
}

/**
 * Unsend (soft-delete) a chat message. Same ownership/time-window rule as edit.
 */
function deleteAtemMessage($id, $message_id, $staff_id)
{
    $endpoint = 'atem/' . (int)$id . '/messages/' . (int)$message_id;
    $result = getApiDataWithJWT($endpoint, array('sender_staff_id' => (int)$staff_id), 'DELETE', $staff_id);
    $httpCode = $result['httpCode'];
    $decoded = json_decode($result['response'], true);

    if ($httpCode == 200) {
        return array('success' => true);
    } else {
        return array(
            'success' => false,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'Failed to unsend message'
        );
    }
}

/**
 * Server-side gate for chat-send. api.php is directly POST-able, so this is
 * re-checked here rather than trusting edit.php's page-level gating alone.
 * Mirrors edit.php's $is_arci_member scan (ANY ARCI role qualifies, not just
 * the stricter Accountable-only $can_edit rule).
 */
function userCanPostAtemChat($record, $staff_id, $is_superadmin)
{
    if ($is_superadmin) {
        return true;
    }
    $sid = (int) $staff_id;
    if (!$sid || !is_array($record)) {
        return false;
    }
    if ((int)(isset($record['issuer_staff_id']) ? $record['issuer_staff_id'] : 0) === $sid) {
        return true;
    }
    if (isset($record['arci']) && is_array($record['arci'])) {
        foreach ($record['arci'] as $m) {
            if ((int)(isset($m['staff_id']) ? $m['staff_id'] : 0) === $sid) {
                return true;
            }
        }
    }
    return false;
}

/**
 * List recent notifications + unread count for the given staff member.
 */
function getAtemNotifications($staff_id, $limit = 20)
{
    $result = getApiDataWithJWT('notifications?staff_id=' . (int)$staff_id . '&limit=' . (int)$limit, null, 'GET', $staff_id);
    $httpCode = $result['httpCode'];
    $decoded = json_decode($result['response'], true);

    if ($httpCode == 200) {
        return array(
            'success' => true,
            'data' => isset($decoded['data']) ? $decoded['data'] : array(),
            'unread_count' => isset($decoded['meta']['unread_count']) ? (int)$decoded['meta']['unread_count'] : 0
        );
    } else {
        return array('success' => false, 'message' => 'Failed to load notifications');
    }
}

function markAtemNotificationRead($notif_id, $staff_id)
{
    $result = getApiDataWithJWT('notifications/' . (int)$notif_id . '/read', array('recipient_staff_id' => (int)$staff_id), 'PATCH', $staff_id);
    $decoded = json_decode($result['response'], true);
    return (!empty($decoded['success']))
        ? array('success' => true)
        : array('success' => false, 'message' => 'Failed to mark notification read');
}

function markAllAtemNotificationsRead($staff_id)
{
    $result = getApiDataWithJWT('notifications/mark-all-read', array('recipient_staff_id' => (int)$staff_id), 'PATCH', $staff_id);
    $decoded = json_decode($result['response'], true);
    return (!empty($decoded['success']))
        ? array('success' => true)
        : array('success' => false, 'message' => 'Failed to mark all notifications read');
}

/**
 * Stream an attachment's bytes back to the browser by relaying the API
 * response (the browser cannot reach the atem-api host directly).
 * @param int $id ATEM ID
 * @param int $att_id Attachment ID
 * @param int $staff_id Staff ID for authentication
 * @return void Emits the file content and exits via the caller
 */
function downloadAtemAttachment($id, $att_id, $staff_id)
{
    $token = getAuthToken($staff_id);
    if (!$token) {
        header('HTTP/1.1 401 Unauthorized');
        echo 'Authentication failed';
        return;
    }

    $host = getApiHost();
    $url = $host . 'atem/' . (int)$id . '/attachments/' . (int)$att_id . '/download';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . $token, 'Accept: */*'));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($raw === false || $httpCode != 200) {
        header('HTTP/1.1 404 Not Found');
        echo 'File not found';
        return;
    }

    $rawHeaders = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);

    // Relay only the content headers needed for the browser to save the file.
    $lines = explode("\r\n", $rawHeaders);
    foreach ($lines as $line) {
        if (stripos($line, 'Content-Type:') === 0
            || stripos($line, 'Content-Disposition:') === 0
            || stripos($line, 'Content-Length:') === 0
        ) {
            header($line);
        }
    }
    echo $body;
}

// Returns array of month numbers to match for a period, or null = "any month" (whole year).
function atem_period_months($month, $quarter)
{
    $qmap = array(1 => array(1, 2, 3), 2 => array(4, 5, 6), 3 => array(7, 8, 9), 4 => array(10, 11, 12));
    if ($quarter > 0 && isset($qmap[$quarter])) {
        return $qmap[$quarter];
    }
    if ($month > 0) {
        return array($month);
    }
    return null;
}

// True if $dateStr (YYYY-MM-DD...) falls within $year and (if not null) one of $months.
function atem_date_in_period($dateStr, $months, $year)
{
    if (!$dateStr) {
        return false;
    }
    $y = (int)substr($dateStr, 0, 4);
    $m = (int)substr($dateStr, 5, 2);
    if ($year > 0 && $y !== (int)$year) {
        return false;
    }
    if ($months !== null && !in_array($m, $months, true)) {
        return false;
    }
    return true;
}

// Mirrors CalculateBonusEligibility.php's per-column date basis exactly:
// complete/extend/failed are bucketed by closure_date, active by start_date, atem = union of all four.
function atem_matches_period_column($status, $start_date, $closure_date, $col, $month, $year, $quarter)
{
    $months = atem_period_months($month, $quarter);
    switch ($col) {
        case 'complete':
            return in_array($status, array('Completed', 'Completed with Excellence', 'Completed with Extension'), true)
                && atem_date_in_period($closure_date, $months, $year);
        case 'active':
            return $status === 'Active'
                && atem_date_in_period($start_date, $months, $year);
        case 'extend':
            return $status === 'Extended'
                && atem_date_in_period($closure_date, $months, $year);
        case 'failed':
            return $status === 'Failed'
                && atem_date_in_period($closure_date, $months, $year);
        case 'atem':
        default:
            return atem_matches_period_column($status, $start_date, $closure_date, 'complete', $month, $year, $quarter)
                || atem_matches_period_column($status, $start_date, $closure_date, 'active', $month, $year, $quarter)
                || atem_matches_period_column($status, $start_date, $closure_date, 'extend', $month, $year, $quarter)
                || atem_matches_period_column($status, $start_date, $closure_date, 'failed', $month, $year, $quarter);
    }
}

// The 6 exact ATEM statuses selectable on the Staff Performance status filter.
// Draft/Suspended/Deleted are never relevant to performance tracking. okr_cards'
// okr_statuses.value strings are byte-identical to these (verified against the
// live DB), so this same whitelist is reused for OKR bucketing below - no
// separate OKR status list needed. NOTE: okr/lib.php hardcodes a DIFFERENT,
// stale set of strings ('Complete'/'Extend'/'Fail') that do not match the live
// okr_statuses.value column - do not copy those, they silently match nothing.
function atem_performance_status_options()
{
    return array('Completed', 'Completed with Excellence', 'Completed with Extension', 'Active', 'Extended', 'Failed');
}

// Quarter-opening month (Jan/Apr/Jul/Oct) -> the quarter number that just
// closed and is being paid out this month (Jan closes Q4, Apr closes Q1,
// Jul closes Q2, Oct closes Q3). Returns null for any other month.
function payoutLockWindowQuarterForMonth($month)
{
    $map = array(1 => 4, 4 => 1, 7 => 2, 10 => 3);
    return isset($map[$month]) ? $map[$month] : null;
}

// Number of days into that opening month Lock Payout stays open for the
// closed quarter - each quarter has its own independently configurable
// duration (atem_config keys payout_lock_window_days_q1..q4), since e.g. the
// year-end quarter may need a longer/shorter window than the others.
function payoutLockWindowDays($conn, $quarter)
{
    $quarter = (int)$quarter;
    if ($quarter < 1 || $quarter > 4) { return 10; }
    $days = 10;
    $key = 'payout_lock_window_days_q' . $quarter;
    $r = mysqli_query($conn, "SELECT setting_value FROM atem_config WHERE setting_key = '" . $key . "'");
    if ($r && ($row = mysqli_fetch_assoc($r))) {
        $d = (int)$row['setting_value'];
        if ($d > 0) { $days = $d; }
    }
    return $days;
}

// Unlike the struct window (any month's first N days), Lock Payout only ever
// opens in the month a quarter actually closes in (Jan/Apr/Jul/Oct) - locking
// mid-quarter makes no sense since the period isn't closed out yet. Which
// quarter's own configured duration applies depends on which quarter just
// closed (see payoutLockWindowQuarterForMonth()).
function isPayoutLockWindowOpen($conn)
{
    $quarter = payoutLockWindowQuarterForMonth((int)date('n'));
    if ($quarter === null) { return false; }
    return (int)date('j') <= payoutLockWindowDays($conn, $quarter);
}

// Records one export event per target staff, shown in the Logs section of
// staff_performance/edit.php alongside ATEM/OKR lock events. $exportType is
// a free-form label (e.g. 'atem', 'performance') just for display context.
function logAtemExport($conn, $targetStaffId, $actorStaffId, $exportType)
{
    $targetStaffId = (int)$targetStaffId;
    $actorStaffId  = (int)$actorStaffId;
    if ($targetStaffId <= 0 || $actorStaffId <= 0) { return; }
    $exportTypeEsc = mysqli_real_escape_string($conn, (string)$exportType);
    mysqli_query($conn, "INSERT INTO atem_export_logs (target_staff_id, actor_staff_id, export_type)
                          VALUES ($targetStaffId, $actorStaffId, '$exportTypeEsc')");
}

// Which performance-table column ('complete'/'active'/'extend'/'failed') a status
// belongs to, or null if it's not a performance-relevant status at all.
function atem_status_bucket($status)
{
    if (in_array($status, array('Completed', 'Completed with Excellence', 'Completed with Extension'), true)) {
        return 'complete';
    }
    if ($status === 'Active')   { return 'active'; }
    if ($status === 'Extended') { return 'extend'; }
    if ($status === 'Failed')   { return 'failed'; }
    return null;
}

// OKR equivalent of atem_status_bucket() - same live status strings (see
// atem_performance_status_options() above), so the bucket mapping is identical.
function okr_status_bucket($status)
{
    return atem_status_bucket($status);
}

// Normalizes okr_cards' raw status_value (Draft/Active/Complete/Complete with
// Excellence/Extend/Fail) to the ATEM-style spelling this file's status
// whitelist/bucket functions expect ('Completed'/'Completed with
// Excellence'/'Extended'/'Failed'). OKR has no distinct "Complete with
// Extension" status - extension is tracked via okr_cards.extended instead -
// so a completed+extended row maps to that ATEM label here.
function okr_normalize_status_value($rawStatus, $isExtended)
{
    switch ($rawStatus) {
        case 'Complete':
            return $isExtended ? 'Completed with Extension' : 'Completed';
        case 'Complete with Excellence':
            return 'Completed with Excellence';
        case 'Extend':
            return 'Extended';
        case 'Fail':
            return 'Failed';
        default:
            return $rawStatus; // 'Draft', 'Active', or unrecognized
    }
}

// Mirrors CalculateBonusEligibility.php's date basis: completed-family/extended/
// failed are matched by closure_date, active by start_date.
function atem_status_period_field($status)
{
    return ($status === 'Active') ? 'start_date' : 'closure_date';
}

// OKR equivalent of atem_matches_period_column() - identical bucket/date-basis
// rules, substituting okr_cards.closed_at (a datetime, hence the substr) for
// ATEM's closure_date.
function okr_matches_period_column($status, $start_date, $closed_at, $col, $month, $year, $quarter)
{
    $closure_date = $closed_at ? substr($closed_at, 0, 10) : '';
    $months = atem_period_months($month, $quarter);
    switch ($col) {
        case 'complete':
            return in_array($status, array('Completed', 'Completed with Excellence', 'Completed with Extension'), true)
                && atem_date_in_period($closure_date, $months, $year);
        case 'active':
            return $status === 'Active'
                && atem_date_in_period($start_date, $months, $year);
        case 'extend':
            return $status === 'Extended'
                && atem_date_in_period($closure_date, $months, $year);
        case 'failed':
            return $status === 'Failed'
                && atem_date_in_period($closure_date, $months, $year);
        case 'okr':
        default:
            return okr_matches_period_column($status, $start_date, $closed_at, 'complete', $month, $year, $quarter)
                || okr_matches_period_column($status, $start_date, $closed_at, 'active', $month, $year, $quarter)
                || okr_matches_period_column($status, $start_date, $closed_at, 'extend', $month, $year, $quarter)
                || okr_matches_period_column($status, $start_date, $closed_at, 'failed', $month, $year, $quarter);
    }
}

// Live, per-status equivalent of the old atem_bonus_eligibilities snapshot table.
// Computes per-staff Complete/Active/Extend/Failed counts and total_incentive
// directly from current ATEM records, counting ONLY the caller-selected exact
// statuses — any status not in $selectedStatuses contributes nothing anywhere,
// so its bucket reads 0 rather than a stale/mismatched snapshot value.
function getStaffPerformanceLive($month, $year, $quarter, $selectedStatuses, $staff_id, $filterAtemType = 0, $filterOutletId = 0)
{
    $listResult = getAtemList($staff_id);
    if (!$listResult['success']) {
        return array('success' => false, 'message' => 'Failed to load ATEM data', 'data' => array());
    }

    $months = atem_period_months($month, $quarter);
    $aggregates = array();

    foreach ($listResult['data'] as $item) {
        if ($filterAtemType > 0) {
            $itemAtemType = isset($item['atem_type']) ? (int)$item['atem_type'] : 1;
            if ($itemAtemType !== $filterAtemType) { continue; }
        }
        if ($filterOutletId > 0) {
            $itemHasOutlet = false;
            if (isset($item['outlets']) && is_array($item['outlets'])) {
                foreach ($item['outlets'] as $o) {
                    if (!empty($o['outlet_id']) && (int)$o['outlet_id'] === $filterOutletId) {
                        $itemHasOutlet = true;
                        break;
                    }
                }
            }
            if (!$itemHasOutlet) { continue; }
        }

        $statusVal = isset($item['status']['value']) ? $item['status']['value'] : '';

        // Involved staff: issuer, then Outlet-type area managers (no dept of
        // their own), then every ARCI member last so a person who's also an
        // ARCI member keeps their real per-card dept_id (mirrors
        // CalculateBonusEligibility.php's three-source ordering). Computed
        // unconditionally (not gated on $selectedStatuses) since it's needed
        // both for the raw "ATEM" total below and the status-selected bucket
        // counts further down.
        $involved = array();
        $issuerId = isset($item['issuer_staff_id']) ? (int)$item['issuer_staff_id'] : 0;
        if ($issuerId) {
            $involved[$issuerId] = isset($item['staff_dept_id']) ? (int)$item['staff_dept_id'] : 0;
        }
        if (isset($item['area_managers']) && is_array($item['area_managers'])) {
            foreach ($item['area_managers'] as $am) {
                if (!empty($am['staff_id'])) {
                    $involved[(int)$am['staff_id']] = 0;
                }
            }
        }
        if (isset($item['arci']) && is_array($item['arci'])) {
            foreach ($item['arci'] as $m) {
                if (!empty($m['staff_id'])) {
                    $involved[(int)$m['staff_id']] = isset($m['staff_dept_id']) ? (int)$m['staff_dept_id'] : 0;
                }
            }
        }

        // Raw "ATEM" total (all statuses, all roles) - period-filtered only,
        // using each status's normal date basis (start_date for Active,
        // closure_date otherwise); a card with no usable date for its current
        // status (e.g. an unclosed Draft) simply never falls "in" any period.
        // This feeds the "ATEM" summary column, which now links straight to
        // edit.php instead of opening a filtered/narrowed modal - so it's
        // intentionally the broadest count on the page. Also guarantees every
        // involved staff gets an $aggregates entry even when the card's exact
        // status is never in $selectedStatuses (e.g. Draft/Suspended).
        $rawDateField = atem_status_period_field($statusVal);
        $rawDateStr   = isset($item[$rawDateField]) ? $item[$rawDateField] : '';
        if (atem_date_in_period($rawDateStr, $months, $year)) {
            foreach ($involved as $sid => $deptId) {
                if (!isset($aggregates[$sid])) {
                    $aggregates[$sid] = array(
                        'dept_id' => $deptId,
                        'total_all' => 0,
                        'complete' => 0, 'active' => 0, 'extend' => 0, 'failed' => 0,
                        'total_incentive' => 0.0,
                        'has_locked' => false, 'has_unlocked' => false,
                    );
                }
                $aggregates[$sid]['total_all']++;
                if ($deptId) { $aggregates[$sid]['dept_id'] = $deptId; }
            }
        }

        if (!in_array($statusVal, $selectedStatuses, true)) { continue; }

        $bucket = atem_status_bucket($statusVal);
        if ($bucket === null) { continue; }

        $dateField = atem_status_period_field($statusVal);
        $dateStr   = isset($item[$dateField]) ? $item[$dateField] : '';
        if (!atem_date_in_period($dateStr, $months, $year)) { continue; }

        // Payout lock state — only terminal statuses are ever payout-eligible
        // (mirrors edit.php's $payout_terminal_statuses); 'complete' also
        // covers Completed with Extension, which isn't itself terminal for the
        // performance bucket but is for payout, so this is checked against the
        // exact status value, not the bucket.
        $itemIsPayoutTerminal = in_array($statusVal, array('Completed', 'Completed with Excellence', 'Completed with Extension', 'Failed'), true);
        $itemIsLocked = $itemIsPayoutTerminal && isset($item['payout_status']) && $item['payout_status'] === 'Closed';

        // Incentive only ever applies to completed-family cards, and only once
        // the payout was actually approved (final_incentive_amount > 0) — same
        // rule as CalculateBonusEligibility.php.
        $incentivePerStaff = array();
        if ($bucket === 'complete'
            && isset($item['final_incentive_amount']) && (float)$item['final_incentive_amount'] > 0
            && isset($item['arci']) && is_array($item['arci'])
        ) {
            $incACount = 0;
            $incRCount = 0;
            foreach ($item['arci'] as $m) {
                if (empty($m['is_incentivised'])) { continue; }
                if ($m['role'] === 'A') { $incACount++; }
                if ($m['role'] === 'R') { $incRCount++; }
            }
            foreach ($item['arci'] as $m) {
                if (empty($m['staff_id']) || empty($m['is_incentivised'])) { continue; }
                $sid = (int)$m['staff_id'];
                if ($m['role'] === 'A' && $incACount > 0) {
                    $amt = (float)(isset($item['a_incentive_amount']) ? $item['a_incentive_amount'] : 0) / $incACount;
                    $incentivePerStaff[$sid] = (isset($incentivePerStaff[$sid]) ? $incentivePerStaff[$sid] : 0.0) + $amt;
                } elseif ($m['role'] === 'R' && $incRCount > 0) {
                    $amt = (float)(isset($item['r_incentive_amount']) ? $item['r_incentive_amount'] : 0) / $incRCount;
                    $incentivePerStaff[$sid] = (isset($incentivePerStaff[$sid]) ? $incentivePerStaff[$sid] : 0.0) + $amt;
                }
            }
        }

        foreach ($involved as $sid => $deptId) {
            if (!isset($aggregates[$sid])) {
                $aggregates[$sid] = array(
                    'dept_id' => $deptId,
                    'total_all' => 0,
                    'complete' => 0, 'active' => 0, 'extend' => 0, 'failed' => 0,
                    'total_incentive' => 0.0,
                    'has_locked' => false, 'has_unlocked' => false,
                );
            }
            // The 'complete' bucket only counts incentivised A/R members who
            // actually earned a nonzero reward on this card (the same set
            // $incentivePerStaff was built from, just above) - a plain issuer,
            // area manager, C/I role, or a non-incentivised A/R no longer
            // counts as a "Completed ATEM" for themselves, even though the
            // card itself is Completed. Active/Extend/Failed buckets are
            // unaffected and keep counting every involved staff as before.
            if ($bucket !== 'complete' || (isset($incentivePerStaff[$sid]) && $incentivePerStaff[$sid] > 0)) {
                $aggregates[$sid][$bucket]++;
            }
            if ($deptId) { $aggregates[$sid]['dept_id'] = $deptId; }
            if (isset($incentivePerStaff[$sid])) {
                $aggregates[$sid]['total_incentive'] += $incentivePerStaff[$sid];
            }
            if ($itemIsPayoutTerminal) {
                if ($itemIsLocked) {
                    $aggregates[$sid]['has_locked'] = true;
                } else {
                    $aggregates[$sid]['has_unlocked'] = true;
                }
            }
        }
    }

    return array('success' => true, 'data' => $aggregates);
}

// OKR equivalent of getStaffPerformanceLive(). Reads okr_cards/okr_statuses/
// okr_levels directly (no require_once of okr/lib.php - avoids pulling in
// nas_config.php and avoids re-copying okr/lib.php's stale status-string bug;
// this page is a pure reader of okr_cards, never touching the OKR module's own
// files). Reward attribution mirrors okr/lib.php's okrStaffPerformanceRows()
// $shares logic exactly: RULE1 (single incentivised owner) pays that owner
// 100% and the other owner 0%; RULE2 (incentivised_owner_staff_id left blank
// by the OKR module's own create flow) splits 50/50 between both owners.
function getStaffOkrPerformanceLive($conn, $month, $year, $quarter, $selectedStatuses)
{
    $months = atem_period_months($month, $quarter);
    $aggregates = array();

    $query = "SELECT c.id, c.owner_staff_id, c.owner2_staff_id, c.issuer_staff_id, c.incentive_rule, c.incentivised_owner_staff_id,
                     c.result_status, c.incentive_locked, c.start_date, c.closed_at, c.extended,
                     os.value AS status_value, lv.base_rm AS level_rm
              FROM okr_cards c
              LEFT JOIN okr_statuses os ON c.result_status = os.id
              LEFT JOIN okr_levels   lv ON c.difficulty_level = lv.level
              WHERE c.deleted_at IS NULL";
    $result = mysqli_query($conn, $query);
    if (!$result) {
        return array('success' => false, 'message' => 'Failed to load OKR data', 'data' => array());
    }

    while ($row = mysqli_fetch_assoc($result)) {
        $statusVal = isset($row['status_value']) ? $row['status_value'] : '';
        $statusVal = okr_normalize_status_value($statusVal, !empty($row['extended']));
        $ownerId  = (int)$row['owner_staff_id'];
        $owner2Id = ($row['owner2_staff_id'] !== null) ? (int)$row['owner2_staff_id'] : 0;
        $issuerId = ($row['issuer_staff_id'] !== null) ? (int)$row['issuer_staff_id'] : 0;

        // Raw "OKR" total (all statuses, Owner/Owner 2/Issuer involvement) -
        // period-filtered only, mirrors getStaffPerformanceLive()'s "ATEM"
        // raw total. Feeds the "OKR" summary column, which now links straight
        // to edit.php's OKR tab instead of opening a modal.
        $rawDateStr = ($statusVal === 'Active')
            ? $row['start_date']
            : (isset($row['closed_at']) && $row['closed_at'] ? substr($row['closed_at'], 0, 10) : '');
        if (atem_date_in_period($rawDateStr, $months, $year)) {
            foreach (array_unique(array($ownerId, $owner2Id, $issuerId)) as $rsid) {
                if ($rsid <= 0) { continue; }
                if (!isset($aggregates[$rsid])) {
                    $aggregates[$rsid] = array(
                        'total_all' => 0,
                        'complete' => 0, 'active' => 0, 'extend' => 0, 'failed' => 0,
                        'reward' => 0.0,
                        'has_locked' => false, 'has_unlocked' => false,
                    );
                }
                $aggregates[$rsid]['total_all']++;
            }
        }

        if (!in_array($statusVal, $selectedStatuses, true)) { continue; }

        $bucket = okr_status_bucket($statusVal);
        if ($bucket === null) { continue; }

        $dateStr = $rawDateStr;
        if (!atem_date_in_period($dateStr, $months, $year)) { continue; }

        $levelRm  = (float)$row['level_rm'];

        $shares = array();
        if ($owner2Id > 0) {
            if ((int)$row['incentive_rule'] === 1) {
                $incentivisedId = (int)$row['incentivised_owner_staff_id'];
                $otherId = ($incentivisedId === $ownerId) ? $owner2Id : $ownerId;
                $shares[$incentivisedId] = $levelRm;
                $shares[$otherId] = 0.0;
            } else {
                $shares[$ownerId]  = $levelRm / 2;
                $shares[$owner2Id] = $levelRm / 2;
            }
        } else {
            $shares[$ownerId] = $levelRm;
        }

        $itemIsPayoutTerminal = in_array($statusVal, array('Completed', 'Completed with Excellence', 'Completed with Extension', 'Failed'), true);
        $itemIsLocked = $itemIsPayoutTerminal && (int)$row['incentive_locked'] === 1;

        foreach (array($ownerId, $owner2Id) as $sid) {
            if ($sid <= 0) { continue; }
            if (!isset($aggregates[$sid])) {
                $aggregates[$sid] = array(
                    'total_all' => 0,
                    'complete' => 0, 'active' => 0, 'extend' => 0, 'failed' => 0,
                    'reward' => 0.0,
                    'has_locked' => false, 'has_unlocked' => false,
                );
            }
            $aggregates[$sid][$bucket]++;
            $aggregates[$sid]['reward'] += isset($shares[$sid]) ? $shares[$sid] : 0.0;
            if ($itemIsPayoutTerminal) {
                if ($itemIsLocked) {
                    $aggregates[$sid]['has_locked'] = true;
                } else {
                    $aggregates[$sid]['has_unlocked'] = true;
                }
            }
        }
    }

    return array('success' => true, 'data' => $aggregates);
}

/**
 * Resolves the ATEM ids eligible for a bulk payout lock/unlock action.
 *
 * Applies the exact same period/status/type/outlet matching as
 * getStaffPerformanceLive() (so "lock the cards behind this filtered view"
 * means precisely that), further narrowed to only terminal, payout-eligible
 * statuses (Active/Extended never qualify, regardless of the tab's Status
 * filter), and to cards where at least one involved staff member (issuer,
 * ARCI member, or Outlet area manager) is in $targetStaffIds.
 *
 * $targetStaffIds is either the explicit set of checked rows ("Selected"
 * buttons), or every staff id the caller already resolved to match the tab's
 * current dept/grade/struct/staff filter (bar-level "all filtered" buttons) —
 * resolved fresh server-side either way, never trusting a client-captured,
 * possibly stale/paginated id list.
 */
function resolvePayoutAtemIds($month, $year, $quarter, $selectedStatuses, $staff_id, $filterAtemType, $filterOutletId, $targetStaffIds)
{
    $listResult = getAtemList($staff_id);
    if (!$listResult['success']) {
        return array('success' => false, 'message' => 'Failed to load ATEM data', 'ids' => array());
    }

    $months = atem_period_months($month, $quarter);
    $payoutTerminalStatuses = array('Completed', 'Completed with Excellence', 'Completed with Extension', 'Failed');
    $targetSet = array_flip(array_map('intval', $targetStaffIds));

    $ids = array();
    foreach ($listResult['data'] as $item) {
        if ($filterAtemType > 0) {
            $itemAtemType = isset($item['atem_type']) ? (int)$item['atem_type'] : 1;
            if ($itemAtemType !== $filterAtemType) { continue; }
        }
        if ($filterOutletId > 0) {
            $itemHasOutlet = false;
            if (isset($item['outlets']) && is_array($item['outlets'])) {
                foreach ($item['outlets'] as $o) {
                    if (!empty($o['outlet_id']) && (int)$o['outlet_id'] === $filterOutletId) { $itemHasOutlet = true; break; }
                }
            }
            if (!$itemHasOutlet) { continue; }
        }

        $statusVal = isset($item['status']['value']) ? $item['status']['value'] : '';
        if (!in_array($statusVal, $selectedStatuses, true)) { continue; }
        if (!in_array($statusVal, $payoutTerminalStatuses, true)) { continue; }

        $dateStr = isset($item['closure_date']) ? $item['closure_date'] : '';
        if (!atem_date_in_period($dateStr, $months, $year)) { continue; }

        $involved = array();
        $issuerId = isset($item['issuer_staff_id']) ? (int)$item['issuer_staff_id'] : 0;
        if ($issuerId) { $involved[$issuerId] = true; }
        if (isset($item['area_managers']) && is_array($item['area_managers'])) {
            foreach ($item['area_managers'] as $am) {
                if (!empty($am['staff_id'])) { $involved[(int)$am['staff_id']] = true; }
            }
        }
        if (isset($item['arci']) && is_array($item['arci'])) {
            foreach ($item['arci'] as $m) {
                if (!empty($m['staff_id'])) { $involved[(int)$m['staff_id']] = true; }
            }
        }

        $matches = false;
        foreach ($involved as $sid => $_unused) {
            if (isset($targetSet[$sid])) { $matches = true; break; }
        }
        if (!$matches) { continue; }

        if (!empty($item['id'])) { $ids[] = (int)$item['id']; }
    }

    return array('success' => true, 'ids' => array_values(array_unique($ids)));
}

/**
 * OKR equivalent of resolvePayoutAtemIds(). $unlock selects incentive_locked=1
 * (reversible) vs =0 (lockable) as the base eligibility set. A card matches if
 * EITHER owner is in $targetStaffIds, mirroring getStaffOkrPerformanceLive()'s
 * both-owners attribution.
 */
function resolvePayoutOkrIds($conn, $month, $year, $quarter, $selectedStatuses, $targetStaffIds, $unlock = false)
{
    if (empty($targetStaffIds)) {
        return array('success' => true, 'ids' => array());
    }

    $months = atem_period_months($month, $quarter);
    $payoutTerminalStatuses = array('Completed', 'Completed with Excellence', 'Completed with Extension', 'Failed');
    $lockedFlag = $unlock ? 1 : 0;
    $idsCsv = implode(',', array_map('intval', $targetStaffIds));

    $query = "SELECT c.id, c.result_status, c.start_date, c.closed_at, c.extended, os.value AS status_value
              FROM okr_cards c
              LEFT JOIN okr_statuses os ON c.result_status = os.id
              WHERE c.deleted_at IS NULL
                AND c.incentive_locked = " . (int)$lockedFlag . "
                AND (c.owner_staff_id IN ($idsCsv) OR c.owner2_staff_id IN ($idsCsv))";
    $result = mysqli_query($conn, $query);
    if (!$result) {
        return array('success' => false, 'message' => 'Failed to load OKR data', 'ids' => array());
    }

    $ids = array();
    while ($row = mysqli_fetch_assoc($result)) {
        // Normalize okr_cards' raw status word ("Complete") to the ATEM
        // spelling ("Completed") that $selectedStatuses/$payoutTerminalStatuses
        // are expressed in - see okr_normalize_status_value() for the
        // rationale. Without this, Lock/Unlock Payout would never match any
        // OKR card.
        $statusVal = okr_normalize_status_value(isset($row['status_value']) ? $row['status_value'] : '', !empty($row['extended']));
        if (!in_array($statusVal, $selectedStatuses, true)) { continue; }
        if (!in_array($statusVal, $payoutTerminalStatuses, true)) { continue; }

        $dateStr = isset($row['closed_at']) && $row['closed_at'] ? substr($row['closed_at'], 0, 10) : '';
        if (!atem_date_in_period($dateStr, $months, $year)) { continue; }

        $ids[] = (int)$row['id'];
    }

    return array('success' => true, 'ids' => array_values(array_unique($ids)));
}

/**
 * Bulk lock/unlock incentive_locked for a set of okr_cards ids. Column
 * semantics mirror okr/lib.php's okrLockPayoutCards()/okrUnlockPayoutCards()
 * (locked_by/locked_at/unlocked_by/unlocked_at/payout_remark), executed
 * directly against $conn rather than via require_once('okr/lib.php') - keeps
 * this page a pure reader/writer of okr_cards without depending on the OKR
 * module's own files (see getStaffOkrPerformanceLive() for the full rationale).
 * Every id passed in is assumed already-eligible (pre-filtered by
 * resolvePayoutOkrIds()), so nothing is ever skipped here.
 */
function bulkUpdateOkrPayoutStatus($conn, $ids, $remarks, $actor_id, $unlock = false)
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (empty($ids)) {
        return array('success' => true, ($unlock ? 'unlocked' : 'locked') => 0, 'skipped' => 0);
    }
    $idsCsv = implode(',', $ids);
    $remarksEsc = mysqli_real_escape_string($conn, $remarks);
    $actorId = (int)$actor_id;

    if ($unlock) {
        $sql = "UPDATE okr_cards SET incentive_locked = 0, unlocked_by = $actorId, unlocked_at = NOW(),
                       payout_remark = '$remarksEsc'
                WHERE id IN ($idsCsv)";
    } else {
        $sql = "UPDATE okr_cards SET incentive_locked = 1, locked_by = $actorId, locked_at = NOW(),
                       payout_remark = '$remarksEsc'
                WHERE id IN ($idsCsv)";
    }
    mysqli_query($conn, $sql);

    // One okr_audit_logs row per card, mirroring okr/lib.php's okrLogAudit() shape.
    $event = $unlock ? 'incentive_unlocked' : 'incentive_locked';
    $summaryEsc = mysqli_real_escape_string($conn, ($unlock ? 'Incentive unlocked' : 'Incentive locked') . ' for payout by People Management (via ATEM Staff Performance page). Remark: ' . $remarks);
    foreach ($ids as $cid) {
        mysqli_query($conn, "INSERT INTO okr_audit_logs (card_id, event, actor_staff_id, summary, created_at)
                              VALUES ($cid, '$event', $actorId, '$summaryEsc', NOW())");
    }

    return array('success' => true, ($unlock ? 'unlocked' : 'locked') => count($ids), 'skipped' => 0);
}

/**
 * Resolves which staff a bulk payout lock/unlock action targets.
 *
 * If the request carries an explicit staff_ids array (row-level "Selected"
 * buttons — the row checkboxes' values, which on this page are staff ids, not
 * ATEM ids), that list is used directly. Otherwise (bar-level "all filtered"
 * buttons) the target staff are re-derived server-side from the same
 * dept/grade/struct/staff_id filtering get-performance-list applies to the
 * union of getStaffPerformanceLive() and getStaffOkrPerformanceLive()'s
 * aggregates, so "lock payout for everyone currently shown" means exactly
 * that - including an OKR-only struct-5 staff member with zero ATEM cards.
 */
function resolvePayoutTargetStaffIds($jsonData, $staff_id, $conn)
{
    $staffIds = array();
    if (isset($jsonData['staff_ids']) && is_array($jsonData['staff_ids'])) {
        foreach ($jsonData['staff_ids'] as $sid) {
            $sid = (int)$sid;
            if ($sid > 0) { $staffIds[] = $sid; }
        }
        return $staffIds;
    }

    $month    = isset($jsonData['month'])   ? (int)$jsonData['month']   : 0;
    $year     = isset($jsonData['year'])    ? (int)$jsonData['year']    : (int)date('Y');
    $quarter  = isset($jsonData['quarter']) ? (int)$jsonData['quarter'] : 0;
    $atemType = isset($jsonData['filter_atem_type']) ? (int)$jsonData['filter_atem_type'] : 0;
    $outletId = isset($jsonData['filter_outlet_id']) ? (int)$jsonData['filter_outlet_id'] : 0;
    $dept     = isset($jsonData['dept'])     ? (int)$jsonData['dept']     : 0;
    $gradeF   = isset($jsonData['grade'])    ? (int)$jsonData['grade']    : 0;
    $structF  = isset($jsonData['struct'])   ? (int)$jsonData['struct']   : 0;
    $staffF   = isset($jsonData['staff_id']) ? (int)$jsonData['staff_id'] : 0;
    $allowedStatuses = atem_performance_status_options();
    $statuses = (isset($jsonData['statuses']) && is_array($jsonData['statuses']))
        ? array_values(array_intersect($jsonData['statuses'], $allowedStatuses))
        : array('Completed', 'Completed with Excellence');

    // Outlet exclusion is applied below via staff.outlet (matching the merged
    // Staff Performance table's own scoping), not via getStaffPerformanceLive's
    // per-card outlet tagging - so this discovery call itself is unrestricted
    // by outlet, otherwise an outlet-assigned staff member with zero outlet-
    // tagged cards this period would wrongly disappear from "target staff".
    $live = getStaffPerformanceLive($month, $year, $quarter, $statuses, $staff_id, $atemType, 0);
    if (empty($live['success'])) {
        return array();
    }
    $okrLive = getStaffOkrPerformanceLive($conn, $month, $year, $quarter, $statuses);

    $staffGrade  = array();
    $staffStruct = array();
    $staffDeptFirst = array();
    $staffOutletIds = array();
    $sgr = mysqli_query($conn, "SELECT id, grade, struct, department, outlet FROM staff WHERE recycle != 1");
    if ($sgr) {
        while ($r = mysqli_fetch_assoc($sgr)) {
            $id_ = (int)$r['id'];
            $staffGrade[$id_]  = ($r['grade']  !== null) ? (int)$r['grade']  : null;
            $staffStruct[$id_] = ($r['struct'] !== null) ? (int)$r['struct'] : null;
            $staffDeptFirst[$id_] = 0;
            foreach (explode(',', (string)$r['department']) as $_d) {
                $_d = (int)trim($_d);
                if ($_d > 0) { $staffDeptFirst[$id_] = $_d; break; }
            }
            $_outletIds = array();
            foreach (explode(',', (string)$r['outlet']) as $_o) {
                $_o = (int)trim($_o);
                if ($_o > 0) { $_outletIds[] = $_o; }
            }
            $staffOutletIds[$id_] = $_outletIds;
        }
    }

    $unionSids = array_unique(array_merge(array_keys($live['data']), array_keys($okrLive['data'])));

    foreach ($unionSids as $sid) {
        $sid = (int)$sid;
        $rec = isset($live['data'][$sid]) ? $live['data'][$sid] : null;
        $deptId   = $rec && isset($rec['dept_id']) && $rec['dept_id']
            ? (int)$rec['dept_id']
            : (isset($staffDeptFirst[$sid]) ? $staffDeptFirst[$sid] : 0);
        $gradeId  = isset($staffGrade[$sid])  ? $staffGrade[$sid]  : null;
        $structId = isset($staffStruct[$sid]) ? $staffStruct[$sid] : null;

        if ($dept    > 0 && $deptId   !== $dept)    { continue; }
        // Same staff.outlet exclusion as get-performance-list's row scoping -
        // keeps "Lock Payout" (no specific selection) targeting exactly the
        // staff currently visible in the table when an Outlet filter is set.
        if ($outletId > 0) {
            $_ownOutletIds = isset($staffOutletIds[$sid]) ? $staffOutletIds[$sid] : array();
            if (!in_array($outletId, $_ownOutletIds, true)) { continue; }
        }
        if ($gradeF  > 0 && $gradeId  !== $gradeF)  { continue; }
        if ($structF > 0 && $structId !== $structF) { continue; }
        if ($staffF  > 0 && $sid      !== $staffF)  { continue; }

        $staffIds[] = $sid;
    }

    return $staffIds;
}

function getBonusEligibilityList($month, $year, $staff_id_param, $staff_id)
{
    $params = array();
    if ($month)          { $params[] = 'month='    . (int)$month; }
    if ($year)           { $params[] = 'year='     . (int)$year; }
    if ($staff_id_param) { $params[] = 'staff_id=' . (int)$staff_id_param; }
    $qs = $params ? '?' . implode('&', $params) : '';

    $result = getApiDataWithJWT('bonus-eligibility' . $qs, null, 'GET', $staff_id);
    if (!$result['success']) {
        return array('success' => false, 'message' => 'API error', 'data' => array());
    }
    $body = json_decode($result['response'], true);
    return array('success' => true, 'data' => isset($body['data']) ? $body['data'] : array());
}

function updateBonusRemark($record_id, $remark, $staff_id)
{
    $result = getApiDataWithJWT(
        'bonus-eligibility/' . (int)$record_id,
        array('remark' => $remark),
        'PUT',
        $staff_id
    );
    if (!$result['success']) {
        return array('success' => false, 'message' => 'API error');
    }
    $body = json_decode($result['response'], true);
    return array('success' => true, 'data' => isset($body['data']) ? $body['data'] : null);
}

function getIidasMigrationPreview($staff_id, $page = 1, $status = '', $committed = '')
{
    $qs = '?per_page=50&page=' . (int)$page;
    if ($status !== '')    { $qs .= '&status='    . urlencode($status); }
    if ($committed !== '') { $qs .= '&committed=' . urlencode($committed); }

    $result = getApiDataWithJWT('iidas/migration-preview' . $qs, null, 'GET', $staff_id);
    if (!$result['success']) {
        return array('success' => false, 'message' => 'API error', 'data' => array(), 'meta' => array());
    }
    $body = json_decode($result['response'], true);
    return array(
        'success' => true,
        'data'    => isset($body['data']) ? $body['data'] : array(),
        'meta'    => isset($body['meta']) ? $body['meta'] : array(),
    );
}

function getIidasMigrationSummary($staff_id)
{
    $result = getApiDataWithJWT('iidas/migration-preview/summary', null, 'GET', $staff_id);
    if (!$result['success']) {
        return array('success' => false, 'message' => 'API error', 'data' => array());
    }
    $body = json_decode($result['response'], true);
    return array('success' => true, 'data' => isset($body['data']) ? $body['data'] : array());
}

// Only run request handler if this file is accessed directly (not included)
if (!defined('API_JWT_INCLUDED')) {
    // Main request handler
    $input = file_get_contents('php://input');
    $jsonData = json_decode($input, true);
    $response = array('success' => false, 'message' => 'Invalid request');

    // Check for action in query parameter, multipart POST field, or JSON body.
    // Multipart uploads (file attachments) carry the action in $_POST because the
    // raw body is consumed as form-data, so json_decode above returns null.
    $action = isset($_GET['action'])
        ? $_GET['action']
        : (isset($_POST['action'])
            ? $_POST['action']
            : (isset($jsonData['action']) ? $jsonData['action'] : null));

    // Check if we have a staff ID for authentication
    if (!$staff_id) {
        echo json_encode(array(
            'success' => false,
            'error' => 'No staff ID available for authentication',
            'message' => 'Staff ID is required for JWT authentication. Please ensure you are logged in.',
            'debug' => array(
                'session_username' => isset($_SESSION["myusername"]) ? $_SESSION["myusername"] : 'not set',
                'staff_id' => $staff_id
            )
        ));
        exit;
    }

    // Attachment download is a plain GET link that streams binary content rather
    // than JSON, so it is handled before the JSON request switch below.
    if ($action === 'attachment-download') {
        $dlId  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $dlAtt = isset($_GET['att']) ? (int)$_GET['att'] : 0;
        downloadAtemAttachment($dlId, $dlAtt, $staff_id);
        exit;
    }

    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($action) {
            switch ($action) {
                case 'lookups':
                    $response = getAtemLookups($staff_id);
                    break;

                case 'create-draft':
                    $issuer = getStaffAuthData($staff_id);
                    $draftData = array(
                        'issuer_staff_id' => $issuer ? $issuer['staff_id'] : $staff_id,
                        'issuer_name'     => $issuer ? $issuer['staff_name'] : null,
                        'staff_dept_id'   => $issuer ? $issuer['staff_dept_id'] : null,
                        'department_name' => $issuer ? $issuer['department_name'] : null
                    );
                    $response = createAtemDraft($draftData, $staff_id);
                    break;

                case 'list-atems':
                    $response = getAtemList($staff_id);
                    break;

                case 'dashboard-stats':
                    $listResult = getAtemList($staff_id, true);
                    if (!$listResult['success']) {
                        $response = array('success' => false, 'message' => 'Failed to load ATEM data');
                        break;
                    }
                    $items = $listResult['data'];

                    // Role-based visibility — mirrors view.php server-side filtering.
                    // $atem_permission is set when api.php is included from a page,
                    // but NOT in direct-access mode (how the dashboard calls it), so
                    // fall back to a grade/atem lookup like get-staff-atem-list does.
                    $_perm = 0;
                    if (isset($atem_permission)) {
                        $_perm = (int)$atem_permission;
                    } elseif (isset($_SESSION['atem_dev_role_override'])) {
                        // Dev role simulation (localhost): mirror header.php so the
                        // dashboard reflects the simulated grade rather than the real
                        // DB grade/atem. Dev override is never treated as superadmin.
                        $_perm = (int)$_SESSION['atem_dev_role_override'];
                    } elseif ($staff_id) {
                        $_perm_res = mysqli_query($conn, "SELECT grade, atem FROM staff WHERE id = " . (int)$staff_id . " AND recycle != 1");
                        if ($_perm_res && ($_perm_row = mysqli_fetch_assoc($_perm_res))) {
                            $_perm = ((int)$_perm_row['atem'] === 1) ? 6 : (int)$_perm_row['grade'];
                        }
                    }

                    // staff.department is comma-separated (e.g. "3,7"); a user can
                    // belong to several departments. Parse all of them for overlap.
                    $_userDeptIds = array();
                    if (isset($department) && $department !== '') {
                        foreach (explode(',', (string)$department) as $_dpart) {
                            $_dpart = (int)trim($_dpart);
                            if ($_dpart > 0) { $_userDeptIds[] = $_dpart; }
                        }
                    }
                    // staff.outlet is comma-separated too - used to narrow a grade-2
                    // Outlet-department viewer down to their own specific outlet(s),
                    // instead of every outlet company-wide (see the elseif below).
                    $_userOutletIds = array();
                    if (isset($outlet) && $outlet !== '') {
                        foreach (explode(',', (string)$outlet) as $_opart) {
                            $_opart = (int)trim($_opart);
                            if ($_opart > 0) { $_userOutletIds[] = $_opart; }
                        }
                    }
                    $_userStaff = (int)$staff_id;

                    if ($_perm === 1) {
                        $roleFiltered = array();
                        foreach ($items as $_item) {
                            $_issuerId = isset($_item['issuer_staff_id']) ? (int)$_item['issuer_staff_id'] : 0;
                            $_arciIds  = array();
                            if (isset($_item['arci']) && is_array($_item['arci'])) {
                                foreach ($_item['arci'] as $_m) {
                                    if (!empty($_m['staff_id'])) { $_arciIds[] = (int)$_m['staff_id']; }
                                }
                            }
                            if ($_issuerId === $_userStaff || in_array($_userStaff, $_arciIds)) {
                                $roleFiltered[] = $_item;
                            }
                        }
                        $items = $roleFiltered;
                    } elseif ($_perm === 2 && in_array(1, $_userDeptIds, true)) {
                        // Grade 2, Outlet department: narrowed to the viewer's own
                        // specific outlet(s) (staff.outlet overlap with the card's own
                        // linked outlets) - department=1 alone is shared by every
                        // outlet company-wide, so the dept-overlap rule below would
                        // show every outlet's cards.
                        $roleFiltered = array();
                        foreach ($items as $_item) {
                            $_itemOutletIds = array();
                            if (isset($_item['outlets']) && is_array($_item['outlets'])) {
                                foreach ($_item['outlets'] as $_o) {
                                    if (!empty($_o['outlet_id'])) { $_itemOutletIds[] = (int)$_o['outlet_id']; }
                                }
                            }
                            if (array_intersect($_userOutletIds, $_itemOutletIds)) {
                                $roleFiltered[] = $_item;
                            }
                        }
                        $items = $roleFiltered;
                    } elseif ($_perm === 2 || $_perm === 3) {
                        // Grades 2 and 3: cards where the issuer or any ARCI member
                        // belongs to ANY of the user's departments.
                        $roleFiltered = array();
                        foreach ($items as $_item) {
                            $_itemDept  = isset($_item['staff_dept_id']) ? (int)$_item['staff_dept_id'] : 0;
                            $_arciDepts = array();
                            if (isset($_item['arci']) && is_array($_item['arci'])) {
                                foreach ($_item['arci'] as $_m) {
                                    if (!empty($_m['staff_dept_id'])) { $_arciDepts[] = (int)$_m['staff_dept_id']; }
                                }
                            }
                            if (in_array($_itemDept, $_userDeptIds) || array_intersect($_userDeptIds, $_arciDepts)) {
                                $roleFiltered[] = $_item;
                            }
                        }
                        $items = $roleFiltered;
                    }
                    // Grades 4–6 (superadmin resolves to 6): no role-based filtering

                    $filterYear      = isset($jsonData['filter_year'])       ? (int)$jsonData['filter_year']       : 0;
                    $filterMonth     = isset($jsonData['filter_month'])      ? (int)$jsonData['filter_month']      : 0;
                    $filterQuarter   = isset($jsonData['filter_quarter'])    ? (int)$jsonData['filter_quarter']    : 0;
                    $filterDeptId    = isset($jsonData['filter_dept_id'])    ? (int)$jsonData['filter_dept_id']    : 0;
                    $filterStaffId   = isset($jsonData['filter_staff_id'])   ? (int)$jsonData['filter_staff_id']   : 0;
                    // 0 = unfiltered, 1 = HQ, 2 = Outlet. Both dashboard tabs now send
                    // an explicit value so HQ/Outlet cards never mix in one response.
                    $filterAtemType  = isset($jsonData['filter_atem_type'])  ? (int)$jsonData['filter_atem_type']  : 0;
                    $filterOutletId  = isset($jsonData['filter_outlet_id'])  ? (int)$jsonData['filter_outlet_id']  : 0;
                    // Pillar is matched by name (not id), matching the vfo-pillar plain
                    // <select> convention already used on view.php/js/view.js.
                    $filterPillarName = isset($jsonData['filter_pillar_name']) ? trim((string)$jsonData['filter_pillar_name']) : '';
                    $periodMonths = atem_period_months($filterMonth, $filterQuarter);

                    $levelMap = array(
                        1 => array('label' => 'L1 Operational',     'cards' => 0, 'complete' => 0, 'excellence' => 0, 'fail' => 0, 'forecast' => 0.0),
                        2 => array('label' => 'L2 Improvement',     'cards' => 0, 'complete' => 0, 'excellence' => 0, 'fail' => 0, 'forecast' => 0.0),
                        3 => array('label' => 'L3 Cross/Strategic',  'cards' => 0, 'complete' => 0, 'excellence' => 0, 'fail' => 0, 'forecast' => 0.0),
                        4 => array('label' => 'L4 Company-Level',    'cards' => 0, 'complete' => 0, 'excellence' => 0, 'fail' => 0, 'forecast' => 0.0),
                    );
                    // Pillars (Outlet-type equivalent of levels) come from a DB table,
                    // not fixed ids - pre-seed every known pillar (even ones with 0
                    // matching cards) so the table always lists all of them, mirroring
                    // how $levelMap above always shows all 4 levels.
                    $pillarMap = array();
                    $_pillarLookup = getAtemLookups($staff_id);
                    if (!empty($_pillarLookup['success']) && isset($_pillarLookup['data']['pillars'])) {
                        foreach ($_pillarLookup['data']['pillars'] as $_p) {
                            if (isset($_p['id'])) {
                                $pillarMap[(int)$_p['id']] = array('label' => $_p['name'], 'cards' => 0, 'complete' => 0, 'excellence' => 0, 'fail' => 0, 'forecast' => 0.0);
                            }
                        }
                    }

                    $byStatus = array('active' => 0, 'complete' => 0, 'excellence' => 0, 'extended' => 0, 'extended_status' => 0, 'failed' => 0, 'draft' => 0);
                    $total = 0;
                    $incentiveTotal = 0.0;
                    $overdueCount = 0;
                    $byDept = array();
                    $today = date('Y-m-d');

                    // Suspended/Force Terminated live outside every other aggregate
                    // above (they are soft-deleted, like genuinely-Deleted cards) but
                    // still need their own dashboard visibility - tallied separately
                    // below rather than folded into $byStatus/$total.
                    $suspendedCount = 0;
                    $forceTerminatedCount = 0;
                    $byIssuerSft = array();

                    // Involvement breakdown — how many (filtered, non-deleted) cards
                    // someone is tagged on as Issuer vs. each ARCI role. A single card
                    // can count toward multiple roles (e.g. Issuer AND 'C'), but each
                    // role is counted at most once per card.
                    //
                    // Scope depends on the active filters so grade 2+ users (who see
                    // beyond their own cards) can drill in: selecting a Staff filter
                    // shows that staff member's involvement; selecting a Department
                    // (with no staff) aggregates involvement across everyone in that
                    // department; with neither, it falls back to the logged-in user's
                    // own involvement.
                    $myScopeMode = 'me';
                    $myScopeStaffId = (int)$staff_id;
                    if ($filterStaffId > 0) {
                        $myScopeMode = 'staff';
                        $myScopeStaffId = $filterStaffId;
                    } elseif ($filterDeptId > 0) {
                        $myScopeMode = 'dept';
                    }
                    $myRoleBreakdown = array('issuer' => 0, 'A' => 0, 'R' => 0, 'C' => 0, 'I' => 0);
                    $myInvolvedTotal = 0;

                    foreach ($items as $item) {
                        $statusVal = isset($item['status']['value']) ? $item['status']['value'] : '';

                        // Generic scope filters apply regardless of status, so the
                        // Suspended/Force Terminated tally below stays consistent with
                        // every other aggregate (same dept/staff/atem-type/outlet/pillar
                        // scope) instead of only inheriting the earlier role-based filter.
                        if ($filterDeptId > 0) {
                            $itemDeptId = isset($item['staff_dept_id']) ? (int)$item['staff_dept_id'] : 0;
                            $itemArciDepts = array();
                            if (isset($item['arci']) && is_array($item['arci'])) {
                                foreach ($item['arci'] as $_m) {
                                    if (!empty($_m['staff_dept_id'])) { $itemArciDepts[] = (int)$_m['staff_dept_id']; }
                                }
                            }
                            if ($itemDeptId !== $filterDeptId && !in_array($filterDeptId, $itemArciDepts)) { continue; }
                        }
                        if ($filterStaffId > 0) {
                            $itemIssuerId = isset($item['issuer_staff_id']) ? (int)$item['issuer_staff_id'] : 0;
                            $itemIsArci = false;
                            if (isset($item['arci']) && is_array($item['arci'])) {
                                foreach ($item['arci'] as $_m) {
                                    if (!empty($_m['staff_id']) && (int)$_m['staff_id'] === $filterStaffId) {
                                        $itemIsArci = true;
                                        break;
                                    }
                                }
                            }
                            if ($itemIssuerId !== $filterStaffId && !$itemIsArci) { continue; }
                        }
                        if ($filterAtemType > 0) {
                            $itemAtemType = isset($item['atem_type']) ? (int)$item['atem_type'] : 1;
                            if ($itemAtemType !== $filterAtemType) { continue; }
                        }
                        if ($filterOutletId > 0) {
                            $itemHasOutlet = false;
                            if (isset($item['outlets']) && is_array($item['outlets'])) {
                                foreach ($item['outlets'] as $_o) {
                                    if (!empty($_o['outlet_id']) && (int)$_o['outlet_id'] === $filterOutletId) {
                                        $itemHasOutlet = true;
                                        break;
                                    }
                                }
                            }
                            if (!$itemHasOutlet) { continue; }
                        }
                        if ($filterPillarName !== '') {
                            $itemPillarName = isset($item['pillar']['name']) ? $item['pillar']['name'] : '';
                            if ($itemPillarName !== $filterPillarName) { continue; }
                        }

                        if ($statusVal === 'Suspended' || $statusVal === 'Force Terminated') {
                            // closure_date is always null for these statuses (they never
                            // actually closed), so period filtering falls back to
                            // start_date instead of the closure_date used below.
                            if ($filterYear > 0 || $filterMonth > 0 || $filterQuarter > 0) {
                                $sftPeriodDate = isset($item['start_date']) ? $item['start_date'] : '';
                                if ($sftPeriodDate && !atem_date_in_period($sftPeriodDate, $periodMonths, $filterYear)) {
                                    continue;
                                }
                            }
                            if ($statusVal === 'Suspended') {
                                $suspendedCount++;
                            } else {
                                $forceTerminatedCount++;
                            }
                            $sftIssuerId = isset($item['issuer_staff_id']) ? (int)$item['issuer_staff_id'] : 0;
                            if ($sftIssuerId > 0) {
                                if (!isset($byIssuerSft[$sftIssuerId])) {
                                    $byIssuerSft[$sftIssuerId] = array(
                                        'issuer_staff_id'  => $sftIssuerId,
                                        'dept_id'          => isset($item['staff_dept_id']) ? (int)$item['staff_dept_id'] : 0,
                                        'suspended'        => 0,
                                        'force_terminated' => 0,
                                    );
                                }
                                if ($statusVal === 'Suspended') {
                                    $byIssuerSft[$sftIssuerId]['suspended']++;
                                } else {
                                    $byIssuerSft[$sftIssuerId]['force_terminated']++;
                                }
                            }
                            continue;
                        }
                        if ($statusVal === 'Deleted' || !empty($item['deleted_at'])) { continue; }

                        if ($filterYear > 0 || $filterMonth > 0 || $filterQuarter > 0) {
                            // Active/Draft cards haven't closed yet, so the period filter
                            // goes by when they started; every other status (Completed
                            // family, Extended, Failed) is bucketed by when it closed —
                            // mirrors atem_status_period_field()'s convention already used
                            // by Staff Performance, instead of start_date for everything.
                            $periodField = ($statusVal === 'Active' || $statusVal === 'Draft') ? 'start_date' : 'closure_date';
                            $periodDate  = isset($item[$periodField]) ? $item[$periodField] : '';
                            if ($periodDate && !atem_date_in_period($periodDate, $periodMonths, $filterYear)) {
                                continue;
                            }
                        }

                        $total++;

                        if ($myScopeMode === 'dept') {
                            // Department-wide aggregate: count the card toward 'issuer'
                            // if the issuer belongs to the department, and toward each
                            // ARCI role held by any member of that department.
                            $_involved     = false;
                            $_issuerDeptId = isset($item['staff_dept_id']) ? (int)$item['staff_dept_id'] : 0;
                            if ($_issuerDeptId === $filterDeptId) {
                                $myRoleBreakdown['issuer']++;
                                $_involved = true;
                            }
                            if (isset($item['arci']) && is_array($item['arci'])) {
                                $_seenRoles = array();
                                foreach ($item['arci'] as $_m) {
                                    $_mDeptId = !empty($_m['staff_dept_id']) ? (int)$_m['staff_dept_id'] : 0;
                                    if ($_mDeptId === $filterDeptId
                                        && !empty($_m['role']) && isset($myRoleBreakdown[$_m['role']])
                                        && !in_array($_m['role'], $_seenRoles)) {
                                        $myRoleBreakdown[$_m['role']]++;
                                        $_seenRoles[] = $_m['role'];
                                        $_involved = true;
                                    }
                                }
                            }
                            if ($_involved) { $myInvolvedTotal++; }
                        } elseif ($myScopeStaffId > 0) {
                            $_involved  = false;
                            $_issuerId  = isset($item['issuer_staff_id']) ? (int)$item['issuer_staff_id'] : 0;
                            if ($_issuerId === $myScopeStaffId) {
                                $myRoleBreakdown['issuer']++;
                                $_involved = true;
                            }
                            if (isset($item['arci']) && is_array($item['arci'])) {
                                $_seenRoles = array();
                                foreach ($item['arci'] as $_m) {
                                    if (!empty($_m['staff_id']) && (int)$_m['staff_id'] === $myScopeStaffId
                                        && !empty($_m['role']) && isset($myRoleBreakdown[$_m['role']])
                                        && !in_array($_m['role'], $_seenRoles)) {
                                        $myRoleBreakdown[$_m['role']]++;
                                        $_seenRoles[] = $_m['role'];
                                        $_involved = true;
                                    }
                                }
                            }
                            if ($_involved) { $myInvolvedTotal++; }
                        }

                        $levelStr  = isset($item['level_structure']['level']) ? $item['level_structure']['level'] : '';
                        preg_match('/\d+/', $levelStr, $lvlMatch);
                        $levelNum  = $lvlMatch ? (int)$lvlMatch[0] : 0;

                        $pillarId   = isset($item['pillar']['id'])   ? (int)$item['pillar']['id']   : 0;
                        $pillarName = isset($item['pillar']['name']) ? $item['pillar']['name']      : '';

                        $isExtended = !empty($item['is_extended']);
                        if ($statusVal === 'Extended') {
                            $byStatus['extended_status']++;
                            $byStatus['active']++;
                        } elseif ($statusVal === 'Active') {
                            $byStatus['active']++;
                        } elseif ($statusVal === 'Draft') {
                            $byStatus['draft']++;
                        } elseif ($statusVal === 'Completed') {
                            $byStatus['complete']++;
                        } elseif ($statusVal === 'Completed with Excellence') {
                            $byStatus['excellence']++;
                        } elseif ($statusVal === 'Completed with Extension') {
                            $byStatus['extended']++;
                        } elseif ($statusVal === 'Failed') {
                            $byStatus['failed']++;
                        }

                        if ($levelNum >= 1 && $levelNum <= 4) {
                            $levelMap[$levelNum]['cards']++;
                            if ($statusVal === 'Completed') {
                                $levelMap[$levelNum]['complete']++;
                            } elseif ($statusVal === 'Completed with Excellence') {
                                $levelMap[$levelNum]['excellence']++;
                            } elseif ($statusVal === 'Failed') {
                                $levelMap[$levelNum]['fail']++;
                            }
                        }

                        if ($pillarId > 0) {
                            if (!isset($pillarMap[$pillarId])) {
                                $pillarMap[$pillarId] = array('label' => $pillarName, 'cards' => 0, 'complete' => 0, 'excellence' => 0, 'fail' => 0, 'forecast' => 0.0);
                            }
                            $pillarMap[$pillarId]['cards']++;
                            if ($statusVal === 'Completed') {
                                $pillarMap[$pillarId]['complete']++;
                            } elseif ($statusVal === 'Completed with Excellence') {
                                $pillarMap[$pillarId]['excellence']++;
                            } elseif ($statusVal === 'Failed') {
                                $pillarMap[$pillarId]['fail']++;
                            }
                        }

                        // Active cards have an undecided outcome, so they forecast off
                        // the potential/raw amount (what they'd earn if they close well).
                        // Completed/Completed with Excellence/Extended are already
                        // decided - Extended always forfeits incentive per the
                        // no-incentive-on-extension rule (AtemController::update()), so
                        // using the raw potential amount there would overstate the
                        // forecast; using final_incentive_amount/final_amount instead
                        // correctly contributes RM0 for Extended and the real payout for
                        // Completed/Excellence. Suspended/Force Terminated cards are
                        // soft-deleted and excluded from this loop entirely already.
                        $forecastStatuses = array('Active', 'Extended', 'Completed', 'Completed with Excellence');
                        $itemAtemTypeVal  = isset($item['atem_type']) ? (int)$item['atem_type'] : 1;
                        if ($statusVal === 'Active') {
                            $forecastAmount = ($itemAtemTypeVal === 2)
                                ? (float)($item['reward_amount'] ?? 0)
                                : (float)($item['total_incentive_amount'] ?? 0);
                        } else {
                            $forecastAmount = ($itemAtemTypeVal === 2)
                                ? (float)($item['final_amount'] ?? 0)
                                : (float)($item['final_incentive_amount'] ?? 0);
                        }
                        if (in_array($statusVal, $forecastStatuses)) {
                            $incentiveTotal += $forecastAmount;
                            if ($levelNum >= 1 && $levelNum <= 4) {
                                $levelMap[$levelNum]['forecast'] += $forecastAmount;
                            }
                            if ($pillarId > 0) {
                                $pillarMap[$pillarId]['forecast'] += $forecastAmount;
                            }
                        }

                        if ($statusVal === 'Active' || $statusVal === 'Extended') {
                            $dueDate = !empty($item['final_due_date'])
                                ? $item['final_due_date']
                                : (isset($item['end_date']) ? $item['end_date'] : '');
                            if ($dueDate && substr($dueDate, 0, 10) < $today) {
                                $overdueCount++;
                            }
                        }

                        $deptId = isset($item['staff_dept_id']) ? (int)$item['staff_dept_id'] : 0;
                        if (!isset($byDept[$deptId])) {
                            $byDept[$deptId] = array('cards' => 0, 'complete' => 0, 'excellence' => 0, 'fail' => 0, 'forecast' => 0.0);
                        }
                        $byDept[$deptId]['cards']++;
                        if ($statusVal === 'Completed') {
                            $byDept[$deptId]['complete']++;
                        } elseif ($statusVal === 'Completed with Excellence') {
                            $byDept[$deptId]['excellence']++;
                        } elseif ($statusVal === 'Failed') {
                            $byDept[$deptId]['fail']++;
                        }
                        if (in_array($statusVal, $forecastStatuses)) {
                            $byDept[$deptId]['forecast'] += $forecastAmount;
                        }
                    }

                    $byLevel = array();
                    foreach ($levelMap as $lvlId => $lvlData) {
                        $byLevel[] = array(
                            'level_id'   => $lvlId,
                            'label'      => $lvlData['label'],
                            'cards'      => $lvlData['cards'],
                            'complete'   => $lvlData['complete'],
                            'excellence' => $lvlData['excellence'],
                            'fail'       => $lvlData['fail'],
                            'forecast'   => $lvlData['forecast'],
                        );
                    }

                    $byPillar = array();
                    foreach ($pillarMap as $pId => $pData) {
                        $byPillar[] = array(
                            'pillar_id'  => $pId,
                            'label'      => $pData['label'],
                            'cards'      => $pData['cards'],
                            'complete'   => $pData['complete'],
                            'excellence' => $pData['excellence'],
                            'fail'       => $pData['fail'],
                            'forecast'   => $pData['forecast'],
                        );
                    }
                    usort($byPillar, function($a, $b) { return strcmp($a['label'], $b['label']); });

                    $deptNames = array();
                    $deptRes = mysqli_query($conn, "SELECT id, depart_name FROM staff_department");
                    if ($deptRes) {
                        while ($drow = mysqli_fetch_assoc($deptRes)) {
                            $deptNames[(int)$drow['id']] = $drow['depart_name'];
                        }
                    }
                    $byDepartment = array();
                    foreach ($byDept as $dId => $dData) {
                        $byDepartment[] = array(
                            'dept_id'    => $dId,
                            'dept_name'  => isset($deptNames[$dId]) ? $deptNames[$dId] : ($dId ? 'Dept #' . $dId : 'Unknown'),
                            'cards'      => $dData['cards'],
                            'complete'   => $dData['complete'],
                            'excellence' => $dData['excellence'],
                            'fail'       => $dData['fail'],
                            'forecast'   => $dData['forecast'],
                        );
                    }
                    usort($byDepartment, function($a, $b) { return $b['cards'] - $a['cards']; });

                    $bySuspendForceTerminate = array();
                    if (!empty($byIssuerSft)) {
                        $sftStaffNames = array();
                        $sftStaffIds = array_map('intval', array_keys($byIssuerSft));
                        $staffRes = mysqli_query($conn, "SELECT id, nama_staff FROM staff WHERE id IN (" . implode(',', $sftStaffIds) . ")");
                        if ($staffRes) {
                            while ($srow = mysqli_fetch_assoc($staffRes)) {
                                $sftStaffNames[(int)$srow['id']] = $srow['nama_staff'];
                            }
                        }
                        foreach ($byIssuerSft as $sftIssuerId => $sftRow) {
                            $bySuspendForceTerminate[] = array(
                                'issuer_staff_id'  => $sftIssuerId,
                                'issuer_name'      => isset($sftStaffNames[$sftIssuerId]) ? $sftStaffNames[$sftIssuerId] : ('Staff #' . $sftIssuerId),
                                'dept_id'          => $sftRow['dept_id'],
                                'dept_name'        => isset($deptNames[$sftRow['dept_id']]) ? $deptNames[$sftRow['dept_id']] : ($sftRow['dept_id'] ? 'Dept #' . $sftRow['dept_id'] : 'Unknown'),
                                'suspended'        => $sftRow['suspended'],
                                'force_terminated' => $sftRow['force_terminated'],
                            );
                        }
                        usort($bySuspendForceTerminate, function($a, $b) {
                            return ($b['suspended'] + $b['force_terminated']) - ($a['suspended'] + $a['force_terminated']);
                        });
                    }

                    $response = array(
                        'success' => true,
                        'data'    => array(
                            'total'           => $total,
                            'by_status'       => $byStatus,
                            'by_level'        => $byLevel,
                            'by_pillar'       => $byPillar,
                            'incentive_total' => $incentiveTotal,
                            'overdue_count'   => $overdueCount,
                            'suspended_count' => $suspendedCount,
                            'force_terminated_count' => $forceTerminatedCount,
                            'by_department'   => $byDepartment,
                            'by_suspend_force_terminate' => $bySuspendForceTerminate,
                            'my_roles'        => array(
                                'issuer'   => $myRoleBreakdown['issuer'],
                                'A'        => $myRoleBreakdown['A'],
                                'R'        => $myRoleBreakdown['R'],
                                'C'        => $myRoleBreakdown['C'],
                                'I'        => $myRoleBreakdown['I'],
                                'involved' => $myInvolvedTotal,
                                'scope'    => $myScopeMode,
                            ),
                        ),
                    );
                    break;

                case 'get-atem':
                    if (isset($jsonData['id'])) {
                        $response = getAtem($jsonData['id'], $staff_id);
                    } else {
                        $response = array('success' => false, 'message' => 'Missing ATEM ID');
                    }
                    break;

                case 'update-atem':
                    if (isset($jsonData['id']) && isset($jsonData['data'])) {
                        $data = $jsonData['data'];
                        $data['updated_by'] = $staff_id; // inject server-side
                        if ($is_api_superadmin) {
                            $data['superadmin_override'] = 1;
                        }
                        $update_before = getAtem($jsonData['id'], $staff_id);
                        $update_was_suspended = !empty($update_before['success']) && isset($update_before['data']['status']['value'])
                            && $update_before['data']['status']['value'] === 'Suspended';
                        $response = updateAtem($jsonData['id'], $data, $staff_id);
                        // Manual Suspended -> Force Terminated transition: atem-api already
                        // created the in-app notification; this only sends the email, since
                        // atem-api never sends mail itself.
                        if ($response['success'] && $update_was_suspended
                            && isset($response['data']['status']['value']) && $response['data']['status']['value'] === 'Force Terminated') {
                            $ft_issuer_id = isset($response['data']['issuer_staff_id']) ? (int)$response['data']['issuer_staff_id'] : 0;
                            $ft_issuer = $ft_issuer_id ? getStaffEmail($ft_issuer_id) : null;
                            if ($ft_issuer) {
                                sendAtemForceTerminateEmail(
                                    $ft_issuer['email'],
                                    $ft_issuer['name'],
                                    $jsonData['id'],
                                    isset($response['data']['title']) ? $response['data']['title'] : ('ATEM #' . (int)$jsonData['id']),
                                    ''
                                );
                            }
                        }
                    } else {
                        $response = array('success' => false, 'message' => 'Missing ATEM ID or data');
                    }
                    break;

                case 'update-atem-suspended':
                    if (isset($jsonData['id']) && isset($jsonData['data'])) {
                        $data = $jsonData['data'];
                        $data['updated_by'] = $staff_id; // inject server-side
                        $response = updateAtemSuspendedFields($jsonData['id'], $data, $staff_id);
                    } else {
                        $response = array('success' => false, 'message' => 'Missing ATEM ID or data');
                    }
                    break;

                case 'delete-atem':
                    if (isset($jsonData['id'])) {
                        $delete_remarks = isset($jsonData['remarks']) ? (string)$jsonData['remarks'] : '';
                        $response = deleteAtem($jsonData['id'], $staff_id, $delete_remarks, $is_api_superadmin);
                    } else {
                        $response = array('success' => false, 'message' => 'Missing ATEM ID');
                    }
                    break;

                case 'suspend-atem':
                    if (isset($jsonData['id'])) {
                        $suspend_remarks = isset($jsonData['remarks']) ? (string)$jsonData['remarks'] : '';
                        // Fetch the record before suspending so we still have the
                        // issuer/title even after the status changes (suspend() itself
                        // returns no record data - mirrors chat-send's getAtem() usage).
                        $suspend_atem = getAtem($jsonData['id'], $staff_id);
                        $response = suspendAtem($jsonData['id'], $staff_id, $suspend_remarks);
                        if ($response['success'] && !empty($suspend_atem['success']) && isset($suspend_atem['data'])) {
                            $suspend_issuer_id = isset($suspend_atem['data']['issuer_staff_id']) ? (int)$suspend_atem['data']['issuer_staff_id'] : 0;
                            $suspend_issuer = $suspend_issuer_id ? getStaffEmail($suspend_issuer_id) : null;
                            if ($suspend_issuer) {
                                sendAtemSuspensionEmail(
                                    $suspend_issuer['email'],
                                    $suspend_issuer['name'],
                                    $jsonData['id'],
                                    isset($suspend_atem['data']['title']) ? $suspend_atem['data']['title'] : ('ATEM #' . (int)$jsonData['id']),
                                    $suspend_remarks,
                                    $nama_staff ? $nama_staff : 'SuperAdmin'
                                );
                            }
                        }
                    } else {
                        $response = array('success' => false, 'message' => 'Missing ATEM ID.');
                    }
                    break;

                case 'appeal-atem':
                    if (!$staff_id) {
                        $response = array('success' => false, 'message' => 'Not authenticated.');
                        break;
                    }
                    if (isset($jsonData['id']) && isset($jsonData['remarks']) && trim((string)$jsonData['remarks']) !== '') {
                        $appeal_atem = getAtem($jsonData['id'], $staff_id);
                        if (empty($appeal_atem['success']) || !isset($appeal_atem['data'])) {
                            $response = array('success' => false, 'message' => 'ATEM card not found.');
                            break;
                        }
                        $appeal_issuer_id = (int)(isset($appeal_atem['data']['issuer_staff_id']) ? $appeal_atem['data']['issuer_staff_id'] : 0);
                        if ((int)$staff_id !== $appeal_issuer_id) {
                            $response = array('success' => false, 'message' => 'Only the Issuer can appeal this suspension.');
                            break;
                        }
                        $appeal_remarks = trim((string)$jsonData['remarks']);
                        $response = appealAtem($jsonData['id'], $staff_id, $appeal_remarks);
                        if ($response['success']) {
                            $appeal_suspended_by = (int)(isset($appeal_atem['data']['suspended_by']) ? $appeal_atem['data']['suspended_by'] : 0);
                            $appeal_suspender = $appeal_suspended_by ? getStaffEmail($appeal_suspended_by) : null;
                            if ($appeal_suspender) {
                                sendAtemAppealEmail(
                                    $appeal_suspender['email'],
                                    $appeal_suspender['name'],
                                    $jsonData['id'],
                                    isset($appeal_atem['data']['title']) ? $appeal_atem['data']['title'] : ('ATEM #' . (int)$jsonData['id']),
                                    $appeal_remarks,
                                    $nama_staff ? $nama_staff : 'Issuer'
                                );
                            }
                        }
                    } else {
                        $response = array('success' => false, 'message' => 'Missing ATEM ID or appeal reason.');
                    }
                    break;

                case 'unsuspend-atem':
                    if (isset($jsonData['id'])) {
                        $response = unsuspendAtem($jsonData['id'], $staff_id);
                    } else {
                        $response = array('success' => false, 'message' => 'Missing ATEM ID.');
                    }
                    break;

                case 'update-payout-status':
                    if (isset($jsonData['id']) && isset($jsonData['payout_status']) && isset($jsonData['remarks'])) {
                        // $atem_permission is only set when api.php is included from a page
                        // (edit.php); the browser's apiCall() posts directly here, so resolve
                        // permission fresh from the DB — same fallback used by dashboard-stats.
                        $pp_perm = 0;
                        if (isset($atem_permission)) {
                            $pp_perm = (int)$atem_permission;
                        } elseif ($staff_id) {
                            $pp_perm_res = mysqli_query($conn, "SELECT grade, atem FROM staff WHERE id = " . (int)$staff_id . " AND recycle != 1");
                            if ($pp_perm_res && ($pp_perm_row = mysqli_fetch_assoc($pp_perm_res))) {
                                $pp_perm = ((int)$pp_perm_row['atem'] === 1) ? 6 : (int)$pp_perm_row['grade'];
                            }
                        }
                        $pp_dept_ids = array();
                        if (isset($department) && $department !== '') {
                            foreach (explode(',', (string)$department) as $_ppd) {
                                $_ppd = (int)trim($_ppd);
                                if ($_ppd > 0) { $pp_dept_ids[] = $_ppd; }
                            }
                        }
                        if (!$is_api_superadmin && $pp_perm < 4 && !in_array(17, $pp_dept_ids)) {
                            $response = array('success' => false, 'message' => 'Insufficient permission to update payout status.');
                            break;
                        }
                        $pp_remarks = (string)$jsonData['remarks'];
                        $response = updatePayoutStatus($jsonData['id'], $jsonData['payout_status'], $pp_remarks, $staff_id);
                    } else {
                        $response = array('success' => false, 'message' => 'Missing ATEM ID, payout status, or remarks.');
                    }
                    break;

                case 'arci-add':
                    if (isset($jsonData['id']) && isset($jsonData['data'])) {
                        $data = $jsonData['data'];
                        $data['assigned_by'] = $staff_id; // inject server-side
                        $response = addAtemArci($jsonData['id'], $data, $staff_id);
                    } else {
                        $response = array('success' => false, 'message' => 'Missing ATEM ID or member data');
                    }
                    break;

                case 'arci-remove':
                    if (isset($jsonData['id']) && isset($jsonData['staff_id']) && isset($jsonData['role'])) {
                        $response = removeAtemArci($jsonData['id'], $jsonData['staff_id'], $jsonData['role'], $staff_id);
                    } else {
                        $response = array('success' => false, 'message' => 'Missing ATEM ID, staff_id or role');
                    }
                    break;

                case 'arci-remove-role':
                    if (isset($jsonData['id']) && isset($jsonData['role'])) {
                        $response = removeAtemArciByRole($jsonData['id'], $jsonData['role'], $staff_id);
                    } else {
                        $response = array('success' => false, 'message' => 'Missing ATEM ID or role');
                    }
                    break;

                case 'arci-set-incentivised':
                    if (isset($jsonData['id']) && isset($jsonData['arci_id']) && isset($jsonData['is_incentivised'])) {
                        $response = updateAtemArciIncentivised(
                            $jsonData['id'],
                            $jsonData['arci_id'],
                            (bool)$jsonData['is_incentivised'],
                            $staff_id
                        );
                    } else {
                        $response = array('success' => false, 'message' => 'Missing id, arci_id or is_incentivised');
                    }
                    break;

                case 'reflink-list':
                    if (isset($jsonData['id'])) {
                        $response = getAtemReferenceLinks($jsonData['id'], $staff_id);
                    } else {
                        $response = array('success' => false, 'message' => 'Missing ATEM ID');
                    }
                    break;

                case 'reflink-add':
                    if (isset($jsonData['id']) && isset($jsonData['data'])) {
                        $data = $jsonData['data'];
                        $data['added_by'] = $staff_id; // inject server-side
                        $response = addAtemReferenceLink($jsonData['id'], $data, $staff_id);
                    } else {
                        $response = array('success' => false, 'message' => 'Missing ATEM ID or link data');
                    }
                    break;

                case 'reflink-remove':
                    if (isset($jsonData['id']) && isset($jsonData['link_id'])) {
                        $response = removeAtemReferenceLink($jsonData['id'], $jsonData['link_id'], $staff_id);
                    } else {
                        $response = array('success' => false, 'message' => 'Missing ATEM ID or link_id');
                    }
                    break;

                case 'attachment-list':
                    if (isset($jsonData['id'])) {
                        $response = getAtemAttachments($jsonData['id'], $staff_id);
                    } else {
                        $response = array('success' => false, 'message' => 'Missing ATEM ID');
                    }
                    break;

                case 'attachment-upload':
                    // Multipart request: id arrives in $_POST and the file in $_FILES.
                    if (isset($_POST['id']) && isset($_FILES['file'])) {
                        $response = uploadAtemAttachment((int)$_POST['id'], $_FILES['file'], $staff_id);
                    } else {
                        $response = array('success' => false, 'message' => 'Missing ATEM ID or file');
                    }
                    break;

                case 'attachment-remove':
                    if (isset($jsonData['id']) && isset($jsonData['att_id'])) {
                        $response = removeAtemAttachment($jsonData['id'], $jsonData['att_id'], $staff_id);
                    } else {
                        $response = array('success' => false, 'message' => 'Missing ATEM ID or att_id');
                    }
                    break;

                case 'progress-list':
                    if (isset($jsonData['id'])) {
                        $response = getAtemProgress($jsonData['id'], $staff_id);
                        if ($response['success'] && is_array($response['data'])) {
                            $response['data'] = resolveProgressCreatorNames($response['data'], $conn);
                        }
                    } else {
                        $response = array('success' => false, 'message' => 'Missing ATEM ID');
                    }
                    break;

                case 'progress-add':
                    if (isset($jsonData['id']) && isset($jsonData['data'])) {
                        $data = $jsonData['data'];
                        $data['created_by'] = $staff_id;
                        $response = addAtemProgress($jsonData['id'], $data, $staff_id);
                        if ($response['success'] && is_array($response['data'])) {
                            $response['data'] = resolveProgressCreatorNames($response['data'], $conn);
                        }
                    } else {
                        $response = array('success' => false, 'message' => 'Missing ATEM ID or progress data');
                    }
                    break;

                case 'progress-update':
                    if (isset($jsonData['id']) && isset($jsonData['progress_id']) && isset($jsonData['data'])) {
                        $response = updateAtemProgress($jsonData['id'], $jsonData['progress_id'], $jsonData['data'], $staff_id);
                        if ($response['success'] && is_array($response['data'])) {
                            $response['data'] = resolveProgressCreatorNames($response['data'], $conn);
                        }
                    } else {
                        $response = array('success' => false, 'message' => 'Missing ATEM ID, progress_id or data');
                    }
                    break;

                case 'progress-remove':
                    if (isset($jsonData['id']) && isset($jsonData['progress_id'])) {
                        $response = removeAtemProgress($jsonData['id'], $jsonData['progress_id'], $staff_id);
                        if ($response['success'] && is_array($response['data'])) {
                            $response['data'] = resolveProgressCreatorNames($response['data'], $conn);
                        }
                    } else {
                        $response = array('success' => false, 'message' => 'Missing ATEM ID or progress_id');
                    }
                    break;

                case 'chat-list':
                    if (isset($jsonData['id'])) {
                        $response = getAtemMessages($jsonData['id'], $staff_id);
                        if ($response['success'] && is_array($response['data'])) {
                            $response['data'] = resolveMessageSenderNames($response['data'], $conn);
                        }
                    } else {
                        $response = array('success' => false, 'message' => 'Missing ATEM ID');
                    }
                    break;

                case 'chat-send':
                    if (!$staff_id) {
                        $response = array('success' => false, 'message' => 'Not authenticated.');
                        break;
                    }
                    if (isset($jsonData['id']) && isset($jsonData['message']) && trim((string)$jsonData['message']) !== '') {
                        $chat_atem = getAtem($jsonData['id'], $staff_id);
                        if (empty($chat_atem['success']) || !isset($chat_atem['data'])) {
                            $response = array('success' => false, 'message' => 'ATEM card not found.');
                            break;
                        }
                        // Chat stays open regardless of status (Suspended/Force Terminated/
                        // Completed/etc) - only a genuinely deleted or payout-closed card blocks it.
                        if (!empty($chat_atem['data']['deleted_at'])) {
                            $response = array('success' => false, 'message' => 'This ATEM card has been deleted.');
                            break;
                        }
                        if (isset($chat_atem['data']['payout_status']) && $chat_atem['data']['payout_status'] === 'Closed') {
                            $response = array('success' => false, 'message' => 'This ATEM card is locked because its payout has been closed.');
                            break;
                        }
                        if (!userCanPostAtemChat($chat_atem['data'], $staff_id, $is_api_superadmin)) {
                            $response = array('success' => false, 'message' => 'You do not have permission to post in this chat.');
                            break;
                        }
                        $chat_data = array(
                            'message' => trim((string)$jsonData['message']),
                            'sender_staff_id' => $staff_id
                        );
                        $response = addAtemMessage($jsonData['id'], $chat_data, $staff_id);
                        if ($response['success'] && is_array($response['data'])) {
                            $resolved = resolveMessageSenderNames(array($response['data']), $conn);
                            $response['data'] = $resolved[0];
                        }
                    } else {
                        $response = array('success' => false, 'message' => 'Missing ATEM ID or message text');
                    }
                    break;

                case 'chat-edit':
                    if (!$staff_id) {
                        $response = array('success' => false, 'message' => 'Not authenticated.');
                        break;
                    }
                    if (isset($jsonData['id']) && isset($jsonData['message_id']) && isset($jsonData['message']) && trim((string)$jsonData['message']) !== '') {
                        $edit_data = array(
                            'message' => trim((string)$jsonData['message']),
                            'sender_staff_id' => $staff_id
                        );
                        $response = updateAtemMessage($jsonData['id'], $jsonData['message_id'], $edit_data, $staff_id);
                        if ($response['success'] && is_array($response['data'])) {
                            $resolved = resolveMessageSenderNames(array($response['data']), $conn);
                            $response['data'] = $resolved[0];
                        }
                    } else {
                        $response = array('success' => false, 'message' => 'Missing ATEM ID, message id, or message text');
                    }
                    break;

                case 'chat-unsend':
                    if (!$staff_id) {
                        $response = array('success' => false, 'message' => 'Not authenticated.');
                        break;
                    }
                    if (isset($jsonData['id']) && isset($jsonData['message_id'])) {
                        $response = deleteAtemMessage($jsonData['id'], $jsonData['message_id'], $staff_id);
                    } else {
                        $response = array('success' => false, 'message' => 'Missing ATEM ID or message id');
                    }
                    break;

                case 'notif-list':
                    $response = $staff_id
                        ? getAtemNotifications($staff_id)
                        : array('success' => false, 'message' => 'Not authenticated.');
                    break;

                case 'notif-mark-read':
                    if (isset($jsonData['id'])) {
                        $response = markAtemNotificationRead($jsonData['id'], $staff_id);
                    } else {
                        $response = array('success' => false, 'message' => 'Missing notification id');
                    }
                    break;

                case 'notif-mark-all-read':
                    $response = markAllAtemNotificationsRead($staff_id);
                    break;

                // --- Session-backed in-progress draft (no DB row until save) ---
                case 'draft-get':
                    $response = array(
                        'success' => true,
                        'data'    => isset($_SESSION['atem_draft']) ? $_SESSION['atem_draft'] : null
                    );
                    break;

                case 'draft-save':
                    $_SESSION['atem_draft'] = isset($jsonData['data']) ? $jsonData['data'] : null;
                    $response = array('success' => true);
                    break;

                // Staged attachments (base64) live in their own session key so the
                // frequent text draft-save does not re-send the file bytes.
                case 'draft-files-save':
                    $_SESSION['atem_draft_files'] = isset($jsonData['data']) ? $jsonData['data'] : array();
                    $response = array('success' => true);
                    break;

                case 'draft-clear':
                    unset($_SESSION['atem_draft']);
                    unset($_SESSION['atem_draft_files']);
                    $response = array('success' => true);
                    break;

                case 'update-staff-field':
                    $usf_perm = 0;
                    if (isset($atem_permission)) {
                        $usf_perm = (int)$atem_permission;
                    } elseif ($staff_id) {
                        $usf_perm_res = mysqli_query($conn, "SELECT grade, atem FROM staff WHERE id = " . (int)$staff_id . " AND recycle != 1");
                        if ($usf_perm_res && ($usf_perm_row = mysqli_fetch_assoc($usf_perm_res))) {
                            $usf_perm = ((int)$usf_perm_row['atem'] === 1) ? 6 : (int)$usf_perm_row['grade'];
                        }
                    }
                    if ($usf_perm < 4) {
                        $response = array('success' => false, 'message' => 'Insufficient permission');
                        break;
                    }
                    $usf_sid   = isset($jsonData['staff_id']) ? (int)$jsonData['staff_id'] : 0;
                    $usf_field = isset($jsonData['field'])    ? $jsonData['field']          : '';
                    $usf_value = isset($jsonData['value'])    ? $jsonData['value']          : null;
                    if (!$usf_sid || !in_array($usf_field, array('grade', 'struct'))) {
                        $response = array('success' => false, 'message' => 'Invalid parameters');
                        break;
                    }
                    $usf_col = ($usf_field === 'grade') ? 'grade' : 'struct';
                    if ($usf_value === null || $usf_value === '') {
                        $usf_sql = "UPDATE staff SET `$usf_col` = NULL WHERE id = $usf_sid AND recycle != 1";
                    } else {
                        $usf_int = (int)$usf_value;
                        $usf_sql = "UPDATE staff SET `$usf_col` = $usf_int WHERE id = $usf_sid AND recycle != 1";
                    }
                    if (mysqli_query($conn, $usf_sql) && mysqli_affected_rows($conn) >= 0) {
                        $response = array('success' => true);
                    } else {
                        $response = array('success' => false, 'message' => 'Database update failed');
                    }
                    break;

                case 'bonus-update-remark':
                    if (isset($jsonData['id'])) {
                        $remark = isset($jsonData['remark']) ? $jsonData['remark'] : null;
                        $response = updateBonusRemark($jsonData['id'], $remark, $staff_id);
                    } else {
                        $response = array('success' => false, 'message' => 'Missing record ID');
                    }
                    break;

                case 'get-performance-list':
                    // Access: grade 3+ or SuperAdmin — mirrors staff_performance/index.php's
                    // page guard so every page-admitted user can also load the table.
                    $pl_perm  = 0;
                    $pl_is_sa = false;
                    if (isset($atem_permission)) {
                        // Included after header.php ran (e.g. from a page) - already
                        // dev-override-aware (header.php bakes the override into both
                        // $atem_permission and $_is_superadmin).
                        $pl_perm  = (int)$atem_permission;
                        $pl_is_sa = isset($_is_superadmin) ? (bool)$_is_superadmin : false;
                    } elseif (isset($_SESSION['atem_dev_role_override'])) {
                        // Direct-AJAX call (how staff_performance/index.php's JS actually
                        // calls this action) - header.php never ran in this request, so
                        // $atem_permission/$_is_superadmin above are simply undefined.
                        // Mirrors dashboard-stats' identical fallback tier.
                        $pl_perm  = (int)$_SESSION['atem_dev_role_override'];
                        $pl_is_sa = false;
                    } elseif ($staff_id) {
                        $_pp_res = mysqli_query($conn, "SELECT grade, atem FROM staff WHERE id = " . (int)$staff_id . " AND recycle != 1");
                        if ($_pp_res && ($_pp_row = mysqli_fetch_assoc($_pp_res))) {
                            $pl_perm  = (int)$_pp_row['grade'];
                            $pl_is_sa = ((int)$_pp_row['atem'] === 1);
                        }
                    }
                    // $department/$outlet are always already resolved (and dev-view-
                    // override aware) by api.php's own bootstrap at the top of this
                    // file, regardless of which branch above resolved grade/SA - no
                    // separate re-query needed.
                    $pl_dept_str   = isset($department) ? (string)$department : '';
                    $pl_outlet_str = isset($outlet)     ? (string)$outlet     : '';
                    $pl_dept_ids = array();
                    if ($pl_dept_str !== '') {
                        foreach (explode(',', $pl_dept_str) as $_pld) {
                            $_pld = (int)trim($_pld);
                            if ($_pld > 0) { $pl_dept_ids[] = $_pld; }
                        }
                    }
                    // staff.outlet is comma-separated too - used to narrow a grade-2
                    // Outlet-department caller down to their own specific outlet(s),
                    // instead of every outlet company-wide (see the scoping below).
                    $pl_outlet_ids = array();
                    if ($pl_outlet_str !== '') {
                        foreach (explode(',', $pl_outlet_str) as $_plo) {
                            $_plo = (int)trim($_plo);
                            if ($_plo > 0) { $pl_outlet_ids[] = $_plo; }
                        }
                    }
                    if ($pl_perm < 3 && !$pl_is_sa) {
                        $response = array('success' => false, 'message' => 'Insufficient permissions');
                        break;
                    }
                    // Grade-2/3 non-SA callers only see rows scoped to their own
                    // department(s) - a grade-2 Outlet-department caller is narrowed
                    // further to their own specific outlet(s). Previously this action
                    // had no caller-side scoping at all (only the optional dept/grade/
                    // struct/staff UI filters below), so e.g. a grade-2 user picking
                    // "All Department" could see every department's performance data.
                    $pl_is_grade2_outlet = ($pl_perm === 2 && !$pl_is_sa && in_array(1, $pl_dept_ids, true));

                    $pl_month      = isset($jsonData['month'])   ? (int)$jsonData['month']   : 0;
                    $pl_year       = isset($jsonData['year'])    ? (int)$jsonData['year']    : (int)date('Y');
                    $pl_quarter    = isset($jsonData['quarter']) ? (int)$jsonData['quarter'] : 0;
                    $pl_dept       = isset($jsonData['dept'])    ? (int)$jsonData['dept']    : 0;
                    $pl_grade      = isset($jsonData['grade'])   ? (int)$jsonData['grade']   : 0;
                    $pl_struct     = isset($jsonData['struct'])  ? (int)$jsonData['struct']  : 0;
                    $pl_staff      = isset($jsonData['staff_id']) ? (int)$jsonData['staff_id'] : 0;
                    $pl_outlet_id  = isset($jsonData['filter_outlet_id'])  ? (int)$jsonData['filter_outlet_id']  : 0;

                    if ($pl_quarter < 1 || $pl_quarter > 4) { $pl_quarter = 0; }
                    if ($pl_quarter > 0) { $pl_month = 0; }
                    if ($pl_month < 1 || $pl_month > 12) { $pl_month = 0; }

                    // Whitelist against the 6 selectable statuses — anything not
                    // selected contributes 0 to every bucket, never a stale/mismatched
                    // count, since it's excluded before any aggregation happens.
                    $pl_allowed_statuses = atem_performance_status_options();
                    $pl_statuses = (isset($jsonData['statuses']) && is_array($jsonData['statuses']))
                        ? array_values(array_intersect($jsonData['statuses'], $pl_allowed_statuses))
                        : array('Completed', 'Completed with Excellence');

                    // The page shows HQ ATEM and Outlet ATEM as separate columns on the
                    // SAME row for a given staff member, so getStaffPerformanceLive() -
                    // which only ever returns one combined bucket per call - is called
                    // once per type and merged below, rather than once unfiltered.
                    // The Outlet filter (if any) only narrows the Outlet-side call.
                    $pl_live_hq = getStaffPerformanceLive($pl_month, $pl_year, $pl_quarter, $pl_statuses, $staff_id, 1, 0);
                    if (empty($pl_live_hq['success'])) {
                        $response = array('success' => false, 'message' => 'Unable to reach the ATEM API. Please try again later.');
                        break;
                    }
                    $pl_live_outlet = getStaffPerformanceLive($pl_month, $pl_year, $pl_quarter, $pl_statuses, $staff_id, 2, $pl_outlet_id);
                    if (empty($pl_live_outlet['success'])) {
                        $response = array('success' => false, 'message' => 'Unable to reach the ATEM API. Please try again later.');
                        break;
                    }
                    // OKR has no HQ/Outlet split at the card level, so it's always
                    // fetched once, unfiltered, and its columns always shown - there's
                    // no tab to hide them behind anymore.
                    $pl_okr_live = getStaffOkrPerformanceLive($conn, $pl_month, $pl_year, $pl_quarter, $pl_statuses);
                    if (empty($pl_okr_live['success'])) { $pl_okr_live = array('success' => true, 'data' => array()); }

                    // Resolve current staff details from ODB directly (live, not a
                    // point-in-time snapshot) — name, department, grade, struct,
                    // and (for the Outlet tab's sub-label) position — mirrors
                    // view.php's $staff_positions/$staff_has_outlet convention:
                    // a staff member "is from outlet" based on their own
                    // staff.outlet assignment, not a specific card's dept_id.
                    $pl_staff_names      = array();
                    $pl_staff_grade      = array();
                    $pl_staff_struct     = array();
                    $pl_staff_position   = array();
                    $pl_staff_outlet_ids = array();
                    $pl_staff_dept_first = array();
                    $pl_dept_names       = array();
                    $pl_grade_labels     = array();
                    $pl_struct_labels    = array();

                    $pl_sr = mysqli_query($conn, "SELECT s.id, s.nama_staff, s.grade, s.struct, s.outlet, s.department, p.position_name
                                                   FROM staff s
                                                   LEFT JOIN position_rymnet p ON p.id = s.status_rym
                                                   WHERE s.recycle != 1");
                    if ($pl_sr) {
                        while ($pl_r = mysqli_fetch_assoc($pl_sr)) {
                            $pl_id_ = (int)$pl_r['id'];
                            $pl_staff_names[$pl_id_]  = $pl_r['nama_staff'];
                            $pl_staff_grade[$pl_id_]  = ($pl_r['grade']  !== null) ? (int)$pl_r['grade']  : null;
                            $pl_staff_struct[$pl_id_] = ($pl_r['struct'] !== null) ? (int)$pl_r['struct'] : null;
                            $pl_staff_position[$pl_id_] = !empty($pl_r['outlet'])
                                ? (!empty($pl_r['position_name']) ? $pl_r['position_name'] : '-')
                                : null;
                            $_pl_sids = array();
                            foreach (explode(',', (string)$pl_r['outlet']) as $_plso) {
                                $_plso = (int)trim($_plso);
                                if ($_plso > 0) { $_pl_sids[] = $_plso; }
                            }
                            $pl_staff_outlet_ids[$pl_id_] = $_pl_sids;
                            // First department id, used only as a fallback dept when a
                            // staff has no ATEM aggregate row to inherit dept_id from
                            // (i.e. an OKR-only staff member) - mirrors okrDeptIdsFromCsv().
                            $pl_staff_dept_first[$pl_id_] = 0;
                            foreach (explode(',', (string)$pl_r['department']) as $_pld2) {
                                $_pld2 = (int)trim($_pld2);
                                if ($_pld2 > 0) { $pl_staff_dept_first[$pl_id_] = $_pld2; break; }
                            }
                        }
                    }
                    $pl_dr = mysqli_query($conn, "SELECT id, depart_name FROM staff_department");
                    if ($pl_dr) { while ($pl_r = mysqli_fetch_assoc($pl_dr)) { $pl_dept_names[(int)$pl_r['id']] = $pl_r['depart_name']; } }
                    $pl_gr = mysqli_query($conn, "SELECT id, grade_name FROM staff_grade ORDER BY id ASC");
                    if ($pl_gr) { while ($pl_r = mysqli_fetch_assoc($pl_gr)) { $pl_grade_labels[(int)$pl_r['id']] = $pl_r['grade_name']; } }
                    $pl_str = mysqli_query($conn, "SELECT id, struct_name FROM staff_struct ORDER BY id ASC");
                    if ($pl_str) { while ($pl_r = mysqli_fetch_assoc($pl_str)) { $pl_struct_labels[(int)$pl_r['id']] = $pl_r['struct_name']; } }

                    // Evaluation Structure can change over time (staff_struct_history is
                    // written per staff/quarter by access_control/backend.php) - shown
                    // as a "Qx - year" subtext under the struct name (same two-line
                    // pattern as the Staff Details column), always, from each staff's
                    // most recent history entry, independent of whatever period the
                    // page's own filter happens to be set to. Staff with no history row
                    // yet (never recorded a struct change) simply show no subtext.
                    $pl_struct_period = array();
                    $pl_sh = mysqli_query($conn, "SELECT h.staff_id, h.year, h.quarter
                                                   FROM staff_struct_history h
                                                   INNER JOIN (
                                                       SELECT staff_id, MAX(year * 10 + quarter) AS latest
                                                       FROM staff_struct_history
                                                       GROUP BY staff_id
                                                   ) m ON m.staff_id = h.staff_id AND (h.year * 10 + h.quarter) = m.latest");
                    if ($pl_sh) {
                        while ($pl_r = mysqli_fetch_assoc($pl_sh)) {
                            $pl_struct_period[(int)$pl_r['staff_id']] = 'Q' . (int)$pl_r['quarter'] . ' - ' . (int)$pl_r['year'];
                        }
                    }

                    // Only grade 2 (non-SA) is mandatorily scoped to their own
                    // department overlap - a grade-2 Outlet-department caller is
                    // narrowed further to their own specific outlet(s). Grade 3+
                    // and SuperAdmin see company-wide data, same as grade 4/5 -
                    // matches the Department filter dropdown (index.php), which
                    // already shows every department starting at grade 3. Dept-17
                    // grade-1/below users (the only other way to reach this gate)
                    // are intentionally NOT scoped here - People Management needs
                    // to see/lock payroll company-wide, mirroring the page's own
                    // "single tier" access model (no narrower carve-out).
                    $pl_is_scoped_grade = ($pl_perm === 2 && !$pl_is_sa);

                    // Union of HQ-ATEM-involved, Outlet-ATEM-involved, and OKR-involved
                    // staff ids - a staff member with only OKR cards and no ATEM cards
                    // (e.g. struct 5, "12 OKR") would otherwise never appear at all.
                    $pl_union_sids = array_unique(array_merge(
                        array_keys($pl_live_hq['data']),
                        array_keys($pl_live_outlet['data']),
                        array_keys($pl_okr_live['data'])
                    ));

                    $pl_out = array();
                    foreach ($pl_union_sids as $pl_sid) {
                        $pl_sid = (int)$pl_sid;
                        $pl_hq_rec  = isset($pl_live_hq['data'][$pl_sid])     ? $pl_live_hq['data'][$pl_sid]     : null;
                        $pl_out_rec = isset($pl_live_outlet['data'][$pl_sid]) ? $pl_live_outlet['data'][$pl_sid] : null;
                        $pl_okr_rec = isset($pl_okr_live['data'][$pl_sid])    ? $pl_okr_live['data'][$pl_sid]    : null;

                        $pl_rec_dept = (!empty($pl_hq_rec['dept_id']))
                            ? (int)$pl_hq_rec['dept_id']
                            : ((!empty($pl_out_rec['dept_id']))
                                ? (int)$pl_out_rec['dept_id']
                                : (isset($pl_staff_dept_first[$pl_sid]) ? $pl_staff_dept_first[$pl_sid] : 0));
                        $pl_grade_id   = isset($pl_staff_grade[$pl_sid])  ? $pl_staff_grade[$pl_sid]  : null;
                        $pl_struct_id  = isset($pl_staff_struct[$pl_sid]) ? $pl_staff_struct[$pl_sid] : null;

                        if ($pl_is_scoped_grade) {
                            if ($pl_is_grade2_outlet) {
                                $_pl_target_outlet_ids = isset($pl_staff_outlet_ids[$pl_sid]) ? $pl_staff_outlet_ids[$pl_sid] : array();
                                if (!array_intersect($pl_outlet_ids, $_pl_target_outlet_ids)) { continue; }
                            } elseif (!in_array($pl_rec_dept, $pl_dept_ids, true)) {
                                continue;
                            }
                        }

                        if ($pl_dept   > 0 && $pl_rec_dept  !== $pl_dept)   { continue; }
                        // Outlet filter narrows the whole row, not just the Outlet ATEM
                        // count - a staff member not assigned to the selected outlet
                        // (staff.outlet) is excluded entirely, matching how the old
                        // separate Outlet tab worked.
                        if ($pl_outlet_id > 0) {
                            $_pl_own_outlet_ids = isset($pl_staff_outlet_ids[$pl_sid]) ? $pl_staff_outlet_ids[$pl_sid] : array();
                            if (!in_array($pl_outlet_id, $_pl_own_outlet_ids, true)) { continue; }
                        }
                        if ($pl_grade  > 0 && $pl_grade_id  !== $pl_grade)  { continue; }
                        if ($pl_struct > 0 && $pl_struct_id !== $pl_struct) { continue; }
                        if ($pl_staff  > 0 && $pl_sid        !== $pl_staff)  { continue; }

                        // "HQ ATEM"/"Outlet ATEM"/"OKR" totals are the raw, all-status/
                        // all-role, period-filtered counts (they link straight to
                        // edit.php rather than opening a modal) - not the status-
                        // selected bucket sums, which only drive Completed/Failed.
                        $pl_hq_total     = $pl_hq_rec  ? $pl_hq_rec['total_all']  : 0;
                        $pl_outlet_total = $pl_out_rec ? $pl_out_rec['total_all'] : 0;
                        $pl_okr_total    = $pl_okr_rec ? $pl_okr_rec['total_all'] : 0;
                        if ($pl_hq_total <= 0 && $pl_outlet_total <= 0 && $pl_okr_total <= 0) { continue; }

                        // Est. Reward composition: always HQ + Outlet ATEM + OKR summed
                        // together, regardless of struct - a staff member is rewarded
                        // for whatever they actually completed, even if their
                        // Evaluation Structure normally wouldn't include that category
                        // (e.g. a "12 OKR" staff who completed an ATEM card anyway).
                        $pl_atem_reward = ($pl_hq_rec ? round($pl_hq_rec['total_incentive'], 2) : 0.0)
                            + ($pl_out_rec ? round($pl_out_rec['total_incentive'], 2) : 0.0);
                        $pl_okr_reward  = $pl_okr_rec ? round($pl_okr_rec['reward'], 2) : 0.0;
                        $pl_total_reward = $pl_atem_reward + $pl_okr_reward;

                        $pl_complete_hq     = $pl_hq_rec  ? $pl_hq_rec['complete']  : 0;
                        $pl_complete_outlet = $pl_out_rec ? $pl_out_rec['complete'] : 0;
                        $pl_complete_okr    = $pl_okr_rec ? $pl_okr_rec['complete'] : 0;
                        $pl_failed_hq       = $pl_hq_rec  ? $pl_hq_rec['failed']    : 0;
                        $pl_failed_outlet   = $pl_out_rec ? $pl_out_rec['failed']   : 0;
                        $pl_failed_okr      = $pl_okr_rec ? $pl_okr_rec['failed']   : 0;

                        $pl_has_locked   = !empty($pl_hq_rec['has_locked'])   || !empty($pl_out_rec['has_locked'])   || !empty($pl_okr_rec['has_locked']);
                        $pl_has_unlocked = !empty($pl_hq_rec['has_unlocked']) || !empty($pl_out_rec['has_unlocked']) || !empty($pl_okr_rec['has_unlocked']);
                        // Once a staff member's payout is fully locked (nothing left
                        // unlocked), drop them from the on-screen list entirely - there's
                        // nothing actionable left for them here. They still appear in
                        // the Export CSV (with a "Payout" column), which is the actual
                        // record of who's been paid.
                        if ($pl_has_locked && !$pl_has_unlocked) { continue; }

                        $pl_out[] = array(
                            'id'           => $pl_sid,
                            'staff_id'     => $pl_sid,
                            'month'        => $pl_month,
                            'year'         => $pl_year,
                            'staff_name'   => isset($pl_staff_names[$pl_sid]) ? $pl_staff_names[$pl_sid] : ('Staff #' . $pl_sid),
                            'dept_id'      => $pl_rec_dept,
                            'dept_name'    => ($pl_rec_dept && isset($pl_dept_names[$pl_rec_dept])) ? $pl_dept_names[$pl_rec_dept] : '-',
                            'position_label' => isset($pl_staff_position[$pl_sid]) && $pl_staff_position[$pl_sid] !== null ? $pl_staff_position[$pl_sid] : '-',
                            'grade_id'     => $pl_grade_id,
                            'grade_label'  => ($pl_grade_id !== null && isset($pl_grade_labels[$pl_grade_id])) ? $pl_grade_labels[$pl_grade_id] : '-',
                            'struct_id'    => $pl_struct_id,
                            'struct_label' => ($pl_struct_id !== null && isset($pl_struct_labels[$pl_struct_id])) ? $pl_struct_labels[$pl_struct_id] : '-',
                            'struct_period' => isset($pl_struct_period[$pl_sid]) ? $pl_struct_period[$pl_sid] : '',
                            'total_hq_atem'      => $pl_hq_total,
                            'complete_hq_count'  => $pl_complete_hq,
                            'active_hq_count'    => $pl_hq_rec ? $pl_hq_rec['active'] : 0,
                            'extend_hq_count'    => $pl_hq_rec ? $pl_hq_rec['extend'] : 0,
                            'failed_hq_count'    => $pl_failed_hq,
                            'total_outlet_atem'      => $pl_outlet_total,
                            'complete_outlet_count'  => $pl_complete_outlet,
                            'active_outlet_count'    => $pl_out_rec ? $pl_out_rec['active'] : 0,
                            'extend_outlet_count'    => $pl_out_rec ? $pl_out_rec['extend'] : 0,
                            'failed_outlet_count'    => $pl_failed_outlet,
                            'total_okr'          => $pl_okr_total,
                            'complete_okr_count' => $pl_complete_okr,
                            'active_okr_count'   => $pl_okr_rec ? $pl_okr_rec['active'] : 0,
                            'extend_okr_count'   => $pl_okr_rec ? $pl_okr_rec['extend'] : 0,
                            'failed_okr_count'   => $pl_failed_okr,
                            'complete_total'  => $pl_complete_hq + $pl_complete_outlet + $pl_complete_okr,
                            'failed_total'    => $pl_failed_hq + $pl_failed_outlet + $pl_failed_okr,
                            'total_incentive' => round($pl_total_reward, 2),
                            'has_locked'      => $pl_has_locked,
                            'has_unlocked'    => $pl_has_unlocked,
                        );
                    }

                    // Default sort: highest Est. Reward first.
                    usort($pl_out, function ($a, $b) {
                        return $b['total_incentive'] <=> $a['total_incentive'];
                    });

                    $response = array('success' => true, 'data' => $pl_out);
                    break;

                case 'bulk-lock-payout':
                    // Access: SuperAdmin only. Resolved fresh from DB — same fallback
                    // the single-record update-payout-status case already uses when
                    // api.php is hit directly (no $atem_permission). Dev-role-override
                    // suppresses SA (mirrors header.php) so the Dev Grade toolbar can
                    // simulate a non-SuperAdmin's lack of access.
                    $pyk_is_sa = false;
                    if (isset($_is_superadmin)) {
                        $pyk_is_sa = (bool)$_is_superadmin;
                    } elseif (isset($_SESSION['atem_dev_role_override'])) {
                        $pyk_is_sa = false;
                    } elseif ($staff_id) {
                        $_pyk_res = mysqli_query($conn, "SELECT atem FROM staff WHERE id = " . (int)$staff_id . " AND recycle != 1");
                        if ($_pyk_res && ($_pyk_row = mysqli_fetch_assoc($_pyk_res))) {
                            $pyk_is_sa = ((int)$_pyk_row['atem'] === 1);
                        }
                    }
                    if (!$pyk_is_sa) {
                        $response = array('success' => false, 'message' => 'Insufficient permission to lock payout.');
                        break;
                    }
                    if (!isPayoutLockWindowOpen($conn)) {
                        $response = array('success' => false, 'message' =>
                            'Lock Payout is only available during the configured window at the start of each quarter (Jan/Apr/Jul/Oct).');
                        break;
                    }

                    $pyk_remarks = isset($jsonData['remarks']) ? trim((string)$jsonData['remarks']) : '';
                    if ($pyk_remarks === '') {
                        $response = array('success' => false, 'message' => 'A remark is required to lock payout.');
                        break;
                    }

                    $pyk_staff_ids = resolvePayoutTargetStaffIds($jsonData, $staff_id, $conn);
                    if (empty($pyk_staff_ids)) {
                        $response = array('success' => false, 'message' => 'No staff matched the current filter.');
                        break;
                    }

                    $pyk_month       = isset($jsonData['month'])   ? (int)$jsonData['month']   : 0;
                    $pyk_year        = isset($jsonData['year'])    ? (int)$jsonData['year']    : (int)date('Y');
                    $pyk_quarter     = isset($jsonData['quarter']) ? (int)$jsonData['quarter'] : 0;
                    $pyk_atem_type   = isset($jsonData['filter_atem_type'])  ? (int)$jsonData['filter_atem_type']  : 0;
                    $pyk_outlet_id   = isset($jsonData['filter_outlet_id'])  ? (int)$jsonData['filter_outlet_id']  : 0;
                    $pyk_allowed_statuses = atem_performance_status_options();
                    $pyk_statuses = (isset($jsonData['statuses']) && is_array($jsonData['statuses']))
                        ? array_values(array_intersect($jsonData['statuses'], $pyk_allowed_statuses))
                        : array('Completed', 'Completed with Excellence');

                    $pyk_resolved     = resolvePayoutAtemIds($pyk_month, $pyk_year, $pyk_quarter, $pyk_statuses, $staff_id, $pyk_atem_type, $pyk_outlet_id, $pyk_staff_ids);
                    $pyk_okr_resolved = resolvePayoutOkrIds($conn, $pyk_month, $pyk_year, $pyk_quarter, $pyk_statuses, $pyk_staff_ids, false);
                    $pyk_atem_ids = (!empty($pyk_resolved['success'])) ? $pyk_resolved['ids'] : array();
                    $pyk_okr_ids  = (!empty($pyk_okr_resolved['success'])) ? $pyk_okr_resolved['ids'] : array();
                    // Only fail outright if NEITHER side has anything eligible - a
                    // struct-3 ("8 ATEM") staff legitimately has zero OKR records and a
                    // struct-5 ("12 OKR") staff legitimately has zero ATEM records, and
                    // either alone is still a valid lock action.
                    if (empty($pyk_atem_ids) && empty($pyk_okr_ids)) {
                        $response = array('success' => false, 'message' => 'No eligible ATEM or OKR records matched.');
                        break;
                    }

                    // Skip the ATEM-side call entirely when there are no eligible ATEM
                    // ids - the atem-api endpoint rejects an empty ids[] outright ("No
                    // ATEM ids supplied."), which would otherwise fail an OKR-only lock.
                    $pyk_atem_result = !empty($pyk_atem_ids)
                        ? bulkUpdatePayoutStatus($pyk_atem_ids, $pyk_remarks, $staff_id, false, $pyk_is_sa)
                        : array('success' => true, 'locked' => 0, 'skipped' => 0);
                    $pyk_okr_result  = bulkUpdateOkrPayoutStatus($conn, $pyk_okr_ids, $pyk_remarks, $staff_id, false);
                    if (empty($pyk_atem_result['success'])) {
                        $response = $pyk_atem_result;
                        break;
                    }
                    $response = array(
                        'success'      => true,
                        'atem_locked'  => (int)(isset($pyk_atem_result['locked']) ? $pyk_atem_result['locked'] : 0),
                        'atem_skipped' => (int)(isset($pyk_atem_result['skipped']) ? $pyk_atem_result['skipped'] : 0),
                        'okr_locked'   => (int)(isset($pyk_okr_result['locked']) ? $pyk_okr_result['locked'] : 0),
                        'okr_skipped'  => (int)(isset($pyk_okr_result['skipped']) ? $pyk_okr_result['skipped'] : 0),
                    );
                    break;

                case 'bulk-unlock-payout':
                    // Access: SuperAdmin only. Resolved fresh from DB (not the dev-
                    // override-suppressed session flag) when hit without page context.
                    // Dev-role-override suppresses SA (mirrors header.php) so the Dev
                    // Grade toolbar can simulate a non-SuperAdmin's lack of access.
                    $pyu_is_sa = false;
                    if (isset($_is_superadmin)) {
                        $pyu_is_sa = (bool)$_is_superadmin;
                    } elseif (isset($_SESSION['atem_dev_role_override'])) {
                        $pyu_is_sa = false;
                    } elseif ($staff_id) {
                        $_pyu_res = mysqli_query($conn, "SELECT atem FROM staff WHERE id = " . (int)$staff_id . " AND recycle != 1");
                        if ($_pyu_res && ($_pyu_row = mysqli_fetch_assoc($_pyu_res))) {
                            $pyu_is_sa = ((int)$_pyu_row['atem'] === 1);
                        }
                    }
                    if (!$pyu_is_sa) {
                        $response = array('success' => false, 'message' => 'Insufficient permission to unlock payout.');
                        break;
                    }

                    $pyu_remarks = isset($jsonData['remarks']) ? trim((string)$jsonData['remarks']) : '';
                    if ($pyu_remarks === '') {
                        $response = array('success' => false, 'message' => 'A remark is required to unlock payout.');
                        break;
                    }

                    $pyu_staff_ids = resolvePayoutTargetStaffIds($jsonData, $staff_id, $conn);
                    if (empty($pyu_staff_ids)) {
                        $response = array('success' => false, 'message' => 'No staff matched the current filter.');
                        break;
                    }

                    $pyu_month     = isset($jsonData['month'])   ? (int)$jsonData['month']   : 0;
                    $pyu_year      = isset($jsonData['year'])    ? (int)$jsonData['year']    : (int)date('Y');
                    $pyu_quarter   = isset($jsonData['quarter']) ? (int)$jsonData['quarter'] : 0;
                    $pyu_atem_type = isset($jsonData['filter_atem_type'])  ? (int)$jsonData['filter_atem_type']  : 0;
                    $pyu_outlet_id = isset($jsonData['filter_outlet_id'])  ? (int)$jsonData['filter_outlet_id']  : 0;
                    $pyu_allowed_statuses = atem_performance_status_options();
                    $pyu_statuses = (isset($jsonData['statuses']) && is_array($jsonData['statuses']))
                        ? array_values(array_intersect($jsonData['statuses'], $pyu_allowed_statuses))
                        : array('Completed', 'Completed with Excellence');

                    $pyu_resolved     = resolvePayoutAtemIds($pyu_month, $pyu_year, $pyu_quarter, $pyu_statuses, $staff_id, $pyu_atem_type, $pyu_outlet_id, $pyu_staff_ids);
                    $pyu_okr_resolved = resolvePayoutOkrIds($conn, $pyu_month, $pyu_year, $pyu_quarter, $pyu_statuses, $pyu_staff_ids, true);
                    $pyu_atem_ids = (!empty($pyu_resolved['success'])) ? $pyu_resolved['ids'] : array();
                    $pyu_okr_ids  = (!empty($pyu_okr_resolved['success'])) ? $pyu_okr_resolved['ids'] : array();
                    if (empty($pyu_atem_ids) && empty($pyu_okr_ids)) {
                        $response = array('success' => false, 'message' => 'No locked ATEM or OKR records matched.');
                        break;
                    }

                    // Skip the ATEM-side call entirely when there are no eligible ATEM
                    // ids - the atem-api endpoint rejects an empty ids[] outright ("No
                    // ATEM ids supplied."), which would otherwise fail an OKR-only unlock.
                    $pyu_atem_result = !empty($pyu_atem_ids)
                        ? bulkUpdatePayoutStatus($pyu_atem_ids, $pyu_remarks, $staff_id, true, $pyu_is_sa)
                        : array('success' => true, 'unlocked' => 0, 'skipped' => 0);
                    $pyu_okr_result  = bulkUpdateOkrPayoutStatus($conn, $pyu_okr_ids, $pyu_remarks, $staff_id, true);
                    if (empty($pyu_atem_result['success'])) {
                        $response = $pyu_atem_result;
                        break;
                    }
                    $response = array(
                        'success'        => true,
                        'atem_unlocked'  => (int)(isset($pyu_atem_result['unlocked']) ? $pyu_atem_result['unlocked'] : 0),
                        'atem_skipped'   => (int)(isset($pyu_atem_result['skipped']) ? $pyu_atem_result['skipped'] : 0),
                        'okr_unlocked'   => (int)(isset($pyu_okr_result['unlocked']) ? $pyu_okr_result['unlocked'] : 0),
                        'okr_skipped'    => (int)(isset($pyu_okr_result['skipped']) ? $pyu_okr_result['skipped'] : 0),
                    );
                    break;

                case 'get-staff-atem-list':
                    // Access: grade 3+ or SuperAdmin — same tier as get-performance-list,
                    // since this only ever backs that page's drill-down modal.
                    // $atem_permission is set when included from a page, not in
                    // direct-access mode.
                    $caller_perm  = 0;
                    $caller_is_sa = false;
                    if (isset($atem_permission)) {
                        $caller_perm  = (int)$atem_permission;
                        $caller_is_sa = isset($_is_superadmin) ? (bool)$_is_superadmin : false;
                    } elseif ($staff_id) {
                        $_perm_res = mysqli_query($conn, "SELECT grade, atem FROM staff WHERE id = " . (int)$staff_id . " AND recycle != 1");
                        if ($_perm_res && ($_perm_row = mysqli_fetch_assoc($_perm_res))) {
                            $caller_perm  = (int)$_perm_row['grade'];
                            $caller_is_sa = ((int)$_perm_row['atem'] === 1);
                        }
                    }
                    if ($caller_perm < 3 && !$caller_is_sa) {
                        $response = array('success' => false, 'message' => 'Insufficient permissions');
                        break;
                    }
                    if (!isset($jsonData['target_staff_id'])) {
                        $response = array('success' => false, 'message' => 'Missing target_staff_id');
                        break;
                    }
                    $target_sid = (int)$jsonData['target_staff_id'];
                    $list_result = getStaffAtemList($target_sid, $staff_id);
                    if (!$list_result['success']) {
                        $response = array('success' => false, 'message' => isset($list_result['message']) ? $list_result['message'] : 'Failed');
                        break;
                    }

                    $gsl_has_period = isset($jsonData['year']);
                    $gsl_col        = isset($jsonData['col'])     ? $jsonData['col']         : 'atem';
                    $gsl_month      = isset($jsonData['month'])   ? (int)$jsonData['month']   : 0;
                    $gsl_year       = isset($jsonData['year'])    ? (int)$jsonData['year']    : 0;
                    $gsl_quarter    = isset($jsonData['quarter']) ? (int)$jsonData['quarter'] : 0;
                    $gsl_atem_type  = isset($jsonData['filter_atem_type']) ? (int)$jsonData['filter_atem_type'] : 0;
                    if ($gsl_quarter < 1 || $gsl_quarter > 4) { $gsl_quarter = 0; }

                    // Optional exact-status restriction — lets the Staff Performance
                    // modal mirror whichever statuses are checked in its Status
                    // filter, instead of the coarse 4-bucket match below, which
                    // would otherwise surface statuses the summary table excluded.
                    $gsl_statuses = (isset($jsonData['statuses']) && is_array($jsonData['statuses']) && !empty($jsonData['statuses']))
                        ? array_values(array_intersect($jsonData['statuses'], atem_performance_status_options()))
                        : null;

                    // Build name lookup maps for enrichment.
                    $_s_names = array();
                    $_d_names = array();
                    $_s_res = mysqli_query($conn, "SELECT id, nama_staff FROM staff WHERE recycle != 1");
                    if ($_s_res) { while ($_r = mysqli_fetch_assoc($_s_res)) { $_s_names[(int)$_r['id']] = $_r['nama_staff']; } }
                    $_d_res = mysqli_query($conn, "SELECT id, depart_name FROM staff_department");
                    if ($_d_res) { while ($_r = mysqli_fetch_assoc($_d_res)) { $_d_names[(int)$_r['id']] = $_r['depart_name']; } }

                    // Filter to ATEMs where the target staff is issuer, an Outlet-type
                    // area manager, or an ARCI member, then enrich.
                    $_enriched = array();
                    foreach ($list_result['data'] as $_a) {
                        if ($gsl_atem_type > 0) {
                            $_a_type = isset($_a['atem_type']) ? (int)$_a['atem_type'] : 1;
                            if ($_a_type !== $gsl_atem_type) { continue; }
                        }

                        $issuer_id = isset($_a['issuer_staff_id']) ? (int)$_a['issuer_staff_id'] : 0;
                        $involved  = ($issuer_id === $target_sid);
                        $_is_area_manager = false;
                        if (isset($_a['area_managers']) && is_array($_a['area_managers'])) {
                            foreach ($_a['area_managers'] as $_am) {
                                if (!empty($_am['staff_id']) && (int)$_am['staff_id'] === $target_sid) {
                                    $_is_area_manager = true;
                                    break;
                                }
                            }
                        }
                        $involved = $involved || $_is_area_manager;
                        if (!$involved && isset($_a['arci']) && is_array($_a['arci'])) {
                            foreach ($_a['arci'] as $_m) {
                                if (!empty($_m['staff_id']) && (int)$_m['staff_id'] === $target_sid) {
                                    $involved = true;
                                    break;
                                }
                            }
                        }
                        if (!$involved) { continue; }

                        $_level  = isset($_a['level_structure']) && $_a['level_structure'] ? $_a['level_structure'] : null;
                        $_status = isset($_a['status']) && $_a['status'] ? $_a['status'] : null;
                        $_accountable = array();
                        $_role_parts = array();
                        if ($issuer_id === $target_sid) {
                            $_role_parts[] = 'Issuer';
                        }
                        if ($_is_area_manager) {
                            $_role_parts[] = 'Area Manager';
                        }
                        if (isset($_a['arci']) && is_array($_a['arci'])) {
                            foreach ($_a['arci'] as $_m) {
                                if (isset($_m['role']) && $_m['role'] === 'A' && !empty($_m['staff_id'])) {
                                    $_aid  = (int)$_m['staff_id'];
                                    $_adid = isset($_m['staff_dept_id']) ? (int)$_m['staff_dept_id'] : 0;
                                    $_accountable[] = array(
                                        'name' => isset($_s_names[$_aid]) ? $_s_names[$_aid] : ('Staff #' . $_aid),
                                        'dept' => ($_adid && isset($_d_names[$_adid])) ? $_d_names[$_adid] : '-',
                                    );
                                }
                                if (!empty($_m['staff_id']) && (int)$_m['staff_id'] === $target_sid && isset($_m['role'])) {
                                    $_role_parts[] = $_m['role'];
                                }
                            }
                        }
                        $_my_role = !empty($_role_parts) ? $_role_parts : null;
                        $_row_status = ($_status && isset($_status['value'])) ? $_status['value'] : '';
                        $_row_start  = isset($_a['start_date'])   ? $_a['start_date']   : '';
                        $_row_closure = isset($_a['closure_date']) ? $_a['closure_date'] : '';

                        if ($gsl_has_period && !atem_matches_period_column($_row_status, $_row_start, $_row_closure, $gsl_col, $gsl_month, $gsl_year, $gsl_quarter)) {
                            continue;
                        }
                        if ($gsl_statuses !== null && !in_array($_row_status, $gsl_statuses, true)) {
                            continue;
                        }

                        // Mirrors getStaffPerformanceLive()'s 'complete' bucket
                        // restriction (the "Completed ATEM" column count) - only an
                        // incentivised A/R member with an actual nonzero reward on
                        // this card belongs in the Completed tab of their own modal;
                        // a plain issuer, area manager, C/I role, or non-incentivised
                        // A/R never appears here even though the card is Completed.
                        if ($gsl_col === 'complete') {
                            $_target_reward = 0.0;
                            if (isset($_a['final_incentive_amount']) && (float)$_a['final_incentive_amount'] > 0
                                && isset($_a['arci']) && is_array($_a['arci'])
                            ) {
                                $_incACount = 0;
                                $_incRCount = 0;
                                foreach ($_a['arci'] as $_m2) {
                                    if (empty($_m2['is_incentivised'])) { continue; }
                                    if ($_m2['role'] === 'A') { $_incACount++; }
                                    if ($_m2['role'] === 'R') { $_incRCount++; }
                                }
                                foreach ($_a['arci'] as $_m2) {
                                    if (empty($_m2['staff_id']) || (int)$_m2['staff_id'] !== $target_sid || empty($_m2['is_incentivised'])) { continue; }
                                    if ($_m2['role'] === 'A' && $_incACount > 0) {
                                        $_target_reward = (float)(isset($_a['a_incentive_amount']) ? $_a['a_incentive_amount'] : 0) / $_incACount;
                                    } elseif ($_m2['role'] === 'R' && $_incRCount > 0) {
                                        $_target_reward = (float)(isset($_a['r_incentive_amount']) ? $_a['r_incentive_amount'] : 0) / $_incRCount;
                                    }
                                }
                            }
                            if ($_target_reward <= 0) { continue; }
                        }

                        $_enriched[] = array(
                            'id'          => (int)(isset($_a['id']) ? $_a['id'] : 0),
                            'atem_type'   => isset($_a['atem_type']) ? (int)$_a['atem_type'] : 1,
                            'title'       => isset($_a['title']) ? $_a['title'] : '',
                            'level_label' => ($_level && isset($_level['level'])) ? $_level['level'] : '',
                            'system_name' => $_level ? (isset($_level['system_name']) ? $_level['system_name'] : '') : '',
                            'start_date'  => $_row_start,
                            'end_date'    => isset($_a['end_date']) ? $_a['end_date'] : '',
                            'closure_date' => $_row_closure,
                            'status'      => $_row_status,
                            'accountable' => $_accountable,
                            'is_extended' => !empty($_a['is_extended']),
                            'extended_date_1' => isset($_a['extended_date_1']) ? $_a['extended_date_1'] : '',
                            'my_role'     => $_my_role,
                        );
                    }
                    $response = array('success' => true, 'data' => $_enriched);
                    break;

                case 'get-staff-okr-list':
                    // Access: same tier as get-staff-atem-list (grade 3+ or SuperAdmin) -
                    // no caller-side department scoping, mirroring that sibling's current
                    // (unscoped) behavior; this is a known, pre-existing gap, not
                    // something this task fixes.
                    $okr_caller_perm  = 0;
                    $okr_caller_is_sa = false;
                    if (isset($atem_permission)) {
                        $okr_caller_perm  = (int)$atem_permission;
                        $okr_caller_is_sa = isset($_is_superadmin) ? (bool)$_is_superadmin : false;
                    } elseif ($staff_id) {
                        $_okr_perm_res = mysqli_query($conn, "SELECT grade, atem FROM staff WHERE id = " . (int)$staff_id . " AND recycle != 1");
                        if ($_okr_perm_res && ($_okr_perm_row = mysqli_fetch_assoc($_okr_perm_res))) {
                            $okr_caller_perm  = (int)$_okr_perm_row['grade'];
                            $okr_caller_is_sa = ((int)$_okr_perm_row['atem'] === 1);
                        }
                    }
                    if ($okr_caller_perm < 3 && !$okr_caller_is_sa) {
                        $response = array('success' => false, 'message' => 'Insufficient permissions');
                        break;
                    }
                    if (!isset($jsonData['target_staff_id'])) {
                        $response = array('success' => false, 'message' => 'Missing target_staff_id');
                        break;
                    }
                    $okr_target_sid = (int)$jsonData['target_staff_id'];

                    $gol_has_period = isset($jsonData['year']);
                    $gol_col        = isset($jsonData['col'])     ? $jsonData['col']         : 'okr';
                    $gol_month      = isset($jsonData['month'])   ? (int)$jsonData['month']   : 0;
                    $gol_year       = isset($jsonData['year'])    ? (int)$jsonData['year']    : 0;
                    $gol_quarter    = isset($jsonData['quarter']) ? (int)$jsonData['quarter'] : 0;
                    if ($gol_quarter < 1 || $gol_quarter > 4) { $gol_quarter = 0; }

                    // Optional exact-status restriction, same convention as
                    // get-staff-atem-list - lets the OKR modal's tabs mirror whichever
                    // statuses are checked in the page's Status filter (a tab shows no
                    // data if its status isn't currently selected there).
                    $gol_statuses = (isset($jsonData['statuses']) && is_array($jsonData['statuses']) && !empty($jsonData['statuses']))
                        ? array_values(array_intersect($jsonData['statuses'], atem_performance_status_options()))
                        : null;

                    $_ol_names = array();
                    $_ol_res = mysqli_query($conn, "SELECT id, nama_staff FROM staff WHERE recycle != 1");
                    if ($_ol_res) { while ($_r = mysqli_fetch_assoc($_ol_res)) { $_ol_names[(int)$_r['id']] = $_r['nama_staff']; } }

                    $okr_query = "SELECT c.id, c.objective, c.owner_staff_id, c.owner2_staff_id, c.issuer_staff_id,
                                         c.incentivised_owner_staff_id, c.start_date, c.end_date, c.extended, c.extended_date,
                                         c.closed_at, c.result_status, os.value AS status_value, lv.label AS level_label
                                  FROM okr_cards c
                                  LEFT JOIN okr_statuses os ON c.result_status = os.id
                                  LEFT JOIN okr_levels lv ON c.difficulty_level = lv.level
                                  WHERE c.deleted_at IS NULL
                                    AND (c.owner_staff_id = " . (int)$okr_target_sid . " OR c.owner2_staff_id = " . (int)$okr_target_sid . ")";
                    $okr_result = mysqli_query($conn, $okr_query);
                    $_okr_enriched = array();
                    if ($okr_result) {
                        while ($_o = mysqli_fetch_assoc($okr_result)) {
                            // Normalize okr_cards' raw status word ("Complete") to the
                            // ATEM spelling ("Completed") that okr_matches_period_column()
                            // and $gol_statuses are both expressed in - see
                            // okr_normalize_status_value() for the full rationale.
                            $_o_status  = okr_normalize_status_value(isset($_o['status_value']) ? $_o['status_value'] : '', !empty($_o['extended']));
                            $_o_start   = isset($_o['start_date']) ? $_o['start_date'] : '';
                            $_o_closed  = isset($_o['closed_at']) ? $_o['closed_at'] : '';
                            $_o_closure = $_o_closed ? substr($_o_closed, 0, 10) : '';

                            if ($gol_has_period && !okr_matches_period_column($_o_status, $_o_start, $_o_closed, $gol_col, $gol_month, $gol_year, $gol_quarter)) {
                                continue;
                            }
                            if ($gol_statuses !== null && !in_array($_o_status, $gol_statuses, true)) {
                                continue;
                            }

                            $_o_role_parts = array();
                            $_o_incentivised_id = isset($_o['incentivised_owner_staff_id']) ? (int)$_o['incentivised_owner_staff_id'] : 0;
                            if ((int)$_o['owner_staff_id'] === $okr_target_sid) { $_o_role_parts[] = 'Owner'; }
                            if (!empty($_o['owner2_staff_id']) && (int)$_o['owner2_staff_id'] === $okr_target_sid) { $_o_role_parts[] = 'Owner 2'; }
                            if (!empty($_o['issuer_staff_id']) && (int)$_o['issuer_staff_id'] === $okr_target_sid) { $_o_role_parts[] = 'Issuer'; }
                            if ($_o_incentivised_id === $okr_target_sid) { $_o_role_parts[] = 'Incentivised'; }

                            $_okr_enriched[] = array(
                                'id'           => (int)$_o['id'],
                                'title'        => isset($_o['objective']) ? $_o['objective'] : '',
                                'level_label'  => isset($_o['level_label']) ? $_o['level_label'] : '',
                                'start_date'   => $_o_start,
                                'end_date'     => (!empty($_o['extended']) && !empty($_o['extended_date'])) ? $_o['extended_date'] : (isset($_o['end_date']) ? $_o['end_date'] : ''),
                                'closure_date' => $_o_closure,
                                'status'       => $_o_status,
                                'my_role'      => !empty($_o_role_parts) ? $_o_role_parts : null,
                            );
                        }
                    }
                    $response = array('success' => true, 'data' => $_okr_enriched);
                    break;

                case 'save-atem':
                    if (isset($jsonData['data'])) {
                        $issuer = getStaffAuthData($staff_id);
                        $data = $jsonData['data'];
                        // Issuer identity and audit fields are injected server-side.
                        $data['issuer_staff_id'] = $issuer ? $issuer['staff_id'] : $staff_id;
                        $data['staff_dept_id']   = $issuer ? $issuer['staff_dept_id'] : null;
                        $data['created_by']      = $staff_id;
                        $response = saveAtemCard($data, $staff_id);
                        if (!empty($response['success'])) {
                            unset($_SESSION['atem_draft']);
                            unset($_SESSION['atem_draft_files']);
                        }
                    } else {
                        $response = array('success' => false, 'message' => 'Missing ATEM data');
                    }
                    break;
            }
            echo json_encode($response);
        }
    } else {
        echo json_encode($response);
    }
} // End of direct access check