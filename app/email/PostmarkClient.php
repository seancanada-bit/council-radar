<?php
/**
 * PostmarkClient - Simple Postmark API wrapper for sending transactional email
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

class PostmarkClient
{
    private string $apiKey;
    private string $fromEmail;
    private string $fromName;

    public function __construct()
    {
        $this->apiKey    = POSTMARK_API_KEY;
        $this->fromEmail = POSTMARK_FROM_EMAIL;
        $this->fromName  = POSTMARK_FROM_NAME;
    }

    /**
     * Send an email via Postmark Send API
     *
     * @param string $to        Recipient email address
     * @param string $subject   Email subject line
     * @param string $htmlBody  HTML version of the email body
     * @param string $textBody  Plain-text version of the email body
     * @param string $tag       Optional Postmark tag for categorization
     * @return string           Postmark MessageID on success
     * @throws Exception        On cURL or API failure
     */
    public function send(string $to, string $subject, string $htmlBody, string $textBody, string $tag = ''): string
    {
        $payload = [
            'From'       => $this->fromName . ' <' . $this->fromEmail . '>',
            'To'         => $to,
            'Subject'    => $subject,
            'HtmlBody'   => $htmlBody,
            'TextBody'   => $textBody,
            'TrackOpens' => true,
            'TrackLinks' => 'HtmlOnly',
        ];

        if ($tag !== '') {
            $payload['Tag'] = $tag;
        }

        $ch = curl_init('https://api.postmarkapp.com/email');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/json',
                'X-Postmark-Server-Token: ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            logMessage('email.log', "CURL ERROR sending to $to: $curlError");
            throw new Exception("Postmark cURL error: $curlError");
        }

        $data = json_decode($response, true);

        if ($httpCode !== 200 || empty($data['MessageID'])) {
            $errorMsg = $data['Message'] ?? 'Unknown error';
            $errorCode = $data['ErrorCode'] ?? $httpCode;
            logMessage('email.log', "POSTMARK ERROR sending to $to: [$errorCode] $errorMsg");
            throw new Exception("Postmark API error [$errorCode]: $errorMsg");
        }

        $messageId = $data['MessageID'];
        logMessage('email.log', "SENT to=$to subject=\"$subject\" tag=\"$tag\" messageId=$messageId");

        return $messageId;
    }
}
