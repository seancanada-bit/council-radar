<?php
/**
 * Scraper for BC Local Government elected officials (mayors, councillors)
 *
 * Strategy:
 *   1. Represent API (represent.opennorth.ca) - bulk source for 877+ BC municipal officials
 *      Has names, roles, phone numbers, but no emails
 *   2. Municipal website council pages - scraped for email addresses
 *      Each municipality has a different URL structure, configured in COUNCIL_PAGES
 *
 * CivicInfo BC is Cloudflare-protected and inaccessible from server-side PHP.
 */

require_once __DIR__ . '/BaseScraper.php';

class LocalGovScraper extends BaseScraper {

    private const REPRESENT_API_URL = 'https://represent.opennorth.ca/representatives/british-columbia-municipal-councils/?limit=1000&format=json';

    // Council contact page URLs for our monitored municipalities
    // These pages contain individual email addresses and phone numbers
    private const COUNCIL_PAGES = [
        'Parksville' => 'http://www.parksville.ca/cms.asp?wpID=80',
        'Kamloops' => 'https://www.kamloops.ca/city-hall/city-council/council-contact-information-bios',
        'Cranbrook' => 'https://www.cranbrook.ca/city-government/mayor-and-council',
        'Colwood' => 'https://www.colwood.ca/government/mayor-council',
        'Smithers' => 'https://www.smithers.ca/mayor-and-council',
        'Quesnel' => 'https://www.quesnel.ca/city-hall/mayor-council',
        'Trail' => 'https://www.trail.ca/en/city-hall/mayor-and-council.aspx',
        'Revelstoke' => 'https://www.revelstoke.ca/government/mayor-council',
        'Nanaimo' => 'https://www.nanaimo.ca/your-government/city-council/contact-mayor-and-council',
        'Victoria' => 'https://www.victoria.ca/government/mayor-council',
        'Kelowna' => 'https://www.kelowna.ca/city-hall/contact-us/general-inquiries-15',
    ];

    private string $logFile;
    private int $officialsFound = 0;
    private int $officialsInserted = 0;
    private int $officialsUpdated = 0;

    public function __construct() {
        parent::__construct();
        $this->logFile = __DIR__ . '/../../logs/officials_localgov.log';
    }

    public function scrapeAll(): array {
        $startTime = microtime(true);
        $this->writeLog("Starting local government officials scrape");

        try {
            // Step 1: Bulk fetch from Represent API
            $representData = $this->fetchRepresentAPI();
            $this->writeLog("Represent API: " . count($representData) . " BC municipal officials loaded");

            // Step 2: Upsert all Represent API officials
            foreach ($representData as $official) {
                $this->upsertOfficial($official, 'represent_api');
            }
            $this->writeLog("Upserted {$this->officialsInserted} new, {$this->officialsUpdated} updated from Represent API");

            // Step 3: Scrape municipal websites for emails
            $emailsFound = $this->scrapeCouncilPages();
            $this->writeLog("Council page scraping: {$emailsFound} emails found/updated");

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $this->logOfficialsScrape('localgov_combined', 'municipal', 'success', $durationMs);
            $this->writeLog("Complete: {$this->officialsFound} found, {$this->officialsInserted} inserted, {$this->officialsUpdated} updated ({$durationMs}ms)");

            return [
                'officials_found' => $this->officialsFound,
                'officials_inserted' => $this->officialsInserted,
                'officials_updated' => $this->officialsUpdated,
            ];

        } catch (\Exception $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $this->logOfficialsScrape('localgov_combined', 'municipal', 'error', $durationMs, $e->getMessage());
            $this->writeLog("ERROR: " . $e->getMessage());
            throw $e;
        }
    }

    public function scrapeMunicipality(array $muni): array {
        return [];
    }

