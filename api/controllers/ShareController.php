<?php
/**
 * Share Controller
 * Handles public share pages with OG meta tags for social media
 */

class ShareController
{
    /**
     * Render plant share page with OG meta tags
     * GET /share/plant/{id}
     * No auth required
     */
    public function plant(array $params, array $body, ?int $userId): array
    {
        $plantId = $params['id'] ?? null;

        if (!$plantId) {
            return ['status' => 404, 'data' => ['error' => 'Plant not found']];
        }

        // Fetch plant data
        try {
            $stmt = db()->prepare('
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
        $imageUrl = 'https://dereka328.sg-host.com/icons/icon-512x512.png';
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

        // Output HTML with OG meta tags (this will be output directly, not JSON)
        header('Content-Type: text/html; charset=utf-8');

        echo $this->renderSharePage($title, $description, $imageUrl, $pageUrl);

        exit;
    }

    /**
     * Render gallery share page with OG meta tags
     * GET /share/gallery/{token}
     * No auth required
     */
    public function gallery(array $params, array $body, ?int $userId): array
    {
        $token = $params['token'] ?? null;

        if (!$token) {
            return ['status' => 404, 'data' => ['error' => 'Gallery not found']];
        }

        // Fetch gallery data
        try {
            $stmt = db()->prepare('
                SELECT u.id, u.public_gallery_name, u.email,
                       (SELECT COUNT(*) FROM plants WHERE user_id = u.id AND archived_at IS NULL) as plant_count,
                       (SELECT ph.filename FROM photos ph
                        JOIN plants pl ON ph.plant_id = pl.id
                        WHERE pl.user_id = u.id AND pl.archived_at IS NULL
                        ORDER BY ph.uploaded_at DESC LIMIT 1) as cover_photo
                FROM users u
                WHERE u.public_gallery_token = ? AND u.public_gallery_enabled = 1
            ');
            $stmt->execute([$token]);
            $gallery = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $gallery = null;
        }

        // Default values
        $title = 'Plant Collection - Sunwise';
        $description = 'Check out this plant collection on Sunwise!';
        $imageUrl = 'https://dereka328.sg-host.com/icons/icon-512x512.png';
        $pageUrl = 'https://dereka328.sg-host.com/gallery/' . $token;

        if ($gallery) {
            $galleryName = $gallery['public_gallery_name'] ?: 'Plant Collection';
            $ownerName = ucfirst(explode('@', $gallery['email'])[0]);
            $plantCount = (int)$gallery['plant_count'];

            $title = htmlspecialchars($galleryName) . ' - Sunwise';
            $description = htmlspecialchars($ownerName) . "'s collection of " . $plantCount . " plant" . ($plantCount !== 1 ? 's' : '') . " on Sunwise";

            if ($gallery['cover_photo']) {
                $imageUrl = 'https://dereka328.sg-host.com/uploads/plants/' . $gallery['cover_photo'];
            }
        }

        // Output HTML with OG meta tags
        header('Content-Type: text/html; charset=utf-8');

        echo $this->renderSharePage($title, $description, $imageUrl, $pageUrl);

        exit;
    }

    /**
     * Render the share page HTML with OG meta tags and promo
     */
    private function renderSharePage(string $title, string $description, string $imageUrl, string $pageUrl): string
    {
        return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . $title . '</title>

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="' . $pageUrl . '">
    <meta property="og:title" content="' . $title . '">
    <meta property="og:description" content="' . $description . '">
    <meta property="og:image" content="' . $imageUrl . '">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:site_name" content="Sunwise">

    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:url" content="' . $pageUrl . '">
    <meta name="twitter:title" content="' . $title . '">
    <meta name="twitter:description" content="' . $description . '">
    <meta name="twitter:image" content="' . $imageUrl . '">

    <!-- Redirect to SPA after crawlers read the meta tags -->
    <meta http-equiv="refresh" content="1;url=' . $pageUrl . '">
    <link rel="canonical" href="' . $pageUrl . '">

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .card {
            background: white;
            border-radius: 24px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.1);
            padding: 32px;
            max-width: 400px;
            width: 100%;
            text-align: center;
        }
        .logo {
            width: 64px;
            height: 64px;
            margin: 0 auto 16px;
        }
        h1 {
            font-size: 24px;
            color: #166534;
            margin-bottom: 8px;
        }
        .description {
            color: #6b7280;
            margin-bottom: 24px;
        }
        .redirect-text {
            font-size: 14px;
            color: #9ca3af;
            margin-bottom: 24px;
        }
        .spinner {
            width: 24px;
            height: 24px;
            border: 3px solid #dcfce7;
            border-top-color: #22c55e;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 16px;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .promo {
            background: linear-gradient(135deg, #166534 0%, #15803d 100%);
            border-radius: 16px;
            padding: 20px;
            margin-top: 24px;
            color: white;
        }
        .promo h2 {
            font-size: 18px;
            margin-bottom: 8px;
        }
        .promo p {
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 16px;
        }
        .promo-btn {
            display: inline-block;
            background: white;
            color: #166534;
            padding: 12px 24px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            transition: transform 0.2s;
        }
        .promo-btn:hover {
            transform: scale(1.05);
        }
    </style>
</head>
<body>
    <div class="card">
        <img src="https://dereka328.sg-host.com/icons/icon-512x512.png" alt="Sunwise" class="logo">
        <h1>' . $title . '</h1>
        <p class="description">' . $description . '</p>
        <div class="spinner"></div>
        <p class="redirect-text">Redirecting to Sunwise...</p>

        <div class="promo">
            <h2>Track Your Plants with Sunwise</h2>
            <p>Get AI-powered care reminders, health monitoring, and never forget to water again!</p>
            <a href="https://dereka328.sg-host.com/" class="promo-btn">Try Sunwise Free</a>
        </div>
    </div>
</body>
</html>';
    }
}
