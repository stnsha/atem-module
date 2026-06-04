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
        return 'http://localhost/atem-api/public/api/';
    } else {
        // TODO: set the production atem-api base URL when deployed.
        return 'http://localhost/atem-api/public/api/';
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
        'department_id'   => $row['department'] !== null ? (int)$row['department'] : null,
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
 * Add an ARCI member to an ATEM card
 * @param int $id ATEM ID
 * @param array $data Member data (staff_id, staff_name, department_id, department_name, role, assigned_by)
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
    $result = getApiDataWithJWT($endpoint, null, 'DELETE', $staff_id);
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
    $result = getApiDataWithJWT($endpoint, null, 'DELETE', $staff_id);
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

    // Check for action in query parameter or JSON body
    $action = isset($_GET['action']) ? $_GET['action'] : (isset($jsonData['action']) ? $jsonData['action'] : null);

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
                        'department_id'   => $issuer ? $issuer['department_id'] : null,
                        'department_name' => $issuer ? $issuer['department_name'] : null
                    );
                    $response = createAtemDraft($draftData, $staff_id);
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
            }
            echo json_encode($response);
        }
    } else {
        echo json_encode($response);
    }
} // End of direct access check
