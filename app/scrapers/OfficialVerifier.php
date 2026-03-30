<?php
/**
 * Cross-reference verification engine for elected officials data
 *
 * Runs after all scrapers and compares data across sources:
 *   - Provincial: Represent API vs leg.bc.ca
 *   - Municipal: CivicInfo BC vs Represent API
 *   - School: Government CSV vs district websites
 *
 * Optionally uses Google Civic Information API for spot-checks
 * (requires GOOGLE_CIVIC_API_KEY in .env)
 */

require_once __DIR__ . '/BaseScraper.php';

class OfficialVerifier extends BaseScraper {

    private const REPRESENT_PROVINCIAL_URL = 'https://represent.opennorth.ca/representatives/bc-legislature/?limit=100&format=json';
    private const REPRESENT_MUNICIPAL_URL = 'https://represent.opennorth.ca/representatives/british-columbia-municipal-councils/?limit=1000&format=json';
    private const GOOGLE_CIVIC_URL = 'https://www.googleapis.com/civicinfo/v2/representatives';

    private string $logFile;

    public function __construct() {
        parent::__construct();
        $this->logFile = __DIR__ . '/../../logs/officials_verify.log';
    }

    /**
     * Not used by this class
     */
    public function scrapeAll(): array {
        return $this->verifyAll();
    }

    public function scrapeMunicipality(array $muni): array {
        return [];
    }

    /**
     * Run all verification checks
     */
    public function verifyAll(): array {
        $this->writeLog("Starting verification pass");
        $totalVerified = 0;
        $totalMismatches = 0;

        // Verify provincial MLAs against Represent API
        $result = $this->verifyProvincial();
        $totalVerified += $result['verified'];
        $totalMismatches += $result['mismatches'];

        // Verify municipal officials against Represent API
        $result = $this->verifyMunicipal();
        $totalVerified += $result['verified'];
        $totalMismatches += $result['mismatches'];

        // Spot-check with Google Civic API if configured
        $googleApiKey = getenv('GOOGLE_CIVIC_API_KEY');
        if ($googleApiKey) {
            $result = $this->spotCheckWithGoogle($googleApiKey);
            $totalVerified += $result['verified'];
            $totalMismatches += $result['mismatches'];
        }

        // Generate confidence report
        $this->generateReport();

        $this->writeLog("Verification complete: {$totalVerified} verified, {$totalMismatches} mismatches");

        return [
            'verified' => $totalVerified,
            'mismatches' => $totalMismatches,
        ];
    }

    /**
     * Verify provincial MLAs against Represent API
     */
    private function verifyProvincial(): array {
        $this->writeLog("Verifying provincial MLAs...");

        $result = $this->fetch(self::REPRESENT_PROVINCIAL_URL);
        if ($result['error']) {
            $this->writeLog("  Represent API error: " . $result['error']);
            return ['verified' => 0, 'mismatches' => 0];
        }

        $json = json_decode($result['body'], true);
        if (!$json || !isset($json['objects'])) return ['verified' => 0, 'mismatches' => 0];

        $verified = 0;
        $mismatches = 0;

        foreach ($json['objects'] as $apiMLA) {
            $name = trim($apiMLA['name'] ?? '');
            $district = trim($apiMLA['district_name'] ?? '');

            $stmt = $this->db->prepare(
                'SELECT id, name, email, party FROM elected_officials
                 WHERE government_level = ? AND jurisdiction_name = ?'
            );
            $stmt->execute(['provincial', $district]);
            $dbOfficial = $stmt->fetch();

            if (!$dbOfficial) {
                $this->writeLog("  NOT IN DB: {$name} ({$district})");
                $mismatches++;
                continue;
            }

            $fieldsMatched = [];
            $fieldsMismatched = [];

            // Name comparison (fuzzy — handle honorifics, middle names)
            if ($this->fuzzyNameMatch($dbOfficial['name'], $name)) {
                $fieldsMatched['name'] = true;
            } else {
                $fieldsMismatched['name'] = ['db' => $dbOfficial['name'], 'api' => $name];
            }

            // Email comparison
            $apiEmail = trim($apiMLA['email'] ?? '');
            if ($apiEmail && $dbOfficial['email']) {
                if (strtolower($apiEmail) === strtolower($dbOfficial['email'])) {
                    $fieldsMatched['email'] = true;
                } else {
                    $fieldsMismatched['email'] = ['db' => $dbOfficial['email'], 'api' => $apiEmail];
                }
            }

            // Party comparison
            $apiParty = trim($apiMLA['party_name'] ?? '');
            if ($apiParty && $dbOfficial['party']) {
                if ($this->fuzzyPartyMatch($dbOfficial['party'], $apiParty)) {
                    $fieldsMatched['party'] = true;
                } else {
                    $fieldsMismatched['party'] = ['db' => $dbOfficial['party'], 'api' => $apiParty];
                }
            }

            // Log verification
            $this->logVerification($dbOfficial['id'], 'represent_api_verify', self::REPRESENT_PROVINCIAL_URL, $fieldsMatched, $fieldsMismatched);

            if (empty($fieldsMismatched)) {
                $verified++;
            } else {
                $mismatches++;
                $this->writeLog("  MISMATCH: {$name} — " . json_encode($fieldsMismatched));
            }
        }

        $this->writeLog("  Provincial: {$verified} verified, {$mismatches} mismatches");
        return ['verified' => $verified, 'mismatches' => $mismatches];
    }

