<?php
require_once 'db_connect.php';

$sql_events = "SELECT * FROM hotel_events ORDER BY event_date DESC";
$result_events = $conn->query($sql_events);

$html = '';

if ($result_events && $result_events->num_rows > 0) {
    while ($evt = $result_events->fetch_assoc()) {
        $evtImg = !empty($evt['image_path']) ? "../../room_includes/uploads/events/" . $evt['image_path'] : "../../IMG/default_event.jpg";
        $cleanDesc = strip_tags($evt['description']);
        $descShort = (strlen($cleanDesc) > 50) ? substr($cleanDesc, 0, 50) . '...' : $cleanDesc;

        $isActive = isset($evt['is_active']) ? $evt['is_active'] : 1;
        $rowStyle = ($isActive == 0) ? 'display:none; background-color: #f3f4f6; opacity: 0.8;' : '';
        $rowClass = ($isActive == 0) ? 'archived-event-row' : '';

        $safeTitle = addslashes($evt['title']);
        $safeTime = addslashes($evt['time_start']);
        $safeDesc = base64_encode($evt['description']);

        $html .= '<tr id="event-row-' . $evt['id'] . '" class="' . $rowClass . '" style="' . $rowStyle . '">';
        $html .= '<td><div style="width: 80px; height: 60px; background:#eee; border-radius:6px; overflow:hidden;">';
        $html .= '<img src="' . htmlspecialchars($evtImg) . '" style="width:100%; height:100%; object-fit:cover;" onerror="this.style.display=\'none\'">';
        $html .= '</div></td>';
        $html .= '<td style="font-weight: 600; color: #333;">' . htmlspecialchars($evt['title']);
        if ($isActive == 0) {
            $html .= '<span style="font-size:0.7rem; background:#999; color:white; padding:2px 5px; border-radius:4px; margin-left:5px;">ARCHIVED</span>';
        }
        $html .= '</td>';
        $html .= '<td style="font-size: 0.9rem; color: #555;">' . date('M d, Y', strtotime($evt['event_date'])) . '<br><small style="color:#888">' . htmlspecialchars($evt['time_start']) . '</small></td>';
        $html .= '<td style="font-size: 0.85rem; color: #666;">' . $descShort . '</td>';
        $html .= '<td>';
        $html .= '<button class="btn-secondary" style="padding:6px 12px; margin-right: 5px;" onclick="openEditEventModal(\'' . $evt['id'] . '\', \'' . $safeTitle . '\', \'' . $evt['event_date'] . '\', \'' . $safeTime . '\', \'' . $safeDesc . '\', \'' . $evt['image_path'] . '\')">';
        $html .= '<i class="fas fa-edit"></i> Edit</button>';

        if ($isActive == 1) {
            $html .= '<button class="btn-secondary" style="padding:6px 12px; color:#555; border-color: #FECACA; background: #FEF2F2;" onclick="deleteEvent(\'' . $evt['id'] . '\')">';
            $html .= '<i class="fas fa-trash"></i></button>';
        } else {
            $html .= '<button class="btn-secondary" style="padding:6px 12px; color:#10B981; border-color: #A7F3D0; background: #ECFDF5;" onclick="restoreEvent(\'' . $evt['id'] . '\')">';
            $html .= '<i class="fas fa-trash-restore"></i> Restore</button>';
            $html .= '<button class="btn-secondary" style="padding:6px 12px; color:white; border-color: #B91C1C; background: #DC2626; margin-left: 5px;" onclick="permanentDeleteEvent(\'' . $evt['id'] . '\')">';
            $html .= '<i class="fas fa-times"></i> Delete Forever</button>';
        }
        $html .= '</td></tr>';
    }
} else {
    $html .= '<tr><td colspan="5" style="text-align:center; padding:60px 20px; color:#94a3b8;">
               <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; gap:12px;">
                   <div style="width:64px; height:64px; background:#f1f5f9; border-radius:50%; display:flex; align-items:center; justify-content:center;">
                       <i class="fas fa-calendar-times" style="font-size:1.8rem; color:#cbd5e1;"></i>
                   </div>
                   <div style="font-weight:600; font-size:1.1rem; color:#64748b;">No Events Scheduled</div>
               </div>
            </td></tr>';
}

header('Content-Type: application/json');
echo json_encode(['status' => 'success', 'html' => $html]);
