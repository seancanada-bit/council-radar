<?php
/**
 * Scraper for CivicWeb municipal portals
 *
 * CivicWeb portals (powered by iCompass/Diligent) organize documents in folder hierarchies:
 *   /filepro/documents/{folderId}  - browse folders
 *   /document/{docId}              - view a document
 *
 * The portal page at /filepro/documents embeds JSON data (initialDocumentList) with fields:
 *   Id, Title, ContentUrl, DateUpdated, FileFormat, Folder, IsPublic
 *
 * Navigation: Portal root -> Agendas folder -> meeting type subfolders -> year folder -> documents
 */

require_once __DIR__ . '/BaseScraper.php';

class CivicWebScraper extends BaseScraper {

    // Known agenda folder names to look for (case-insensitive partial matches)
    private const AGENDA_FOLDER_PATTERNS = [
        'agenda',
    ];

    // Meeting type mapping from folder names
    private const MEETING_TYPE_MAP = [
        'council agenda' => 'Regular Council',
        'committee of the whole' => 'Committee of the Whole',
        'public hearing' => 'Public Hearing',
        'advisory planning' => 'Advisory Planning Commission',
        'advisory design' => 'Advisory Design Panel',
        'parks' => 'Parks Committee',
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

        // Step 1: Fetch the portal root document page
        $portalUrl = $baseUrl . '/filepro/documents';
        $response = $this->fetch($portalUrl);

        if ($response['error']) {
            throw new Exception("Failed to fetch portal: {$response['error']}");
        }

        // Step 2: Find the Agendas folder from the root
        $items = $this->extractJsonItems($response['body']);
        $agendaFolder = null;

        foreach ($items as $item) {
            if (!empty($item['Folder']) && $this->isAgendaFolder($item['Title'] ?? '')) {
                $agendaFolder = $item;
                break;
            }
        }

        // Fallback: try HTML links
        if (!$agendaFolder) {
            $agendaFolders = $this->findAgendaFoldersHtml($response['body'], $baseUrl);
            if (!empty($agendaFolders)) {
                $agendaFolder = $agendaFolders[0];
            }
        }

        if (!$agendaFolder) {
            logMessage('scrape.log', "  No agenda folder found for {$muni['name']}");
            return ['meetings_found' => 0, 'folders_checked' => 0];
        }

        $agendaUrl = $baseUrl . '/filepro/documents/' . ($agendaFolder['Id'] ?? $agendaFolder['id'] ?? '');
        logMessage('scrape.log', "  Found agendas folder: " . ($agendaFolder['Title'] ?? $agendaFolder['name'] ?? 'unknown'));

        // Step 3: Fetch the agendas folder to find sub-folders (Council Agendas, Public Hearing, etc.)
        $this->rateLimit();
        $agendaResponse = $this->fetch($agendaUrl);
        if ($agendaResponse['error']) {
            throw new Exception("Failed to fetch agendas folder: {$agendaResponse['error']}");
        }

        $subItems = $this->extractJsonItems($agendaResponse['body']);
        $meetingTypeFolders = [];

        foreach ($subItems as $item) {
            if (!empty($item['Folder'])) {
                $meetingTypeFolders[] = $item;
            }
        }

        // If no sub-folders, treat this folder as having documents directly
        if (empty($meetingTypeFolders)) {
            $meetingTypeFolders = [['Id' => $agendaFolder['Id'] ?? $agendaFolder['id'], 'Title' => 'Agendas', '_body' => $agendaResponse['body']]];
        }

        logMessage('scrape.log', "  Found " . count($meetingTypeFolders) . " meeting type folder(s)");

        // Step 4: For each meeting type folder, find year folders, then documents
        foreach ($meetingTypeFolders as $mtFolder) {
            $folderName = $mtFolder['Title'] ?? 'Agendas';

            if (isset($mtFolder['_body'])) {
                $mtBody = $mtFolder['_body'];
            } else {
                $this->rateLimit();
                $mtUrl = $baseUrl . '/filepro/documents/' . $mtFolder['Id'];
                $mtResponse = $this->fetch($mtUrl);
                if ($mtResponse['error']) {
                    logMessage('scrape.log', "  Failed to fetch folder '{$folderName}': {$mtResponse['error']}");
                    continue;
                }
                $mtBody = $mtResponse['body'];
            }

            $mtItems = $this->extractJsonItems($mtBody);

            // Look for year folders
            $currentYear = (int) date('Y');
            $yearFolders = [];
            $directDocs = [];

            foreach ($mtItems as $item) {
                if (!empty($item['Folder'])) {
                    $title = $item['Title'] ?? '';
                    if (preg_match('/^(20\d{2})$/', $title, $ym)) {
                        $year = (int) $ym[1];
                        if ($year >= $currentYear - 1 && $year <= $currentYear + 1) {
                            $yearFolders[$year] = $item;
                        }
                    }
                } else {
                    $directDocs[] = $item;
                }
            }

            // If we found year folders, fetch them; otherwise use direct docs
            $docsToProcess = [];

            if (!empty($yearFolders)) {
                foreach ($yearFolders as $year => $yf) {
                    $this->rateLimit();
                    $yfUrl = $baseUrl . '/filepro/documents/' . $yf['Id'];
                    $yfResponse = $this->fetch($yfUrl);
                    if ($yfResponse['error']) continue;

                    $yearDocs = $this->extractJsonItems($yfResponse['body']);
                    foreach ($yearDocs as $doc) {
                        if (empty($doc['Folder'])) {
                            $docsToProcess[] = $doc;
                        }
                    }
                }
            } else {
                $docsToProcess = $directDocs;
            }

            logMessage('scrape.log', "  Folder '{$folderName}': " . count($docsToProcess) . " document(s) found");

            // Filter to recent documents and fetch each
            $recentDocs = $this->filterRecentJsonDocs($docsToProcess);
            logMessage('scrape.log', "  Folder '{$folderName}': " . count($recentDocs) . " recent document(s)");

            foreach ($recentDocs as $doc) {
                $docUrl = $baseUrl . ($doc['ContentUrl'] ?? '/document/' . $doc['Id']);

                if ($this->meetingExists($docUrl)) {
                    continue;
                }

                // Skip PDF files - we want the HTML/DOCX agenda content
                $format = strtolower($doc['FileFormat'] ?? '');
                if ($format === 'pdf') {
                    // Check if there's a matching non-PDF version
                    $hasPair = false;
                    $docTitle = $doc['Title'] ?? '';
                    foreach ($docsToProcess as $other) {
                        if ($other['Id'] !== $doc['Id']
                            && ($other['Title'] ?? '') === $docTitle
                            && strtolower($other['FileFormat'] ?? '') !== 'pdf') {
                            $hasPair = true;
                            break;
                        }
                    }
                    if ($hasPair) continue; // Skip the PDF, we'll get the DOCX version
                }

                $this->rateLimit();

                $docResponse = $this->fetch($docUrl);
                if ($docResponse['error']) {
                    logMessage('scrape.log', "  Failed to fetch document: {$docResponse['error']}");
                    continue;
                }

                $meetingType = $this->detectMeetingType($folderName, $doc['Title'] ?? '');
                $meetingDate = $this->extractMeetingDate($doc['Title'] ?? '', $doc['DateUpdated'] ?? null);

                $this->insertMeeting(
                    $muni['id'],
                    $meetingType,
                    $meetingDate,
                    $docUrl,
                    $docResponse['body']
                );

                $meetingsFound++;
                logMessage('scrape.log', "  Stored: " . ($doc['Title'] ?? 'unknown') . " ({$docUrl})");
            }
        }

        return ['meetings_found' => $meetingsFound, 'folders_checked' => count($meetingTypeFolders)];
    }

