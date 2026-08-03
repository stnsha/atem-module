<?php
date_default_timezone_set('Asia/Kuala_Lumpur');

// This is a standalone, session-gated script that streams a binary PDF response.
// It cannot include header.php/lock_adv.php: those emit <!DOCTYPE html>/<head>
// HTML output before their own session check runs, which would corrupt the PDF
// binary ("headers already sent"). The effective auth chain (session check +
// $atem_permission/$_is_superadmin resolution, mirroring header.php) is
// replicated here without any HTML output.

if (session_id() == '') {
    session_start();
}
if (!isset($_SESSION['myusername']) || $_SESSION['type'] !== 'odb') {
    header('HTTP/1.1 401 Unauthorized');
    echo 'You must be logged in to view this file.';
    exit;
}

$connect = 1;
include(dirname(__FILE__) . '/../common/index_adv.php');
if (!isset($conn)) {
    header('HTTP/1.1 500 Internal Server Error');
    echo 'Database connection error.';
    exit;
}

$staff_id = null;
$department = null;
$grade = 0;
$atem_flag = 0;
$q = "SELECT id, department, grade, atem FROM staff WHERE username = '"
    . mysqli_real_escape_string($conn, $_SESSION['myusername']) . "' AND recycle != 1";
$r = mysqli_query($conn, $q);
if ($r && ($row = mysqli_fetch_assoc($r))) {
    $staff_id   = (int) $row['id'];
    $department = $row['department'];
    $grade      = (int) $row['grade'];
    $atem_flag  = (int) $row['atem'];
}
if (!$staff_id) {
    header('HTTP/1.1 401 Unauthorized');
    echo 'Staff record not found.';
    exit;
}

// Mirrors header.php's exact resolution.
$atem_permission = $grade;
if (isset($_SESSION['atem_dev_role_override'])) {
    $atem_permission = (int) $_SESSION['atem_dev_role_override'];
}
$_is_superadmin = (!isset($_SESSION['atem_dev_role_override']) && $atem_flag === 1);

define('API_JWT_INCLUDED', true);
include(dirname(__FILE__) . '/api.php');

$atem_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($atem_id <= 0) {
    header('HTTP/1.1 400 Bad Request');
    echo 'Missing ATEM ID.';
    exit;
}

$get = getAtem($atem_id, $staff_id);
$record = (!empty($get['success']) && isset($get['data'])) ? $get['data'] : null;
if (!$record) {
    header('HTTP/1.1 404 Not Found');
    echo 'ATEM not found.';
    exit;
}

// Re-derive $can_view exactly as edit.php does: SuperAdmin, grade >= 2, issuer,
// or ARCI member. No extra restriction for PDF download beyond normal card-view access.
$is_issuer = ($staff_id && (int) $staff_id === (int) (isset($record['issuer_staff_id']) ? $record['issuer_staff_id'] : 0));
$is_arci_member = false;
if (isset($record['arci']) && is_array($record['arci'])) {
    foreach ($record['arci'] as $m) {
        if ((int) (isset($m['staff_id']) ? $m['staff_id'] : 0) === (int) $staff_id) { $is_arci_member = true; break; }
    }
}
$can_view = $_is_superadmin || (int) $atem_permission >= 2 || $is_issuer || $is_arci_member;
if (!$can_view) {
    header('HTTP/1.1 403 Forbidden');
    echo 'You do not have permission to view this ATEM card.';
    exit;
}

$staff_names = array();
$sres = mysqli_query($conn, "SELECT id, nama_staff FROM staff WHERE recycle != 1");
if ($sres) {
    while ($sr = mysqli_fetch_assoc($sres)) {
        $staff_names[(int) $sr['id']] = $sr['nama_staff'];
    }
}
$dept_names = array();
$dres = mysqli_query($conn, "SELECT id, depart_name FROM staff_department");
if ($dres) {
    while ($dr = mysqli_fetch_assoc($dres)) {
        $dept_names[(int) $dr['id']] = $dr['depart_name'];
    }
}

