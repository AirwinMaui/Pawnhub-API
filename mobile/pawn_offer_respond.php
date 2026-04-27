<?php
/**
 * mobile/pawn_offer_respond.php
 * 
 * Customer accepts or declines a loan offer from staff.
 * 
 * Method: POST
 * Auth:   Bearer token (mobile_customer_tokens)
 * Body:   { "request_no": "REQ-20260427-4364", "action": "accept" | "decline" }
 * 
 * Responses:
 *   200 { "success": true,  "message": "...", "data": { ... } }
 *   4xx { "success": false, "message": "..." }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

require_once __DIR__ . '/../db.php';

// ── 1. Auth: Bearer token ─────────────────────────────────────────────────────
$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
$token = '';
if (preg_match('/Bearer\s+(.+)/i', $auth_header, $m)) {
    $token = trim($m[1]);
}

if (!$token) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Token required.']);
    exit;
}

// Validate token and get mobile customer
$stmt = $pdo->prepare("
    SELECT mct.*, mc.id AS mc_id, mc.tenant_id, mc.full_name, mc.username
    FROM mobile_customer_tokens mct
    JOIN mobile_customers mc ON mc.id = mct.customer_id
    WHERE mct.token = ? AND (mct.expires_at IS NULL OR mct.expires_at > NOW())
    LIMIT 1
");
$stmt->execute([$token]);
$auth = $stmt->fetch();

if (!$auth) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired token.']);
    exit;
}

$customer_id = (int)$auth['mc_id'];
$tenant_id   = (int)$auth['tenant_id'];

// ── 2. Parse request body ─────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);

$request_no = trim($body['request_no'] ?? '');
$action     = trim($body['action']     ?? ''); // 'accept' or 'decline'

if (!$request_no || !in_array($action, ['accept', 'decline'])) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'request_no and action (accept|decline) are required.']);
    exit;
}

// ── 3. Fetch the pawn request ─────────────────────────────────────────────────
$req_stmt = $pdo->prepare("
    SELECT * FROM pawn_requests
    WHERE request_no = ? AND tenant_id = ? AND customer_id = ?
    LIMIT 1
");
$req_stmt->execute([$request_no, $tenant_id, $customer_id]);
$pr = $req_stmt->fetch();

if (!$pr) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Pawn request not found.']);
    exit;
}

// Must be in 'approved' status (offer has been sent by staff)
if ($pr['status'] !== 'approved') {
    $friendly = match($pr['status']) {
        'pending'   => 'No offer has been sent yet. Please wait for staff to review your request.',
        'cancelled' => 'This request has already been finalized.',
        'rejected'  => 'This request has been rejected.',
        default     => 'This request cannot be responded to at this time.',
    };
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => $friendly]);
    exit;
}

// ── 4. Handle accept / decline ────────────────────────────────────────────────
if ($action === 'accept') {

    // Set a new status to signal staff that customer accepted
    // We add 'customer_accepted' — make sure your DB enum includes it
    // (see migration_pawn_requests.sql or run: 
    //  ALTER TABLE pawn_requests MODIFY status ENUM('pending','approved','customer_accepted','rejected','cancelled') DEFAULT 'pending';)
    $pdo->prepare("
        UPDATE pawn_requests
        SET status = 'customer_accepted', updated_at = NOW()
        WHERE id = ?
    ")->execute([$pr['id']]);

    // Write a pawn_update notification so staff sees it on their end
    try {
        $pdo->prepare("
            INSERT INTO pawn_updates (tenant_id, ticket_no, event_type, message, created_at)
            VALUES (?, ?, 'CUSTOMER_ACCEPTED', ?, NOW())
        ")->execute([
            $tenant_id,
            $pr['request_no'],
            "Customer {$auth['full_name']} has accepted the loan offer of ₱" .
                number_format((float)$pr['offer_amount'], 2) .
                ". Please finalize the pawn ticket at the branch."
        ]);
    } catch (Throwable $e) {}

    echo json_encode([
        'success' => true,
        'message' => 'You have accepted the loan offer. Please visit the branch to complete your pawn transaction.',
        'data'    => [
            'request_no'   => $pr['request_no'],
            'status'       => 'customer_accepted',
            'offer_amount' => (float)$pr['offer_amount'],
            'interest_rate'=> (float)$pr['interest_rate'],
            'claim_term'   => $pr['claim_term'],
            'appraisal'    => (float)$pr['appraisal_value'],
            'total_redeem' => round((float)$pr['offer_amount'] * (1 + (float)$pr['interest_rate']), 2),
        ]
    ]);

} else {
    // decline
    $pdo->prepare("
        UPDATE pawn_requests
        SET status = 'rejected', updated_at = NOW()
        WHERE id = ?
    ")->execute([$pr['id']]);

    try {
        $pdo->prepare("
            INSERT INTO pawn_updates (tenant_id, ticket_no, event_type, message, created_at)
            VALUES (?, ?, 'CUSTOMER_DECLINED', ?, NOW())
        ")->execute([
            $tenant_id,
            $pr['request_no'],
            "Customer {$auth['full_name']} has declined the loan offer for request {$pr['request_no']}."
        ]);
    } catch (Throwable $e) {}

    echo json_encode([
        'success' => true,
        'message' => 'You have declined the loan offer. Your request has been closed.',
        'data'    => [
            'request_no' => $pr['request_no'],
            'status'     => 'rejected',
        ]
    ]);
}
