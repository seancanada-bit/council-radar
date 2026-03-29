<?php
/**
 * CouncilRadar - Privacy Policy
 */

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../templates/layout.php';

layoutHeader('Privacy Policy', 'Privacy Policy for CouncilRadar municipal agenda monitoring.');
?>

<section class="legal-page">
    <div class="container container-narrow">
        <h1>Privacy Policy</h1>
        <p style="color: #718096; margin-bottom: 2rem;">Last updated: March 28, 2026</p>

        <div class="legal-content" style="line-height: 1.8;">

            <h2 style="color: #1a365d;">1. Information We Collect</h2>
            <p>We collect the following information when you use CouncilRadar:</p>
            <ul>
                <li><strong>Email address</strong> - required to deliver alerts and manage your account.</li>
                <li><strong>Name</strong> - optional, used for personalization.</li>
                <li><strong>Organization</strong> - optional, helps us understand our users.</li>
                <li><strong>Payment data</strong> - processed entirely by Stripe. We never see or store your credit card numbers.</li>
                <li><strong>IP address</strong> - recorded at the time of consent for CASL compliance.</li>
                <li><strong>Usage analytics</strong> - email open rates and click rates to help us improve alert quality.</li>
            </ul>

            <h2 style="color: #1a365d;">2. How We Use Your Information</h2>
            <p>We use the information we collect to:</p>
            <ul>
                <li>Deliver municipal agenda alert emails based on your preferences.</li>
                <li>Process subscription payments through Stripe.</li>
                <li>Improve service quality and alert relevance.</li>
                <li>Communicate service updates, changes, or issues.</li>
            </ul>

            <h2 style="color: #1a365d;">3. No Data Sales</h2>
            <p>We do not sell, rent, or share subscriber data with third parties, municipalities, or other organizations. Your information is used solely for operating CouncilRadar.</p>

            <h2 style="color: #1a365d;">4. Data Retention</h2>
            <ul>
                <li>Active account data is retained for as long as your account remains active.</li>
                <li>If you unsubscribe, consent records are retained for 3 years as required by Canada's Anti-Spam Legislation (CASL).</li>
                <li>Email sending stops within 10 business days of an unsubscribe request.</li>
            </ul>

            <h2 style="color: #1a365d;">5. Your Rights</h2>
            <p>You have the right to request access to, correction of, or deletion of your personal data at any time. Contact us and we will respond promptly.</p>

            <h2 style="color: #1a365d;">6. Security</h2>
            <p>We take reasonable measures to protect your data, including:</p>
            <ul>
                <li>SSL/TLS encryption for all connections.</li>
                <li>Bcrypt password hashing.</li>
                <li>Parameterized database queries to prevent injection attacks.</li>
                <li>Access controls on all stored data.</li>
            </ul>

            <h2 style="color: #1a365d;">7. PIPEDA Compliance</h2>
            <p>Canadian personal information is handled in accordance with the Personal Information Protection and Electronic Documents Act (PIPEDA). We collect only what is necessary, use it only for stated purposes, and protect it with appropriate safeguards.</p>

            <h2 style="color: #1a365d;">8. Contact</h2>
            <p>If you have questions about this privacy policy or want to exercise your data rights, contact us at <a href="mailto:support@councilradar.ca" style="color: #2b6cb0;">support@councilradar.ca</a>.</p>

        </div>
    </div>
</section>

<?php layoutFooter(); ?>
