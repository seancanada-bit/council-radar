<?php
/**
 * Scraper for BC Provincial MLAs
 *
 * Sources:
 *   1. Represent API (represent.opennorth.ca) — JSON API with all 93 MLAs
 *   2. BC Legislature contact page (leg.bc.ca) — static HTML table for verification
 *   3. Individual MLA profile pages (leg.bc.ca) — office addresses/phones (JS-rendered)
 */

require_once __DIR__ . '/BaseScraper.php';

class ProvincialScraper extends BaseScraper {

    private const REPRESENT_API_URL = 'https://represent.opennorth.ca/representatives/bc-legislature/?limit=100&format=json';
    private const LEG_CONTACT_URL = 'https://www.leg.bc.ca/contact-us/mla-contact-information';
    private const LEG_PROFILE_BASE = 'https://www.leg.bc.ca/members/43rd-Parliament/';

    private string $logFile;
    private int $officialsFound = 0;
    private int $officialsInserted = 0;
    private int $officialsUpdated = 0;

    public function __construct() {
        parent::__construct();
        $this->logFile = __DIR__ . '/../../logs/officials_provincial.log';
    }

    public function scrapeAll(): array {
        $startTime = microtime(true);
        $this->writeLog("Starting provincial MLA scrape");

        try {
            // Step 1: Fetch from Represent API (primary source)
            $representData = $this->fetchRepresentAPI();
            if (empty($representData)) {
                throw new \Exception('Represent API returned no data');
            }
            $this->writeLog("Represent API: " . count($representData) . " MLAs found");

            // Step 2: Fetch from leg.bc.ca contact page (verification source)
            $legContactData = $this->fetchLegContactPage();
            $this->writeLog("leg.bc.ca contacts: " . count($legContactData) . " entries found");

            // Step 3: Upsert officials from Represent API data
            foreach ($representData as $mla) {
                $this->upsertOfficial($mla, 'represent_api');
            }

            // Step 4: Cross-reference with leg.bc.ca contact data
            $this->crossReferenceWithLeg($legContactData);

            // Step 5: Fetch individual profile pages for office details
            $this->enrichFromProfiles($representData);

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $this->logOfficialsScrape('provincial_represent', 'provincial', 'success', $durationMs);

            $this->writeLog("Complete: {$this->officialsFound} found, {$this->officialsInserted} inserted, {$this->officialsUpdated} updated ({$durationMs}ms)");

            return [
                'officials_found' => $this->officialsFound,
                'officials_inserted' => $this->officialsInserted,
                'officials_updated' => $this->officialsUpdated,
            ];

        } catch (\Exception $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $this->logOfficialsScrape('provincial_represent', 'provincial', 'error', $durationMs, $e->getMessage());
            $this->writeLog("ERROR: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Not used — provincial MLAs aren't tied to individual municipalities
     */
    public function scrapeMunicipality(array $muni): array {
        return [];
    }

    /**
     * Fetch all MLAs from the Open North Represent API
     */
    private function fetchRepresentAPI(): array {
        $this->writeLog("Fetching Represent API...");
        $result = $this->fetch(self::REPRESENT_API_URL);

        if ($result['error']) {
            $this->writeLog("Represent API error: " . $result['error']);
            return [];
        }

        $json = json_decode($result['body'], true);
        if (!$json || !isset($json['objects'])) {
            $this->writeLog("Represent API: invalid JSON response");
            return [];
        }

        $officials = [];
        foreach ($json['objects'] as $obj) {
            $officials[] = [
                'name' => trim($obj['name'] ?? ''),
                'first_name' => trim($obj['first_name'] ?? ''),
                'last_name' => trim($obj['last_name'] ?? ''),
                'district_name' => trim($obj['district_name'] ?? ''),
                'party' => trim($obj['party_name'] ?? ''),
                'email' => trim($obj['email'] ?? ''),
                'photo_url' => trim($obj['photo_url'] ?? ''),
                'source_url' => trim($obj['source_url'] ?? self::REPRESENT_API_URL),
            ];
        }

        $this->officialsFound = count($officials);
        return $officials;
    }

    /**
     * Scrape the leg.bc.ca MLA contact information page (static HTML table)
     * Returns array of ['name' => ..., 'email' => ...]
     */
    private function fetchLegContactPage(): array {
        $this->writeLog("Fetching leg.bc.ca contact page...");
        $this->rateLimit();
        $result = $this->fetch(self::LEG_CONTACT_URL);

        if ($result['error']) {
            $this->writeLog("leg.bc.ca contact page error: " . $result['error']);
            return [];
        }

        $contacts = [];
        $html = $result['body'];

        // Parse the HTML table — each row has MLA name (linked) and email (mailto)
        if (preg_match('/<table[^>]*>.*?<\/table>/si', $html, $tableMatch)) {
            $table = $tableMatch[0];
            preg_match_all('/<tr[^>]*>(.*?)<\/tr>/si', $table, $rows);

            foreach ($rows[1] as $row) {
                // Skip header rows
                if (strpos($row, '<th') !== false) continue;

                // Extract name from first cell (may have link and "Hon." prefix)
                $name = '';
                if (preg_match('/<td[^>]*>(.*?)<\/td>/si', $row, $cell1)) {
                    $name = $this->stripHtml($cell1[1]);
                    // Remove honorifics for matching
                    $name = preg_replace('/^Hon\.\s*/i', '', $name);
                    $name = trim($name);
                }

                // Extract email from mailto link
                $email = '';
                if (preg_match('/mailto:([^"\'>\s]+)/i', $row, $emailMatch)) {
                    $email = trim($emailMatch[1]);
                }

                if ($name) {
                    $contacts[] = [
                        'name' => $name,
                        'email' => $email,
                        'source_url' => self::LEG_CONTACT_URL,
                    ];
                }
            }
        }

        return $contacts;
    }

    /**
     * Upsert an official into the elected_officials table
     */
    private function upsertOfficial(array $data, string $sourceName): void {
        $name = $data['name'];
        $jurisdiction = $data['district_name'];
        $level = 'provincial';

        // Check if exists
        $stmt = $this->db->prepare(
            'SELECT id FROM elected_officials
             WHERE name = ? AND jurisdiction_name = ? AND government_level = ?'
        );
        $stmt->execute([$name, $jurisdiction, $level]);
        $existing = $stmt->fetchColumn();

        if ($existing) {
            // Update
            $stmt = $this->db->prepare(
                'UPDATE elected_officials SET
                    first_name = ?, last_name = ?, role = ?, party = ?,
                    email = ?, photo_url = ?, source_url = ?, source_name = ?,
                    updated_at = NOW()
                 WHERE id = ?'
            );
            $stmt->execute([
                $data['first_name'], $data['last_name'], 'MLA', $data['party'],
                $data['email'], $data['photo_url'], $data['source_url'], $sourceName,
                $existing
            ]);
            $this->officialsUpdated++;
        } else {
            // Insert
            $stmt = $this->db->prepare(
                'INSERT INTO elected_officials
                    (government_level, jurisdiction_name, name, first_name, last_name,
                     role, party, email, photo_url, source_url, source_name, confidence_score)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
            );
            $stmt->execute([
                $level, $jurisdiction, $name, $data['first_name'], $data['last_name'],
                'MLA', $data['party'], $data['email'], $data['photo_url'],
                $data['source_url'], $sourceName
            ]);
            $this->officialsInserted++;
        }
    }

    /**
     * Cross-reference Represent API data with leg.bc.ca contact page
     * Updates confidence_score and logs verification results
     */
    private function crossReferenceWithLeg(array $legContacts): void {
        if (empty($legContacts)) return;

        $this->writeLog("Cross-referencing with leg.bc.ca data...");

        // Build lookup by normalized last name
        $legByLastName = [];
        foreach ($legContacts as $contact) {
            $parts = preg_split('/[\s,]+/', $contact['name']);
            $lastName = strtolower(end($parts));
            $legByLastName[$lastName][] = $contact;
        }

        // Match against our DB records
        $stmt = $this->db->prepare(
            'SELECT id, name, first_name, last_name, email
             FROM elected_officials WHERE government_level = ?'
        );
        $stmt->execute(['provincial']);
        $officials = $stmt->fetchAll();

        $matched = 0;
        foreach ($officials as $official) {
            $lastName = strtolower($official['last_name']);

            if (!isset($legByLastName[$lastName])) continue;

            // Find best match (could be multiple people with same last name)
            $bestMatch = null;
            foreach ($legByLastName[$lastName] as $legContact) {
                $firstInitial = strtolower(substr($official['first_name'], 0, 1));
                if (stripos($legContact['name'], $official['first_name']) !== false
                    || stripos($legContact['name'], $firstInitial) === 0) {
                    $bestMatch = $legContact;
                    break;
                }
            }

            if (!$bestMatch) continue;

            $fieldsMatched = ['name' => true];
            $fieldsMismatched = [];

            // Compare email
            if ($bestMatch['email'] && $official['email']) {
                if (strtolower($bestMatch['email']) === strtolower($official['email'])) {
                    $fieldsMatched['email'] = true;
                } else {
                    $fieldsMismatched['email'] = [
                        'represent' => $official['email'],
                        'leg_bc_ca' => $bestMatch['email'],
                    ];
                }
            }

            // Log verification
            $verifyStmt = $this->db->prepare(
                'INSERT INTO official_verifications
                    (official_id, source_name, source_url, fields_matched, fields_mismatched)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $verifyStmt->execute([
                $official['id'],
                'leg_bc_ca',
                self::LEG_CONTACT_URL,
                json_encode($fieldsMatched),
                !empty($fieldsMismatched) ? json_encode($fieldsMismatched) : null,
            ]);

            // Bump confidence if name matched
            $updateStmt = $this->db->prepare(
                'UPDATE elected_officials SET confidence_score = LEAST(confidence_score + 1, 3), verified_at = NOW() WHERE id = ?'
            );
            $updateStmt->execute([$official['id']]);

            $matched++;
        }

        $this->writeLog("Cross-reference: {$matched} of " . count($officials) . " matched against leg.bc.ca");
    }

    /**
     * Enrich officials with office address/phone from individual leg.bc.ca profile pages
     * These pages are JS-rendered but may contain data in script tags or meta elements
     */
    private function enrichFromProfiles(array $representData): void {
        $this->writeLog("Enriching from individual profile pages...");
        $enriched = 0;
        $failed = 0;

        foreach ($representData as $mla) {
            // Build profile URL: LastName-FirstName
            $slug = $this->buildProfileSlug($mla['last_name'], $mla['first_name']);
            $url = self::LEG_PROFILE_BASE . $slug;

            $this->rateLimit();
            $result = $this->fetchWithBackoff($url);

            if ($result['error']) {
                // Try alternative slug formats
                $altSlug = $this->buildProfileSlug($mla['last_name'], $mla['first_name'], true);
                if ($altSlug !== $slug) {
                    $this->rateLimit();
                    $result = $this->fetchWithBackoff(self::LEG_PROFILE_BASE . $altSlug);
                }
            }

            if ($result['error']) {
                $this->writeLog("  Profile fetch failed for {$mla['name']}: " . $result['error']);
                $failed++;
                continue;
            }

            $profileData = $this->parseProfilePage($result['body']);
            if (!empty($profileData)) {
                $this->updateOfficialProfile($mla['name'], $mla['district_name'], $profileData);
                $enriched++;
            }
        }

        $this->writeLog("Profiles enriched: {$enriched}, failed: {$failed}");
    }

    /**
     * Build a profile URL slug from name parts
     * Format: LastName-FirstName (e.g., Banman-Bruce)
     */
    private function buildProfileSlug(string $lastName, string $firstName, bool $alternate = false): string {
        // Remove suffixes like "K.C."
        $lastName = preg_replace('/\s+(K\.C\.|Q\.C\.|K\.?C|Q\.?C)$/i', '', $lastName);
        $firstName = preg_replace('/\s+(K\.C\.|Q\.C\.|K\.?C|Q\.?C)$/i', '', $firstName);

        // Handle hyphenated/compound names
        $lastName = trim($lastName);
        $firstName = trim($firstName);

        if ($alternate) {
            // Try first name only (no middle names)
            $firstParts = explode(' ', $firstName);
            $firstName = $firstParts[0];
        }

        // Replace spaces with hyphens, keep existing hyphens
        $lastName = str_replace(' ', '-', $lastName);
        $firstName = str_replace(' ', '-', $firstName);

        return $lastName . '-' . $firstName;
    }

    /**
     * Parse a JS-rendered MLA profile page for embedded data
     * Look for phone numbers, addresses in script tags, JSON-LD, or meta tags
     */
    private function parseProfilePage(string $html): array {
        $data = [];

        // Look for phone patterns in the raw HTML (even if JS-rendered, phone numbers may appear in source)
        if (preg_match('/(?:Legislature|Victoria)\s*(?:Office)?[^<]*?(\(\d{3}\)\s*\d{3}[\s-]\d{4}|\d{3}[\s.-]\d{3}[\s.-]\d{4})/si', $html, $legPhone)) {
            $data['phone'] = trim($legPhone[1]);
        }

        // Constituency office phone
        if (preg_match('/Constituency\s*Office[^<]*?(\(\d{3}\)\s*\d{3}[\s-]\d{4}|\d{3}[\s.-]\d{3}[\s.-]\d{4})/si', $html, $constPhone)) {
            $data['constituency_phone'] = trim($constPhone[1]);
        }

        // Look for address patterns
        if (preg_match('/Parliament Buildings[^<]*?Victoria[^<]*?V\d[A-Z]\s*\d[A-Z]\d/si', $html, $legAddr)) {
            $data['office_address'] = trim($this->stripHtml($legAddr[0]));
        }

        // Constituency office address (street address before a city name + postal code)
        if (preg_match('/Constituency\s*Office[^<]*?((?:\d+[^<]+?(?:Street|St|Avenue|Ave|Road|Rd|Drive|Dr|Boulevard|Blvd|Highway|Hwy|Way)[^<]*?BC[^<]*?V\d[A-Z]\s*\d[A-Z]\d))/si', $html, $constAddr)) {
            $data['constituency_office_address'] = trim($this->stripHtml($constAddr[1]));
        }

        // Look for embedded JSON data (some pages embed member data in script tags)
        if (preg_match('/<script[^>]*>\s*(?:var|let|const)\s+\w*[Mm]ember\w*\s*=\s*(\{[^;]+\});/s', $html, $jsonMatch)) {
            $jsonData = json_decode($jsonMatch[1], true);
            if ($jsonData) {
                $data['_json'] = $jsonData;
            }
        }

        return $data;
    }

    /**
     * Update an official's profile with enriched data
     */
    private function updateOfficialProfile(string $name, string $jurisdiction, array $profileData): void {
        $sets = [];
        $params = [];

        if (!empty($profileData['phone'])) {
            $sets[] = 'phone = ?';
            $params[] = $profileData['phone'];
        }
        if (!empty($profileData['office_address'])) {
            $sets[] = 'office_address = ?';
            $params[] = $profileData['office_address'];
        }
        if (!empty($profileData['constituency_office_address'])) {
            $sets[] = 'constituency_office_address = ?';
            $params[] = $profileData['constituency_office_address'];
        }

        if (empty($sets)) return;

        $sql = 'UPDATE elected_officials SET ' . implode(', ', $sets) .
               ' WHERE name = ? AND jurisdiction_name = ? AND government_level = ?';
        $params[] = $name;
        $params[] = $jurisdiction;
        $params[] = 'provincial';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Fetch with exponential backoff on 429/403
     */
    private function fetchWithBackoff(string $url, int $maxRetries = 3): array {
        $delay = $this->requestDelay;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            $result = $this->fetch($url);

            if ($result['code'] === 429 || $result['code'] === 403) {
                if ($attempt < $maxRetries) {
                    $delay *= 2;
                    $this->writeLog("  Rate limited ({$result['code']}), backing off {$delay}s...");
                    sleep($delay);
                    continue;
                }
            }

            return $result;
        }

        return ['body' => '', 'code' => 0, 'error' => 'Max retries exceeded'];
    }

    /**
     * Log to the officials_scrape_log table
     */
    private function logOfficialsScrape(string $scraper, string $level, string $status, int $durationMs, ?string $error = null): void {
        $stmt = $this->db->prepare(
            'INSERT INTO officials_scrape_log
                (scraper, government_level, status, officials_found, officials_inserted, officials_updated, error_message, duration_ms)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $scraper, $level, $status,
            $this->officialsFound, $this->officialsInserted, $this->officialsUpdated,
            $error, $durationMs
        ]);
    }

    private function writeLog(string $message): void {
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($this->logFile, "[{$timestamp}] [Provincial] {$message}\n", FILE_APPEND);
    }
}
