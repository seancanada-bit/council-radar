<?php
/**
 * Scraper for CivicWeb municipal portals
 *
 * CivicWeb portals (powered by iCompass/Diligent) organize documents in folder hierarchies:
 *   /filepro/documents/{folderId}  - browse folders
 *   /document/{docId}              - view a document
 *
 * The scraper navigates: Portal root -> Agendas folder -> year subfolder -> individual documents
 * It uses the JSON data embedded in the filepro pages to find document IDs and metadata.
 */

require_once __DIR__ . '/BaseScraper.php';

class CivicWebScraper extends BaseScraper {

    // Known agenda folder names to look for (case-insensitive partial matches)
    private const AGENDA_FOLDER_PATTERNS = [
        'agenda',
        'council agenda',
        'committee of the whole agenda',
        'public hearing agenda',
    ];

    // Meeting type mapping from folder names
    private const MEETING_TYPE_MAP = [
        'council agenda' => 'Regular Council',
        'committee of the whole' => 'Committee of the Whole',
        'public hearing' => 'Public Hearing',
        'advisory planning' => 'Advisory Planning Commission',
    ];

    public function scrapeAll(): array {
        $municipalities = $this->getMunicipalities('civicweb');
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

        logMessage('scrape.log', "Scraping CivicWeb: {$muni['name']} ({$baseUrl})");

        // Step 1: Fetch the portal root to find document folder IDs
        $portalUrl = $baseUrl . '/Portal/MeetingTypeList.aspx';
        $response = $this->fetch($portalUrl);

        if ($response['error']) {
            // Try the filepro documents root as fallback
            $portalUrl = $baseUrl . '/filepro/documents';
            $response = $this->fetch($portalUrl);
            if ($response['error']) {
                throw new Exception("Failed to fetch portal: {$response['error']}");
            }
        }

        // Step 2: Find agenda folder links
        $agendaFolders = $this->findAgendaFolders($response['body'], $baseUrl);

        if (empty($agendaFolders)) {
            logMessage('scrape.log', "  No agenda folders found for {$muni['name']}");
            return ['meetings_found' => 0, 'folders_checked' => 0];
        }

        logMessage('scrape.log', "  Found " . count($agendaFolders) . " agenda folder(s)");

        // Step 3: For each agenda folder, find recent documents
        foreach ($agendaFolders as $folder) {
            $this->rateLimit();

            $folderResponse = $this->fetch($folder['url']);
            if ($folderResponse['error']) {
                logMessage('scrape.log', "  Failed to fetch folder {$folder['name']}: {$folderResponse['error']}");
                continue;
            }

            // Look for year subfolders or direct document listings
            $yearFolders = $this->findYearFolders($folderResponse['body'], $baseUrl);
            $currentYear = (int) date('Y');

            // Check current year and previous year folders
            $yearsToCheck = [$currentYear, $currentYear - 1];
            $foldersToScan = [];

            if (!empty($yearFolders)) {
                foreach ($yearsToCheck as $year) {
                    if (isset($yearFolders[$year])) {
                        $foldersToScan[] = $yearFolders[$year];
                    }
                }
            } else {
                // No year subfolders - documents are listed directly in this folder
                $foldersToScan[] = ['url' => $folder['url'], 'body' => $folderResponse['body']];
            }

            foreach ($foldersToScan as $scanFolder) {
                if (!isset($scanFolder['body'])) {
                    $this->rateLimit();
                    $subResponse = $this->fetch($scanFolder['url']);
                    if ($subResponse['error']) continue;
                    $scanFolder['body'] = $subResponse['body'];
                }

                $documents = $this->findDocuments($scanFolder['body'], $baseUrl);
                $recentDocs = $this->filterRecentDocuments($documents);

                logMessage('scrape.log', "  Found " . count($recentDocs) . " recent document(s) in folder");

                foreach ($recentDocs as $doc) {
                    $docUrl = $doc['url'];

                    if ($this->meetingExists($docUrl)) {
                        continue;
                    }

                    $this->rateLimit();

                    $docResponse = $this->fetch($docUrl);
                    if ($docResponse['error']) {
                        logMessage('scrape.log', "  Failed to fetch document: {$docResponse['error']}");
                        continue;
                    }

                    $meetingType = $this->detectMeetingType($folder['name'], $doc['title']);
                    $meetingDate = $this->extractMeetingDate($doc['title'], $doc['modified'] ?? null);

                    $this->insertMeeting(
                        $muni['id'],
                        $meetingType,
                        $meetingDate,
                        $docUrl,
                        $docResponse['body']
                    );

                    $meetingsFound++;
                    logMessage('scrape.log', "  Stored: {$doc['title']} ({$docUrl})");
                }
            }
        }

        return ['meetings_found' => $meetingsFound, 'folders_checked' => count($agendaFolders)];
    }

