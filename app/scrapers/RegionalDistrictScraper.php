<?php
/**
 * Scraper for BC Regional District board members
 *
 * Each of BC's 27 regional districts has a board composed of:
 *   - Electoral Area Directors (directly elected by voters in unincorporated areas)
 *   - Municipal Directors (appointed by member municipality councils, usually mayors)
 *
 * We already have mayors/councillors from the LocalGovScraper. This scraper
 * focuses on Electoral Area Directors, who aren't in any other source.
 * Municipal directors are stored too for completeness but marked as such.
 *
 * Source: Individual RD websites (board of directors pages)
 * CivicInfo BC is Cloudflare-blocked from server-side PHP.
 */

require_once __DIR__ . '/BaseScraper.php';

class RegionalDistrictScraper extends BaseScraper {

    // Board of directors page URLs for regional districts covering our 16 municipalities
    // Format: 'RD Name' => ['url' => board page, 'municipalities' => covered municipalities]
    private const REGIONAL_DISTRICTS = [
        'Regional District of Nanaimo' => [
            'url' => 'https://rdn.bc.ca/regional-board',
            'municipalities' => ['Parksville', 'Nanaimo'],
        ],
        'Thompson-Nicola Regional District' => [
            'url' => 'https://tnrd.civicweb.net/portal/members.aspx?id=25',
            'municipalities' => ['Kamloops', 'Clearwater', 'Sun Peaks'],
        ],
        'Regional District of East Kootenay' => [
            'url' => 'https://www.rdek.bc.ca/about/board_of_directors',
            'municipalities' => ['Cranbrook'],
        ],
        'Capital Regional District' => [
            'url' => 'https://www.crd.ca/government-administration/boards-committees/board-directors',
            'pages' => 3,
            'municipalities' => ['Colwood', 'Victoria'],
        ],
        'Regional District of Central Okanagan' => [
            'url' => 'https://www.rdco.com/your-government/regional-board/',
            'municipalities' => ['Kelowna'],
        ],
        'Regional District of Bulkley-Nechako' => [
            'url' => 'https://www.rdbn.bc.ca/departments/administration/board-of-directors',
            'municipalities' => ['Smithers', 'Houston'],
        ],
        'Cariboo Regional District' => [
            'url' => 'https://www.cariboord.ca/contacts-directory/',
            'municipalities' => ['Quesnel'],
        ],
        'Regional District of Kootenay Boundary' => [
            'url' => 'https://rdkb.com/Regional-Government/Who-we-are-what-we-do/Board-of-Directors',
            'municipalities' => ['Trail'],
        ],
        'Columbia Shuswap Regional District' => [
            'url' => 'https://www.csrd.bc.ca/344/Board-of-Directors',
            'municipalities' => ['Revelstoke'],
        ],
        'Regional District of Fraser-Fort George' => [
            'url' => 'https://www.rdffg.ca/government/board-directors/members',
            'municipalities' => ['Mackenzie'],
        ],
        'Kitimat-Stikine Regional District' => [
            'url' => 'https://www.rdks.bc.ca/government/board',
            'municipalities' => ['Stewart'],
        ],
    ];

    private string $logFile;
    private int $officialsFound = 0;
    private int $officialsInserted = 0;
    private int $officialsUpdated = 0;

    public function __construct() {
        parent::__construct();
        $this->logFile = __DIR__ . '/../../logs/officials_rd.log';
    }

    public function scrapeAll(): array {
        $startTime = microtime(true);
        $this->writeLog("Starting regional district board scrape");

        try {
            foreach (self::REGIONAL_DISTRICTS as $rdName => $config) {
                $this->scrapeRegionalDistrict($rdName, $config);
            }

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $this->logOfficialsScrape('regional_district_websites', 'regional_district', 'success', $durationMs);
            $this->writeLog("Complete: {$this->officialsFound} found, {$this->officialsInserted} inserted, {$this->officialsUpdated} updated ({$durationMs}ms)");

            return [
                'officials_found' => $this->officialsFound,
                'officials_inserted' => $this->officialsInserted,
                'officials_updated' => $this->officialsUpdated,
            ];

        } catch (\Exception $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $this->logOfficialsScrape('regional_district_websites', 'regional_district', 'error', $durationMs, $e->getMessage());
            $this->writeLog("ERROR: " . $e->getMessage());
            throw $e;
        }
    }

    public function scrapeMunicipality(array $muni): array {
        return [];
    }

