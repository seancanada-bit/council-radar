<?php
/**
 * Base scraper providing HTTP fetching, rate limiting, and logging
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../functions.php';

abstract class BaseScraper {

    protected PDO $db;
    protected int $requestDelay;
    protected int $timeout;
    protected string $userAgent;

    public function __construct() {
        $this->db = DB::get();
        $this->requestDelay = SCRAPER_REQUEST_DELAY;
        $this->timeout = SCRAPER_TIMEOUT;
        $this->userAgent = SCRAPER_USER_AGENT;
    }

    /**
     * Scrape all active municipalities for this platform type
     */
    abstract public function scrapeAll(): array;

    /**
     * Scrape a single municipality
     */
    abstract public function scrapeMunicipality(array $muni): array;

    /**
     * Fetch a URL via cURL
     * Returns ['body' => string, 'code' => int, 'error' => string|null]
     */
    protected function fetch(string $url): array {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING => '', // accept all encodings
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-CA,en;q=0.5',
            ],
        ]);

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['body' => '', 'code' => 0, 'error' => $error ?: 'cURL request failed'];
        }

        if ($code >= 400) {
            return ['body' => $body, 'code' => $code, 'error' => "HTTP $code"];
        }

        return ['body' => $body, 'code' => $code, 'error' => null];
    }

    /**
     * Rate limit delay between requests
     */
    protected function rateLimit(): void {
        sleep($this->requestDelay);
    }

    /**
     * Log a scrape result to the scrape_log table
     */
    protected function log(int $municipalityId, string $status, int $meetingsFound = 0, int $itemsParsed = 0, ?string $errorMessage = null, int $durationMs = 0): void {
        $stmt = $this->db->prepare(
            'INSERT INTO scrape_log (municipality_id, status, meetings_found, items_parsed, error_message, duration_ms)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$municipalityId, $status, $meetingsFound, $itemsParsed, $errorMessage, $durationMs]);
    }

    /**
     * Update the last_scraped_at timestamp for a municipality
     */
    protected function updateLastScraped(int $municipalityId): void {
        $stmt = $this->db->prepare('UPDATE municipalities SET last_scraped_at = NOW() WHERE id = ?');
        $stmt->execute([$municipalityId]);
    }

    /**
     * Check if a source URL already exists in the meetings table
     */
    protected function meetingExists(string $sourceUrl): bool {
        $stmt = $this->db->prepare('SELECT 1 FROM meetings WHERE source_url = ? LIMIT 1');
        $stmt->execute([$sourceUrl]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Insert a new meeting record
     * Returns the meeting ID
     */
    protected function insertMeeting(int $municipalityId, string $meetingType, ?string $meetingDate, string $sourceUrl, string $rawHtml): int {
        $stmt = $this->db->prepare(
            'INSERT INTO meetings (municipality_id, meeting_type, meeting_date, source_url, raw_html, parsed)
             VALUES (?, ?, ?, ?, ?, 0)'
        );
        $stmt->execute([$municipalityId, $meetingType, $meetingDate, $sourceUrl, $rawHtml]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Get all active municipalities for a given platform
     */
    protected function getMunicipalities(string $platform): array {
        $stmt = $this->db->prepare('SELECT * FROM municipalities WHERE platform = ? AND active = 1');
        $stmt->execute([$platform]);
        return $stmt->fetchAll();
    }

    /**
     * Extract text content from HTML, stripping tags
     */
    protected function stripHtml(string $html): string {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    /**
     * Parse a date string into Y-m-d format, or null on failure
     */
    protected function parseDate(string $dateStr): ?string {
        $dateStr = trim($dateStr);
        $ts = strtotime($dateStr);
        if ($ts === false) return null;
        return date('Y-m-d', $ts);
    }
}
