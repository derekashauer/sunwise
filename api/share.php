<?php
/**
 * Social Share OG Meta Handler
 * Serves proper OG meta tags for social media crawlers
 * Regular browsers get redirected to the SPA
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

// Get plant ID from URL
$requestUri = $_SERVER['REQUEST_URI'];
preg_match('/\/plant\/(\d+)/', $requestUri, $matches);
$plantId = $matches[1] ?? null;

if (!$plantId) {
    // Redirect to home if no plant ID
    header('Location: /');
    exit;
}

// Check if request is from a social media crawler
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isCrawler = preg_match(
    '/(facebookexternalhit|Facebot|Twitterbot|Pinterest|LinkedInBot|Slackbot|WhatsApp|Telegram|Discordbot|Applebot)/i',
    $userAgent
);

// If not a crawler, serve the SPA
if (!$isCrawler) {
    // Serve index.html from the web root (parent of /api/)
    $indexPath = dirname(__DIR__) . '/index.html';
    if (file_exists($indexPath)) {
        readfile($indexPath);
        exit;
    }
    // Fallback: redirect to the SPA URL
    header('Location: /plant/' . $plantId);
    exit;
}

// Fetch plant data for crawlers
try {
    $db = db();
    $stmt = $db->prepare('
        SELECT p.id, p.name, p.species, p.created_at,
               u.email as owner_email,
               (SELECT filename FROM photos WHERE plant_id = p.id ORDER BY uploaded_at DESC LIMIT 1) as photo
        FROM plants p
        JOIN users u ON p.user_id = u.id
        WHERE p.id = ? AND p.archived_at IS NULL
    ');
    $stmt->execute([$plantId]);
    $plant = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $plant = null;
}

// Default values
$title = 'Plant - Sunwise';
$description = 'Check out this plant on Sunwise!';
$imageUrl = 'https://dereka328.sg-host.com/favicon.svg';
$pageUrl = 'https://dereka328.sg-host.com/plant/' . $plantId;

if ($plant) {
    $title = htmlspecialchars($plant['name']) . ' - Sunwise';
    $ownerName = ucfirst(explode('@', $plant['owner_email'])[0]);

    if ($plant['species']) {
        $description = htmlspecialchars($plant['species']) . ' - Shared by ' . htmlspecialchars($ownerName) . ' on Sunwise';
    } else {
        $description = 'A plant shared by ' . htmlspecialchars($ownerName) . ' on Sunwise';
    }

    if ($plant['photo']) {
        $imageUrl = 'https://dereka328.sg-host.com/uploads/plants/' . $plant['photo'];
    }
}

// Output HTML with OG meta tags
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?></title>

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= $pageUrl ?>">
    <meta property="og:title" content="<?= $title ?>">
    <meta property="og:description" content="<?= $description ?>">
    <meta property="og:image" content="<?= $imageUrl ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:site_name" content="Sunwise">

    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:url" content="<?= $pageUrl ?>">
    <meta name="twitter:title" content="<?= $title ?>">
    <meta name="twitter:description" content="<?= $description ?>">
    <meta name="twitter:image" content="<?= $imageUrl ?>">

    <!-- Redirect regular browsers to the SPA -->
    <script>window.location.href = '<?= $pageUrl ?>';</script>
</head>
<body>
    <h1><?= $title ?></h1>
    <p><?= $description ?></p>
    <p><a href="<?= $pageUrl ?>">View this plant on Sunwise</a></p>
</body>
</html>
