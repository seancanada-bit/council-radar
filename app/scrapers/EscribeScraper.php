<?php
/**
 * Scraper for eSCRIBE municipal meeting portals
 *
 * eSCRIBE portals list meetings at:
 *   /                                    - homepage with upcoming/past meetings
 *   /Meeting.aspx?Id={UUID}&lang=English - individual meeting page with agenda items
 *
 * The scraper fetches the main page, extracts meeting links with UUIDs,
 * then fetches each meeting page to get agenda content.
 */

require_once __DIR__ . '/BaseScraper.php';

class EscribeScraper extends BaseScraper {

    public function scrapeAll(): array {
        $municipalities = $this->getMunicipalities('escribe');
        $results = [];

        foreach ($municipalities as $muni) {
            $startTime = microtime(true);
            try {
                $result = $this->scrapeMunicipality($muni);
                $durationMs = (int) ((microtime(true) - $startTime) * 1000);
                $this->log(
                    $muni['id'],
                    $result['meetings_found'] > 0 ? 'success' : 'no_new',
                    $result['meetings_found'],
                    0,
                    null,
                    $durationMs
                );
                $this->updateLastScraped($muni['id']);
                $results[$muni['slug']] = $result;
            } catch (Exception $e) {
                $durationMs = (int) ((microtime(true) - $startTime) * 1000);
                $this->log($muni['id'], 'error', 0, 0, $e->getMessage(), $durationMs);
                logMessage('scrape.log', "ERROR [{$muni['slug']}]: " . $e->getMessage());
                $results[$muni['slug']] = ['error' => $e->getMessage(), 'meetings_found' => 0];
            }
        }

        return $results;
    }

    public function scrapeMunicipality(array $muni): array {
        $baseUrl = rtrim($muni['base_url'], '/');
        $meetingsFound = 0;

        logMessage('scrape.log', "Scraping eSCRIBE: {$muni['name']} ({$baseUrl})");

        // Step 1: Fetch the main meetings page
        $response = $this->fetch($baseUrl);
        if ($response['error']) {
            throw new Exception("Failed to fetch portal: {$response['error']}");
        }

        // Step 2: Extract meeting links from the page
        $meetingLinks = $this->extractMeetingLinks($response['body'], $baseUrl);

        logMessage('scrape.log', "  Found " . count($meetingLinks) . " meeting link(s)");

        // Step 3: Filter to recent meetings and fetch each
        $cutoffPast = strtotime('-7 days');
        $cutoffFuture = strtotime('+14 days');

        foreach ($meetingLinks as $meeting) {
            $meetingUrl = $meeting['url'];

            if ($this->meetingExists($meetingUrl)) {
                continue;
            }

            // Filter by date if we have one
            if ($meeting['date']) {
                $ts = strtotime($meeting['date']);
                if ($ts < $cutoffPast || $ts > $cutoffFuture) {
                    continue;
                }
            }

            $this->rateLimit();

            $meetingResponse = $this->fetch($meetingUrl);
            if ($meetingResponse['error']) {
                logMessage('scrape.log', "  Failed to fetch meeting: {$meetingResponse['error']}");
                continue;
            }

            // Extract agenda items content from the meeting page
            $agendaHtml = $this->extractAgendaContent($meetingResponse['body']);
            if (empty($agendaHtml)) {
                logMessage('scrape.log', "  No agenda content found for: {$meeting['title']}");
                continue;
            }

            $meetingDate = $meeting['date'] ?: $this->extractDateFromPage($meetingResponse['body']);
            $meetingType = $meeting['type'] ?: 'Council Meeting';

            $this->insertMeeting(
                $muni['id'],
                $meetingType,
                $meetingDate,
                $meetingUrl,
                $agendaHtml
            );

            $meetingsFound++;
            logMessage('scrape.log', "  Stored: {$meeting['title']} ({$meetingUrl})");
        }

        return ['meetings_found' => $meetingsFound, 'links_found' => count($meetingLinks)];
    }

