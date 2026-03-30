<?php
/**
 * Scraper for BC Local Government elected officials
 *
 * Sources:
 *   1. CivicInfo BC (civicinfo.bc.ca) — municipalities + regional districts
 *   2. Represent API (represent.opennorth.ca) — partial coverage (~13 councils) for verification
 *
 * CivicInfo BC structure:
 *   /municipalities?id={0-160}     — org page with elected officials table
 *   /regionaldistricts?id={0-27}   — same format for RDs
 *   /people?id={XXXX}              — individual contact page with email/phone
 *
 * CivicInfo blocks plain HTTP requests (403), so we set browser-like headers.
 * Rate limiting: 3-second delay, batched in chunks of 50 with 30s pauses.
 */

require_once __DIR__ . '/BaseScraper.php';

class LocalGovScraper extends BaseScraper {

    private const CIVICINFO_BASE = 'https://www.civicinfo.bc.ca';
    private const REPRESENT_MUNICIPAL_URL = 'https://represent.opennorth.ca/representatives/british-columbia-municipal-councils/?limit=1000&format=json';

    // Increased delay for CivicInfo BC
    private const CIVICINFO_DELAY = 3;
    private const BATCH_SIZE = 50;
    private const BATCH_PAUSE = 30;

    private string $logFile;
    private int $officialsFound = 0;
    private int $officialsInserted = 0;
    private int $officialsUpdated = 0;
    private int $requestCount = 0;

    public function __construct() {
        parent::__construct();
        $this->logFile = __DIR__ . '/../../logs/officials_localgov.log';
    }

    public function scrapeAll(): array {
        $startTime = microtime(true);
        $this->writeLog("Starting local government officials scrape");

        try {
            // Step 1: Fetch Represent API data for verification later
            $representData = $this->fetchRepresentAPI();
            $this->writeLog("Represent API: " . count($representData) . " municipal officials loaded for verification");

            // Step 2: Scrape CivicInfo BC municipalities
            $this->writeLog("Scraping CivicInfo BC municipalities...");
            $this->scrapeCivicInfoSection('municipalities', 'municipal', 161);

            // Step 3: Scrape CivicInfo BC regional districts
            $this->writeLog("Scraping CivicInfo BC regional districts...");
            $this->scrapeCivicInfoSection('regionaldistricts', 'regional_district', 28);

            // Step 4: Cross-reference with Represent API
            $this->crossReferenceWithRepresent($representData);

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $this->logOfficialsScrape('civicinfo_bc', 'municipal', 'success', $durationMs);

            $this->writeLog("Complete: {$this->officialsFound} found, {$this->officialsInserted} inserted, {$this->officialsUpdated} updated ({$durationMs}ms, {$this->requestCount} requests)");

            return [
                'officials_found' => $this->officialsFound,
                'officials_inserted' => $this->officialsInserted,
                'officials_updated' => $this->officialsUpdated,
            ];

        } catch (\Exception $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $this->logOfficialsScrape('civicinfo_bc', 'municipal', 'error', $durationMs, $e->getMessage());
            $this->writeLog("ERROR: " . $e->getMessage());
            throw $e;
        }
    }

    public function scrapeMunicipality(array $muni): array {
        return [];
    }

