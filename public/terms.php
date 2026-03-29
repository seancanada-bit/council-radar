<?php
/**
 * CouncilRadar - Terms of Service
 */

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../templates/layout.php';

layoutHeader('Terms of Service', 'Terms of Service for CouncilRadar municipal agenda monitoring.');
?>

<section class="legal-page">
    <div class="container container-narrow">
        <h1>Terms of Service</h1>
        <p style="color: #718096; margin-bottom: 2rem;">Last updated: March 28, 2026</p>

        <div class="legal-content" style="line-height: 1.8;">

            <h2 style="color: #1a365d;">1. Service Description</h2>
            <p>CouncilRadar aggregates publicly available municipal council agenda data from BC municipalities as an informational convenience. We scan agenda postings and deliver filtered email alerts based on your keyword preferences. Our goal is to save you time - not to replace your own due diligence.</p>

            <h2 style="color: #1a365d;">2. No Warranty of Accuracy</h2>
            <p>All data provided through CouncilRadar is offered "as-is" with no warranty of accuracy, completeness, or timeliness. Municipal websites may change without notice, documents may be posted late, or our parsing may miss items. You are responsible for independently verifying all information before relying on it for business, legal, or financial decisions.</p>

            <h2 style="color: #1a365d;">3. Not a Legal Notification Service</h2>
            <p>CouncilRadar does not replace direct monitoring of municipal agendas, official legal notices, or any statutory notification process. Missing a CouncilRadar alert does not constitute grounds for missing any regulatory or legal deadline. If you have legal obligations tied to municipal proceedings, you must monitor official channels directly.</p>

            <h2 style="color: #1a365d;">4. Limitation of Liability</h2>
            <p>Our total liability to you is capped at the subscription fees you have paid in the preceding 12 months. CouncilRadar is not liable for errors, omissions, delayed alerts, service interruptions, or any direct, indirect, or consequential damages arising from the use or inability to use the service.</p>

            <h2 style="color: #1a365d;">5. Service Level</h2>
            <p>We make reasonable efforts to deliver alerts within 24 hours of an agenda being posted to a municipal website. However, we do not guarantee any specific uptime or delivery times. There are no refunds for missed alerts or service interruptions.</p>

            <h2 style="color: #1a365d;">6. Data Sources</h2>
            <p>All data is sourced from publicly available municipal websites under the BC Community Charter. A list of the municipalities we monitor, with links to their official agenda pages, is maintained at <a href="/alerts.php" style="color: #2b6cb0;">councilradar.ca/alerts</a>.</p>

            <h2 style="color: #1a365d;">7. Billing</h2>
            <p>Paid subscriptions are billed monthly or annually via Stripe. You may cancel at any time - your access continues through the end of your current billing period. We do not offer partial-period refunds.</p>

            <h2 style="color: #1a365d;">8. Modifications</h2>
            <p>We may modify these terms from time to time. If we make material changes, we will give you at least 30 days notice by email before the new terms take effect. Continued use of the service after that notice period constitutes acceptance of the updated terms.</p>

            <h2 style="color: #1a365d;">9. Governing Law</h2>
            <p>These terms are governed by the laws of British Columbia, Canada. Any disputes will be resolved in the courts of British Columbia.</p>

            <hr style="margin: 2rem 0; border: none; border-top: 1px solid #e2e8f0;">

            <p style="color: #718096;">Questions about these terms? Contact us at <a href="mailto:support@councilradar.ca" style="color: #2b6cb0;">support@councilradar.ca</a>.</p>

        </div>
    </div>
</section>

<?php layoutFooter(); ?>
