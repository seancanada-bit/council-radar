<?php
/**
 * Scraper for BC Provincial MLAs
 *
 * Sources:
 *   1. Represent API (represent.opennorth.ca) - JSON API with all 93 MLAs
 *   2. BC Legislature contact page (leg.bc.ca) - HTML table with official emails
 *
 * The leg.bc.ca contact page is the authoritative source for email addresses.
 * The Represent API provides riding, party, and photo data.
 */

require_once __DIR__ . '/BaseScraper.php';

class ProvincialScraper extends BaseScraper {

    private const REPRESENT_API_URL = 'https://represent.opennorth.ca/representatives/bc-legislature/?limit=100&format=json';
    private const LEG_CONTACT_URL = 'https://www.leg.bc.ca/contact-us/mla-contact-information';

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
            // Step 1: Fetch from Represent API (primary source for riding/party/photo)
            $representData = $this->fetchRepresentAPI();
            if (empty($representData)) {
                throw new \Exception('Represent API returned no data');
            }
            $this->writeLog("Represent API: " . count($representData) . " MLAs found");

            // Step 2: Fetch from leg.bc.ca contact page (authoritative emails)
            $legContactData = $this->fetchLegContactPage();
            $this->writeLog("leg.bc.ca contacts: " . count($legContactData) . " entries found");

            // Step 3: Merge leg.bc.ca emails into Represent data
            $mergedData = $this->mergeData($representData, $legContactData);
            $this->writeLog("Merged data: " . count($mergedData) . " MLAs with enriched data");

            // Step 4: Upsert officials
            foreach ($mergedData as $mla) {
                $this->upsertOfficial($mla);
            }

            // Step 5: Cross-reference and bump confidence for verified matches
            $this->crossReference($representData, $legContactData);

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
     * Not used - provincial MLAs aren't tied to individual municipalities
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
     * Scrape the leg.bc.ca MLA contact information page
     * Returns array of ['name' => ..., 'email' => ..., 'profile_url' => ...]
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

