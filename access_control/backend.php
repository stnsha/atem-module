<?php
date_default_timezone_set('Asia/Kuala_Lumpur');
header('Content-Type: application/json');

if (session_id() == '') {
    session_start();
}

if (!isset($_SESSION['myusername'])) {
    echo json_encode(array('error' => 'Unauthorized'));
    exit;
}

$connect = 1;
include(__DIR__ . '/../../common/index_adv.php');

if (!isset($conn)) {
    echo json_encode(array('error' => 'Database connection error'));
    exit;
}

$_be_serverName = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : '';
$_be_httpHost   = isset($_SERVER['HTTP_HOST'])   ? $_SERVER['HTTP_HOST']   : '';
$_be_isLocal    = in_array($_be_serverName, array('localhost', '127.0.0.1'))
    || strpos($_be_serverName, 'localhost') !== false
    || strpos($_be_httpHost,   'localhost') !== false
    || strpos($_be_httpHost,   '127.0.0.1') !== false;

// Always query DB to get requester identity and department
$username    = mysqli_real_escape_string($conn, $_SESSION['myusername']);
$auth_result = mysqli_query($conn, "SELECT id, grade, atem, department FROM staff WHERE username = '$username' AND recycle != 1");
if (!$auth_result || mysqli_num_rows($auth_result) === 0) {
    echo json_encode(array('error' => 'Unauthorized'));
    exit;
}
$auth_row          = mysqli_fetch_assoc($auth_result);
$requester_id      = (int)$auth_row['id'];
$requester_dept_id = (int)$auth_row['department'];

// Dev grade override applies to grade only; department always from DB
if ($_be_isLocal && isset($_SESSION['atem_dev_role_override'])) {
    $requester_grade = (int)$_SESSION['atem_dev_role_override'];
} else {
    $requester_grade = (int)$auth_row['grade'];
    if ((int)$auth_row['atem'] === 1) {
        $requester_grade = 6;
    }
}

if ($requester_grade < 1) {
    echo json_encode(array('error' => 'Unauthorized'));
    exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($action === 'getActiveStaff' && $_SERVER['REQUEST_METHOD'] === 'GET') {

    if ($requester_grade === 1) {
        $query  = "SELECT s.id, s.nama_staff, s.grade, s.status_semasa, s.struct,
                          d.depart_name, st.struct_name
                   FROM staff s
                   LEFT JOIN staff_department d  ON s.department = d.id
                   LEFT JOIN staff_struct     st ON s.struct      = st.id
                   WHERE s.recycle != 1 AND s.id = $requester_id";
        $result = mysqli_query($conn, $query);
        $staff  = array();
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $staff[] = array(
                    'id'              => (int)$row['id'],
                    'nama_staff'      => $row['nama_staff'],
                    'grade'           => (int)$row['grade'],
                    'status_semasa'   => $row['status_semasa']  ? $row['status_semasa']  : '-',
                    'department_name' => $row['depart_name']    ? $row['depart_name']    : '-',
                    'struct_id'       => ($row['struct'] !== null) ? (int)$row['struct'] : 0,
                    'struct_name'     => $row['struct_name']    ? $row['struct_name']    : '-'
                );
            }
        }
        echo json_encode(array(
            'success'     => true,
            'data'        => $staff,
            'total'       => count($staff),
            'page'        => 1,
            'per_page'    => count($staff),
            'total_pages' => 1
        ));
        exit;
    }

    $page    = isset($_GET['page'])     ? max(1, (int)$_GET['page'])               : 1;
    $perPage = isset($_GET['per_page']) ? max(5, min(100, (int)$_GET['per_page'])) : 30;
    $offset  = ($page - 1) * $perPage;

    if ($requester_grade <= 3) {
        $where_clause       = "s.recycle != 1 AND s.grade > 0 AND s.department = $requester_dept_id";
        $where_clause_count = "recycle != 1 AND grade > 0 AND department = $requester_dept_id";
    } else {
        $where_clause       = "s.recycle != 1 AND s.grade > 0";
        $where_clause_count = "recycle != 1 AND grade > 0";
    }

    $count_result = mysqli_query($conn, "SELECT COUNT(*) as total FROM staff WHERE $where_clause_count");
    $total = 0;
    if ($count_result) {
        $count_row = mysqli_fetch_assoc($count_result);
        $total     = (int)$count_row['total'];
    }

    $query = "SELECT s.id, s.nama_staff, s.grade, s.status_semasa, s.struct,
                     d.depart_name, st.struct_name
              FROM staff s
              LEFT JOIN staff_department d  ON s.department = d.id
              LEFT JOIN staff_struct     st ON s.struct      = st.id
              WHERE $where_clause
              ORDER BY s.grade ASC, s.nama_staff ASC
              LIMIT $perPage OFFSET $offset";
    $result = mysqli_query($conn, $query);

    $staff = array();
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $staff[] = array(
                'id'              => (int)$row['id'],
                'nama_staff'      => $row['nama_staff'],
                'grade'           => (int)$row['grade'],
                'status_semasa'   => $row['status_semasa']  ? $row['status_semasa']  : '-',
                'department_name' => $row['depart_name']    ? $row['depart_name']    : '-',
                'struct_id'       => ($row['struct'] !== null) ? (int)$row['struct'] : 0,
                'struct_name'     => $row['struct_name']    ? $row['struct_name']    : '-'
            );
        }
    }

    echo json_encode(array(
        'success'     => true,
        'data'        => $staff,
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $perPage,
        'total_pages' => (int)ceil($total / $perPage)
    ));
    exit;
}