    /**
     * Scrape a single regional district board page (handles pagination)
     */
    private function scrapeRegionalDistrict(string $rdName, array $config): void {
        $url = $config['url'];
        $pages = $config['pages'] ?? 1;
        $this->writeLog("Scraping: {$rdName}" . ($pages > 1 ? " ({$pages} pages)" : ''));

        $allDirectors = [];

        for ($page = 0; $page < $pages; $page++) {
            $pageUrl = $pages > 1 ? $url . '?page=' . $page : $url;

            $this->rateLimit();
            $result = $this->fetch($pageUrl);

            if ($result['error']) {
                $this->writeLog("  Failed" . ($pages > 1 ? " (page $page)" : '') . ": " . $result['error']);
                if ($page === 0) return; // First page failed, skip entirely
                continue;
            }

            $directors = $this->parseBoardPage($result['body'], $rdName);
            $allDirectors = array_merge($allDirectors, $directors);
        }

        $this->writeLog("  Found " . count($allDirectors) . " director(s)");

        foreach ($allDirectors as $director) {
            $this->upsertDirector($director, $rdName);
        }
    }

    /**
     * Parse a board of directors page using multiple strategies
     */
    private function parseBoardPage(string $html, string $rdName): array {
        $directors = [];

        // Strategy 1: Table rows with Electoral Area / Municipality columns
        $directors = $this->parseFromTable($html);

        // Strategy 2: Inline "Director, Electoral Area X" pattern (RDN style)
        if (empty($directors)) {
            $directors = $this->parseInlinePattern($html);
        }

        // Strategy 3: Headings or strong tags with "Electoral Area" followed by names
        if (empty($directors)) {
            $directors = $this->parseHeadingPattern($html);
        }

        // Strategy 4: Links containing "electoral-area" in href with name as link text
        if (empty($directors)) {
            $directors = $this->parseLinkPattern($html);
        }

        // Strategy 5: Email-based extraction (fallback)
        if (empty($directors)) {
            $directors = $this->parseFromEmails($html);
        }

        // For all found directors, try to extract emails
        foreach ($directors as &$dir) {
            if (empty($dir['email'])) {
                $dir['email'] = $this->findEmailForDirector($html, $dir['name']);
            }
        }
        unset($dir);

        return $directors;
    }

    /**
     * Strategy 1: Parse from HTML table
     * Looks for tables with columns like Name | Area/Municipality | Phone | Email
     */
    private function parseFromTable(string $html): array {
        $directors = [];

        // Find tables that contain "Electoral Area" text
        if (!preg_match_all('/<table[^>]*>(.*?)<\/table>/si', $html, $tables)) {
            return [];
        }

        foreach ($tables[1] as $table) {
            if (stripos($table, 'Electoral Area') === false && stripos($table, 'Director') === false) {
                continue;
            }

            if (!preg_match_all('/<tr[^>]*>(.*?)<\/tr>/si', $table, $rows)) {
                continue;
            }

            foreach ($rows[1] as $row) {
                if (strpos($row, '<th') !== false) continue;

                $cells = [];
                if (preg_match_all('/<td[^>]*>(.*?)<\/td>/si', $row, $cellMatches)) {
                    $cells = array_map(function ($c) {
                        return trim($this->stripHtml($c));
                    }, $cellMatches[1]);
                }

                if (count($cells) < 2) continue;

                $name = $cells[0];
                $area = $cells[1] ?? '';
                $email = '';
                $phone = '';

                // Check for mailto in the row
                if (preg_match('/mailto:([^"\'>\s]+)/i', $row, $em)) {
                    $email = trim($em[1]);
                }

                // Check for phone
                if (preg_match('/(\(?\d{3}\)?[\s.-]\d{3}[\s.-]\d{4})/', $row, $pm)) {
                    $phone = trim($pm[1]);
                }

                // Determine if electoral area or municipal director
                // Patterns: "Electoral Area X", "(A) Name Rural", "Area X"
                $isElectoral = (bool) preg_match('/Electoral\s+Area|^\([A-Z]\)\s|^Area\s+[A-Z]/i', $area);
                $role = $isElectoral ? 'Electoral Area Director' : 'Municipal Director';

                // Clean "Director" or "Mayor" prefix from name
                $name = preg_replace('/^(?:Director|Mayor|Councillor|Alternate)\s+/i', '', $name);

                if ($this->isValidDirectorName($name)) {
                    // Re-check role based on name prefix
                    if (preg_match('/^Mayor\s/i', $cells[0] ?? '')) {
                        $role = 'Municipal Director';
                    }

                    $directors[] = [
                        'name' => $name,
                        'area' => $area,
                        'role' => $role,
                        'email' => $email,
                        'phone' => $phone,
                        'is_electoral' => $isElectoral,
                    ];
                }
            }
        }

        return $directors;
    }

