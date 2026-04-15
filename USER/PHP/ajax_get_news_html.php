<?php
// USER/PHP/ajax_get_news_html.php
require 'db_connect.php';

// Fetch ALL News (Exactly as in index.php)
$newsQuery = "SELECT * FROM hotel_news WHERE is_active = 1 ORDER BY news_date DESC";
$newsResult = mysqli_query($conn, $newsQuery);
$newsImgBase = '../../room_includes/uploads/news/';
$newsPlaceholder = "data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMDAiIGhlaWdodD0iMTAwIiB2aWV3Qm94PSIwIDAgMTAwIDEwMCI+PHJlY3QgZmlsbD0iI2RkZCIgd2lkdGg9IjEwMCIgaGVpZ2h0PSIxMDAiLz48dGV4dCB4PSI1MCIgeT0iNTAiIGZvbnQtZmFtaWx5PSJhcmlhbCIgZm9udC1zaXplPSIxMiIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzU1NSI+Tm8gSW1hZ2U8L3RleHQ+PC9zdmc+";

if ($newsResult && mysqli_num_rows($newsResult) > 0) {
    while ($newsItem = mysqli_fetch_assoc($newsResult)) {
        $rawNewsPath = $newsItem['image_path'];
        if (strpos($rawNewsPath, ',') !== false) {
            $parts = explode(',', $rawNewsPath);
            $rawNewsPath = trim($parts[0]);
        }
        $newsImgUrl = !empty($rawNewsPath) ? $newsImgBase . htmlspecialchars($rawNewsPath) : $newsPlaceholder;

        $dateObj = new DateTime($newsItem['news_date']);
        $formattedDate = $dateObj->format('F d, Y');

        $cleanDesc = strip_tags($newsItem['description']);
        if (strlen($cleanDesc) > 100) {
            $cleanDesc = substr($cleanDesc, 0, 100) . '...';
        }

        $link = "news_details.php?id=" . $newsItem['id'];
        ?>
        <article class="news-card" id="news-item-<?php echo $newsItem['id']; ?>">
            <a href="<?php echo $link; ?>" class="news-img-container" style="display:block;">
                <img src="<?php echo $newsImgUrl; ?>"
                    alt="<?php echo htmlspecialchars($newsItem['title']); ?>"
                    onerror="this.src='<?php echo $newsPlaceholder; ?>'">
            </a>
            <div class="news-content">
                <span class="news-date"><?php echo $formattedDate; ?></span>
                <h3 class="news-title"><?php echo htmlspecialchars($newsItem['title']); ?></h3>
                <p class="news-excerpt"><?php echo htmlspecialchars($cleanDesc); ?></p>
                <a href="<?php echo $link; ?>" class="read-more-link">Read Full Story</a>
            </div>
        </article>
        <?php
    }
} else {
    echo '<p style="text-align:center; padding: 20px;">No news updates at the moment.</p>';
}
?>