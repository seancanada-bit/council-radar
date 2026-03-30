<?php
/**
 * Scraper for BC School Board Trustees
 *
 * Sources:
 *   1. BC School Contacts CSV (bcschoolcontacts.gov.bc.ca) — Board Chairs + district contacts
 *   2. Individual school district websites — full trustee rosters (60 different sites)
 *
 * The government CSV provides Board Chairs and district office info for all 60 districts.
 * Individual district sites provide full trustee lists but each has a unique format.
 * We maintain a config file (config/school_districts.json) mapping districts to their trustee page URLs.
 */

require_once __DIR__ . '/BaseScraper.php';

class SchoolTrusteeScraper extends BaseScraper {

    private const GOV_CSV_URL = 'https://bcschoolcontacts.gov.bc.ca/api/download/alldistrictcontacts.csv';
    private const CONFIG_FILE = __DIR__ . '/../../config/school_districts.json';

    private string $logFile;
    private int $officialsFound = 0;
    private int $officialsInserted = 0;
    private int $officialsUpdated = 0;

    public function __construct() {
        parent::__construct();
        $this->logFile = __DIR__ . '/../../logs/officials_school.log';
    }

    public function scrapeAll(): array {
        $startTime = microtime(true);
        $this->writeLog("Starting school trustee scrape");

        try {
            // Step 1: Download and parse BC Government CSV
            $csvData = $this->fetchGovernmentCSV();
            $this->writeLog("Government CSV: " . count($csvData) . " district contact records");

            // Step 2: Upsert Board Chairs from CSV
            $this->processCSVData($csvData);

            // Step 3: Load district config and scrape individual sites
            $districts = $this->loadDistrictConfig();
            $mappedCount = count(array_filter($districts, fn($d) => !empty($d['trustee_url'])));
            $this->writeLog("District config: {$mappedCount} of " . count($districts) . " have trustee URLs mapped");

            $this->scrapeDistrictSites($districts);

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $this->logOfficialsScrape('school_trustees', 'school_board', 'success', $durationMs);

            $this->writeLog("Complete: {$this->officialsFound} found, {$this->officialsInserted} inserted, {$this->officialsUpdated} updated ({$durationMs}ms)");

            return [
                'officials_found' => $this->officialsFound,
                'officials_inserted' => $this->officialsInserted,
                'officials_updated' => $this->officialsUpdated,
            ];

        } catch (\Exception $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $this->logOfficialsScrape('school_trustees', 'school_board', 'error', $durationMs, $e->getMessage());
            $this->writeLog("ERROR: " . $e->getMessage());
            throw $e;
        }
    }

    public function scrapeMunicipality(array $muni): array {
        return [];
    }