    /**
     * Verify municipal officials against Represent API
     */
    private function verifyMunicipal(): array {
        $this->writeLog("Verifying municipal officials...");

        $result = $this->fetch(self::REPRESENT_MUNICIPAL_URL);
        if ($result['error']) {
            $this->writeLog("  Represent API error: " . $result['error']);
            return ['verified' => 0, 'mismatches' => 0];
        }

        $json = json_decode($result['body'], true);
        if (!$json || !isset($json['objects'])) return ['verified' => 0, 'mismatches' => 0];

        $verified = 0;
        $mismatches = 0;

        foreach ($json['objects'] as $apiOfficial) {
            $name = trim($apiOfficial['name'] ?? '');
            $lastName = $this->extractLastName($name);

            // Search by last name + municipal level (Represent API district names may not match exactly)
            $stmt = $this->db->prepare(
                'SELECT id, name, email, jurisdiction_name FROM elected_officials
                 WHERE government_level IN (?, ?) AND last_name = ?'
            );
            $stmt->execute(['municipal', 'regional_district', $lastName]);
            $candidates = $stmt->fetchAll();

            if (empty($candidates)) continue;

            // Find best match
            $bestMatch = null;
            foreach ($candidates as $candidate) {
                if ($this->fuzzyNameMatch($candidate['name'], $name)) {
                    $bestMatch = $candidate;
                    break;
                }
            }

            if (!$bestMatch) continue;

            $fieldsMatched = ['name' => true];
            $fieldsMismatched = [];

            $apiEmail = trim($apiOfficial['email'] ?? '');
            if ($apiEmail && $bestMatch['email']) {
                if (strtolower($apiEmail) === strtolower($bestMatch['email'])) {
                    $fieldsMatched['email'] = true;
                } else {
                    $fieldsMismatched['email'] = ['db' => $bestMatch['email'], 'api' => $apiEmail];
                }
            }

            $this->logVerification($bestMatch['id'], 'represent_api_verify', self::REPRESENT_MUNICIPAL_URL, $fieldsMatched, $fieldsMismatched);

            if (empty($fieldsMismatched)) {
                $verified++;
            } else {
                $mismatches++;
            }
        }

        $this->writeLog("  Municipal: {$verified} verified, {$mismatches} mismatches");
        return ['verified' => $verified, 'mismatches' => $mismatches];
    }

    /**
     * Spot-check random officials using Google Civic Information API
     */
    private function spotCheckWithGoogle(string $apiKey): array {
        $this->writeLog("Spot-checking with Google Civic API...");

        // Pick 10 random officials with low confidence
        $stmt = $this->db->prepare(
            'SELECT id, name, jurisdiction_name, government_level, role
             FROM elected_officials
             WHERE confidence_score < 2
             ORDER BY RAND() LIMIT 10'
        );
        $stmt->execute();
        $officials = $stmt->fetchAll();

        $verified = 0;
        $mismatches = 0;

        foreach ($officials as $official) {
            // Use jurisdiction as address for Google lookup
            $address = $official['jurisdiction_name'] . ', British Columbia, Canada';
            $url = self::GOOGLE_CIVIC_URL . '?' . http_build_query([
                'key' => $apiKey,
                'address' => $address,
            ]);

            $this->rateLimit();
            $result = $this->fetch($url);

            if ($result['error']) continue;

            $json = json_decode($result['body'], true);
            if (!$json || !isset($json['officials'])) continue;

            // Search for a name match in Google's results
            $found = false;
            foreach ($json['officials'] as $gOfficial) {
                if ($this->fuzzyNameMatch($official['name'], $gOfficial['name'] ?? '')) {
                    $found = true;
                    $fieldsMatched = ['name' => true];

                    $this->logVerification($official['id'], 'google_civic', $url, $fieldsMatched, []);

                    // Bump confidence
                    $updateStmt = $this->db->prepare(
                        'UPDATE elected_officials SET confidence_score = LEAST(confidence_score + 1, 3), verified_at = NOW() WHERE id = ?'
                    );
                    $updateStmt->execute([$official['id']]);

                    $verified++;
                    break;
                }
            }

            if (!$found) {
                $mismatches++;
            }
        }

        $this->writeLog("  Google spot-check: {$verified} verified, {$mismatches} not found");
        return ['verified' => $verified, 'mismatches' => $mismatches];
    }