if ($action === 'searchStaff' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($requester_grade === 1) {
        echo json_encode(array());
        exit;
    }

    $search_term = isset($_POST['search_term']) ? mysqli_real_escape_string($conn, trim($_POST['search_term'])) : '';
    if ($search_term === '') {
        echo json_encode(array());
        exit;
    }

    $dept_filter = ($requester_grade <= 3) ? "AND s.department = $requester_dept_id" : '';

    $query = "SELECT s.id, s.nama_staff, s.grade, s.status_semasa, s.struct,
                     d.depart_name, st.struct_name
              FROM staff s
              LEFT JOIN staff_department d  ON s.department = d.id
              LEFT JOIN staff_struct     st ON s.struct      = st.id
              WHERE s.nama_staff LIKE '%$search_term%'
              AND s.recycle != 1
              $dept_filter
              ORDER BY s.nama_staff ASC
              LIMIT 20";
    $result = mysqli_query($conn, $query);

    $staff = array();
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $staff[] = array(
                'id'              => (int)$row['id'],
                'nama_staff'      => $row['nama_staff'],
                'grade'           => (int)$row['grade'],
                'status_semasa'   => $row['status_semasa']  ? $row['status_semasa']  : '-',
                'department_name' => $row['depart_name']    ? $row['depart_name']    : '-',
                'struct_id'       => ($row['struct'] !== null) ? (int)$row['struct'] : 0,
                'struct_name'     => $row['struct_name']    ? $row['struct_name']    : '-'
            );
        }
    }

    echo json_encode($staff);
    exit;
}

if ($action === 'updateAccess' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($requester_grade < 2) {
        echo json_encode(array('success' => false, 'message' => 'Unauthorized.'));
        exit;
    }

    $target_id = isset($_POST['staff_id'])  ? (int)$_POST['staff_id']  : 0;
    $grade     = isset($_POST['grade'])     ? (int)$_POST['grade']     : -1;
    $struct_id = isset($_POST['struct_id']) ? (int)$_POST['struct_id'] : 0;

    $valid_grades = array(0, 1, 2, 3, 4, 5, 6);

    if ($target_id <= 0 || !in_array($grade, $valid_grades)) {
        echo json_encode(array('success' => false, 'message' => 'Invalid input.'));
        exit;
    }

    if ($requester_grade <= 3) {
        $dept_check = mysqli_query($conn, "SELECT department FROM staff WHERE id = $target_id AND recycle != 1");
        if (!$dept_check || mysqli_num_rows($dept_check) === 0) {
            echo json_encode(array('success' => false, 'message' => 'Staff not found.'));
            exit;
        }
        $dept_row = mysqli_fetch_assoc($dept_check);
        if ((int)$dept_row['department'] !== $requester_dept_id) {
            echo json_encode(array('success' => false, 'message' => 'You can only update staff in your own department.'));
            exit;
        }
    }

    $struct_id_safe = max(0, $struct_id);
    $update = "UPDATE staff SET grade = $grade, struct = $struct_id_safe WHERE id = $target_id AND recycle != 1";
    if (mysqli_query($conn, $update)) {
        echo json_encode(array('success' => true, 'message' => 'Updated successfully.'));
    } else {
        echo json_encode(array('success' => false, 'message' => 'Database error: ' . mysqli_error($conn)));
    }
    exit;
}

