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

    /**
     * Hard cap on a single response body, in bytes.
     *
     * Agenda pages are HTML, so a few hundred KB at most. Without a cap, a
     * municipality that serves a large PDF or a huge attachment from an agenda
     * URL buffers the whole thing into memory and kills the process:
     *
     *   PHP Fatal error: Allowed memory size of 134217728 bytes exhausted
     *   (tried to allocate 98570240 bytes) in BaseScraper.php on line 55
     *
     * That is a fatal, not an exception, so it took down the entire nightly
     * scrape rather than skipping one municipality. 8 MB leaves plenty of room
     * for legitimate pages while staying well under the 128 MB limit.
     */
    protected int $maxBodyBytes = 8388608;

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
        $body = '';
        $bytes = 0;
        $tooLarge = false;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => false, // body is collected by the write callback
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
            // Cheap early bail when the server sends a Content-Length.
            CURLOPT_MAXFILESIZE => $this->maxBodyBytes,
            // The real guard. MAXFILESIZE does nothing for chunked responses or
            // when Content-Length is absent, and it sees the compressed size
            // rather than the decoded size. This callback receives decoded bytes,
            // so it catches a small gzipped response that expands enormously.
            // Returning a value other than the chunk length aborts the transfer.
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$body, &$bytes, &$tooLarge): int {
                $len = strlen($chunk);
                if ($bytes + $len > $this->maxBodyBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $bytes += $len;
                $body .= $chunk;
                return $len;
            },
        ]);

        $ok = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        // Check the size guards before the generic failure branch: aborting the
        // transfer deliberately also sets an error, and treating that as a
        // network failure would hide the real cause.
        if ($tooLarge || $errno === CURLE_FILESIZE_EXCEEDED) {
            return [
                'body' => '',
                'code' => $code,
                'error' => sprintf(
                    'Response exceeded the %d byte cap, skipped: %s',
                    $this->maxBodyBytes,
                    $url
                ),
            ];
        }

        if ($ok === false) {
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
