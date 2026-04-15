<?php
// ADMIN/PHP/fetch_food_menu_table.php
require 'db_connect.php';

$sql_food = "SELECT * FROM food_menu ORDER BY is_active DESC, category DESC, item_name ASC";
$res_food = $conn->query($sql_food);

$html = "";

if ($res_food && $res_food->num_rows > 0) {
    while ($food = $res_food->fetch_assoc()) {
        // 1. Icon & Color Logic
        $cat = strtolower($food['category']);
        $iconClass = 'fa-concierge-bell';
        $iconColor = '#9CA3AF'; // Default Grey

        if (strpos($cat, 'beverage') !== false || strpos($cat, 'drink') !== false) {
            $iconClass = 'fa-glass-martini-alt';
            $iconColor = '#3B82F6';
        } elseif (strpos($cat, 'dessert') !== false) {
            $iconClass = 'fa-ice-cream';
            $iconColor = '#EC4899';
        } elseif (strpos($cat, 'snack') !== false) {
            $iconClass = 'fa-cookie-bite';
            $iconColor = '#F59E0B';
        } elseif (strpos($cat, 'soup') !== false) {
            $iconClass = 'fa-mug-hot';
            $iconColor = '#EA580C';
        } elseif (strpos($cat, 'breakfast') !== false) {
            $iconClass = 'fa-bacon';
            $iconColor = '#8B5CF6';
        } elseif (strpos($cat, 'main') !== false) {
            $iconClass = 'fa-utensils';
            $iconColor = '#10B981';
        }

        // 2. Image Logic
        $foodImg = !empty($food['image_path']) ? "../../room_includes/uploads/food/" . $food['image_path'] : "";

        // 3. Archive Logic
        $isActive = isset($food['is_active']) ? $food['is_active'] : 1;

        $rowClass = ($isActive == 0) ? 'archived-food-row' : '';
        $rowStyle = ($isActive == 0) ? 'display:none; background-color: #f3f4f6; opacity: 0.8;' : '';

        $safeName = addslashes($food['item_name']);
        
        $html .= '<tr id="food-menu-row-' . $food['id'] . '" class="' . $rowClass . '" style="' . $rowStyle . '">';
        $html .= '<td>';
        $html .= '<div style="width: 60px; height: 50px; background:#eee; border-radius:6px; overflow:hidden; border:1px solid #ddd; display:flex; align-items:center; justify-content:center;">';
        if ($foodImg) {
            $html .= '<img src="' . htmlspecialchars($foodImg) . '" style="width:100%; height:100%; object-fit:cover;" onerror="this.style.display=\'none\'; this.nextElementSibling.style.display=\'block\';">';
            $html .= '<i class="fas ' . $iconClass . '" style="color: ' . $iconColor . '; font-size: 1.1rem; display:none;"></i>';
        } else {
            $html .= '<i class="fas ' . $iconClass . '" style="color: ' . $iconColor . '; font-size: 1.1rem;"></i>';
        }
        $html .= '</div>';
        $html .= '</td>';
        $html .= '<td style="text-align:center;">';
        $html .= '<i class="fas ' . $iconClass . '" style="color: ' . $iconColor . ';"></i>';
        $html .= '</td>';
        $html .= '<td>';
        $html .= '<div style="font-weight: 700; color: #333; font-size: 1rem;">' . htmlspecialchars($food['item_name']) . '</div>';
        if ($isActive == 0) {
            $html .= '<span style="font-size:0.7rem; background:#999; color:white; padding:2px 5px; border-radius:4px;">ARCHIVED</span>';
        }
        $html .= '</td>';
        $html .= '<td>';
        $html .= '<span class="badge" style="background:#F3F4F6; color:#555; border:1px solid #ddd; text-transform:uppercase; letter-spacing:0.5px;">' . htmlspecialchars($food['category']) . '</span>';
        $html .= '</td>';
        $html .= '<td style="font-weight: 700; color: #B88E2F;">₱' . number_format($food['price'], 2) . '</td>';
        $html .= '<td style="text-align: right;">';
        $html .= '<div style="display: flex; justify-content: flex-end; gap: 5px;">';

        if ($isActive == 1) {
            $html .= '<button class="btn-secondary" style="padding:5px 10px;" onclick="openEditFoodModal(\'' . $food['id'] . '\', \'' . $safeName . '\', \'' . $food['category'] . '\', \'' . $food['price'] . '\', \'' . $food['image_path'] . '\')">';
            $html .= '<i class="fas fa-edit"></i> Edit</button>';
            $html .= '<button class="btn-secondary" style="padding:5px 10px; color:#DC2626; border-color: #FECACA; background: #FEF2F2;" onclick="deleteFood(\'' . $food['id'] . '\')">';
            $html .= '<i class="fas fa-trash"></i></button>';
        } else {
            $html .= '<button class="btn-secondary" style="padding:5px 10px; color:#10B981; border-color: #A7F3D0; background: #ECFDF5;" onclick="restoreFood(\'' . $food['id'] . '\')">';
            $html .= '<i class="fas fa-trash-restore"></i> Restore</button>';
            $html .= '<button class="btn-secondary" style="padding:5px 10px; color:white; border-color: #B91C1C; background: #DC2626; margin-left:5px;" onclick="permanentDeleteFood(\'' . $food['id'] . '\')">';
            $html .= '<i class="fas fa-times"></i></button>';
        }

        $html .= '</div>';
        $html .= '</td>';
        $html .= '</tr>';
    }
} else {
    $html .= '<tr><td colspan="6" class="text-center" style="padding:30px; color:#888;">No menu items found.</td></tr>';
}

echo json_encode(['status' => 'success', 'html' => $html]);
?>