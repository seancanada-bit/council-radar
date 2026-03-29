<?php
session_start();
require __DIR__ . '/../app/config.php';
require __DIR__ . '/../templates/layout.php';

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

layoutHeader(
    'CouncilRadar - Municipal Agenda Monitoring for BC',
    'CouncilRadar monitors 16 BC municipalities and delivers filtered agenda alerts straight to your inbox. Know what is on every council agenda before anyone else.'
);
?>

    <!-- Hero Section -->
    <section class="hero" id="signup">
        <div class="container">
            <h1>Know what's on every BC council agenda before anyone else.</h1>
            <p class="hero-subtitle">CouncilRadar monitors 16 BC municipalities and delivers filtered agenda alerts straight to your inbox.</p>

            <div class="signup-form-card">
                <form id="signupForm" method="post" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                    <div class="form-group">
                        <label for="signup-email" class="form-label">Email Address *</label>
                        <input type="email" id="signup-email" name="email" class="form-input" placeholder="you@example.com" required>
                    </div>

                    <div class="form-group">
                        <label for="signup-name" class="form-label">Name</label>
                        <input type="text" id="signup-name" name="name" class="form-input" placeholder="Your name (optional)">
                    </div>

                    <div class="form-group">
                        <label for="signup-org" class="form-label">Organization</label>
                        <input type="text" id="signup-org" name="organization" class="form-input" placeholder="Company or firm (optional)">
                    </div>

                    <div class="form-group">
                        <label class="form-check">
                            <input type="checkbox" name="casl_consent" value="1">
                            <span class="form-check-label">Yes, I want to receive municipal council agenda alerts from CouncilRadar. I understand I can unsubscribe at any time.</span>
                        </label>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg btn-block">Start Monitoring</button>
                </form>
            </div>
        </div>
    </section>

    <!-- How It Works -->
    <section class="section" id="how-it-works">
        <div class="container">
            <div class="section-header">
                <h2>How It Works</h2>
                <p>Three simple steps to stay ahead of every council decision that matters to you.</p>
            </div>

            <div class="grid grid-3">
                <div class="card step-card">
                    <div class="step-number">1</div>
                    <h3>We Scan</h3>
                    <p>Every night, CouncilRadar checks council agenda postings across 16 BC municipalities.</p>
                </div>

                <div class="card step-card">
                    <div class="step-number">2</div>
                    <h3>We Match</h3>
                    <p>Our system flags items matching your interests - rezoning, development permits, public hearings, and more.</p>
                </div>

                <div class="card step-card">
                    <div class="step-number">3</div>
                    <h3>You Know</h3>
                    <p>Get a curated email digest so you never miss what matters to your clients or your business.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Who It's For -->
    <section class="section section-alt" id="who-its-for">
        <div class="container">
            <div class="section-header">
                <h2>Who It's For</h2>
                <p>Built for professionals who need to stay on top of municipal decisions across BC.</p>
            </div>

            <div class="grid grid-3">
                <div class="use-case-card">
                    <h4>Planning Consultants</h4>
                </div>
                <div class="use-case-card">
                    <h4>Land Use Lawyers</h4>
                </div>
                <div class="use-case-card">
                    <h4>Real Estate Developers</h4>
                </div>
                <div class="use-case-card">
                    <h4>Realtors</h4>
                </div>
                <div class="use-case-card">
                    <h4>Journalists</h4>
                </div>
                <div class="use-case-card">
                    <h4>Community Advocates</h4>
                </div>
            </div>
        </div>
    </section>

    <!-- Pricing -->
    <section class="section" id="pricing">
        <div class="container">
            <div class="section-header">
                <h2>Simple, Transparent Pricing</h2>
                <p>Start free. Upgrade when you need more.</p>
            </div>

            <div class="grid grid-3">
                <!-- Free Tier -->
                <div class="pricing-card">
                    <h3>Free</h3>
                    <div class="pricing-price">
                        <span class="pricing-amount">$0</span>
                        <span class="pricing-period">/month</span>
                    </div>
                    <ul class="pricing-features">
                        <li>Weekly digest</li>
                        <li>Basic keyword matching</li>
                        <li>All 16 municipalities</li>
                        <li>Email support</li>
                    </ul>
                    <a href="#signup" class="btn btn-outline btn-block">Get Started</a>
                </div>

                <!-- Professional Tier -->
                <div class="pricing-card featured">
                    <h3>Professional</h3>
                    <div class="pricing-price">
                        <span class="pricing-amount">$19</span>
                        <span class="pricing-period">/month</span>
                    </div>
                    <ul class="pricing-features">
                        <li>Daily alerts</li>
                        <li>Full item details with source links</li>
                        <li>Custom keyword filters</li>
                        <li>Municipality selection</li>
                        <li>Priority support</li>
                    </ul>
                    <a href="#signup" class="btn btn-primary btn-block">Start Monitoring</a>
                </div>

                <!-- Firm Tier -->
                <div class="pricing-card">
                    <h3>Firm</h3>
                    <div class="pricing-price">
                        <span class="pricing-amount">$49</span>
                        <span class="pricing-period">/month</span>
                    </div>
                    <ul class="pricing-features">
                        <li>Everything in Professional</li>
                        <li>Up to 5 team members</li>
                        <li>Consolidated billing</li>
                        <li>Dedicated onboarding</li>
                    </ul>
                    <a href="#signup" class="btn btn-secondary btn-block">Contact Us</a>
                </div>
            </div>
        </div>
    </section>

    <!-- Coverage -->
    <section class="section section-alt" id="coverage">
        <div class="container">
            <div class="section-header">
                <h2>Currently Monitoring 16 BC Municipalities</h2>
                <p>We track council agendas across the fastest-growing communities in British Columbia.</p>
            </div>

            <div class="coverage-grid">
                <div class="coverage-item">
                    <h4>Vancouver</h4>
                    <p>Pop. 662,248</p>
                </div>
                <div class="coverage-item">
                    <h4>Surrey</h4>
                    <p>Pop. 568,322</p>
                </div>
                <div class="coverage-item">
                    <h4>Burnaby</h4>
                    <p>Pop. 249,125</p>
                </div>
                <div class="coverage-item">
                    <h4>Richmond</h4>
                    <p>Pop. 209,937</p>
                </div>
                <div class="coverage-item">
                    <h4>Kelowna</h4>
                    <p>Pop. 144,576</p>
                </div>
                <div class="coverage-item">
                    <h4>Abbotsford</h4>
                    <p>Pop. 153,524</p>
                </div>
                <div class="coverage-item">
                    <h4>Coquitlam</h4>
                    <p>Pop. 148,625</p>
                </div>
                <div class="coverage-item">
                    <h4>Saanich</h4>
                    <p>Pop. 117,735</p>
                </div>
                <div class="coverage-item">
                    <h4>Victoria</h4>
                    <p>Pop. 91,867</p>
                </div>
                <div class="coverage-item">
                    <h4>Nanaimo</h4>
                    <p>Pop. 99,863</p>
                </div>
                <div class="coverage-item">
                    <h4>Kamloops</h4>
                    <p>Pop. 97,902</p>
                </div>
                <div class="coverage-item">
                    <h4>Chilliwack</h4>
                    <p>Pop. 93,203</p>
                </div>
                <div class="coverage-item">
                    <h4>North Vancouver (District)</h4>
                    <p>Pop. 88,168</p>
                </div>
                <div class="coverage-item">
                    <h4>Langley (Township)</h4>
                    <p>Pop. 132,603</p>
                </div>
                <div class="coverage-item">
                    <h4>West Kelowna</h4>
                    <p>Pop. 36,078</p>
                </div>
                <div class="coverage-item">
                    <h4>Prince George</h4>
                    <p>Pop. 76,708</p>
                </div>
            </div>
        </div>
    </section>

<?php layoutFooter(); ?>