    /**
     * Find agenda-related folder links from the portal page HTML
     */
    private function findAgendaFolders(string $html, string $baseUrl): array {
        $folders = [];

        // Pattern 1: filepro document links
        if (preg_match_all('/<a[^>]+href="([^"]*\/filepro\/documents\/(\d+))"[^>]*>([^<]+)/i', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $name = trim(strip_tags($match[3]));
                if ($this->isAgendaFolder($name)) {
                    $url = $match[1];
                    if (strpos($url, 'http') !== 0) {
                        $url = $baseUrl . $url;
                    }
                    $folders[] = ['name' => $name, 'url' => $url, 'id' => $match[2]];
                }
            }
        }

        // Pattern 2: JSON data in script blocks (CivicWeb embeds folder data as JSON)
        if (preg_match_all('/\{[^}]*"Name"\s*:\s*"([^"]+)"[^}]*"ContentUrl"\s*:\s*"([^"]+)"[^}]*/i', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $name = $match[1];
                if ($this->isAgendaFolder($name)) {
                    $url = $match[2];
                    if (strpos($url, 'http') !== 0) {
                        $url = $baseUrl . $url;
                    }
                    // Avoid duplicate URLs
                    $exists = false;
                    foreach ($folders as $f) {
                        if ($f['url'] === $url) { $exists = true; break; }
                    }
                    if (!$exists) {
                        $folders[] = ['name' => $name, 'url' => $url];
                    }
                }
            }
        }

        // If no agenda-specific folders found, look for any folder with "Agenda" in it
        if (empty($folders)) {
            if (preg_match_all('/<a[^>]+href="([^"]*(?:\/filepro\/documents\/|\/document\/)(\d+))"[^>]*>([^<]*agenda[^<]*)/i', $html, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $url = $match[1];
                    if (strpos($url, 'http') !== 0) {
                        $url = $baseUrl . $url;
                    }
                    $folders[] = ['name' => trim($match[3]), 'url' => $url];
                }
            }
        }

        return $folders;
    }

