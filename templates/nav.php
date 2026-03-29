<?php
/**
 * CouncilRadar - Navigation Bar
 */
$isLoggedIn = isset($_SESSION['subscriber_id']);
?>
<header class="site-header">
    <nav class="navbar">
        <div class="container navbar-inner">
            <a href="/" class="navbar-brand">
                <span class="brand-text">CouncilRadar</span>
            </a>

            <button class="nav-toggle" aria-label="Toggle navigation" aria-expanded="false">
                <span class="hamburger-line"></span>
                <span class="hamburger-line"></span>
                <span class="hamburger-line"></span>
            </button>

            <div class="nav-menu" id="navMenu">
                <ul class="nav-links">
                    <li><a href="/#how-it-works" class="nav-link">How It Works</a></li>
                    <li><a href="/#pricing" class="nav-link">Pricing</a></li>
                    <li><a href="/#coverage" class="nav-link">Coverage</a></li>
                    <?php if ($isLoggedIn): ?>
                        <li><a href="/dashboard.php" class="nav-link">Dashboard</a></li>
                    <?php endif; ?>
                </ul>
                <div class="nav-actions">
                    <?php if ($isLoggedIn): ?>
                        <a href="/dashboard.php" class="btn btn-outline-light btn-sm">Dashboard</a>
                        <a href="/logout.php" class="btn btn-ghost btn-sm">Log Out</a>
                    <?php else: ?>
                        <a href="/login.php" class="btn btn-ghost btn-sm">Log In</a>
                        <a href="/#signup" class="btn btn-primary btn-sm">Sign Up Free</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>
</header>
