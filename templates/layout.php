<?php
/**
 * CouncilRadar - Shared Layout Template
 *
 * Provides layoutHeader() and layoutFooter() functions.
 * Usage in pages:
 *   require __DIR__ . '/../templates/layout.php';
 *   layoutHeader('Page Title', 'Optional description');
 *   // ... page content ...
 *   layoutFooter();
 */

function layoutHeader(string $pageTitle = 'CouncilRadar', string $pageDescription = 'Municipal agenda monitoring for BC, Canada. Get alerts when council agendas match your interests.'): void {
    $siteName = 'CouncilRadar';
    $fullTitle = ($pageTitle !== $siteName) ? "$pageTitle - $siteName" : $siteName;
    $year = date('Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php echo htmlspecialchars($pageDescription); ?>">
    <meta name="robots" content="index, follow">
    <title><?php echo htmlspecialchars($fullTitle); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="canonical" href="<?php echo htmlspecialchars(SITE_URL . $_SERVER['REQUEST_URI']); ?>">
</head>
<body>
    <?php require __DIR__ . '/nav.php'; ?>
    <main>
<?php
}

function layoutFooter(): void {
    $year = date('Y');
?>
    </main>
    <footer class="site-footer">
        <div class="container">
            <div class="footer-grid">
                <div class="footer-brand">
                    <p class="footer-logo">CouncilRadar</p>
                    <p class="footer-tagline">Municipal Agenda Monitoring</p>
                    <p class="footer-operator">Operated by Pacific Logo Design</p>
                </div>
                <div class="footer-links">
                    <h4>Legal</h4>
                    <ul>
                        <li><a href="/terms.php">Terms of Service</a></li>
                        <li><a href="/privacy.php">Privacy Policy</a></li>
                        <li><a href="/contact.php">Contact Us</a></li>
                    </ul>
                </div>
                <div class="footer-links">
                    <h4>Product</h4>
                    <ul>
                        <li><a href="/#how-it-works">How It Works</a></li>
                        <li><a href="/#pricing">Pricing</a></li>
                        <li><a href="/#coverage">Coverage</a></li>
                    </ul>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; <?php echo $year; ?> CouncilRadar - Municipal Agenda Monitoring. All rights reserved.</p>
            </div>
        </div>
    </footer>
    <script src="/assets/js/main.js"></script>
</body>
</html>
<?php
}