if ($action === 'getLibrary' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $grades  = array();
    $structs = array();

    $r = mysqli_query($conn, "SELECT id, grade_name FROM staff_grade ORDER BY id ASC");
    if ($r) {
        while ($row = mysqli_fetch_assoc($r)) {
            $grades[] = array('id' => (int)$row['id'], 'label' => $row['grade_name']);
        }
    }

    $r = mysqli_query($conn, "SELECT id, struct_name FROM staff_struct ORDER BY id ASC");
    if ($r) {
        while ($row = mysqli_fetch_assoc($r)) {
            $structs[] = array('id' => (int)$row['id'], 'label' => $row['struct_name']);
        }
    }

    echo json_encode(array('success' => true, 'grades' => $grades, 'structs' => $structs));
    exit;
}

if ($action === 'updateLibrary' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($requester_grade < 6) {
        echo json_encode(array('success' => false, 'message' => 'Unauthorized.'));
        exit;
    }

    $type  = isset($_POST['type'])  ? $_POST['type']        : '';
    $id    = isset($_POST['id'])    ? (int)$_POST['id']     : -1;
    $label = isset($_POST['label']) ? trim($_POST['label'])  : '';

    if (!in_array($type, array('grade', 'struct')) || $id < 0 || $label === '') {
        echo json_encode(array('success' => false, 'message' => 'Invalid input.'));
        exit;
    }

    $table         = ($type === 'grade') ? 'staff_grade' : 'staff_struct';
    $col           = ($type === 'grade') ? 'grade_name'  : 'struct_name';
    $label_escaped = mysqli_real_escape_string($conn, $label);

    $result = mysqli_query($conn, "UPDATE `$table` SET `$col` = '$label_escaped' WHERE id = $id");
    if ($result && mysqli_affected_rows($conn) >= 0) {
        echo json_encode(array('success' => true, 'message' => 'Label updated successfully.'));
    } else {
        echo json_encode(array('success' => false, 'message' => 'Database error: ' . mysqli_error($conn)));
    }
    exit;
}

if ($action === 'addLibrary' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($requester_grade < 6) {
        echo json_encode(array('success' => false, 'message' => 'Unauthorized.'));
        exit;
    }

    $type  = isset($_POST['type'])  ? $_POST['type']       : '';
    $label = isset($_POST['label']) ? trim($_POST['label']) : '';

    if (!in_array($type, array('grade', 'struct')) || $label === '') {
        echo json_encode(array('success' => false, 'message' => 'Invalid input.'));
        exit;
    }

    $table         = ($type === 'grade') ? 'staff_grade' : 'staff_struct';
    $col           = ($type === 'grade') ? 'grade_name'  : 'struct_name';
    $label_escaped = mysqli_real_escape_string($conn, $label);

    $max_result = mysqli_query($conn, "SELECT COALESCE(MAX(id), -1) + 1 AS next_id FROM `$table`");
    if (!$max_result) {
        echo json_encode(array('success' => false, 'message' => 'Database error: ' . mysqli_error($conn)));
        exit;
    }
    $next_id = (int)mysqli_fetch_assoc($max_result)['next_id'];

    $insert = "INSERT INTO `$table` (id, `$col`) VALUES ($next_id, '$label_escaped')";

    if (mysqli_query($conn, $insert)) {
        echo json_encode(array('success' => true, 'message' => 'Entry added successfully.', 'id' => $next_id));
    } else {
        echo json_encode(array('success' => false, 'message' => 'Database error: ' . mysqli_error($conn)));
    }
    exit;
}

echo json_encode(array('error' => 'Unknown action'));
