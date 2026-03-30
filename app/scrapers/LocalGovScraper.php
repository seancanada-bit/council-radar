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
        'Cranbrook' => 'https://cranbrook.ca/our-city/mayor-and-council/meet-our-councillors',
        'Colwood' => 'https://www.colwood.ca/local-government/mayor-council/council-profiles',
        'Smithers' => 'https://www.smithers.ca/node/490',
        'Quesnel' => 'https://www.quesnel.ca/city-hall/mayor-council/contact-council',
        'Trail' => 'https://trail.ca/en/inside-city-hall/mayor-and-council.aspx',
        'Revelstoke' => 'https://revelstoke.ca/191/City-Council',
        'Nanaimo' => 'https://www.nanaimo.ca/your-government/city-council/contact-mayor-and-council',
        'Victoria' => 'https://www.victoria.ca/city-government/mayor-council/members-council',
        'Kelowna' => 'https://www.kelowna.ca/city-hall/contact-us/general-inquiries-15',
        'Mackenzie' => 'https://districtofmackenzie.ca/government-town-hall/council/',
        'Stewart' => 'https://districtofstewart.com/district-hall/mayors-office/contact',
        'Houston' => 'https://www.houston.ca/contact',
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

                // Skip junk entries
                $nameLower = strtolower($contact['name']);
                if (strlen($contact['name']) < 4
                    || preg_match('/^(and |the |community |city |town |district |info|admin|general|clerk)/i', $contact['name'])
                    || preg_match('/^(info|admin|city|council|clerk|reception|general|foi|cityhall|mayorandcouncil|mayor\.council)@/i', $contact['email'])) {
                    continue;
                }

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

        // Strategy 1: "Mayor/Councillor Name: mailto:email" inline pattern (Revelstoke style)
        // e.g. "Councillor Matt Cherry: <a href="mailto:mcherry@revelstoke.ca">..."
        if (preg_match_all('/(?:Mayor|Councillor|Councilor)\s+([A-Z][a-z]+(?:\s+[A-Z]\.?\s*)?[A-Za-z]+(?:\s+[A-Za-z]+)*)[\s:]*<a[^>]+mailto:([^"\'>\s]+)/si', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $name = trim($m[1]);
                $email = trim($m[2]);
                $role = (stripos($m[0], 'Mayor') !== false) ? 'Mayor' : 'Councillor';
                if ($this->isValidContact($name, $email)) {
                    $contacts[] = ['name' => $name, 'email' => $email, 'phone' => '', 'role' => $role];
                }
            }
        }

        // Strategy 2: "MAYOR/COUNCILLOR NAME" heading followed by mailto nearby
        // e.g. "<strong>MAYOR DOUG O'BRIEN</strong>...mailto:mayor@parksville.ca"
        if (empty($contacts)) {
            if (preg_match_all('/<(?:h[1-6]|strong|b)[^>]*>\s*(?:MAYOR|COUNCILLOR|Mayor|Councillor)\s+(.*?)\s*<\/(?:h[1-6]|strong|b)>/si', $html, $headingMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($headingMatches as $hm) {
                    $name = trim($this->stripHtml($hm[1][0]));
                    $offset = $hm[0][1];
                    $role = (stripos($hm[0][0], 'MAYOR') !== false || stripos($hm[0][0], 'Mayor') !== false) ? 'Mayor' : 'Councillor';

                    // Look for mailto within 500 chars after the heading
                    $after = substr($html, $offset, 800);
                    if (preg_match('/mailto:([^"\'>\s]+)/i', $after, $em)) {
                        $email = trim($em[1]);
                        if ($this->isValidContact($name, $email)) {
                            $phone = '';
                            if (preg_match('/(\(?\d{3}\)?[\s.-]\d{3}[\s.-]\d{4})/', $after, $pm)) {
                                $phone = $this->cleanPhone($pm[1]);
                            }
                            $contacts[] = ['name' => $name, 'email' => $email, 'phone' => $phone, 'role' => $role];
                        }
                    }
                }
            }
        }

        // Strategy 2b: <strong>Name</strong> followed by nearby mailto (Nanaimo style)
        // No Mayor/Councillor prefix required - just bold name + email
        // Tracks used emails to prevent one email being assigned to multiple names
        if (empty($contacts)) {
            $usedEmails = [];
            if (preg_match_all('/<(?:strong|b)[^>]*>\s*([A-Z][a-z]+(?:\s+[A-Z]\'?[a-z]+)+)\s*<\/(?:strong|b)>/si', $html, $boldNames, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($boldNames as $bn) {
                    $name = trim($this->stripHtml($bn[1][0]));
                    $offset = $bn[0][1];

                    // Look for the CLOSEST mailto after the bold name
                    // But stop if we hit another bold name first (to avoid crossing into next person)
                    $after = substr($html, $offset + strlen($bn[0][0]), 500);

                    // Truncate at the next <strong> or <b> to avoid crossing boundaries
                    if (preg_match('/<(?:strong|b)[^>]*>/i', $after, $nextBold, PREG_OFFSET_CAPTURE)) {
                        $after = substr($after, 0, $nextBold[0][1]);
                    }

                    $email = '';
                    if (preg_match('/mailto:([^"\'>\s]+)/i', $after, $em)) {
                        $email = trim($em[1]);
                    }
                    // Check for reversed email text (Mackenzie style spam protection)
                    if (!$email) {
                        if (preg_match('/([a-z]{2}\.[a-z.]+@[a-z]+)/i', $after, $rev)) {
                            $decoded = $this->decodeReversedEmail($rev[1]);
                            if ($decoded) $email = $decoded;
                        }
                    }
                    if ($email) {
                        if (!isset($usedEmails[$email]) && $this->isValidContact($name, $email)) {
                            $usedEmails[$email] = true;
                            $before = substr($html, max(0, $offset - 200), 200);
                            $role = (stripos($before, 'mayor') !== false || stripos($after, 'mayor') !== false) ? 'Mayor' : 'Councillor';
                            $phone = '';
                            if (preg_match('/(\(?\d{3}\)?[\s.-]\d{3}[\s.-]\d{4})/', $after, $pm)) {
                                $phone = $this->cleanPhone($pm[1]);
                            }
                            $contacts[] = ['name' => $name, 'email' => $email, 'phone' => $phone, 'role' => $role];
                        }
                    }
                }
            }
        }

        // Strategy 3: Table rows with name and mailto in same row
        if (empty($contacts)) {
            if (preg_match_all('/<tr[^>]*>(.*?)<\/tr>/si', $html, $rows)) {
                foreach ($rows[1] as $row) {
                    if (strpos($row, '<th') !== false) continue;
                    if (!preg_match('/mailto:([^"\'>\s]+)/i', $row, $em)) continue;

                    $email = trim($em[1]);
                    if (!$this->isValidEmail($email)) continue;

                    // Get first cell text as name
                    if (preg_match('/<td[^>]*>(.*?)<\/td>/si', $row, $cell)) {
                        $name = trim($this->stripHtml($cell[1]));
                        $name = preg_replace('/^(?:Mayor|Councillor|Councilor)\s+/i', '', $name);
                        $role = (stripos($row, 'mayor') !== false) ? 'Mayor' : 'Councillor';

                        if ($this->isValidContact($name, $email)) {
                            $phone = '';
                            if (preg_match('/(\(?\d{3}\)?[\s.-]\d{3}[\s.-]\d{4})/', $row, $pm)) {
                                $phone = $this->cleanPhone($pm[1]);
                            }
                            $contacts[] = ['name' => $name, 'email' => $email, 'phone' => $phone, 'role' => $role];
                        }
                    }
                }
            }
        }

        // Strategy 4: Name as link text with mailto nearby in same container
        // e.g. <a href="/profile">Name</a> ... <a href="mailto:email">
        if (empty($contacts)) {
            // Split page into sections by common delimiters
            $sections = preg_split('/<(?:hr|\/section|\/article|\/div>\s*<div)/i', $html);
            foreach ($sections as $section) {
                $sectionNames = [];
                $sectionEmails = [];

                // Find names with title prefixes
                if (preg_match_all('/(?:Mayor|Councillor|Councilor)\s+([A-Z][a-z]+(?:\s+[A-Z]\.?\s*)?[A-Za-z]+(?:\s+[A-Za-z]+)*)/i', $section, $nm)) {
                    $sectionNames = $nm[1];
                }

                // Find emails
                if (preg_match_all('/mailto:([^"\'>\s]+)/i', $section, $em)) {
                    $sectionEmails = $em[1];
                }

                // If we have exactly one name and one email in this section, pair them
                if (count($sectionNames) === 1 && count($sectionEmails) === 1) {
                    $name = trim($sectionNames[0]);
                    $email = trim($sectionEmails[0]);
                    if ($this->isValidContact($name, $email)) {
                        $contacts[] = ['name' => $name, 'email' => $email, 'phone' => '', 'role' => 'Councillor'];
                    }
                }
            }
        }

        // Strategy 5: Paired by email username matching known names on page
        // Extract all person names and all emails, match by email prefix
        if (empty($contacts)) {
            $allEmails = [];
            if (preg_match_all('/mailto:([^"\'>\s]+)/i', $html, $em)) {
                $allEmails = array_unique($em[1]);
            }

            $allNames = [];
            if (preg_match_all('/(?:Mayor|Councillor|Councilor)\s+([A-Z][a-z]+(?:\s+[A-Z]\.?\s*)?[A-Za-z]+(?:\s+[A-Za-z]+)*)/i', $html, $nm)) {
                $allNames = array_unique($nm[1]);
            }

            foreach ($allEmails as $email) {
                if (!$this->isValidEmail($email)) continue;

                // Try to match email prefix to a name
                // e.g. dkobayashi@colwood.ca -> Doug Kobayashi
                $prefix = strtolower(explode('@', $email)[0]);
                foreach ($allNames as $name) {
                    $parts = preg_split('/\s+/', $name);
                    $lastName = strtolower(end($parts));
                    $firstName = strtolower($parts[0]);
                    $firstInit = substr($firstName, 0, 1);

                    // Match patterns: flast, firstlast, first.last, councillorLast
                    if ($prefix === $firstInit . $lastName
                        || $prefix === $firstName . '.' . $lastName
                        || $prefix === $firstName . $lastName
                        || $prefix === 'councillor' . $lastName
                        || $prefix === 'mayor') {
                        $role = ($prefix === 'mayor' || stripos($html, 'Mayor ' . $name) !== false) ? 'Mayor' : 'Councillor';
                        $contacts[] = ['name' => $name, 'email' => $email, 'phone' => '', 'role' => $role];
                        break;
                    }
                }
            }
        }

        return $contacts;
    }

    /**
     * Check if a name looks like a real person (not an org or junk)
     */
    private function isValidContact(string $name, string $email): bool {
        if (strlen($name) < 4 || strlen($name) > 60) return false;
        if (!$this->isValidEmail($email)) return false;
        // Reject organization-like names
        if (preg_match('/^(and |the |community |city |town |district |bulkley|school|society|association)/i', $name)) return false;
        // Must have at least two words (first + last)
        if (str_word_count($name) < 2) return false;
        return true;
    }

    /**
     * Decode reversed/obfuscated email addresses
     * Some municipalities reverse emails as spam protection
     * e.g. "ac.eiznekcamfotcirtsid@naoj" -> "joan@districtofmackenzie.ca"
     */
    private function decodeReversedEmail(string $text): ?string {
        // Check if it looks like a reversed email (has @ and ends with known reversed TLDs)
        $reversed = strrev($text);
        if (filter_var($reversed, FILTER_VALIDATE_EMAIL)) {
            return $reversed;
        }
        return null;
    }

    /**
     * Check if an email is a personal address (not generic)
     */
    private function isValidEmail(string $email): bool {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
        if (preg_match('/^(info|admin|city|council|clerk|reception|general|foi|cityhall|mayorandcouncil|mayor\.council|communications|finance|planning|engineering|hr|webmaster|postmaster)@/i', $email)) return false;
        return true;
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
