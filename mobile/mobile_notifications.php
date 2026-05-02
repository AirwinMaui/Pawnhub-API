<?php
declare(strict_types=1);

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    echo json_encode([
        "success" => true,
        "message" => "Notifications API is reachable"
    ]);
    exit;
}

/*
  Your db.php should create a PDO connection like this:

  $pdo = new PDO(
      "mysql:host=YOUR_HOST;dbname=YOUR_DATABASE;charset=utf8mb4",
      "YOUR_USERNAME",
      "YOUR_PASSWORD",
      [
          PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
          PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      ]
  );
*/
require_once __DIR__ . "/../db.php";

function jsonResponse(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function getInput(): array
{
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        jsonResponse([
            "success" => false,
            "message" => "Invalid JSON body.",
        ], 400);
    }

    return $data;
}

function addNotification(
    PDO $pdo,
    int $tenantId,
    int $customerId,
    string $type,
    string $title,
    string $message,
    ?string $sourceType = null,
    ?int $sourceId = null,
    ?string $actionScreen = null,
    ?array $actionParams = null
): void {
    $stmt = $pdo->prepare("
        INSERT INTO customer_notifications (
            tenant_id,
            customer_id,
            type,
            title,
            message,
            source_type,
            source_id,
            action_screen,
            action_params
        )
        VALUES (
            :tenant_id,
            :customer_id,
            :type,
            :title,
            :message,
            :source_type,
            :source_id,
            :action_screen,
            :action_params
        )
        ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            message = VALUES(message),
            action_screen = VALUES(action_screen),
            action_params = VALUES(action_params),
            updated_at = NOW()
    ");

    $stmt->execute([
        ":tenant_id" => $tenantId,
        ":customer_id" => $customerId,
        ":type" => $type,
        ":title" => $title,
        ":message" => $message,
        ":source_type" => $sourceType,
        ":source_id" => $sourceId,
        ":action_screen" => $actionScreen,
        ":action_params" => $actionParams ? json_encode($actionParams) : null,
    ]);
}

function detectNotifications(PDO $pdo, int $tenantId, int $customerId): void
{
    /*
      Change these table names if your real tables use different names.

      This example checks:
      1. Pawn requests
      2. Loan due soon
      3. Overdue loans
      4. Redeemed loans
    */

    // Pawn request notifications
    $requestStmt = $pdo->prepare("
        SELECT
            id,
            request_no,
            item_category,
            item_description,
            status,
            created_at
        FROM pawn_requests
        WHERE tenant_id = :tenant_id
          AND customer_id = :customer_id
        ORDER BY created_at DESC
        LIMIT 30
    ");

    $requestStmt->execute([
        ":tenant_id" => $tenantId,
        ":customer_id" => $customerId,
    ]);

    foreach ($requestStmt->fetchAll() as $request) {
        $status = strtolower(trim((string) $request["status"]));
        $requestId = (int) $request["id"];
        $requestNo = (string) $request["request_no"];
        $category = $request["item_category"] ?: "Pawn request";

        if ($status === "pending") {
            addNotification(
                $pdo,
                $tenantId,
                $customerId,
                "request_pending",
                "Pawn request received",
                "Your request {$requestNo} for {$category} is waiting for appraisal.",
                "pawn_request",
                $requestId,
                "new-loan",
                ["requestId" => $requestId]
            );
        }

        if ($status === "approved") {
    addNotification(
        $pdo,
        $tenantId,
        $customerId,
        "request_offer_made",
        "Offer received",
        "An offer has been made for your request {$requestNo} for {$category}. You may accept or decline it.",
        "pawn_request",
        $requestId,
        "my-loans",
        ["requestId" => $requestId]
    );
}

if ($status === "accepted") {
    addNotification(
        $pdo,
        $tenantId,
        $customerId,
        "request_accepted",
        "Offer accepted",
        "You accepted the offer for request {$requestNo} for {$category}.",
        "pawn_request",
        $requestId,
        "my-loans",
        ["requestId" => $requestId]
    );
}}

        if (in_array($status, ["rejected", "declined"], true)) {
            addNotification(
                $pdo,
                $tenantId,
                $customerId,
                "request_rejected",
                "Pawn request declined",
                "Your request {$requestNo} for {$category} was declined.",
                "pawn_request",
                $requestId,
                "new-loan",
                ["requestId" => $requestId]
            );
        }
    }

    // Loan transaction notifications
    $loanStmt = $pdo->prepare("
        SELECT
            id,
            ticket_no,
            item_category,
            item_description,
            status,
            maturity_date,
            created_at
        FROM pawn_transactions
        WHERE tenant_id = :tenant_id
          AND customer_id = :customer_id
        ORDER BY created_at DESC
        LIMIT 50
    ");

    $loanStmt->execute([
        ":tenant_id" => $tenantId,
        ":customer_id" => $customerId,
    ]);

    $today = new DateTimeImmutable("today");

    foreach ($loanStmt->fetchAll() as $loan) {
        $loanId = (int) $loan["id"];
        $ticketNo = (string) $loan["ticket_no"];
        $category = $loan["item_category"] ?: "pawn item";
        $status = strtolower(trim((string) $loan["status"]));
        $maturityDateRaw = $loan["maturity_date"];

        if ($maturityDateRaw && in_array($status, ["active", "ongoing"], true)) {
    $maturityDate = new DateTimeImmutable($maturityDateRaw);
    $daysUntilDue = (int) $today->diff($maturityDate)->format("%r%a");
    $formattedDueDate = $maturityDate->format("M d, Y");

    if ($daysUntilDue > 0 && $daysUntilDue <= 7) {
        addNotification(
            $pdo,
            $tenantId,
            $customerId,
            "payment_due_soon",
            "Payment due soon",
            "Payment for ticket #{$ticketNo} for {$category} is due on {$formattedDueDate}. Please pay or renew before the deadline.",
            "loan",
            $loanId,
            "my-loans",
            ["transactionId" => $loanId]
        );
    }

    if ($daysUntilDue === 0) {
        addNotification(
            $pdo,
            $tenantId,
            $customerId,
            "payment_due_today",
            "Payment due today",
            "Payment for ticket #{$ticketNo} for {$category} is due today. Please pay or renew now.",
            "loan",
            $loanId,
            "my-loans",
            ["transactionId" => $loanId]
        );
    }

    if ($daysUntilDue < 0) {
        addNotification(
            $pdo,
            $tenantId,
            $customerId,
            "payment_overdue",
            "Payment overdue",
            "Payment for ticket #{$ticketNo} for {$category} is overdue. Please settle or renew it as soon as possible.",
            "loan",
            $loanId,
            "my-loans",
            ["transactionId" => $loanId]
        );
    }
}

        if ($status === "redeemed") {
            addNotification(
                $pdo,
                $tenantId,
                $customerId,
                "loan_redeemed",
                "Item redeemed",
                "Ticket #{$ticketNo} for {$category} has been redeemed.",
                "loan",
                $loanId,
                "my-loans",
                ["transactionId" => $loanId]
            );
        }
    }


try {
    $input = getInput();

    $customerId = (int) ($input["customerId"] ?? $input["customer_id"] ?? 0);
    $tenantId = (int) ($input["tenantId"] ?? $input["tenant_id"] ?? 0);
    $action = (string) ($input["action"] ?? "list");

    if ($customerId <= 0 || $tenantId <= 0) {
        jsonResponse([
            "success" => false,
            "message" => "Missing customerId or tenantId.",
        ], 400);
    }

    if ($action === "mark_all_read") {
        $stmt = $pdo->prepare("
            UPDATE customer_notifications
            SET is_read = 1,
                read_at = NOW()
            WHERE tenant_id = :tenant_id
              AND customer_id = :customer_id
              AND is_read = 0
        ");

        $stmt->execute([
            ":tenant_id" => $tenantId,
            ":customer_id" => $customerId,
        ]);

        jsonResponse([
            "success" => true,
            "message" => "Notifications marked as read.",
        ]);
    }

    if ($action === "mark_read") {
        $notificationId = (int) ($input["notificationId"] ?? 0);

        if ($notificationId <= 0) {
            jsonResponse([
                "success" => false,
                "message" => "Missing notificationId.",
            ], 400);
        }

        $stmt = $pdo->prepare("
            UPDATE customer_notifications
            SET is_read = 1,
                read_at = NOW()
            WHERE id = :id
              AND tenant_id = :tenant_id
              AND customer_id = :customer_id
        ");

        $stmt->execute([
            ":id" => $notificationId,
            ":tenant_id" => $tenantId,
            ":customer_id" => $customerId,
        ]);

        jsonResponse([
            "success" => true,
            "message" => "Notification marked as read.",
        ]);
    }

    detectNotifications($pdo, $tenantId, $customerId);

    $notificationStmt = $pdo->prepare("
        SELECT
            id,
            type,
            title,
            message,
            source_type,
            source_id,
            action_screen,
            action_params,
            is_read,
            created_at
        FROM customer_notifications
        WHERE tenant_id = :tenant_id
          AND customer_id = :customer_id
        ORDER BY is_read ASC, created_at DESC
        LIMIT 50
    ");

    $notificationStmt->execute([
        ":tenant_id" => $tenantId,
        ":customer_id" => $customerId,
    ]);

    $notifications = $notificationStmt->fetchAll();

    foreach ($notifications as &$notification) {
        $notification["id"] = (int) $notification["id"];
        $notification["source_id"] = $notification["source_id"] !== null
            ? (int) $notification["source_id"]
            : null;
        $notification["is_read"] = (bool) $notification["is_read"];
        $notification["action_params"] = $notification["action_params"]
            ? json_decode($notification["action_params"], true)
            : null;
    }

    $countStmt = $pdo->prepare("
        SELECT COUNT(*) AS unread_count
        FROM customer_notifications
        WHERE tenant_id = :tenant_id
          AND customer_id = :customer_id
          AND is_read = 0
    ");

    $countStmt->execute([
        ":tenant_id" => $tenantId,
        ":customer_id" => $customerId,
    ]);

    $unreadCount = (int) $countStmt->fetchColumn();

    jsonResponse([
        "success" => true,
        "unread_count" => $unreadCount,
        "notifications" => $notifications,
    ]);
} catch (Throwable $error) {
    jsonResponse([
        "success" => false,
        "message" => $error->getMessage(),
    ], 500);
}