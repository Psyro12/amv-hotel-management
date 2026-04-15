<?php
require_once 'db_connect.php';

$sql = "SELECT * FROM amenities ORDER BY title ASC";
$result = $conn->query($sql);

$html = '';

if ($result && $result->num_rows > 0) {
    while ($am = $result->fetch_assoc()) {
        $safeTitle = addslashes($am['title']);
        $safeIcon = addslashes($am['icon_class']);
        $safeDesc = addslashes($am['description']);

        $html .= '<tr style="vertical-align: middle;">';
        $html .= '<td style="font-weight: 600; color: #888;">' . $am['id'] . '</td>';
        $html .= '<td style="text-align:center;">
                    <div style="font-size: 1.5rem; color: #B88E2F;">
                        <i class="' . htmlspecialchars($am['icon_class']) . '"></i>
                    </div>
                  </td>';
        $html .= '<td style="font-weight: 600; color: #333;">' . htmlspecialchars($am['title']) . '</td>';
        $html .= '<td style="color: #666; font-size: 0.85rem;">' . htmlspecialchars($am['description']) . '</td>';
        $html .= '<td>
                    <button class="btn-secondary" style="padding:6px 12px; margin-right: 5px;" 
                            onclick="openEditAmenityModal(\'' . $am['id'] . '\', \'' . $safeTitle . '\', \'' . $safeIcon . '\', \'' . $safeDesc . '\')">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn-secondary" style="padding:6px 12px; color:#555; border-color: #FECACA; background: #FEF2F2;" 
                            onclick="deleteAmenity(\'' . $am['id'] . '\')">
                        <i class="fas fa-trash"></i>
                    </button>
                  </td>';
        $html .= '</tr>';
    }
} else {
    $html .= '<tr><td colspan="5" style="text-align:center; padding:60px 20px; color:#94a3b8;">
               <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; gap:12px;">
                   <div style="width:64px; height:64px; background:#f1f5f9; border-radius:50%; display:flex; align-items:center; justify-content:center;">
                       <i class="fas fa-concierge-bell" style="font-size:1.8rem; color:#cbd5e1;"></i>
                   </div>
                   <div style="font-weight:600; font-size:1.1rem; color:#64748b;">No Amenities Found</div>
               </div>
            </td></tr>';
}

header('Content-Type: application/json');
echo json_encode(['status' => 'success', 'html' => $html]);
