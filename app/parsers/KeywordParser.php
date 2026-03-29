<?php
/**
 * Parses raw meeting HTML into agenda items and matches keywords
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

class KeywordParser {

    private PDO $db;

    public function __construct() {
        $this->db = DB::get();
    }

    /**
     * Parse all unparsed meetings
     */
    public function parseAll(): array {
        $stmt = $this->db->query('SELECT * FROM meetings WHERE parsed = 0');
        $meetings = $stmt->fetchAll();

        $results = ['total' => count($meetings), 'parsed' => 0, 'items_created' => 0];

        foreach ($meetings as $meeting) {
            try {
                $itemCount = $this->parseMeeting($meeting);
                $results['parsed']++;
                $results['items_created'] += $itemCount;
            } catch (Exception $e) {
                logMessage('parse.log', "ERROR parsing meeting #{$meeting['id']}: " . $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * Parse a single meeting's raw HTML into agenda items
     */
    public function parseMeeting(array $meeting): int {
        $html = $meeting['raw_html'];
        if (empty($html)) {
            $this->markParsed($meeting['id']);
            return 0;
        }

        // Extract individual agenda items
        $items = $this->extractItems($html);

        if (empty($items)) {
            // Treat the whole content as one item
            $text = $this->stripHtml($html);
            if (!empty(trim($text))) {
                $items = [['number' => '', 'title' => 'Full Agenda', 'description' => $text]];
            }
        }

        $count = 0;
        foreach ($items as $item) {
            $keywords = $this->matchKeywords($item['title'] . ' ' . $item['description']);
            $relevance = $this->calculateRelevance($keywords);

            $stmt = $this->db->prepare(
                'INSERT INTO agenda_items (meeting_id, item_number, title, description, keywords_matched, relevance_score)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $meeting['id'],
                $item['number'],
                mb_substr($item['title'], 0, 500),
                $item['description'],
                json_encode($keywords),
                $relevance,
            ]);
            $count++;
        }

        $this->markParsed($meeting['id']);

        logMessage('parse.log', "Parsed meeting #{$meeting['id']}: {$count} items, max relevance: " . $this->getMaxRelevance($meeting['id']));

        return $count;
    }

    /**
     * Extract individual agenda items from HTML
     */
    private function extractItems(string $html): array {
        $items = [];

        // Strategy 1: eSCRIBE AgendaItem elements
        if (preg_match_all('/<div[^>]+class="[^"]*AgendaItem[^"]*"[^>]*>(.*?)<\/div>/is', $html, $matches)) {
            foreach ($matches[1] as $i => $itemHtml) {
                $title = '';
                if (preg_match('/<[^>]+class="[^"]*AgendaItemTitle[^"]*"[^>]*>(.*?)<\/[^>]+>/is', $itemHtml, $tm)) {
                    $title = $this->stripHtml($tm[1]);
                }
                $items[] = [
                    'number' => (string) ($i + 1),
                    'title' => $title ?: $this->stripHtml(mb_substr($itemHtml, 0, 200)),
                    'description' => $this->stripHtml($itemHtml),
                ];
            }
            if (!empty($items)) return $items;
        }

        // Strategy 2: Heading-delimited sections (h2, h3, h4)
        $sections = preg_split('/<h[2-4][^>]*>/i', $html);
        if (count($sections) > 2) {
            // First section is usually preamble, skip it
            for ($i = 1; $i < count($sections); $i++) {
                $section = $sections[$i];
                $title = '';
                if (preg_match('/^(.*?)<\/h[2-4]>/is', $section, $hm)) {
                    $title = $this->stripHtml($hm[1]);
                    $section = substr($section, strlen($hm[0]));
                }
                $desc = $this->stripHtml($section);
                if (!empty(trim($title)) || !empty(trim($desc))) {
                    $items[] = [
                        'number' => (string) $i,
                        'title' => $title,
                        'description' => $desc,
                    ];
                }
            }
            if (!empty($items)) return $items;
        }

        // Strategy 3: Numbered item patterns (1., 2., 3. or A., B., C.)
        $text = $this->stripHtml($html);
        $parts = preg_split('/(?:^|\n)\s*(\d{1,2})\.\s+/m', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (count($parts) > 2) {
            // parts[0] = preamble, then alternating: number, content
            for ($i = 1; $i < count($parts) - 1; $i += 2) {
                $number = $parts[$i];
                $content = trim($parts[$i + 1] ?? '');
                if (empty($content)) continue;

                // First line is the title, rest is description
                $lines = preg_split('/\n/', $content, 2);
                $items[] = [
                    'number' => $number,
                    'title' => trim($lines[0]),
                    'description' => trim($lines[1] ?? ''),
                ];
            }
            if (!empty($items)) return $items;
        }

        // Strategy 4: Look for "ITEM" or "AGENDA ITEM" delimiters
        $parts = preg_split('/(?:AGENDA\s+)?ITEM\s+(\d+[a-z]?)/i', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (count($parts) > 2) {
            for ($i = 1; $i < count($parts) - 1; $i += 2) {
                $number = $parts[$i];
                $content = trim($parts[$i + 1] ?? '');
                if (empty($content)) continue;

                $lines = preg_split('/\n/', $content, 2);
                $items[] = [
                    'number' => $number,
                    'title' => trim($lines[0]),
                    'description' => trim($lines[1] ?? ''),
                ];
            }
            if (!empty($items)) return $items;
        }

        // Strategy 5: Split by bold/strong tags
        $boldSections = preg_split('/<(?:b|strong)[^>]*>/i', $html);
        if (count($boldSections) > 3) {
            for ($i = 1; $i < count($boldSections); $i++) {
                $section = $boldSections[$i];
                $title = '';
                if (preg_match('/^(.*?)<\/(?:b|strong)>/is', $section, $bm)) {
                    $title = $this->stripHtml($bm[1]);
                    $section = substr($section, strlen($bm[0]));
                }
                $desc = $this->stripHtml($section);
                if (!empty(trim($title))) {
                    $items[] = [
                        'number' => (string) $i,
                        'title' => $title,
                        'description' => mb_substr($desc, 0, 2000),
                    ];
                }
            }
            if (!empty($items)) return $items;
        }

        return $items;
    }

    /**
     * Match keywords against text content
     * Returns array of matched keywords with their tier
     */
    private function matchKeywords(string $text): array {
        $matched = [];
        $textLower = mb_strtolower($text);

        $tiers = [
            'primary' => KEYWORDS_PRIMARY,
            'secondary' => KEYWORDS_SECONDARY,
            'tertiary' => KEYWORDS_TERTIARY,
        ];

        foreach ($tiers as $tier => $keywords) {
            foreach ($keywords as $keyword) {
                $keywordLower = mb_strtolower($keyword);

                // Use word boundary matching where practical
                // For multi-word keywords, simple containment check is fine
                if (mb_strlen($keyword) <= 4) {
                    // Short keywords - require word boundaries to avoid false positives
                    $pattern = '/\b' . preg_quote($keywordLower, '/') . '\b/iu';
                    if (preg_match($pattern, $textLower)) {
                        $matched[] = ['keyword' => $keyword, 'tier' => $tier];
                    }
                } else {
                    // Longer keywords - containment check is sufficient
                    if (mb_strpos($textLower, $keywordLower) !== false) {
                        $matched[] = ['keyword' => $keyword, 'tier' => $tier];
                    }
                }
            }
        }

        return $matched;
    }

    /**
     * Calculate relevance score from matched keywords
     * 3 = primary, 2 = secondary, 1 = tertiary, 0 = no match
     */
    private function calculateRelevance(array $keywords): int {
        if (empty($keywords)) return 0;

        $maxScore = 0;
        foreach ($keywords as $kw) {
            $score = match ($kw['tier']) {
                'primary' => 3,
                'secondary' => 2,
                'tertiary' => 1,
                default => 0,
            };
            $maxScore = max($maxScore, $score);
        }
        return $maxScore;
    }

    /**
     * Mark a meeting as parsed
     */
    private function markParsed(int $meetingId): void {
        $stmt = $this->db->prepare('UPDATE meetings SET parsed = 1 WHERE id = ?');
        $stmt->execute([$meetingId]);
    }

    /**
     * Get the max relevance score for a meeting's items
     */
    private function getMaxRelevance(int $meetingId): int {
        $stmt = $this->db->prepare('SELECT MAX(relevance_score) FROM agenda_items WHERE meeting_id = ?');
        $stmt->execute([$meetingId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Strip HTML and normalize whitespace
     */
    private function stripHtml(string $html): string {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }
}