    /**
     * Extract meeting links from the eSCRIBE homepage
     * Links follow pattern: Meeting.aspx?Id={UUID}&lang=English
     */
    private function extractMeetingLinks(string $html, string $baseUrl): array {
        $links = [];
        $seen = [];

        // Pattern: links to Meeting.aspx with UUID
        $pattern = '/href="([^"]*Meeting\.aspx\?Id=([0-9a-f-]{36})[^"]*)"/i';
        if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $uuid = strtolower($match[2]);
                if (isset($seen[$uuid])) continue;
                $seen[$uuid] = true;

                $url = $match[1];
                if (strpos($url, 'http') !== 0) {
                    $url = $baseUrl . '/' . ltrim($url, '/');
                }

                // Try to extract meeting info from surrounding HTML context
                $info = $this->extractMeetingInfo($html, $match[0]);

                $links[] = [
                    'url' => $url,
                    'uuid' => $uuid,
                    'title' => $info['title'] ?? '',
                    'type' => $info['type'] ?? '',
                    'date' => $info['date'] ?? null,
                ];
            }
        }

        return $links;
    }

    /**
     * Try to extract meeting title, type, and date from HTML near the link
     */
    private function extractMeetingInfo(string $html, string $linkHtml): array {
        $info = ['title' => '', 'type' => '', 'date' => null];

        // Find the context around this link (200 chars before and after)
        $pos = strpos($html, $linkHtml);
        if ($pos === false) return $info;

        $start = max(0, $pos - 300);
        $length = 600 + strlen($linkHtml);
        $context = substr($html, $start, $length);

        // Extract title from link text or nearby elements
        if (preg_match('/>' . preg_quote($linkHtml, '/') . '[^<]*<\/a>/i', $html)) {
            // noop - complex pattern
        }

        // Look for meeting title in the link text itself
        if (preg_match('/>([^<]+)<\/a>/', substr($html, $pos, 300), $m)) {
            $info['title'] = trim($m[1]);
        }

        // Look for date patterns near the link
        $months = 'January|February|March|April|May|June|July|August|September|October|November|December';
        if (preg_match("/($months)\s+(\d{1,2}),?\s*(\d{4})/i", $context, $dm)) {
            $info['date'] = $this->parseDate($dm[1] . ' ' . $dm[2] . ', ' . $dm[3]);
        } elseif (preg_match('/(\d{4}-\d{2}-\d{2})/', $context, $dm)) {
            $info['date'] = $dm[1];
        }

        // Try to determine meeting type from title
        $titleLower = strtolower($info['title']);
        if (strpos($titleLower, 'regular') !== false && strpos($titleLower, 'council') !== false) {
            $info['type'] = 'Regular Council';
        } elseif (strpos($titleLower, 'special') !== false) {
            $info['type'] = 'Special Council';
        } elseif (strpos($titleLower, 'committee') !== false) {
            $info['type'] = $info['title'];
        } elseif (strpos($titleLower, 'public hearing') !== false) {
            $info['type'] = 'Public Hearing';
        } else {
            $info['type'] = $info['title'];
        }

        return $info;
    }

    /**
     * Extract the agenda content from an eSCRIBE meeting page
     * Looks for .AgendaItem containers and .Agenda wrapper
     */
    private function extractAgendaContent(string $html): string {
        // Try to extract the agenda section specifically
        // eSCRIBE uses .Agenda class for the agenda list wrapper

        // Method 1: Extract content within the Agenda container
        if (preg_match('/<div[^>]+class="[^"]*\bAgenda\b[^"]*"[^>]*>(.*?)<\/div>\s*<div[^>]+class="[^"]*\bDetails\b/is', $html, $m)) {
            return $m[1];
        }

        // Method 2: Collect all AgendaItem elements
        if (preg_match_all('/<div[^>]+class="[^"]*\bAgendaItem\b[^"]*"[^>]*>.*?<\/div>/is', $html, $matches)) {
            return implode("\n", $matches[0]);
        }

        // Method 3: Look for agenda item titles in links
        if (preg_match_all('/<[^>]+class="[^"]*AgendaItemTitle[^"]*"[^>]*>.*?<\/[^>]+>/is', $html, $matches)) {
            return implode("\n", $matches[0]);
        }

        // Method 4: Extract the main content area
        if (preg_match('/<div[^>]+id="[^"]*content[^"]*"[^>]*>(.*?)<\/div>\s*<(?:footer|div[^>]+id="footer")/is', $html, $m)) {
            return $m[1];
        }

        // Fallback: return the body content
        if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $m)) {
            return $m[1];
        }

        return $html;
    }

    /**
     * Try to extract a meeting date from the page content
     */
    private function extractDateFromPage(string $html): ?string {
        $months = 'January|February|March|April|May|June|July|August|September|October|November|December';

        // Look for date in the meeting title/header area
        if (preg_match("/($months)\s+(\d{1,2}),?\s+(\d{4})/i", $html, $m)) {
            return $this->parseDate($m[1] . ' ' . $m[2] . ', ' . $m[3]);
        }

        // ISO format
        if (preg_match('/(\d{4}-\d{2}-\d{2})/', $html, $m)) {
            return $m[1];
        }

        return null;
    }
}