        // The page has a table with MLA name (linked) and email (mailto)
        // Each row: <td><a href="/members/...">Hon. First Last, K.C.</a></td><td><a href="mailto:...">email</a></td>
        if (preg_match_all('/<tr[^>]*>(.*?)<\/tr>/si', $html, $rows)) {
            foreach ($rows[1] as $row) {
                // Skip header rows
                if (strpos($row, '<th') !== false) continue;

                // Extract name and profile URL from first cell
                $name = '';
                $profileUrl = '';
                if (preg_match('/<a[^>]+href="([^"]*\/members\/[^"]*)"[^>]*>(.*?)<\/a>/si', $row, $linkMatch)) {
                    $profileUrl = 'https://www.leg.bc.ca' . $linkMatch[1];
                    $rawName = $this->stripHtml($linkMatch[2]);
                    $name = $this->cleanMlaName($rawName);
                }

                // Fallback: name from cell text if no link
                if (!$name) {
                    if (preg_match('/<td[^>]*>(.*?)<\/td>/si', $row, $cell)) {
                        $name = $this->cleanMlaName($this->stripHtml($cell[1]));
                    }
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
                        'profile_url' => $profileUrl,
                        'source_url' => self::LEG_CONTACT_URL,
                    ];
                }
            }
        }

        return $contacts;
    }

    /**
     * Clean an MLA name by removing honorifics and credentials
     * "Hon. David Eby, K.C." -> "David Eby"
     * "Laanas - Tamara Davidson" -> "Laanas - Tamara Davidson"
     */
    private function cleanMlaName(string $name): string {
        // Remove "Hon." or "Honourable"
        $name = preg_replace('/^Hon(?:ourable)?\.?\s*/i', '', $name);
        // Remove trailing credentials like ", K.C." or ", Q.C."
        $name = preg_replace('/,?\s+[KQ]\.?C\.?\s*$/i', '', $name);
        return trim($name);
    }

    /**
     * Normalize a name for comparison
     * Lowercase, remove punctuation, collapse whitespace
     */
    private function normalizeName(string $name): string {
        $name = mb_strtolower($name);
        $name = $this->cleanMlaName($name);
        $name = preg_replace('/[^a-z\s-]/', '', $name);
        $name = preg_replace('/\s+/', ' ', $name);
        return trim($name);
    }

    /**
     * Merge leg.bc.ca email data into Represent API data
     * Match by normalized name
     */
    private function mergeData(array $representData, array $legContacts): array {
        // Build lookup from leg.bc.ca by normalized name
        $legByName = [];
        foreach ($legContacts as $contact) {
            $normalized = $this->normalizeName($contact['name']);
            $legByName[$normalized] = $contact;

            // Also index by last name + first initial for fuzzy matching
            $parts = explode(' ', $normalized);
            if (count($parts) >= 2) {
                $lastName = end($parts);
                $firstName = $parts[0];
                $legByName[$lastName . '_' . substr($firstName, 0, 1)] = $contact;
            }
        }

        $merged = 0;
        foreach ($representData as &$mla) {
            $normalized = $this->normalizeName($mla['name']);

            // Exact normalized match
            if (isset($legByName[$normalized])) {
                $legEntry = $legByName[$normalized];
                if (!empty($legEntry['email'])) {
                    $mla['email'] = $legEntry['email'];
                }
                if (!empty($legEntry['profile_url'])) {
                    $mla['profile_url'] = $legEntry['profile_url'];
                }
                $mla['_leg_matched'] = true;
                $merged++;
                continue;
            }

            // Try last name + first initial
            $lastName = strtolower($mla['last_name']);
            $firstInitial = strtolower(substr($mla['first_name'], 0, 1));
            $fuzzyKey = $lastName . '_' . $firstInitial;

            if (isset($legByName[$fuzzyKey])) {
                $legEntry = $legByName[$fuzzyKey];
                if (!empty($legEntry['email'])) {
                    $mla['email'] = $legEntry['email'];
                }
                if (!empty($legEntry['profile_url'])) {
                    $mla['profile_url'] = $legEntry['profile_url'];
                }
                $mla['_leg_matched'] = true;
                $merged++;
                continue;
            }

            // Try matching by email pattern (firstname.lastname.MLA@leg.bc.ca)
            $expectedEmail = strtolower($mla['first_name'] . '.' . $mla['last_name'] . '.MLA@leg.bc.ca');
            foreach ($legContacts as $contact) {
                if (strtolower($contact['email']) === $expectedEmail) {
                    $mla['email'] = $contact['email'];
                    if (!empty($contact['profile_url'])) {
                        $mla['profile_url'] = $contact['profile_url'];
                    }
                    $mla['_leg_matched'] = true;
                    $merged++;
                    break;
                }
            }
        }
        unset($mla);

        $this->writeLog("Merged {$merged} of " . count($representData) . " MLAs with leg.bc.ca data");
        return $representData;
    }

    /**
     * Upsert an official into the elected_officials table
     */
    private function upsertOfficial(array $data): void {
        $name = $data['name'];
        $jurisdiction = $data['district_name'];
        $level = 'provincial';
        $sourceUrl = $data['profile_url'] ?? $data['source_url'] ?? self::REPRESENT_API_URL;
        $sourceName = !empty($data['_leg_matched']) ? 'represent_api+leg_bc_ca' : 'represent_api';

        // Check if exists
        $stmt = $this->db->prepare(
            'SELECT id FROM elected_officials
             WHERE name = ? AND jurisdiction_name = ? AND government_level = ?'
        );
        $stmt->execute([$name, $jurisdiction, $level]);
        $existing = $stmt->fetchColumn();

        if ($existing) {
            $stmt = $this->db->prepare(
                'UPDATE elected_officials SET
                    first_name = ?, last_name = ?, role = ?, party = ?,
                    email = ?, photo_url = ?, source_url = ?, source_name = ?,
                    updated_at = NOW()
                 WHERE id = ?'
            );
            $stmt->execute([
                $data['first_name'], $data['last_name'], 'MLA', $data['party'],
                $data['email'], $data['photo_url'], $sourceUrl, $sourceName,
                $existing
            ]);
            $this->officialsUpdated++;
        } else {
            $stmt = $this->db->prepare(
                'INSERT INTO elected_officials
                    (government_level, jurisdiction_name, name, first_name, last_name,
                     role, party, email, photo_url, source_url, source_name, confidence_score)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $confidence = !empty($data['_leg_matched']) ? 2 : 1;
            $stmt->execute([
                $level, $jurisdiction, $name, $data['first_name'], $data['last_name'],
                'MLA', $data['party'], $data['email'], $data['photo_url'],
                $sourceUrl, $sourceName, $confidence
            ]);
            $this->officialsInserted++;
        }
    }

    /**
     * Cross-reference both sources and log verification results
     */
    private function crossReference(array $representData, array $legContacts): void {
        if (empty($legContacts)) return;

        $this->writeLog("Running cross-reference verification...");

        // Build lookup by normalized name from leg data
        $legByNorm = [];
        foreach ($legContacts as $contact) {
            $legByNorm[$this->normalizeName($contact['name'])] = $contact;
        }

        $stmt = $this->db->prepare(
            'SELECT id, name, first_name, last_name, email
             FROM elected_officials WHERE government_level = ?'
        );
        $stmt->execute(['provincial']);
        $officials = $stmt->fetchAll();

        $verified = 0;
        foreach ($officials as $official) {
            $normalized = $this->normalizeName($official['name']);

            // Try exact match
            $legMatch = $legByNorm[$normalized] ?? null;

            // Try last name + first initial
            if (!$legMatch) {
                $lastName = strtolower($official['last_name']);
                $firstInit = strtolower(substr($official['first_name'], 0, 1));
                foreach ($legContacts as $lc) {
                    $lcNorm = $this->normalizeName($lc['name']);
                    $lcParts = explode(' ', $lcNorm);
                    $lcLast = end($lcParts);
                    $lcFirst = $lcParts[0] ?? '';
                    if ($lcLast === $lastName && substr($lcFirst, 0, 1) === $firstInit) {
                        $legMatch = $lc;
                        break;
                    }
                }
            }

            if (!$legMatch) continue;

            $fieldsMatched = ['name' => true];
            $fieldsMismatched = [];

            // Compare email
            if ($legMatch['email'] && $official['email']) {
                if (strtolower($legMatch['email']) === strtolower($official['email'])) {
                    $fieldsMatched['email'] = true;
                } else {
                    $fieldsMismatched['email'] = [
                        'db' => $official['email'],
                        'leg_bc_ca' => $legMatch['email'],
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

            // Bump confidence
            $updateStmt = $this->db->prepare(
                'UPDATE elected_officials SET confidence_score = LEAST(confidence_score + 1, 3), verified_at = NOW() WHERE id = ?'
            );
            $updateStmt->execute([$official['id']]);

            $verified++;
        }

        $this->writeLog("Cross-reference: {$verified} of " . count($officials) . " verified against leg.bc.ca");
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