    /**
     * Scrape a section of CivicInfo BC (municipalities or regional districts)
     */
    private function scrapeCivicInfoSection(string $section, string $govLevel, int $maxId): void {
        $batchCount = 0;

        for ($id = 0; $id < $maxId; $id++) {
            // Batch pausing
            if ($batchCount >= self::BATCH_SIZE) {
                $this->writeLog("  Batch pause ({$id}/{$maxId})...");
                sleep(self::BATCH_PAUSE);
                $batchCount = 0;
            }

            sleep(self::CIVICINFO_DELAY);
            $url = self::CIVICINFO_BASE . "/{$section}?id={$id}";
            $result = $this->fetchCivicInfo($url);

            if ($result['error']) {
                if ($result['code'] === 404) continue; // ID doesn't exist
                if ($result['code'] === 429 || $result['code'] === 403) {
                    $this->writeLog("  Rate limited at id={$id}, backing off 60s...");
                    sleep(60);
                    // Retry once
                    $result = $this->fetchCivicInfo($url);
                    if ($result['error']) {
                        $this->writeLog("  Still blocked at id={$id}, skipping");
                        continue;
                    }
                } else {
                    continue;
                }
            }

            $this->requestCount++;
            $batchCount++;

            // Parse the org page
            $orgData = $this->parseOrgPage($result['body'], $section);
            if (!$orgData || empty($orgData['officials'])) continue;

            $this->writeLog("  {$orgData['name']}: " . count($orgData['officials']) . " officials");

            // Link to existing municipality if we have it
            $municipalityId = $this->findMunicipalityId($orgData['name']);

            // Fetch individual person pages for contact details
            foreach ($orgData['officials'] as &$official) {
                if (!empty($official['person_url'])) {
                    sleep(self::CIVICINFO_DELAY);
                    $personResult = $this->fetchCivicInfo(self::CIVICINFO_BASE . $official['person_url']);
                    $this->requestCount++;
                    $batchCount++;

                    if (!$personResult['error']) {
                        $personData = $this->parsePersonPage($personResult['body']);
                        $official = array_merge($official, $personData);
                    }
                }

                // Upsert the official
                $this->upsertOfficial($official, $orgData['name'], $govLevel, $municipalityId);
            }
        }
    }

