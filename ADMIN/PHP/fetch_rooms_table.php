<?php
// ADMIN/PHP/fetch_rooms_table.php
require 'db_connect.php';

// 1. Fetch all rooms
$sql = "SELECT * FROM rooms ORDER BY is_active DESC, name ASC";
$res = $conn->query($sql);

$html = "";
$placeholder = "data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMDAiIGhlaWdodD0iMTAwIiB2aWV3Qm94PSIwIDAgMTAwIDEwMCI+PHJlY3QgZmlsbD0iI2RkZCIgd2lkdGg9IjEwMCIgaGVpZ2h0PSIxMDAiLz48dGV4dCB4PSI1MCIgeT0iNTAiIGZvbnQtZmFtaWx5PSJhcmlhbCIgZm9udC1zaXplPSIxMiIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzU1NSI+Tm8gSW1hZ2U8L3RleHQ+PC9zdmc+";

if ($res && $res->num_rows > 0) {
    while ($room = $res->fetch_assoc()) {
        $rawPath = $room['image_path'];
        if (strpos($rawPath, ',') !== false) {
            $pathParts = explode(',', $rawPath);
            $rawPath = trim($pathParts[0]);
        }
        $imgUrl = !empty($rawPath) ? "../../room_includes/uploads/images/" . $rawPath : $placeholder;

        $isActive = $room['is_active'];
        
        // Check if booked
        $checkSql = "SELECT COUNT(*) as total FROM booking_rooms br
             JOIN bookings b ON br.booking_id = b.id
             WHERE br.room_id = ? 
             AND b.status IN ('confirmed', 'pending') 
             AND b.arrival_status != 'checked_out'
             AND b.check_out > CURDATE()";
        $stmtCheck = $conn->prepare($checkSql);
        $stmtCheck->bind_param("i", $room['id']);
        $stmtCheck->execute();
        $isBooked = ($stmtCheck->get_result()->fetch_assoc()['total'] > 0);

        $rowStyle = ($isActive == 0) ? 'display:none; background-color: #f3f4f6; opacity: 0.8;' : '';
        $rowClass = ($isActive == 0) ? 'archived-room-row' : '';

        $html .= '<tr id="room-row-' . $room['id'] . '" class="' . $rowClass . '" style="vertical-align: middle; ' . $rowStyle . '">';
        $html .= '<td style="font-weight: 600; color: #888;">' . $room['id'] . '</td>';
        $html .= '<td>';
        $html .= '<div style="width: 120px; height: 80px; background:#eee; border-radius:6px; overflow:hidden; border:1px solid #ddd;">';
        $html .= '<img src="' . $imgUrl . '?t=' . time() . '" style="width:100%; height:100%; object-fit:cover;" onerror="this.src=\'' . $placeholder . '\'">';
        $html .= '</div>';
        $html .= '</td>';
        $html .= '<td>';
        $html .= '<div class="room-name" style="font-weight: 600; font-size: 1rem; color: #333;">';
        $html .= htmlspecialchars($room['name']);
        if ($isActive == 0) {
            $html .= ' <span style="font-size:0.7rem; background:#999; color:white; padding:2px 5px; border-radius:4px;">ARCHIVED</span>';
        }
        $html .= '</div>';
        $html .= '</td>';
        $html .= '<td>';
        $html .= '<span class="room-bed" style="background: #fff; padding: 4px 10px; border-radius: 4px; border:1px solid #eee; font-size: 0.85rem; font-weight: 500; color: #555;">' . htmlspecialchars($room['bed_type']) . '</span>';
        $html .= '</td>';
        $html .= '<td class="room-price" style="font-weight: 700; color: #333;">₱' . number_format($room['price'], 2) . '</td>';
        $html .= '<td>';
        
        $safeName = addslashes($room['name']);
        $safeType = addslashes($room['bed_type']);
        $safeSize = addslashes($room['size']);
        $safeDesc = htmlspecialchars(addslashes(str_replace(array("\r", "\n"), ' ', $room['description'])), ENT_QUOTES);
        $safePaths = addslashes($room['image_path']);
        $isBookedJS = $isBooked ? 'true' : 'false';

        $html .= '<button class="btn-secondary" style="padding:6px 12px; margin-right: 5px; ' . ($isBooked ? 'border-color:orange; color:#d97706;' : '') . '" ';
        $html .= 'onclick="openEditRoomModal(\'' . $room['id'] . '\', \'' . $safeName . '\', \'' . $room['price'] . '\', \'' . $safeType . '\', \'' . $room['capacity'] . '\', \'' . $safeSize . '\', \'' . $safeDesc . '\', \'' . $safePaths . '\', ' . $isBookedJS . ', \'' . $room['amenities'] . '\')">';
        if ($isBooked) {
            $html .= '<i class="fas fa-exclamation-circle"></i> Edit Price';
        } else {
            $html .= '<i class="fas fa-edit"></i> Edit';
        }
        $html .= '</button>';

        if ($isActive == 1) {
            if ($isBooked) {
                $html .= '<button class="btn-secondary" disabled style="padding:6px 12px; opacity: 0.4; cursor: not-allowed;"><i class="fas fa-trash"></i></button>';
            } else {
                $html .= '<button class="btn-secondary" style="padding:6px 12px; color:#555; border-color: #FECACA; background: #FEF2F2;" onclick="deleteRoom(\'' . $room['id'] . '\')"><i class="fas fa-trash"></i></button>';
            }
        } else {
            $html .= '<button class="btn-secondary" style="padding:6px 12px; color:#10B981; border-color: #A7F3D0; background: #ECFDF5;" onclick="restoreRoom(\'' . $room['id'] . '\')"><i class="fas fa-trash-restore"></i> Restore</button>';
            $html .= '<button class="btn-secondary" style="padding:6px 12px; color:white; border-color: #B91C1C; background: #DC2626; margin-left:5px;" onclick="permanentDeleteRoom(\'' . $room['id'] . '\')"><i class="fas fa-times"></i></button>';
        }

        $html .= '</td>';
        $html .= '</tr>';
    }
} else {
    $html .= '<tr><td colspan="6" class="text-center" style="padding:30px; color:#888;">No rooms found.</td></tr>';
}

echo json_encode(['status' => 'success', 'html' => $html]);
?>