    /**
     * Strategy 2: Inline "Director, Electoral Area X" pattern
     * e.g. "Director, Electoral Area H" near a name
     */
    private function parseInlinePattern(string $html): array {
        $directors = [];

        // Pattern: Name followed by "Director, Electoral Area X"
        if (preg_match_all('/(?:<strong>|<b>)\s*([A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)\s*(?:<\/strong>|<\/b>).*?Director,?\s+Electoral\s+Area\s+([A-Z](?:\s*[-\/]\s*[A-Z])?)/si', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $name = trim($m[1]);
                $areaLetter = trim($m[2]);
                if ($this->isValidDirectorName($name)) {
                    $directors[] = [
                        'name' => $name,
                        'area' => "Electoral Area $areaLetter",
                        'role' => 'Electoral Area Director',
                        'email' => '',
                        'phone' => '',
                        'is_electoral' => true,
                    ];
                }
            }
        }

        // Reverse pattern: "Director, Electoral Area X" then name
        if (empty($directors)) {
            if (preg_match_all('/Director,?\s+Electoral\s+Area\s+([A-Z](?:\s*[-\/]\s*[A-Z])?).*?(?:<strong>|<b>|<a[^>]*>)\s*([A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)\s*(?:<\/strong>|<\/b>|<\/a>)/si', $html, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $areaLetter = trim($m[1]);
                    $name = trim($m[2]);
                    if ($this->isValidDirectorName($name)) {
                        $directors[] = [
                            'name' => $name,
                            'area' => "Electoral Area $areaLetter",
                            'role' => 'Electoral Area Director',
                            'email' => '',
                            'phone' => '',
                            'is_electoral' => true,
                        ];
                    }
                }
            }
        }

        // Also find municipal directors
        if (preg_match_all('/(?:<strong>|<b>)\s*([A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)\s*(?:<\/strong>|<\/b>).*?(?:City|District|Town|Village)\s+of\s+([A-Za-z\s]+?)(?:<|,|\s{2})/si', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $name = trim($m[1]);
                $muni = trim($m[2]);
                if ($this->isValidDirectorName($name) && strlen($muni) > 2) {
                    $directors[] = [
                        'name' => $name,
                        'area' => $muni,
                        'role' => 'Municipal Director',
                        'email' => '',
                        'phone' => '',
                        'is_electoral' => false,
                    ];
                }
            }
        }

        return $directors;
    }

    /**
     * Strategy 3: Heading-based parsing
     * e.g. <h3>Electoral Area A</h3> followed by name
     */
    private function parseHeadingPattern(string $html): array {
        $directors = [];

        if (preg_match_all('/<h[2-5][^>]*>[^<]*Electoral\s+Area\s+([A-Z](?:\s*[-\/]\s*[A-Z])?)[^<]*<\/h[2-5]>.*?([A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)/si', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $areaLetter = trim($m[1]);
                $name = trim($m[2]);
                if ($this->isValidDirectorName($name)) {
                    $directors[] = [
                        'name' => $name,
                        'area' => "Electoral Area $areaLetter",
                        'role' => 'Electoral Area Director',
                        'email' => '',
                        'phone' => '',
                        'is_electoral' => true,
                    ];
                }
            }
        }

        return $directors;
    }

    /**
     * Strategy 4: Links with "electoral-area" in href
     * e.g. <a href="/electoral-area-h">Stuart McLean</a>
     */
    private function parseLinkPattern(string $html): array {
        $directors = [];

        if (preg_match_all('/<a[^>]+href="[^"]*electoral[_-]area[_-]([a-z])[^"]*"[^>]*>\s*([A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)\s*<\/a>/si', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $areaLetter = strtoupper($m[1]);
                $name = trim($m[2]);
                if ($this->isValidDirectorName($name)) {
                    $directors[] = [
                        'name' => $name,
                        'area' => "Electoral Area $areaLetter",
                        'role' => 'Electoral Area Director',
                        'email' => '',
                        'phone' => '',
                        'is_electoral' => true,
                    ];
                }
            }
        }

        return $directors;
    }

    /**
     * Find an email address for a director by scanning nearby HTML
     */
    private function findEmailForDirector(string $html, string $name): string {
        // Look for mailto near the person's name
        $pos = stripos($html, $name);
        if ($pos === false) return '';

        // Check 800 chars after the name
        $after = substr($html, $pos, 800);
        if (preg_match('/mailto:([^"\'>\s]+)/i', $after, $em)) {
            $email = trim($em[1]);
            // Validate it's a personal email, not generic
            if (!preg_match('/^(info|admin|general|inquiries|office)@/i', $email)) {
                return $email;
            }
        }

        // Check for obfuscated email patterns like "name [at] domain"
        if (preg_match('/([a-z][a-z.]+)\s*(?:\[at\]|&#64;|\bat\b)\s*([a-z]+\.[a-z.]+)/i', $after, $obf)) {
            return $obf[1] . '@' . $obf[2];
        }

        return '';
    }