    /**
     * Fetch all BC municipal officials from Represent API
     */
    private function fetchRepresentAPI(): array {
        $this->writeLog("Fetching Represent API...");
        $url = self::REPRESENT_API_URL;
        $allOfficials = [];

        // Represent API paginates - follow next links
        while ($url) {
            $result = $this->fetch($url);
            if ($result['error']) {
                $this->writeLog("Represent API error: " . $result['error']);
                break;
            }

            $json = json_decode($result['body'], true);
            if (!$json || !isset($json['objects'])) break;

            foreach ($json['objects'] as $obj) {
                $name = trim($obj['name'] ?? '');
                if (!$name) continue;

                $allOfficials[] = [
                    'name' => $name,
                    'first_name' => trim($obj['first_name'] ?? ''),
                    'last_name' => trim($obj['last_name'] ?? ''),
                    'jurisdiction' => trim($obj['district_name'] ?? ''),
                    'role' => trim($obj['elected_office'] ?? 'Councillor'),
                    'email' => trim($obj['email'] ?? ''),
                    'phone' => $this->cleanPhone(trim($obj['offices'][0]['tel'] ?? '')),
                    'photo_url' => trim($obj['photo_url'] ?? ''),
                    'source_url' => trim($obj['source_url'] ?? self::REPRESENT_API_URL),
                ];
            }

            // Check for next page
            $url = $json['meta']['next'] ?? null;
            if ($url && strpos($url, 'http') !== 0) {
                $url = 'https://represent.opennorth.ca' . $url;
            }

            $this->rateLimit();
        }

        $this->officialsFound = count($allOfficials);
        return $allOfficials;
    }

    /**
     * Scrape individual municipal council pages for email addresses
     */
    private function scrapeCouncilPages(): int {
        $emailsFound = 0;

        foreach (self::COUNCIL_PAGES as $municipality => $url) {
            $this->writeLog("  Scraping council page: {$municipality}");
            $this->rateLimit();

            $result = $this->fetch($url);
            if ($result['error']) {
                $this->writeLog("  Failed: " . $result['error']);
                continue;
            }

            $contacts = $this->parseCouncilPage($result['body'], $municipality);
            $this->writeLog("  {$municipality}: " . count($contacts) . " contacts found");

            foreach ($contacts as $contact) {
                if (empty($contact['email'])) continue;

                // Try to match to an existing official in the DB
                $matched = $this->matchAndUpdateEmail($contact, $municipality);
                if ($matched) {
                    $emailsFound++;
                }
            }
        }

        return $emailsFound;
    }

    /**
     * Parse a council contact page for names and emails
     * Uses multiple strategies since each municipality has different HTML
     */
    private function parseCouncilPage(string $html, string $municipality): array {
        $contacts = [];

        // Strategy 1: Find mailto links near person names
        // Most council pages have patterns like:
        //   <a href="mailto:email@city.ca">email@city.ca</a>
        //   near a name in a heading, bold text, or table cell

        // Extract all emails from the page
        $emails = [];
        if (preg_match_all('/mailto:([^"\'>\s]+)/i', $html, $emailMatches)) {
            $emails = array_unique($emailMatches[1]);
        }

        // Extract all person-like names near titles like Mayor, Councillor, Council
        // Look for patterns: "Mayor Name", "Councillor Name", or names in headings near emails
        $namePatterns = [
            '/(?:Mayor|Councillor|Councilor)\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)/i',
            '/<(?:h[1-6]|strong|b)[^>]*>\s*(?:Mayor|Councillor|Councilor)?\s*([A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)\s*<\/(?:h[1-6]|strong|b)>/i',
        ];

        $names = [];
        foreach ($namePatterns as $pattern) {
            if (preg_match_all($pattern, $html, $nameMatches)) {
                foreach ($nameMatches[1] as $name) {
                    $name = trim($name);
                    if (strlen($name) > 4 && strlen($name) < 60) {
                        $names[] = $name;
                    }
                }
            }
        }

        // Match names to emails by proximity in HTML or by name-email pattern
        foreach ($emails as $email) {
            // Skip generic emails
            $emailLower = strtolower($email);
            if (preg_match('/^(info|admin|city|council|clerk|reception|general)@/i', $email)) continue;

            // Try to find a name associated with this email
            $associatedName = $this->findNameNearEmail($html, $email, $names);

            if ($associatedName) {
                $role = $this->detectRoleFromContext($html, $associatedName, $email);
                $phone = $this->findPhoneNearName($html, $associatedName);

                $contacts[] = [
                    'name' => $associatedName,
                    'email' => $email,
                    'phone' => $phone,
                    'role' => $role,
                ];
            }
        }

        // Fallback: if we found emails but no name matches, try table parsing
        if (empty($contacts)) {
            $contacts = $this->parseCouncilTable($html);
        }

        return $contacts;
    }