    /**
     * Check if a folder name indicates it contains agendas
     */
    private function isAgendaFolder(string $name): bool {
        $lower = strtolower($name);
        foreach (self::AGENDA_FOLDER_PATTERNS as $pattern) {
            if (strpos($lower, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Find year subfolder links and return mapped by year
     */
    private function findYearFolders(string $html, string $baseUrl): array {
        $years = [];
        $currentYear = (int) date('Y');

        // Match links that are just year numbers (2024, 2025, 2026, etc.)
        // Pattern: links to /filepro/documents/{id} with year as text
        if (preg_match_all('/<a[^>]+href="([^"]*\/filepro\/documents\/(\d+))"[^>]*>\s*(20\d{2})\s*</i', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $year = (int) $match[3];
                if ($year >= $currentYear - 1 && $year <= $currentYear + 1) {
                    $url = $match[1];
                    if (strpos($url, 'http') !== 0) {
                        $url = $baseUrl . $url;
                    }
                    $years[$year] = ['url' => $url, 'id' => $match[2]];
                }
            }
        }

        // Also try JSON data pattern
        if (preg_match_all('/\{[^}]*"Name"\s*:\s*"(20\d{2})"[^}]*"ContentUrl"\s*:\s*"([^"]+)"[^}]*/i', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $year = (int) $match[1];
                if ($year >= $currentYear - 1 && $year <= $currentYear + 1 && !isset($years[$year])) {
                    $url = $match[2];
                    if (strpos($url, 'http') !== 0) {
                        $url = $baseUrl . $url;
                    }
                    $years[$year] = ['url' => $url];
                }
            }
        }

        return $years;
    }

    /**
     * Find individual document entries from a folder page
     */
    private function findDocuments(string $html, string $baseUrl): array {
        $docs = [];

        // Pattern 1: JSON embedded data with document details
        // CivicWeb typically embeds document metadata as JSON arrays
        if (preg_match_all('/\{[^{}]*"Name"\s*:\s*"([^"]+)"[^{}]*"ContentUrl"\s*:\s*"(\/document\/\d+)"[^{}]*"Modified"\s*:\s*"([^"]+)"[^{}]*/i', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $url = $baseUrl . $match[2];
                $docs[] = [
                    'title' => $match[1],
                    'url' => $url,
                    'modified' => $match[3],
                ];
            }
        }

        // Pattern 2: Also try reversed field order in JSON
        if (preg_match_all('/\{[^{}]*"ContentUrl"\s*:\s*"(\/document\/\d+)"[^{}]*"Name"\s*:\s*"([^"]+)"[^{}]*"Modified"\s*:\s*"([^"]+)"[^{}]*/i', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $url = $baseUrl . $match[1];
                // Avoid duplicates
                $exists = false;
                foreach ($docs as $d) {
                    if ($d['url'] === $url) { $exists = true; break; }
                }
                if (!$exists) {
                    $docs[] = [
                        'title' => $match[2],
                        'url' => $url,
                        'modified' => $match[3],
                    ];
                }
            }
        }

        // Pattern 3: Direct HTML links to /document/{id}
        if (empty($docs)) {
            if (preg_match_all('/<a[^>]+href="([^"]*\/document\/(\d+))"[^>]*>([^<]+)/i', $html, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $url = $match[1];
                    if (strpos($url, 'http') !== 0) {
                        $url = $baseUrl . $url;
                    }
                    $docs[] = [
                        'title' => trim(strip_tags($match[3])),
                        'url' => $url,
                        'modified' => null,
                    ];
                }
            }
        }

        return $docs;
    }

    /**
     * Filter documents to only those from the last 7 days or next 14 days
     */
    private function filterRecentDocuments(array $documents): array {
        $cutoffPast = strtotime('-7 days');
        $cutoffFuture = strtotime('+14 days');
        $now = time();
        $recent = [];

        foreach ($documents as $doc) {
            // Try to extract date from the document title first
            $date = $this->extractMeetingDate($doc['title'], $doc['modified'] ?? null);

            if ($date) {
                $ts = strtotime($date);
                if ($ts >= $cutoffPast && $ts <= $cutoffFuture) {
                    $recent[] = $doc;
                    continue;
                }
            }

            // If we can't determine the date, try the modified timestamp
            if (!empty($doc['modified'])) {
                $modTs = strtotime($doc['modified']);
                if ($modTs && $modTs >= $cutoffPast) {
                    $recent[] = $doc;
                    continue;
                }
            }

            // If no date info at all, include it (better to over-include than miss)
            if (!$date && empty($doc['modified'])) {
                $recent[] = $doc;
            }
        }

        return $recent;
    }

    /**
     * Extract a meeting date from the document title
     * Typical formats: "March 16 - Council Agenda", "January 19, 2026 Council Meeting"
     */
    private function extractMeetingDate(string $title, ?string $modified = null): ?string {
        // Pattern: "Month Day" at the beginning of the title
        $months = 'January|February|March|April|May|June|July|August|September|October|November|December';

        // "March 16 - Council Agenda" or "March 16, 2026 Council Agenda"
        if (preg_match("/($months)\s+(\d{1,2})(?:\s*,?\s*(\d{4}))?/i", $title, $m)) {
            $year = !empty($m[3]) ? $m[3] : date('Y');
            $dateStr = $m[1] . ' ' . $m[2] . ', ' . $year;
            return $this->parseDate($dateStr);
        }

        // "2026-03-16" ISO format
        if (preg_match('/(\d{4}-\d{2}-\d{2})/', $title, $m)) {
            return $m[1];
        }

        // Fall back to modified date if available
        if ($modified) {
            return $this->parseDate($modified);
        }

        return null;
    }

    /**
     * Detect the meeting type from folder name and document title
     */
    private function detectMeetingType(string $folderName, string $docTitle): string {
        $combined = strtolower($folderName . ' ' . $docTitle);

        foreach (self::MEETING_TYPE_MAP as $pattern => $type) {
            if (strpos($combined, $pattern) !== false) {
                return $type;
            }
        }

        // Try to extract from the document title after the dash
        if (preg_match('/\d+\s*[-–]\s*(.+?)(?:\s*agenda)?$/i', $docTitle, $m)) {
            return trim($m[1]);
        }

        return 'Council Meeting';
    }
}
