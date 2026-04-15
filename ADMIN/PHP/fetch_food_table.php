<?php
// ADMIN/PHP/fetch_food_table.php
require 'db_connect.php';

// 1. GET PARAMETERS
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';

// 2. BUILD WHERE CLAUSE
$where = " WHERE o.status != 'Pending' 
           AND (
               o.status = 'Preparing' 
               OR DATE(o.order_date) = CURDATE()
           ) ";

if ($search !== "") {
    $where .= " AND (u.name LIKE '%$search%' OR o.room_number LIKE '%$search%' OR o.id LIKE '%$search%') ";
}

// 3. FETCH TOTAL COUNT
$countSql = "SELECT COUNT(*) as total FROM orders o LEFT JOIN users u ON o.user_id = u.id $where";
$countRes = $conn->query($countSql);
$totalCount = $countRes ? $countRes->fetch_assoc()['total'] : 0;

// 4. FETCH DATA
$sql_orders = "SELECT o.*, u.name as guest_name 
               FROM orders o 
               LEFT JOIN users u ON o.user_id = u.id 
               $where
               ORDER BY 
               CASE 
                   WHEN o.status = 'Preparing' THEN 1 
                   ELSE 2 
               END, 
               o.order_date DESC
               LIMIT $limit OFFSET $offset";

$result_orders = $conn->query($sql_orders);

$html = "";

if ($result_orders && $result_orders->num_rows > 0) {
    while ($order = $result_orders->fetch_assoc()) {
        
        // 1. Decode Items
        $items = json_decode($order['items'], true);
        $itemsList = "";
        if (is_array($items)) {
            foreach ($items as $name => $qty) {
                $itemsList .= "<div style='font-size:0.85rem; color:#555;'><b>{$qty}x</b> " . htmlspecialchars($name) . "</div>";
            }
        }

        // 2. Status Badge Logic
        $status = $order['status'];
        $badgeClass = 'badge-pending';
        if ($status === 'Preparing') $badgeClass = 'arrival-today'; // Blue
        if ($status === 'Delivered') $badgeClass = 'badge-confirmed'; // Green
        if ($status === 'Cancelled') $badgeClass = 'badge-cancelled'; // Red

        // 3. Payment Icon
        $payMethod = $order['payment_method'];
        $payIcon = '<i class="fas fa-money-bill-wave" style="color:#10B981;"></i>';
        if ($payMethod === 'GCash') $payIcon = '<i class="fas fa-mobile-alt" style="color:#3B82F6;"></i>';
        if ($payMethod === 'Charge to Room') $payIcon = '<i class="fas fa-door-open" style="color:#F59E0B;"></i>';

        // 4. Render Row
        $html .= "<tr id='order-row-{$order['id']}'>";
        $html .= "<td style='font-weight:700; color:#888;'>#{$order['id']}</td>";
        
        $html .= "<td>
                <div style='font-weight:700; color:#333; font-size:0.95rem;'>" . htmlspecialchars($order['room_number']) . "</div>
                <div style='font-size:0.8rem; color:#666;'>" . htmlspecialchars($order['guest_name']) . "</div>
              </td>";

        $html .= "<td>
                {$itemsList}
              </td>";

        $html .= "<td>
                " . (!empty($order['notes']) ? "<div style='font-size:0.85rem; color:#d97706; font-style:italic;'><i class='fas fa-sticky-note'></i> " . htmlspecialchars($order['notes']) . "</div>" : "<span style='font-size:0.8rem; color:#aaa; font-style:italic;'>no special instructions</span>") . "
              </td>";

        $html .= "<td style='font-weight:700; color:#333;'>₱" . number_format($order['total_price'], 2) . "</td>";

        $html .= "<td>
                <div style='display:flex; align-items:center; gap:6px; font-size:0.85rem; color:#555;'>
                    {$payIcon} {$payMethod}
                </div>
              </td>";

        $html .= "<td><span class='badge {$badgeClass}'>{$status}</span></td>";

        $html .= "<td style='font-size:0.8rem; color:#888;'>
                <div>" . date('M d', strtotime($order['order_date'])) . "</div>
                <div>" . date('h:i A', strtotime($order['order_date'])) . "</div>
              </td>";

        // Action Buttons
        $html .= "<td style='text-align: right;'>";
        if ($status === 'Preparing') {
            $html .= "<button class='btn-secondary' style='background:#DCFCE7; color:#166534; border:1px solid #BBF7D0;' onclick='updateOrderStatus({$order['id']}, \"deliver\")'><i class='fas fa-check'></i> Serve</button>";
        } else {
            $html .= "<span style='font-size:0.8rem; color:#aaa;'>Completed</span>";
        }
        $html .= "</td>";
        $html .= "</tr>";
    }
} else {
    $html = '<tr><td colspan="9" style="text-align:center; padding:60px 20px; color:#94a3b8;">
                <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; gap:12px;">
                    <div style="width:64px; height:64px; background:#f1f5f9; border-radius:50%; display:flex; align-items:center; justify-content:center;">
                        <i class="fas fa-utensils" style="font-size:1.8rem; color:#cbd5e1;"></i>
                    </div>
                    <div style="font-weight:600; font-size:1.1rem; color:#64748b;">No Active Orders</div>
                    <p style="margin:0; font-size:0.9rem; max-width:250px; line-height:1.5;">There are no food orders being prepared or delivered for today yet.</p>
                </div>
            </td></tr>';
}

header('Content-Type: application/json');
echo json_encode([
    'status' => 'success',
    'html' => $html,
    'total' => $totalCount,
    'limit' => $limit,
    'offset' => $offset
]);
exit;
?>