<?php
/**
 * CouncilRadar - 404 Not Found
 *
 * Referenced by the ErrorDocument directive in the root .htaccess.
 *
 * Sends a real 404 status. Before this existed, an unknown URL rewrote into
 * public/ repeatedly until Apache hit its internal redirect limit and returned
 * 500, which tells search engines "broken, retry later" instead of "gone".
 */

http_response_code(404);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../templates/layout.php';

// noindex: a 404 should never be indexed, and this page is also what protected
// paths resolve to, so it gets requested for URLs that must stay out of search.
layoutHeader('Page Not Found', 'That page does not exist on CouncilRadar.', true);
?>

<section class="legal-page">
    <div class="container container-narrow">
        <h1>Page not found</h1>
        <p style="color: #718096; margin-bottom: 2rem;">
            That page does not exist, or it has moved.
        </p>
        <p>
            <a href="/">Back to the homepage</a>
        </p>
    </div>
</section>

<?php layoutFooter(); ?>
