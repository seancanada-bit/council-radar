<?php
/**
 * DigestBuilder - Builds HTML/text email content for alert digests
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../functions.php';

class DigestBuilder
{
    private PDO $db;
    private string $dailyTemplate;
    private string $weeklyTemplate;

    public function __construct()
    {
        $this->db = DB::get();
        $this->dailyTemplate = file_get_contents(__DIR__ . '/../../templates/email/daily_alert.html');
        $this->weeklyTemplate = file_get_contents(__DIR__ . '/../../templates/email/weekly_digest.html');
    }

    /**
     * Build a full-detail daily alert for a paid subscriber
     *
     * @param int $subscriberId
     * @return array|null ['html','text','subject','items_count'] or null if no items
     */
    public function buildDailyAlert(int $subscriberId): ?array
    {
        $subscriber = $this->getSubscriber($subscriberId);
        if (!$subscriber) {
            return null;
        }

        $since = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $items = $this->getMatchingItems($subscriber, $since);

        if (empty($items)) {
            return null;
        }

        $grouped = $this->groupByMunicipality($items);
        $htmlContent = $this->renderDailyContent($grouped);
        $textContent = $this->renderDailyText($grouped);

        $dateRange = date('M j, Y');
        $subject = 'CouncilRadar Daily Alert - ' . $dateRange . ' (' . count($items) . ' items)';

        $html = $this->applyTemplate($this->dailyTemplate, [
            '{{CONTENT}}'         => $htmlContent,
            '{{DATE_RANGE}}'      => $dateRange,
            '{{UNSUBSCRIBE_URL}}' => $this->unsubscribeUrl($subscriber),
            '{{CONSENT_DATE}}'    => date('M j, Y', strtotime($subscriber['consent_date'])),
            '{{CASL_ADDRESS}}'    => h(CASL_MAILING_ADDRESS),
            '{{CASL_EMAIL}}'      => h(CASL_CONTACT_EMAIL),
        ]);

        return [
            'html'        => $html,
            'text'        => $textContent,
            'subject'     => $subject,
            'items_count' => count($items),
        ];
    }

    /**
     * Build a teaser weekly digest for a free subscriber
     *
     * @param int $subscriberId
     * @return array|null ['html','text','subject','items_count'] or null if no items
     */
    public function buildWeeklyDigest(int $subscriberId): ?array
    {
        $subscriber = $this->getSubscriber($subscriberId);
        if (!$subscriber) {
            return null;
        }

        $since = date('Y-m-d H:i:s', strtotime('-7 days'));
        $items = $this->getMatchingItems($subscriber, $since);

        if (empty($items)) {
            return null;
        }

        $grouped = $this->groupByMunicipality($items);
        $htmlContent = $this->renderWeeklyContent($grouped);
        $textContent = $this->renderWeeklyText($grouped);

        $dateStart = date('M j', strtotime('-7 days'));
        $dateEnd = date('M j, Y');
        $dateRange = $dateStart . ' - ' . $dateEnd;
        $subject = 'CouncilRadar Weekly Digest - ' . $dateRange . ' (' . count($items) . ' items)';

        $html = $this->applyTemplate($this->weeklyTemplate, [
            '{{CONTENT}}'         => $htmlContent,
            '{{DATE_RANGE}}'      => $dateRange,
            '{{UPGRADE_URL}}'     => SITE_URL . '/signup.php',
            '{{UNSUBSCRIBE_URL}}' => $this->unsubscribeUrl($subscriber),
            '{{CONSENT_DATE}}'    => date('M j, Y', strtotime($subscriber['consent_date'])),
            '{{CASL_ADDRESS}}'    => h(CASL_MAILING_ADDRESS),
            '{{CASL_EMAIL}}'      => h(CASL_CONTACT_EMAIL),
        ]);

        return [
            'html'        => $html,
            'text'        => $textContent,
            'subject'     => $subject,
            'items_count' => count($items),
        ];
    }

    // ---------------------------------------------------------------
    // Private helpers
    // ---------------------------------------------------------------

    private function getSubscriber(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM subscribers WHERE id = ? AND active = 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Fetch agenda items matching subscriber preferences since a given datetime
     */
    private function getMatchingItems(array $subscriber, string $since): array
    {
        $municipalityIds = json_decode($subscriber['municipalities_filter'] ?? '[]', true) ?: [];
        $keywords = json_decode($subscriber['keywords_filter'] ?? '[]', true) ?: [];

        if (empty($municipalityIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($municipalityIds), '?'));

        $sql = "SELECT ai.*, m.meeting_date, m.meeting_type, mu.name AS municipality_name
                FROM agenda_items ai
                JOIN meetings m ON ai.meeting_id = m.id
                JOIN municipalities mu ON m.municipality_id = mu.id
                WHERE mu.id IN ($placeholders)
                  AND ai.created_at >= ?
                ORDER BY ai.relevance_score DESC, m.meeting_date DESC";

        $params = array_merge($municipalityIds, [$since]);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $allItems = $stmt->fetchAll();

        // If the subscriber has keyword filters, narrow results to matching items
        if (!empty($keywords)) {
            $allItems = array_filter($allItems, function ($item) use ($keywords) {
                $haystack = strtolower($item['title'] . ' ' . ($item['description'] ?? ''));
                foreach ($keywords as $kw) {
                    if (strpos($haystack, strtolower($kw)) !== false) {
                        return true;
                    }
                }
                return false;
            });
            $allItems = array_values($allItems);
        }

        return $allItems;
    }

    private function groupByMunicipality(array $items): array
    {
        $grouped = [];
        foreach ($items as $item) {
            $name = $item['municipality_name'];
            if (!isset($grouped[$name])) {
                $grouped[$name] = [];
            }
            $grouped[$name][] = $item;
        }
        return $grouped;
    }

    // ---------------------------------------------------------------
    // Daily alert rendering (full detail)
    // ---------------------------------------------------------------

    private function renderDailyContent(array $grouped): string
    {
        $html = '';
        foreach ($grouped as $municipality => $items) {
            $html .= '<h3 style="margin:20px 0 10px 0; font-size:16px; color:#1a365d; border-bottom:2px solid #2b6cb0; padding-bottom:6px;">'
                    . h($municipality) . '</h3>';

            foreach ($items as $item) {
                $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:14px;">';
                $html .= '<tr><td style="padding:10px 12px; background-color:#f7fafc; border-radius:4px; border-left:3px solid #2b6cb0;">';

                // Title linked to source
                $title = h($item['title']);
                if (!empty($item['source_url'])) {
                    $html .= '<a href="' . h($item['source_url']) . '" style="font-size:14px; font-weight:bold; color:#2b6cb0; text-decoration:none;">' . $title . '</a>';
                } else {
                    $html .= '<p style="margin:0; font-size:14px; font-weight:bold; color:#1a365d;">' . $title . '</p>';
                }

                // Meeting info
                $html .= '<p style="margin:4px 0 0 0; font-size:12px; color:#a0aec0;">'
                        . h($item['meeting_type'] ?? 'Meeting') . ' - ' . date('M j, Y', strtotime($item['meeting_date']))
                        . '</p>';

                // Keyword badges
                $html .= $this->renderKeywordBadges($item);

                // Description
                if (!empty($item['description'])) {
                    $desc = h($item['description']);
                    if (strlen($desc) > 300) {
                        $desc = substr($desc, 0, 297) . '...';
                    }
                    $html .= '<p style="margin:8px 0 0 0; font-size:13px; color:#4a5568; line-height:1.5;">' . $desc . '</p>';
                }

                $html .= '</td></tr></table>';
            }
        }
        return $html;
    }

    private function renderDailyText(array $grouped): string
    {
        $text = "CouncilRadar Daily Alert - " . date('M j, Y') . "\n";
        $text .= str_repeat('=', 50) . "\n\n";

        foreach ($grouped as $municipality => $items) {
            $text .= strtoupper($municipality) . "\n";
            $text .= str_repeat('-', strlen($municipality)) . "\n\n";

            foreach ($items as $item) {
                $text .= "  " . $item['title'] . "\n";
                $text .= "  " . ($item['meeting_type'] ?? 'Meeting') . ' - ' . date('M j, Y', strtotime($item['meeting_date'])) . "\n";
                if (!empty($item['source_url'])) {
                    $text .= "  Link: " . $item['source_url'] . "\n";
                }
                if (!empty($item['description'])) {
                    $desc = $item['description'];
                    if (strlen($desc) > 300) {
                        $desc = substr($desc, 0, 297) . '...';
                    }
                    $text .= "  " . $desc . "\n";
                }
                $text .= "\n";
            }
        }

        return $text;
    }

    // ---------------------------------------------------------------
    // Weekly digest rendering (teaser only)
    // ---------------------------------------------------------------

    private function renderWeeklyContent(array $grouped): string
    {
        $html = '';
        foreach ($grouped as $municipality => $items) {
            $html .= '<h3 style="margin:20px 0 10px 0; font-size:16px; color:#1a365d; border-bottom:2px solid #2b6cb0; padding-bottom:6px;">'
                    . h($municipality) . ' (' . count($items) . ' items)</h3>';

            foreach ($items as $item) {
                $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:10px;">';
                $html .= '<tr><td style="padding:8px 12px; background-color:#f7fafc; border-radius:4px;">';

                // Title without link (teaser)
                $html .= '<p style="margin:0; font-size:14px; font-weight:bold; color:#1a365d;">' . h($item['title']) . '</p>';

                // Meeting info
                $html .= '<p style="margin:4px 0 0 0; font-size:12px; color:#a0aec0;">'
                        . h($item['meeting_type'] ?? 'Meeting') . ' - ' . date('M j, Y', strtotime($item['meeting_date']))
                        . '</p>';

                // Keyword tags
                $html .= $this->renderKeywordBadges($item);

                // No description, no source link for free tier

                $html .= '</td></tr></table>';
            }
        }
        return $html;
    }

    private function renderWeeklyText(array $grouped): string
    {
        $dateStart = date('M j', strtotime('-7 days'));
        $dateEnd = date('M j, Y');
        $text = "CouncilRadar Weekly Digest - " . $dateStart . " - " . $dateEnd . "\n";
        $text .= str_repeat('=', 50) . "\n\n";

        foreach ($grouped as $municipality => $items) {
            $text .= strtoupper($municipality) . " (" . count($items) . " items)\n";
            $text .= str_repeat('-', strlen($municipality)) . "\n\n";

            foreach ($items as $item) {
                $text .= "  " . $item['title'] . "\n";
                $text .= "  " . ($item['meeting_type'] ?? 'Meeting') . ' - ' . date('M j, Y', strtotime($item['meeting_date'])) . "\n\n";
            }
        }

        $text .= "\nGet full details with CouncilRadar Professional - \$19/month\n";
        $text .= "Upgrade: " . SITE_URL . "/signup.php\n";

        return $text;
    }

    // ---------------------------------------------------------------
    // Shared helpers
    // ---------------------------------------------------------------

    private function renderKeywordBadges(array $item): string
    {
        $matched = json_decode($item['matched_keywords'] ?? '[]', true) ?: [];
        if (empty($matched)) {
            return '';
        }

        $html = '<p style="margin:6px 0 0 0;">';
        foreach ($matched as $kw) {
            $html .= '<span style="display:inline-block; background-color:#2b6cb0; color:#ffffff; font-size:11px; padding:2px 8px; border-radius:10px; margin:2px 4px 2px 0;">'
                    . h($kw) . '</span>';
        }
        $html .= '</p>';
        return $html;
    }

    private function unsubscribeUrl(array $subscriber): string
    {
        return SITE_URL . '/unsubscribe.php?id=' . (int)$subscriber['id'] . '&email=' . urlencode($subscriber['email']);
    }

    private function applyTemplate(string $template, array $replacements): string
    {
        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }
}