    /**
     * Find a person name near an email address in the HTML
     */
    private function findNameNearEmail(string $html, string $email, array $knownNames): ?string {
        $emailPos = strpos($html, $email);
        if ($emailPos === false) return null;

        // Look in a 500 char window around the email
        $start = max(0, $emailPos - 500);
        $context = substr($html, $start, 1000);
        $contextText = $this->stripHtml($context);

        // Check if any known name appears near this email
        foreach ($knownNames as $name) {
            if (stripos($contextText, $name) !== false) {
                return $name;
            }
        }

        // Try to extract a name from the email address itself
        // e.g., CouncillorBeil@parksville.ca -> look for "Beil" in known names
        if (preg_match('/(?:councillor|mayor)?([a-z]+)@/i', $email, $m)) {
            $emailName = $m[1];
            foreach ($knownNames as $name) {
                if (stripos($name, $emailName) !== false) {
                    return $name;
                }
            }
        }

        // Try to find a capitalized name in the context
        if (preg_match('/([A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)/', $contextText, $m)) {
            $candidate = trim($m[1]);
            if (strlen($candidate) > 4 && strlen($candidate) < 60) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Detect if someone is Mayor or Councillor from surrounding HTML context
     */
    private function detectRoleFromContext(string $html, string $name, string $email): string {
        $pos = strpos($html, $name) ?: strpos($html, $email);
        if ($pos === false) return 'Councillor';

        $start = max(0, $pos - 200);
        $context = strtolower(substr($html, $start, 400 + strlen($name)));

        if (strpos($context, 'mayor') !== false) return 'Mayor';
        return 'Councillor';
    }

    /**
     * Find a phone number near a person's name in the HTML
     */
    private function findPhoneNearName(string $html, string $name): string {
        $pos = strpos($html, $name);
        if ($pos === false) return '';

        $context = substr($html, $pos, 500);
        if (preg_match('/(\(?\d{3}\)?[\s.-]\d{3}[\s.-]\d{4})/', $context, $m)) {
            return $this->cleanPhone($m[1]);
        }
        return '';
    }

    /**
     * Parse a council page that uses a table format
     */
    private function parseCouncilTable(string $html): array {
        $contacts = [];

        if (preg_match_all('/<tr[^>]*>(.*?)<\/tr>/si', $html, $rows)) {
            foreach ($rows[1] as $row) {
                if (strpos($row, '<th') !== false) continue;

                $email = '';
                if (preg_match('/mailto:([^"\'>\s]+)/i', $row, $em)) {
                    $email = trim($em[1]);
                    if (preg_match('/^(info|admin|city|council|clerk)@/i', $email)) continue;
                }

                $name = '';
                $cells = [];
                if (preg_match_all('/<td[^>]*>(.*?)<\/td>/si', $row, $cellMatches)) {
                    $cells = $cellMatches[1];
                }
                if (!empty($cells)) {
                    $name = trim($this->stripHtml($cells[0]));
                }

                if ($name && $email && strlen($name) > 2) {
                    $role = 'Councillor';
                    if (stripos($row, 'mayor') !== false || stripos($name, 'mayor') !== false) {
                        $name = preg_replace('/^Mayor\s+/i', '', $name);
                        $role = 'Mayor';
                    }
                    $name = preg_replace('/^Councillor\s+/i', '', $name);

                    $contacts[] = [
                        'name' => $name,
                        'email' => $email,
                        'phone' => '',
                        'role' => $role,
                    ];
                }
            }
        }

        return $contacts;
    }

    /**
     * Match a scraped contact to an existing DB official and update email
     */
    private function matchAndUpdateEmail(array $contact, string $municipality): bool {
        $name = $contact['name'];

        // Try exact name match within the municipality
        $stmt = $this->db->prepare(
            'SELECT id, name, email FROM elected_officials
             WHERE government_level = ? AND jurisdiction_name LIKE ? AND name = ?'
        );
        $stmt->execute(['municipal', "%{$municipality}%", $name]);
        $existing = $stmt->fetch();

        // Fuzzy match by last name
        if (!$existing) {
            $parts = preg_split('/\s+/', $name);
            $lastName = end($parts);
            $stmt = $this->db->prepare(
                'SELECT id, name, email FROM elected_officials
                 WHERE government_level = ? AND jurisdiction_name LIKE ? AND last_name = ?'
            );
            $stmt->execute(['municipal', "%{$municipality}%", $lastName]);
            $existing = $stmt->fetch();
        }

        if ($existing) {
            $sets = ['email = ?'];
            $params = [$contact['email']];

            if (!empty($contact['phone'])) {
                $sets[] = 'phone = COALESCE(NULLIF(?, ""), phone)';
                $params[] = $contact['phone'];
            }

            $sets[] = 'source_name = ?';
            $params[] = 'represent_api+municipal_website';
            $sets[] = 'confidence_score = LEAST(confidence_score + 1, 3)';
            $sets[] = 'verified_at = NOW()';
            $params[] = $existing['id'];

            $stmt = $this->db->prepare(
                'UPDATE elected_officials SET ' . implode(', ', $sets) . ' WHERE id = ?'
            );
            $stmt->execute($params);

            $this->writeLog("    Matched: {$name} -> {$contact['email']}");
            return true;
        } else {
            // Insert as new official from municipal website
            $nameParts = $this->splitName($name);
            $municipalityId = $this->findMunicipalityId($municipality);

            $stmt = $this->db->prepare(
                'INSERT INTO elected_officials
                    (government_level, jurisdiction_name, municipality_id, name, first_name, last_name,
                     role, email, phone, source_url, source_name, confidence_score)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE email = VALUES(email), phone = COALESCE(NULLIF(VALUES(phone), ""), phone),
                    source_name = VALUES(source_name), updated_at = NOW()'
            );
            $stmt->execute([
                'municipal', $municipality, $municipalityId, $name,
                $nameParts['first'], $nameParts['last'],
                $contact['role'] ?? 'Councillor',
                $contact['email'], $contact['phone'] ?? '',
                self::COUNCIL_PAGES[$municipality] ?? '', 'municipal_website'
            ]);

            $this->officialsInserted++;
            $this->officialsFound++;
            $this->writeLog("    New from website: {$name} ({$contact['email']})");
            return true;
        }
    }

    /**
     * Upsert an official from Represent API
     */
    private function upsertOfficial(array $data, string $sourceName): void {
        $name = $data['name'];
        $jurisdiction = $data['jurisdiction'];
        $level = 'municipal';

        $stmt = $this->db->prepare(
            'SELECT id FROM elected_officials
             WHERE name = ? AND jurisdiction_name = ? AND government_level = ?'
        );
        $stmt->execute([$name, $jurisdiction, $level]);
        $existing = $stmt->fetchColumn();

        if ($existing) {
            $stmt = $this->db->prepare(
                'UPDATE elected_officials SET
                    first_name = ?, last_name = ?, role = ?,
                    phone = COALESCE(NULLIF(?, ""), phone),
                    photo_url = COALESCE(NULLIF(?, ""), photo_url),
                    source_url = ?, updated_at = NOW()
                 WHERE id = ?'
            );
            $stmt->execute([
                $data['first_name'], $data['last_name'], $data['role'],
                $data['phone'], $data['photo_url'],
                $data['source_url'], $existing
            ]);
            $this->officialsUpdated++;
        } else {
            $stmt = $this->db->prepare(
                'INSERT INTO elected_officials
                    (government_level, jurisdiction_name, municipality_id, name, first_name, last_name,
                     role, email, phone, photo_url, source_url, source_name, confidence_score)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
            );
            $municipalityId = $this->findMunicipalityId($jurisdiction);
            $stmt->execute([
                $level, $jurisdiction, $municipalityId, $name,
                $data['first_name'], $data['last_name'], $data['role'],
                $data['email'], $data['phone'], $data['photo_url'],
                $data['source_url'], $sourceName
            ]);
            $this->officialsInserted++;
        }
    }

    private function findMunicipalityId(string $name): ?int {
        $searchName = preg_replace('/^(City|District|Town|Village|Resort Municipality)\s+of\s+/i', '', $name);
        $searchName = trim($searchName);

        $stmt = $this->db->prepare(
            'SELECT id FROM municipalities WHERE name LIKE ? OR name LIKE ? LIMIT 1'
        );
        $stmt->execute(["%{$searchName}%", "%{$name}%"]);
        $result = $stmt->fetchColumn();

        return $result ? (int) $result : null;
    }

    private function cleanPhone(string $phone): string {
        if (!$phone) return '';
        // Remove country code prefix
        $phone = preg_replace('/^1\s+/', '', $phone);
        return trim($phone);
    }

    private function splitName(string $name): array {
        $parts = preg_split('/\s+/', trim($name));
        if (count($parts) === 1) {
            return ['first' => '', 'last' => $parts[0]];
        }
        $last = array_pop($parts);
        return ['first' => implode(' ', $parts), 'last' => $last];
    }

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
        file_put_contents($this->logFile, "[{$timestamp}] [LocalGov] {$message}\n", FILE_APPEND);
    }
}