$issuer_id   = (int) (isset($record['issuer_staff_id']) ? $record['issuer_staff_id'] : 0);
$dept_id     = (int) (isset($record['staff_dept_id']) ? $record['staff_dept_id'] : 0);
$issuer_name = isset($staff_names[$issuer_id]) ? $staff_names[$issuer_id] : ('Staff #' . $issuer_id);
$dept_name   = isset($dept_names[$dept_id]) ? $dept_names[$dept_id] : '-';

// ARCI members with their individual incentive share. The atems table only
// stores a pooled total per role (a_incentive_amount / r_incentive_amount);
// each incentivised member's share is that pool split evenly across the
// incentivised members of the same role — mirrors CalculateBonusEligibility.
$arci_role_labels = array('A' => 'Accountable', 'R' => 'Responsible', 'C' => 'Consulted', 'I' => 'Informed');
$arci_members = (isset($record['arci']) && is_array($record['arci'])) ? $record['arci'] : array();
$arci_incentivised_counts = array('A' => 0, 'R' => 0);
foreach ($arci_members as $m) {
    $m_role = isset($m['role']) ? $m['role'] : '';
    if (!empty($m['is_incentivised']) && isset($arci_incentivised_counts[$m_role])) {
        $arci_incentivised_counts[$m_role]++;
    }
}
$arci_role_totals = array(
    'A' => (float) (isset($record['a_incentive_amount']) ? $record['a_incentive_amount'] : 0),
    'R' => (float) (isset($record['r_incentive_amount']) ? $record['r_incentive_amount'] : 0),
);

$updated_by_id   = (int) (isset($record['payout_updated_by']) ? $record['payout_updated_by'] : 0);
$closed_by_id    = (int) (isset($record['payout_closed_by']) ? $record['payout_closed_by'] : 0);
$updated_by_name = $updated_by_id ? (isset($staff_names[$updated_by_id]) ? $staff_names[$updated_by_id] : ('Staff #' . $updated_by_id)) : '-';
$closed_by_name  = $closed_by_id ? (isset($staff_names[$closed_by_id]) ? $staff_names[$closed_by_id] : ('Staff #' . $closed_by_id)) : '-';

function pp_date($v)
{
    if (!$v) { return '-'; }
    $ts = strtotime($v);
    return $ts ? date('Y-m-d', $ts) : '-';
}
function pp_datetime($v)
{
    if (!$v) { return '-'; }
    $ts = strtotime($v);
    return $ts ? date('Y-m-d H:i', $ts) : '-';
}
function pp_money($v)
{
    return 'RM ' . number_format((float) $v, 2);
}

require(dirname(__FILE__) . '/../common/fpdf/tfpdf.php');

class PayoutPDF extends tFPDF
{
    function Header()
    {
        $this->SetFont('helvetica', 'B', 14);
        $this->Cell(0, 10, 'ATEM Payout Details', 0, 1, 'C');
        $this->Ln(2);
    }

    function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 10, 'Generated ' . date('Y-m-d H:i'), 0, 0, 'L');
        $this->Cell(0, 10, 'Page ' . $this->PageNo(), 0, 0, 'R');
    }

    function SectionTitle($title)
    {
        $this->Ln(2);
        $this->SetFont('helvetica', 'B', 11);
        $this->SetFillColor(240, 240, 240);
        $this->Cell(0, 8, $title, 0, 1, 'L', true);
        $this->Ln(1);
    }

    function LabelValue($label, $value)
    {
        $this->SetFont('helvetica', 'B', 10);
        $this->Cell(55, 7, $label, 0);
        $this->SetFont('helvetica', '', 10);
        $this->MultiCell(0, 7, (string) $value);
    }

    function ArciTableHeader()
    {
        $this->SetFont('helvetica', 'B', 9);
        $this->SetFillColor(230, 230, 230);
        $this->Cell(55, 7, 'Name', 1, 0, 'L', true);
        $this->Cell(40, 7, 'Department', 1, 0, 'L', true);
        $this->Cell(30, 7, 'Role', 1, 0, 'L', true);
        $this->Cell(45, 7, 'Incentive Amount', 1, 1, 'R', true);
    }

    function ArciTableRow($name, $dept, $role, $amountText)
    {
        $this->SetFont('helvetica', '', 9);
        $this->Cell(55, 7, $name, 1);
        $this->Cell(40, 7, $dept, 1);
        $this->Cell(30, 7, $role, 1);
        $this->Cell(45, 7, $amountText, 1, 1, 'R');
    }
}