    /**
     * Download and parse the BC Government school contacts CSV
     */
    private function fetchGovernmentCSV(): array {
        $this->writeLog("Fetching government CSV...");
        $result = $this->fetch(self::GOV_CSV_URL);

        if ($result['error']) {
            $this->writeLog("Government CSV error: " . $result['error']);
            return [];
        }

        $rows = [];
        $lines = explode("\n", $result['body']);
        $headers = [];

        foreach ($lines as $i => $line) {
            $line = trim($line);
            if (!$line) continue;

            $fields = str_getcsv($line);

            if ($i === 0) {
                // Normalize headers
                $headers = array_map(fn($h) => strtolower(trim($h)), $fields);
                continue;
            }

            if (count($fields) !== count($headers)) continue;

            $row = array_combine($headers, $fields);
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Process CSV data — extract Board Chairs and district contact info
     */
    private function processCSVData(array $csvData): void {
        // Group by district number
        $byDistrict = [];
        foreach ($csvData as $row) {
            $distNum = $row['district number'] ?? $row['district_number'] ?? '';
            if ($distNum) {
                $byDistrict[$distNum][] = $row;
            }
        }

        foreach ($byDistrict as $distNum => $contacts) {
            $distName = '';
            $boardChair = null;
            $districtEmail = '';
            $districtPhone = '';
            $districtAddress = '';

            foreach ($contacts as $contact) {
                $distName = $contact['district name'] ?? $contact['district_name'] ?? $distName;
                $title = strtolower($contact['title'] ?? $contact['job title'] ?? '');

                // Extract district-level contact info
                if (empty($districtPhone) && !empty($contact['phone'])) {
                    $districtPhone = $contact['phone'];
                }
                if (empty($districtEmail) && !empty($contact['email'])) {
                    $districtEmail = $contact['email'];
                }

                // Find the Board Chair
                if (strpos($title, 'board chair') !== false || strpos($title, 'chairperson') !== false) {
                    $name = trim(($contact['first name'] ?? $contact['first_name'] ?? '') . ' ' .
                                 ($contact['last name'] ?? $contact['last_name'] ?? ''));
                    if (!$name || strlen($name) < 3) {
                        $name = $contact['name'] ?? '';
                    }

                    if ($name) {
                        $boardChair = [
                            'name' => $name,
                            'email' => $contact['email'] ?? '',
                            'phone' => $contact['phone'] ?? '',
                        ];
                    }
                }
            }

            $jurisdiction = "SD{$distNum} {$distName}";

            if ($boardChair) {
                $this->officialsFound++;
                $this->upsertOfficial([
                    'name' => $boardChair['name'],
                    'role' => 'Board Chair',
                    'email' => $boardChair['email'],
                    'phone' => $boardChair['phone'],
                    'office_address' => $districtAddress,
                ], $jurisdiction, 'gov_csv');
            }
        }
    }

    /**
     * Load the school districts config file
     */
    private function loadDistrictConfig(): array {
        if (!file_exists(self::CONFIG_FILE)) {
            $this->writeLog("District config file not found: " . self::CONFIG_FILE);
            return [];
        }

        $json = json_decode(file_get_contents(self::CONFIG_FILE), true);
        return $json['districts'] ?? [];
    }

    /**
     * Scrape individual school district websites for trustee data
     */
    private function scrapeDistrictSites(array $districts): void {
        $scraped = 0;
        $failed = 0;

        foreach ($districts as $district) {
            if (empty($district['trustee_url'])) continue;

            $jurisdiction = "SD{$district['number']} {$district['name']}";
            $this->writeLog("  Scraping {$jurisdiction}...");

            $this->rateLimit();
            $result = $this->fetchWithBackoff($district['trustee_url']);

            if ($result['error']) {
                $this->writeLog("  Failed: " . $result['error']);
                $failed++;
                continue;
            }

            $trustees = $this->parseTrusteePage($result['body'], $district);
            if (!empty($trustees)) {
                foreach ($trustees as $trustee) {
                    $this->officialsFound++;
                    $this->upsertOfficial($trustee, $jurisdiction, 'school_district');
                }
                $this->writeLog("  Found " . count($trustees) . " trustees");
                $scraped++;
            } else {
                $this->writeLog("  No trustees found on page");
                $failed++;
            }
        }

        $this->writeLog("District sites scraped: {$scraped}, failed: {$failed}");
    }

    /**
     * Parse a school district trustee page
     * Uses multiple strategies since every district site is different
     */
    private function parseTrusteePage(string $html, array $district): array {
        $trustees = [];

        // Strategy 1: Look for a trustees table
        if (preg_match_all('/<table[^>]*>.*?<\/table>/si', $html, $tables)) {
            foreach ($tables[0] as $table) {
                if (stripos($table, 'trustee') !== false || stripos($table, 'board') !== false) {
                    $trustees = $this->parseTrusteeTable($table);
                    if (!empty($trustees)) return $trustees;
                }
            }
        }

        // Strategy 2: Look for trustee cards/divs with names and roles
        if (preg_match_all('/<(?:div|article|section)[^>]*class="[^"]*(?:trustee|board-member|member|card)[^"]*"[^>]*>(.*?)<\/(?:div|article|section)>/si', $html, $cards)) {
            foreach ($cards[1] as $card) {
                $trustee = $this->parseTrusteeCard($card);
                if ($trustee) $trustees[] = $trustee;
            }
            if (!empty($trustees)) return $trustees;
        }

        // Strategy 3: Look for <h2>/<h3>/<h4> headings with names followed by role text
        if (preg_match_all('/<h[2-4][^>]*>(.*?)<\/h[2-4]>\s*(?:<[^>]*>)*\s*(?:Chair|Vice-Chair|Trustee|Board\s+Chair)/si', $html, $headings)) {
            foreach ($headings[1] as $heading) {
                $name = trim($this->stripHtml($heading));
                if ($name && strlen($name) > 2 && strlen($name) < 60) {
                    $trustees[] = ['name' => $name, 'role' => 'Trustee', 'email' => '', 'phone' => ''];
                }
            }
            if (!empty($trustees)) return $trustees;
        }

        // Strategy 4: Look for linked names near "trustee" or "board" text
        if (preg_match_all('/<a[^>]*>(.*?)<\/a>/si', $html, $links)) {
            $inTrusteeSection = false;
            $fullHtml = $html;

            // Check if we're in a section about trustees
            if (stripos($fullHtml, 'Board of Education') !== false || stripos($fullHtml, 'Board of Trustees') !== false) {
                foreach ($links[1] as $linkText) {
                    $name = trim($this->stripHtml($linkText));
                    // Filter: names are typically 2-4 words, no URLs, no common non-name words
                    if ($name && preg_match('/^[A-Z][a-z]+ [A-Z][a-z]+/', $name) && strlen($name) < 50) {
                        $trustees[] = ['name' => $name, 'role' => 'Trustee', 'email' => '', 'phone' => ''];
                    }
                }
            }
        }

        // Deduplicate by name
        $seen = [];
        $unique = [];
        foreach ($trustees as $t) {
            $key = strtolower($t['name']);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $t;
            }
        }

        // Infer Chair/Vice-Chair roles from context
        foreach ($unique as &$t) {
            if (preg_match('/\b' . preg_quote($t['name'], '/') . '\b[^<]{0,50}(?:Vice[\s-]?Chair)/si', $html)) {
                $t['role'] = 'Vice-Chair';
            } elseif (preg_match('/\b' . preg_quote($t['name'], '/') . '\b[^<]{0,50}(?:Chair(?!person))/si', $html)) {
                $t['role'] = 'Board Chair';
            }
        }

        return $unique;
    }

    /**
     * Parse trustees from an HTML table
     */
    private function parseTrusteeTable(string $tableHtml): array {
        $trustees = [];
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/si', $tableHtml, $rows);

        foreach ($rows[1] as $row) {
            if (strpos($row, '<th') !== false) continue;

            preg_match_all('/<td[^>]*>(.*?)<\/td>/si', $row, $cells);
            if (empty($cells[1])) continue;

            $name = trim($this->stripHtml($cells[1][0]));
            $role = isset($cells[1][1]) ? trim($this->stripHtml($cells[1][1])) : 'Trustee';
            $email = '';

            // Look for mailto in any cell
            foreach ($cells[1] as $cell) {
                if (preg_match('/mailto:([^"\'>\s]+)/i', $cell, $emailMatch)) {
                    $email = trim($emailMatch[1]);
                    break;
                }
            }

            if ($name && strlen($name) > 2) {
                $trustees[] = [
                    'name' => $name,
                    'role' => $role ?: 'Trustee',
                    'email' => $email,
                    'phone' => '',
                ];
            }
        }

        return $trustees;
    }

    /**
     * Parse a single trustee card/div
     */
    private function parseTrusteeCard(string $cardHtml): ?array {
        $name = '';
        $role = 'Trustee';
        $email = '';

        // Name from heading
        if (preg_match('/<h[2-5][^>]*>(.*?)<\/h[2-5]>/si', $cardHtml, $heading)) {
            $name = trim($this->stripHtml($heading[1]));
        }

        // Role
        if (stripos($cardHtml, 'Vice-Chair') !== false || stripos($cardHtml, 'Vice Chair') !== false) {
            $role = 'Vice-Chair';
        } elseif (stripos($cardHtml, 'Chair') !== false) {
            $role = 'Board Chair';
        }

        // Email
        if (preg_match('/mailto:([^"\'>\s]+)/i', $cardHtml, $emailMatch)) {
            $email = trim($emailMatch[1]);
        }

        if (!$name || strlen($name) < 3) return null;

        return [
            'name' => $name,
            'role' => $role,
            'email' => $email,
            'phone' => '',
        ];
    }

    /**
     * Upsert a school trustee
     */
    private function upsertOfficial(array $data, string $jurisdiction, string $sourceName): void {
        $name = $data['name'];

        $stmt = $this->db->prepare(
            'SELECT id, source_name FROM elected_officials
             WHERE name = ? AND jurisdiction_name = ? AND government_level = ?'
        );
        $stmt->execute([$name, $jurisdiction, 'school_board']);
        $existing = $stmt->fetch();

        $nameParts = $this->splitName($name);

        if ($existing) {
            // If this is a different source confirming the same person, bump confidence
            if ($existing['source_name'] !== $sourceName) {
                $verifyStmt = $this->db->prepare(
                    'INSERT INTO official_verifications
                        (official_id, source_name, source_url, fields_matched)
                     VALUES (?, ?, ?, ?)'
                );
                $verifyStmt->execute([
                    $existing['id'], $sourceName, self::GOV_CSV_URL,
                    json_encode(['name' => true]),
                ]);

                $bumpStmt = $this->db->prepare(
                    'UPDATE elected_officials SET confidence_score = LEAST(confidence_score + 1, 3), verified_at = NOW() WHERE id = ?'
                );
                $bumpStmt->execute([$existing['id']]);
            }

            $stmt = $this->db->prepare(
                'UPDATE elected_officials SET
                    first_name = ?, last_name = ?, role = ?,
                    email = COALESCE(NULLIF(?, ""), email),
                    phone = COALESCE(NULLIF(?, ""), phone),
                    updated_at = NOW()
                 WHERE id = ?'
            );
            $stmt->execute([
                $nameParts['first'], $nameParts['last'], $data['role'],
                $data['email'] ?? '', $data['phone'] ?? '',
                $existing['id']
            ]);
            $this->officialsUpdated++;
        } else {
            $stmt = $this->db->prepare(
                'INSERT INTO elected_officials
                    (government_level, jurisdiction_name, name, first_name, last_name,
                     role, email, phone, office_address, source_url, source_name, confidence_score)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
            );
            $stmt->execute([
                'school_board', $jurisdiction, $name,
                $nameParts['first'], $nameParts['last'], $data['role'],
                $data['email'] ?? '', $data['phone'] ?? '', $data['office_address'] ?? '',
                self::GOV_CSV_URL, $sourceName
            ]);
            $this->officialsInserted++;
        }
    }

    private function splitName(string $name): array {
        $parts = preg_split('/\s+/', trim($name));
        if (count($parts) === 1) {
            return ['first' => '', 'last' => $parts[0]];
        }
        $last = array_pop($parts);
        return ['first' => implode(' ', $parts), 'last' => $last];
    }

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
        file_put_contents($this->logFile, "[{$timestamp}] [School] {$message}\n", FILE_APPEND);
    }
}
