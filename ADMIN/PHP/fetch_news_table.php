<?php
require_once 'db_connect.php';

$sql_news = "SELECT * FROM hotel_news ORDER BY news_date DESC";
$result_news = $conn->query($sql_news);

$html = '';

if ($result_news && $result_news->num_rows > 0) {
    while ($news = $result_news->fetch_assoc()) {
        $newsImg = !empty($news['image_path']) ? "../../room_includes/uploads/news/" . $news['image_path'] : "../../IMG/default_news.jpg";
        $cleanDesc = strip_tags($news['description']);
        $descShort = (strlen($cleanDesc) > 50) ? substr($cleanDesc, 0, 50) . '...' : $cleanDesc;

        $isActive = isset($news['is_active']) ? $news['is_active'] : 1;
        $rowStyle = ($isActive == 0) ? 'display:none; background-color: #f3f4f6; opacity: 0.8;' : '';
        $rowClass = ($isActive == 0) ? 'archived-news-row' : '';
        
        $safeTitle = addslashes($news['title']);
        $safeDesc = base64_encode($news['description']);

        $html .= '<tr id="news-row-' . $news['id'] . '" class="' . $rowClass . '" style="' . $rowStyle . '">';
        $html .= '<td><div style="width: 80px; height: 60px; background:#eee; border-radius:6px; overflow:hidden; border:1px solid #ddd;">';
        $html .= '<img src="' . htmlspecialchars($newsImg) . '" style="width:100%; height:100%; object-fit:cover;" onerror="this.style.display=\'none\'">';
        $html .= '</div></td>';
        $html .= '<td style="font-weight: 600; color: #333;">' . htmlspecialchars($news['title']);
        if ($isActive == 0) {
            $html .= '<span style="font-size:0.7rem; background:#999; color:white; padding:2px 5px; border-radius:4px; margin-left:5px;">ARCHIVED</span>';
        }
        $html .= '</td>';
        $html .= '<td style="font-size: 0.9rem; color: #555;">' . date('M d, Y', strtotime($news['news_date'])) . '</td>';
        $html .= '<td style="font-size: 0.85rem; color: #666;">' . $descShort . '</td>';
        $html .= '<td style="text-align: right;">';
        $html .= '<div style="display: flex; justify-content: flex-end; gap: 5px;">';
        $html .= '<button class="btn-secondary" style="padding:6px 12px;" onclick="openEditNewsModal(\'' . $news['id'] . '\', \'' . addslashes($news['title']) . '\', \'' . $news['news_date'] . '\', \'' . base64_encode($news['description']) . '\', \'' . $news['image_path'] . '\')">';
        $html .= '<i class="fas fa-edit"></i> Edit</button>';

        if ($isActive == 1) {
            $html .= '<button class="btn-secondary" style="padding:6px 12px; color:#555; border-color: #FECACA; background: #FEF2F2;" onclick="deleteNews(\'' . $news['id'] . '\')">';
            $html .= '<i class="fas fa-trash"></i></button>';
        } else {
            $html .= '<button class="btn-secondary" style="padding:6px 12px; color:#10B981; border-color: #A7F3D0; background: #ECFDF5;" onclick="restoreNews(\'' . $news['id'] . '\')">';
            $html .= '<i class="fas fa-trash-restore"></i> Restore</button>';

            $html .= '<button class="btn-secondary" style="padding:6px 12px; color:white; border-color: #B91C1C; background: #DC2626;" onclick="permanentDeleteNews(\'' . $news['id'] . '\')">';
            $html .= '<i class="fas fa-times"></i></button>';
        }
        $html .= '</div></td></tr>';
    }
} else {
    $html .= '<tr><td colspan="5" style="text-align:center; padding:60px 20px; color:#94a3b8;">
               <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; gap:12px;">
                   <div style="width:64px; height:64px; background:#f1f5f9; border-radius:50%; display:flex; align-items:center; justify-content:center;">
                       <i class="fas fa-newspaper" style="font-size:1.8rem; color:#cbd5e1;"></i>
                   </div>
                   <div style="font-weight:600; font-size:1.1rem; color:#64748b;">No News Found</div>
               </div>
            </td></tr>';
}

header('Content-Type: application/json');
echo json_encode(['status' => 'success', 'html' => $html]);