$pdf = new PayoutPDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->AddPage();

$pdf->SectionTitle('ATEM Overview');
$pdf->LabelValue('Title', isset($record['title']) ? $record['title'] : '-');
$pdf->LabelValue('Issuer', $issuer_name);
$pdf->LabelValue('Department', $dept_name);
$pdf->LabelValue('Start Date', pp_date(isset($record['start_date']) ? $record['start_date'] : null));
$pdf->LabelValue('End Date', pp_date(isset($record['end_date']) ? $record['end_date'] : null));
$pdf->LabelValue('Closure Date', pp_date(isset($record['closure_date']) ? $record['closure_date'] : null));
$pdf->LabelValue('Main Status', isset($record['status']['value']) ? $record['status']['value'] : '-');

$pdf->SectionTitle('Incentive Amounts');
$pdf->LabelValue('A - Accountable', pp_money(isset($record['a_incentive_amount']) ? $record['a_incentive_amount'] : 0));
$pdf->LabelValue('R - Responsible', pp_money(isset($record['r_incentive_amount']) ? $record['r_incentive_amount'] : 0));
$pdf->LabelValue('Total Incentive', pp_money(isset($record['total_incentive_amount']) ? $record['total_incentive_amount'] : 0));
$pdf->LabelValue('Final Incentive', pp_money(isset($record['final_incentive_amount']) ? $record['final_incentive_amount'] : 0));

if (!empty($arci_members)) {
    $pdf->SectionTitle('ARCI Members');
    $pdf->ArciTableHeader();
    foreach ($arci_members as $m) {
        $m_sid  = isset($m['staff_id']) ? (int) $m['staff_id'] : 0;
        $m_did  = isset($m['staff_dept_id']) ? (int) $m['staff_dept_id'] : 0;
        $m_role = isset($m['role']) ? $m['role'] : '';
        $m_name = isset($staff_names[$m_sid]) ? $staff_names[$m_sid] : ('Staff #' . $m_sid);
        $m_dept = isset($dept_names[$m_did]) ? $dept_names[$m_did] : '-';
        $m_role_label = isset($arci_role_labels[$m_role]) ? $arci_role_labels[$m_role] : $m_role;

        $amountText = '-';
        if (!empty($m['is_incentivised']) && isset($arci_incentivised_counts[$m_role]) && $arci_incentivised_counts[$m_role] > 0) {
            $per_head = $arci_role_totals[$m_role] / $arci_incentivised_counts[$m_role];
            $amountText = pp_money($per_head);
        }

        $pdf->ArciTableRow($m_name, $m_dept, $m_role_label, $amountText);
    }
}

$pdf->SectionTitle('Payout Details');
$pdf->LabelValue('Payout Status', isset($record['payout_status']) && $record['payout_status'] !== '' ? $record['payout_status'] : 'Not set');
$pdf->LabelValue('Remark', isset($record['payout_remark']) && $record['payout_remark'] !== '' ? $record['payout_remark'] : '-');
$pdf->LabelValue('Last Updated By', $updated_by_name);
$pdf->LabelValue('Last Updated On', pp_datetime(isset($record['payout_updated_at']) ? $record['payout_updated_at'] : null));
$pdf->LabelValue('Closed By', $closed_by_name);
$pdf->LabelValue('Closed On', pp_datetime(isset($record['payout_closed_at']) ? $record['payout_closed_at'] : null));

$pdf->Output('D', 'ATEM-' . $atem_id . '-Payout-Details.pdf');
exit;
