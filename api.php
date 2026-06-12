<?php
date_default_timezone_set('Asia/Kuala_Lumpur');

// Only set JSON header if this file is accessed directly (not included)
if (!defined('API_JWT_INCLUDED')) {
    header('Content-Type: application/json');
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

// Get staff information from session
$staff_id = null;
$department = null;
$nama_staff = null;

if (isset($_SESSION["myusername"])) {
    $username = $_SESSION["myusername"];
    $query = "select * from staff where username = '$username' and recycle!=1";
    $result = $conn->query($query);

    if ($result->num_rows > 0) {
        while ($rows = $result->fetch_assoc()) {
            $staff_id = stripslashes($rows['id']);
            $department = stripslashes($rows['department']);
            $nama_staff = stripslashes($rows['nama_staff']);
        }
    }
}

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

    return $isLocal ? 'local' : 'production';
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
        return 'http://mytotalhealth.com.my/atem-api/public/api/';
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
function getApiDataWithJWT($endpoint, $data = null, $method = 'GET', $staff_id = null)
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

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
 * @return array Result with the atem rows
 */
function getAtemList($staff_id)
{
    $result = getApiDataWithJWT('atem', null, 'GET', $staff_id);
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
 * Delete a Draft ATEM card (Issuer only)
 * @param int $id ATEM ID
 * @param int $staff_id Staff ID for authentication
 * @return array Result
 */
function deleteAtem($id, $staff_id)
{
    // Pass actor_id as a query parameter because the shared DELETE curl branch
    // does not send a request body (CURLOPT_POSTFIELDS is omitted for DELETE).
    $endpoint = 'atem/' . (int)$id . '?actor_id=' . (int)$staff_id;
    $result   = getApiDataWithJWT($endpoint, null, 'DELETE', $staff_id);
    $httpCode = $result['httpCode'];
    $decoded  = json_decode($result['response'], true);
    if ($httpCode >= 200 && $httpCode < 300 && !empty($decoded['success'])) {
        return array('success' => true);
    }
    $msg = (!empty($decoded['message'])) ? $decoded['message'] : 'Failed to delete ATEM.';
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

// Only run request handler if this file is accessed directly (not included)
if (!defined('API_JWT_INCLUDED')) {
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

    // Attachment download is a plain GET link that streams binary content rather
    // than JSON, so it is handled before the JSON request switch below.
    if ($action === 'attachment-download') {
        $dlId  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $dlAtt = isset($_GET['att']) ? (int)$_GET['att'] : 0;
        downloadAtemAttachment($dlId, $dlAtt, $staff_id);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                    $listResult = getAtemList($staff_id);
                    if (!$listResult['success']) {
                        $response = array('success' => false, 'message' => 'Failed to load ATEM data');
                        break;
                    }
                    $items = $listResult['data'];

                    $filterYear    = isset($jsonData['filter_year'])    ? (int)$jsonData['filter_year']    : 0;
                    $filterMonth   = isset($jsonData['filter_month'])   ? (int)$jsonData['filter_month']   : 0;
                    $filterQuarter = isset($jsonData['filter_quarter']) ? (int)$jsonData['filter_quarter'] : 0;
                    $quarterMonths = array(
                        1 => array(1, 2, 3),
                        2 => array(4, 5, 6),
                        3 => array(7, 8, 9),
                        4 => array(10, 11, 12),
                    );

                    $levelMap = array(
                        1 => array('label' => 'L1 Operational',     'cards' => 0, 'complete' => 0, 'excellence' => 0, 'fail' => 0, 'forecast' => 0.0),
                        2 => array('label' => 'L2 Improvement',     'cards' => 0, 'complete' => 0, 'excellence' => 0, 'fail' => 0, 'forecast' => 0.0),
                        3 => array('label' => 'L3 Cross/Strategic',  'cards' => 0, 'complete' => 0, 'excellence' => 0, 'fail' => 0, 'forecast' => 0.0),
                        4 => array('label' => 'L4 Company-Level',    'cards' => 0, 'complete' => 0, 'excellence' => 0, 'fail' => 0, 'forecast' => 0.0),
                    );

                    $byStatus = array('active' => 0, 'complete' => 0, 'excellence' => 0, 'extended' => 0, 'failed' => 0, 'draft' => 0);
                    $total = 0;
                    $incentiveTotal = 0.0;

                    foreach ($items as $item) {
                        if ($filterYear > 0 || $filterMonth > 0 || $filterQuarter > 0) {
                            $createdAt = isset($item['created_at']) ? $item['created_at'] : '';
                            if ($createdAt) {
                                $ts        = strtotime($createdAt);
                                $itemYear  = (int)date('Y', $ts);
                                $itemMonth = (int)date('n', $ts);
                                if ($filterYear > 0 && $itemYear !== $filterYear) { continue; }
                                if ($filterMonth > 0 && $itemMonth !== $filterMonth) { continue; }
                                if ($filterQuarter > 0) {
                                    $qMonths = isset($quarterMonths[$filterQuarter]) ? $quarterMonths[$filterQuarter] : array();
                                    if (!in_array($itemMonth, $qMonths)) { continue; }
                                }
                            }
                        }
                        $total++;
                        $statusVal = isset($item['status']['value']) ? $item['status']['value'] : '';
                        $levelStr  = isset($item['level_structure']['level']) ? $item['level_structure']['level'] : '';
                        preg_match('/\d+/', $levelStr, $lvlMatch);
                        $levelNum  = $lvlMatch ? (int)$lvlMatch[0] : 0;

                        $isExtended = !empty($item['is_extended']);
                        if ($statusVal === 'Active' || $statusVal === 'Extended') {
                            $byStatus['active']++;
                        } elseif ($statusVal === 'Draft') {
                            $byStatus['draft']++;
                        } elseif ($isExtended) {
                            $byStatus['extended']++;
                        } elseif ($statusVal === 'Completed') {
                            $byStatus['complete']++;
                        } elseif ($statusVal === 'Completed with Excellence') {
                            $byStatus['excellence']++;
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

                        $claimable = isset($item['claimable']) ? (bool)$item['claimable'] : false;
                        if ($claimable && isset($item['total_incentive_amount'])) {
                            $amt = (float)$item['total_incentive_amount'];
                            $incentiveTotal += $amt;
                            if ($levelNum >= 1 && $levelNum <= 4) {
                                $levelMap[$levelNum]['forecast'] += $amt;
                            }
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

                    $response = array(
                        'success' => true,
                        'data'    => array(
                            'total'           => $total,
                            'by_status'       => $byStatus,
                            'by_level'        => $byLevel,
                            'incentive_total' => $incentiveTotal,
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
                        $response = updateAtem($jsonData['id'], $data, $staff_id);
                    } else {
                        $response = array('success' => false, 'message' => 'Missing ATEM ID or data');
                    }
                    break;

                case 'delete-atem':
                    if (isset($jsonData['id'])) {
                        $response = deleteAtem($jsonData['id'], $staff_id);
                    } else {
                        $response = array('success' => false, 'message' => 'Missing ATEM ID');
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

                case 'bonus-update-remark':
                    if (isset($jsonData['id'])) {
                        $remark = isset($jsonData['remark']) ? $jsonData['remark'] : null;
                        $response = updateBonusRemark($jsonData['id'], $remark, $staff_id);
                    } else {
                        $response = array('success' => false, 'message' => 'Missing record ID');
                    }
                    break;

                case 'bonus-trigger-calculate':
                    if (isset($jsonData['month']) && isset($jsonData['year'])) {
                        $result = getApiDataWithJWT(
                            'bonus-eligibility/calculate',
                            array('month' => (int)$jsonData['month'], 'year' => (int)$jsonData['year']),
                            'POST',
                            $staff_id
                        );
                        if ($result['success']) {
                            $body = json_decode($result['response'], true);
                            $response = array('success' => true, 'message' => isset($body['message']) ? $body['message'] : 'Done');
                        } else {
                            $response = array('success' => false, 'message' => 'Calculation failed');
                        }
                    } else {
                        $response = array('success' => false, 'message' => 'Missing month or year');
                    }
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