    /**
     * Fetch a CivicInfo BC page with browser-like headers
     */
    private function fetchCivicInfo(string $url): array {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language: en-CA,en-US;q=0.9,en;q=0.8',
                'Cache-Control: no-cache',
                'Connection: keep-alive',
                'Referer: https://www.civicinfo.bc.ca/',
            ],
            CURLOPT_COOKIEFILE => '', // enable cookie engine
        ]);

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['body' => '', 'code' => 0, 'error' => $error ?: 'cURL request failed'];
        }
        if ($code >= 400) {
            return ['body' => $body, 'code' => $code, 'error' => "HTTP {$code}"];
        }

        return ['body' => $body, 'code' => $code, 'error' => null];
    }

    /**
     * Parse a CivicInfo BC organization page for elected officials
     */
    private function parseOrgPage(string $html, string $section): ?array {
        $data = ['name' => '', 'officials' => []];

        // Extract organization name from <h1> or <title>
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/si', $html, $h1)) {
            $data['name'] = trim($this->stripHtml($h1[1]));
        } elseif (preg_match('/<title>(.*?)(?:\s*[-|])/i', $html, $title)) {
            $data['name'] = trim($this->stripHtml($title[1]));
        }

        if (!$data['name']) return null;

        // Find the Elected Officials section/table
        // CivicInfo pages typically have a heading "Elected Officials" followed by a table
        $electedSection = '';
        if (preg_match('/Elected\s+Officials.*?(<table[^>]*>.*?<\/table>)/si', $html, $tableMatch)) {
            $electedSection = $tableMatch[1];
        } elseif (preg_match('/<table[^>]*class="[^"]*elected[^"]*"[^>]*>.*?<\/table>/si', $html, $tableMatch)) {
            $electedSection = $tableMatch[0];
        }

        if ($electedSection) {
            preg_match_all('/<tr[^>]*>(.*?)<\/tr>/si', $electedSection, $rows);
            foreach ($rows[1] as $row) {
                if (strpos($row, '<th') !== false) continue;

                preg_match_all('/<td[^>]*>(.*?)<\/td>/si', $row, $cells);
                if (count($cells[1]) < 2) continue;

                $nameCell = $cells[1][0];
                $roleCell = $cells[1][1] ?? '';

                // Extract name and person link
                $personUrl = '';
                if (preg_match('/href="([^"]*people[^"]*)"/i', $nameCell, $linkMatch)) {
                    $personUrl = $linkMatch[1];
                }

                $name = trim($this->stripHtml($nameCell));
                $role = trim($this->stripHtml($roleCell));

                if ($name) {
                    $data['officials'][] = [
                        'name' => $name,
                        'role' => $role ?: $this->inferRole($section),
                        'person_url' => $personUrl,
                        'email' => '',
                        'phone' => '',
                    ];
                    $this->officialsFound++;
                }
            }
        }

        // Fallback: look for officials in list/div format (some pages use <ul> instead of tables)
        if (empty($data['officials'])) {
            if (preg_match_all('/<a[^>]*href="([^"]*people\?id=\d+)"[^>]*>(.*?)<\/a>/si', $html, $links, PREG_SET_ORDER)) {
                foreach ($links as $link) {
                    $name = trim($this->stripHtml($link[2]));
                    if ($name && strlen($name) > 2) {
                        $data['officials'][] = [
                            'name' => $name,
                            'role' => $this->inferRole($section),
                            'person_url' => $link[1],
                            'email' => '',
                            'phone' => '',
                        ];
                        $this->officialsFound++;
                    }
                }
            }
        }

        return $data;
    }

    /**
     * Parse a CivicInfo BC person page for contact details
     */
    private function parsePersonPage(string $html): array {
        $data = [];

        // Email
        if (preg_match('/mailto:([^"\'>\s]+)/i', $html, $emailMatch)) {
            $data['email'] = trim($emailMatch[1]);
        }

        // Phone
        if (preg_match('/(?:Phone|Tel)[^<]*?(\(\d{3}\)\s*\d{3}[\s-]\d{4}|\d{3}[\s.-]\d{3}[\s.-]\d{4})/si', $html, $phoneMatch)) {
            $data['phone'] = trim($phoneMatch[1]);
        }

        // Role/title (may be more specific than what the org page had)
        if (preg_match('/(?:Primary\s+)?(?:Job\s+)?Title[^<]*?<[^>]*>([^<]+)/si', $html, $titleMatch)) {
            $title = trim($this->stripHtml($titleMatch[1]));
            if ($title && strlen($title) > 2) {
                $data['role'] = $title;
            }
        }

        return $data;
    }

    /**
     * Find existing municipality_id by name
     */
    private function findMunicipalityId(string $orgName): ?int {
        // Strip prefixes like "City of", "District of", "Town of"
        $searchName = preg_replace('/^(City|District|Town|Village|Resort Municipality)\s+of\s+/i', '', $orgName);
        $searchName = trim($searchName);

        $stmt = $this->db->prepare(
            'SELECT id FROM municipalities WHERE name LIKE ? OR name LIKE ? LIMIT 1'
        );
        $stmt->execute(["%{$searchName}%", "%{$orgName}%"]);
        $result = $stmt->fetchColumn();

        return $result ? (int) $result : null;
    }

    /**
     * Upsert a local government official
     */
    private function upsertOfficial(array $data, string $jurisdiction, string $govLevel, ?int $municipalityId): void {
        $name = $data['name'];

        $stmt = $this->db->prepare(
            'SELECT id FROM elected_officials
             WHERE name = ? AND jurisdiction_name = ? AND government_level = ?'
        );
        $stmt->execute([$name, $jurisdiction, $govLevel]);
        $existing = $stmt->fetchColumn();

        // Split name into first/last
        $nameParts = $this->splitName($name);

        if ($existing) {
            $stmt = $this->db->prepare(
                'UPDATE elected_officials SET
                    first_name = ?, last_name = ?, role = ?,
                    email = COALESCE(NULLIF(?, ""), email),
                    phone = COALESCE(NULLIF(?, ""), phone),
                    municipality_id = COALESCE(?, municipality_id),
                    source_url = ?, source_name = ?, updated_at = NOW()
                 WHERE id = ?'
            );
            $stmt->execute([
                $nameParts['first'], $nameParts['last'], $data['role'],
                $data['email'] ?? '', $data['phone'] ?? '',
                $municipalityId,
                self::CIVICINFO_BASE, 'civicinfo',
                $existing
            ]);
            $this->officialsUpdated++;
        } else {
            $stmt = $this->db->prepare(
                'INSERT INTO elected_officials
                    (government_level, jurisdiction_name, municipality_id, name, first_name, last_name,
                     role, email, phone, source_url, source_name, confidence_score)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
            );
            $stmt->execute([
                $govLevel, $jurisdiction, $municipalityId, $name,
                $nameParts['first'], $nameParts['last'], $data['role'],
                $data['email'] ?? '', $data['phone'] ?? '',
                self::CIVICINFO_BASE, 'civicinfo'
            ]);
            $this->officialsInserted++;
        }
    }

    /**
     * Fetch from Represent API for municipal officials (verification data)
     */
    private function fetchRepresentAPI(): array {
        $result = $this->fetch(self::REPRESENT_MUNICIPAL_URL);
        if ($result['error']) {
            $this->writeLog("Represent API error: " . $result['error']);
            return [];
        }

        $json = json_decode($result['body'], true);
        if (!$json || !isset($json['objects'])) return [];

        $byMuni = [];
        foreach ($json['objects'] as $obj) {
            $district = $obj['district_name'] ?? '';
            $byMuni[$district][] = [
                'name' => trim($obj['name'] ?? ''),
                'email' => trim($obj['email'] ?? ''),
                'role' => trim($obj['elected_office'] ?? ''),
            ];
        }

        return $byMuni;
    }

    /**
     * Cross-reference CivicInfo data with Represent API
     */
    private function crossReferenceWithRepresent(array $representData): void {
        if (empty($representData)) return;
        $this->writeLog("Cross-referencing with Represent API...");

        $matched = 0;
        foreach ($representData as $municipality => $officials) {
            foreach ($officials as $repOfficial) {
                // Find matching official in our DB
                $stmt = $this->db->prepare(
                    'SELECT id, name, email FROM elected_officials
                     WHERE government_level IN (?, ?) AND last_name = ?
                     AND jurisdiction_name LIKE ?'
                );
                $lastName = $this->splitName($repOfficial['name'])['last'];
                $stmt->execute(['municipal', 'regional_district', $lastName, "%{$municipality}%"]);
                $dbOfficial = $stmt->fetch();

                if (!$dbOfficial) continue;

                $fieldsMatched = ['name' => true];
                $fieldsMismatched = [];

                if ($repOfficial['email'] && $dbOfficial['email']) {
                    if (strtolower($repOfficial['email']) === strtolower($dbOfficial['email'])) {
                        $fieldsMatched['email'] = true;
                    } else {
                        $fieldsMismatched['email'] = [
                            'civicinfo' => $dbOfficial['email'],
                            'represent' => $repOfficial['email'],
                        ];
                    }
                }

                $verifyStmt = $this->db->prepare(
                    'INSERT INTO official_verifications
                        (official_id, source_name, source_url, fields_matched, fields_mismatched)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $verifyStmt->execute([
                    $dbOfficial['id'], 'represent_api',
                    self::REPRESENT_MUNICIPAL_URL,
                    json_encode($fieldsMatched),
                    !empty($fieldsMismatched) ? json_encode($fieldsMismatched) : null,
                ]);

                $updateStmt = $this->db->prepare(
                    'UPDATE elected_officials SET confidence_score = LEAST(confidence_score + 1, 3), verified_at = NOW() WHERE id = ?'
                );
                $updateStmt->execute([$dbOfficial['id']]);
                $matched++;
            }
        }

        $this->writeLog("Cross-reference: {$matched} matched against Represent API");
    }

    private function inferRole(string $section): string {
        return $section === 'regionaldistricts' ? 'Regional District Director' : 'Councillor';
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