    /**
     * Extract JSON items from embedded initialDocumentList or similar JSON data
     */
    private function extractJsonItems(string $html): array {
        // Look for initialDocumentList JSON array
        if (preg_match('/initialDocumentList\s*=\s*(\[.*?\])\s*;/s', $html, $m)) {
            $items = json_decode($m[1], true);
            if (is_array($items)) {
                return $items;
            }
        }

        // Fallback: try to find JSON array with document-like objects
        if (preg_match('/\[\s*\{[^}]*"Id"\s*:\s*\d+[^}]*"Title"\s*:.*?\]\s*;/s', $html, $m)) {
            $items = json_decode($m[0], true);
            if (is_array($items)) {
                return $items;
            }
        }

        // Fallback: extract individual JSON objects with Id and Title
        $items = [];
        if (preg_match_all('/\{\s*"Id"\s*:\s*(\d+)\s*,\s*"Title"\s*:\s*"([^"]+)"[^}]*\}/s', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $obj = json_decode($match[0], true);
                if (is_array($obj)) {
                    $items[] = $obj;
                } else {
                    $items[] = ['Id' => (int) $match[1], 'Title' => $match[2]];
                }
            }
        }

        return $items;
    }

    /**
     * Fallback: find agenda folders via HTML links
     */
    private function findAgendaFoldersHtml(string $html, string $baseUrl): array {
        $folders = [];

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
     * Filter JSON document items to only those from the last 7 days or next 14 days
     */
    private function filterRecentJsonDocs(array $documents): array {
        $cutoffPast = strtotime('-7 days');
        $cutoffFuture = strtotime('+14 days');
        $recent = [];

        foreach ($documents as $doc) {
            $title = $doc['Title'] ?? '';
            $dateUpdated = $doc['DateUpdated'] ?? null;

            // Try to extract meeting date from the title
            $meetingDate = $this->extractMeetingDate($title, $dateUpdated);

            if ($meetingDate) {
                $ts = strtotime($meetingDate);
                if ($ts >= $cutoffPast && $ts <= $cutoffFuture) {
                    $recent[] = $doc;
                    continue;
                }
            }

            // Fallback to DateUpdated field
            if ($dateUpdated) {
                $ts = strtotime($dateUpdated);
                if ($ts && $ts >= $cutoffPast) {
                    $recent[] = $doc;
                    continue;
                }
            }

            // If no date info at all, include it
            if (!$meetingDate && !$dateUpdated) {
                $recent[] = $doc;
            }
        }

        return $recent;
    }

    /**
     * Extract a meeting date from the document title
     * Typical formats: "March 16 - Council Agenda", "January 19, 2026 Council Meeting"
     */
    private function extractMeetingDate(string $title, ?string $dateUpdated = null): ?string {
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

        // Fall back to DateUpdated
        if ($dateUpdated) {
            return $this->parseDate($dateUpdated);
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