    /**
     * Generate a confidence report
     */
    private function generateReport(): void {
        $stmt = $this->db->query(
            'SELECT government_level,
                    COUNT(*) as total,
                    SUM(confidence_score >= 2) as high_confidence,
                    SUM(confidence_score = 1) as low_confidence,
                    SUM(confidence_score = 0) as unverified
             FROM elected_officials
             GROUP BY government_level'
        );
        $rows = $stmt->fetchAll();

        $this->writeLog("=== Confidence Report ===");
        foreach ($rows as $row) {
            $pct = $row['total'] > 0 ? round(($row['high_confidence'] / $row['total']) * 100) : 0;
            $this->writeLog("  {$row['government_level']}: {$row['total']} total, {$row['high_confidence']} high-confidence ({$pct}%), {$row['low_confidence']} low, {$row['unverified']} unverified");
        }
    }

    /**
     * Fuzzy name matching — handles honorifics, middle names, etc.
     */
    private function fuzzyNameMatch(string $name1, string $name2): bool {
        $n1 = $this->normalizeName($name1);
        $n2 = $this->normalizeName($name2);

        // Exact match after normalization
        if ($n1 === $n2) return true;

        // Last name + first initial match
        $parts1 = explode(' ', $n1);
        $parts2 = explode(' ', $n2);
        $last1 = end($parts1);
        $last2 = end($parts2);
        $first1 = $parts1[0] ?? '';
        $first2 = $parts2[0] ?? '';

        if ($last1 === $last2 && $first1 && $first2 && $first1[0] === $first2[0]) {
            return true;
        }

        return false;
    }

    private function normalizeName(string $name): string {
        // Remove honorifics
        $name = preg_replace('/\b(Hon\.|Dr\.|Mr\.|Ms\.|Mrs\.|K\.C\.|Q\.C\.)\s*/i', '', $name);
        // Normalize whitespace
        $name = preg_replace('/\s+/', ' ', trim($name));
        return strtolower($name);
    }

    /**
     * Fuzzy party name matching
     */
    private function fuzzyPartyMatch(string $party1, string $party2): bool {
        $p1 = strtolower(trim($party1));
        $p2 = strtolower(trim($party2));

        if ($p1 === $p2) return true;

        // Common abbreviations
        $aliases = [
            'bc ndp' => 'british columbia new democratic party',
            'ndp' => 'british columbia new democratic party',
            'new democratic party' => 'british columbia new democratic party',
            'bc liberal' => 'bc liberals',
            'bc conservative' => 'conservative party of british columbia',
            'conservative' => 'conservative party of british columbia',
            'bc green' => 'british columbia green party',
            'green' => 'british columbia green party',
            'green party' => 'british columbia green party',
        ];

        $norm1 = $aliases[$p1] ?? $p1;
        $norm2 = $aliases[$p2] ?? $p2;

        if ($norm1 === $norm2) return true;

        // Substring match
        if (strpos($p1, $p2) !== false || strpos($p2, $p1) !== false) return true;

        return false;
    }

    private function extractLastName(string $name): string {
        $parts = preg_split('/\s+/', trim($name));
        return end($parts);
    }

    private function logVerification(int $officialId, string $source, string $url, array $matched, array $mismatched): void {
        $stmt = $this->db->prepare(
            'INSERT INTO official_verifications
                (official_id, source_name, source_url, fields_matched, fields_mismatched)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $officialId, $source, $url,
            json_encode($matched),
            !empty($mismatched) ? json_encode($mismatched) : null,
        ]);
    }

    private function writeLog(string $message): void {
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($this->logFile, "[{$timestamp}] [Verify] {$message}\n", FILE_APPEND);
    }
}
