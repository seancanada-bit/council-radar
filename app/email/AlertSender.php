<?php
/**
 * AlertSender - Orchestrates building and sending alert emails to subscribers
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/PostmarkClient.php';
require_once __DIR__ . '/DigestBuilder.php';

class AlertSender
{
    private PDO $db;
    private PostmarkClient $postmark;
    private DigestBuilder $builder;

    public function __construct()
    {
        $this->db       = DB::get();
        $this->postmark = new PostmarkClient();
        $this->builder  = new DigestBuilder();
    }

    /**
     * Send daily alerts to all active paid subscribers with frequency=daily
     *
     * @return array Summary stats: sent, skipped, failed
     */
    public function sendDailyAlerts(): array
    {
        $stats = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

        $stmt = $this->db->prepare(
            "SELECT id, email FROM subscribers
             WHERE active = 1
               AND tier IN ('professional', 'firm')
               AND frequency = 'daily'"
        );
        $stmt->execute();
        $subscribers = $stmt->fetchAll();

        logMessage('email.log', "Daily alerts: found " . count($subscribers) . " eligible subscribers");

        foreach ($subscribers as $sub) {
            try {
                $result = $this->builder->buildDailyAlert($sub['id']);

                if ($result === null) {
                    $stats['skipped']++;
                    continue;
                }

                $messageId = $this->postmark->send(
                    $sub['email'],
                    $result['subject'],
                    $result['html'],
                    $result['text'],
                    'daily-alert'
                );

                $this->recordSend($sub['id'], 'daily', $result['subject'], $result['items_count'], $messageId);
                $stats['sent']++;

            } catch (Exception $e) {
                logMessage('email.log', "FAILED daily alert for subscriber {$sub['id']} ({$sub['email']}): " . $e->getMessage());
                $stats['failed']++;
            }
        }

        logMessage('email.log', "Daily alerts complete: " . json_encode($stats));
        return $stats;
    }

    /**
     * Send weekly digest to all active free-tier subscribers
     *
     * @return array Summary stats: sent, skipped, failed
     */
    public function sendWeeklyDigest(): array
    {
        $stats = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

        $stmt = $this->db->prepare(
            "SELECT id, email FROM subscribers
             WHERE active = 1
               AND tier = 'free'"
        );
        $stmt->execute();
        $subscribers = $stmt->fetchAll();

        logMessage('email.log', "Weekly digest: found " . count($subscribers) . " eligible subscribers");

        foreach ($subscribers as $sub) {
            try {
                $result = $this->builder->buildWeeklyDigest($sub['id']);

                if ($result === null) {
                    $stats['skipped']++;
                    continue;
                }

                $messageId = $this->postmark->send(
                    $sub['email'],
                    $result['subject'],
                    $result['html'],
                    $result['text'],
                    'weekly-digest'
                );

                $this->recordSend($sub['id'], 'weekly', $result['subject'], $result['items_count'], $messageId);
                $stats['sent']++;

            } catch (Exception $e) {
                logMessage('email.log', "FAILED weekly digest for subscriber {$sub['id']} ({$sub['email']}): " . $e->getMessage());
                $stats['failed']++;
            }
        }

        logMessage('email.log', "Weekly digest complete: " . json_encode($stats));
        return $stats;
    }

    /**
     * Record a successful send in the alerts_sent table
     */
    private function recordSend(int $subscriberId, string $alertType, string $subject, int $itemsCount, string $messageId): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO alerts_sent (subscriber_id, alert_type, subject, items_count, postmark_message_id)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$subscriberId, $alertType, $subject, $itemsCount, $messageId]);
    }
}