    /**
     * Strategy 5: Email-based extraction
     * Find all mailto links and extract names from nearby context
     * Used when other strategies fail but the page has emails
     */
    private function parseFromEmails(string $html): array {
        $directors = [];

        if (!preg_match_all('/mailto:([^"\'>\s]+)/i', $html, $emailMatches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        foreach ($emailMatches[1] as $em) {
            $email = trim($em[0]);
            $pos = $em[1];

            // Skip generic emails
            if (preg_match('/^(info|admin|general|inquiries|office|reception)@/i', $email)) continue;

            // Look backwards 300 chars for a name
            $before = substr($html, max(0, $pos - 300), min(300, $pos));
            $beforeText = $this->stripHtml($before);

            // Look for "Electoral Area X" near the email
            $context = substr($html, max(0, $pos - 500), 1000);
            $area = '';
            $isElectoral = false;

            if (preg_match('/Electoral\s+Area\s+([A-Z](?:\s*[-\/]\s*[A-Z])?)/i', $context, $am)) {
                $area = "Electoral Area " . trim($am[1]);
                $isElectoral = true;
            } elseif (preg_match('/\(([A-Z])\)\s+[A-Za-z]/i', $context, $am)) {
                $area = "Electoral Area " . $am[1];
                $isElectoral = true;
            }

            // Find the closest person name before the email
            $name = '';
            if (preg_match_all('/([A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)/', $beforeText, $names)) {
                $name = end($names[1]); // Take the closest name
            }

            if ($name && $this->isValidDirectorName($name)) {
                $role = $isElectoral ? 'Electoral Area Director' : 'Municipal Director';
                $directors[] = [
                    'name' => $name,
                    'area' => $area ?: 'Unknown',
                    'role' => $role,
                    'email' => $email,
                    'phone' => '',
                    'is_electoral' => $isElectoral,
                ];
            }
        }

        return $directors;
    }

    /**
     * Validate a director name
     */
    private function isValidDirectorName(string $name): bool {
        if (strlen($name) < 4 || strlen($name) > 60) return false;
        if (str_word_count($name) < 2) return false;
        if (preg_match('/^(Electoral|Regional|District|Board|City|Town|Village|Municipality|Agenda|Schedule|Chair|Vice)/i', $name)) return false;
        return true;
    }

    /**
     * Upsert a director into the elected_officials table
     */
    private function upsertDirector(array $director, string $rdName): void {
        $name = $director['name'];
        $jurisdiction = $rdName . ' - ' . $director['area'];
        $level = 'regional_district';

        $this->officialsFound++;

        // Check if exists
        $stmt = $this->db->prepare(
            'SELECT id FROM elected_officials
             WHERE name = ? AND jurisdiction_name = ? AND government_level = ?'
        );
        $stmt->execute([$name, $jurisdiction, $level]);
        $existing = $stmt->fetchColumn();

        $nameParts = $this->splitName($name);
        $sourceUrl = self::REGIONAL_DISTRICTS[$rdName]['url'] ?? '';

        if ($existing) {
            $stmt = $this->db->prepare(
                'UPDATE elected_officials SET
                    first_name = ?, last_name = ?, role = ?,
                    email = COALESCE(NULLIF(?, ""), email),
                    phone = COALESCE(NULLIF(?, ""), phone),
                    source_url = ?, source_name = ?, updated_at = NOW()
                 WHERE id = ?'
            );
            $stmt->execute([
                $nameParts['first'], $nameParts['last'], $director['role'],
                $director['email'], $director['phone'],
                $sourceUrl, 'rd_website', $existing
            ]);
            $this->officialsUpdated++;
            $this->writeLog("  Updated: {$name} ({$director['role']}, {$director['area']})");
        } else {
            $stmt = $this->db->prepare(
                'INSERT INTO elected_officials
                    (government_level, jurisdiction_name, name, first_name, last_name,
                     role, email, phone, source_url, source_name, confidence_score)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE
                    email = COALESCE(NULLIF(VALUES(email), ""), email),
                    phone = COALESCE(NULLIF(VALUES(phone), ""), phone),
                    updated_at = NOW()'
            );
            $stmt->execute([
                $level, $jurisdiction, $name,
                $nameParts['first'], $nameParts['last'],
                $director['role'], $director['email'], $director['phone'],
                $sourceUrl, 'rd_website'
            ]);
            $this->officialsInserted++;
            $this->writeLog("  Inserted: {$name} ({$director['role']}, {$director['area']})");
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
        file_put_contents($this->logFile, "[{$timestamp}] [RD] {$message}\n", FILE_APPEND);
    }
}
