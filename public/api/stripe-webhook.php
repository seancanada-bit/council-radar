<?php
/**
 * CouncilRadar - Stripe Webhook Endpoint
 *
 * Handles: checkout.session.completed, customer.subscription.deleted, invoice.payment_failed
 */

require_once __DIR__ . '/../../app/config.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/functions.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// Read raw payload
$payload = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// Check if Stripe is configured
if (empty(STRIPE_WEBHOOK_SECRET) || empty(STRIPE_SECRET_KEY)) {
    logMessage('stripe.log', 'Webhook received but Stripe keys are not configured. Ignoring.');
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'message' => 'Stripe not configured.']);
    exit;
}

// Verify webhook signature
$signedPayload = null;
$timestamp     = null;
$signatures    = [];

$parts = explode(',', $sigHeader);
foreach ($parts as $part) {
    $part = trim($part);
    if (strpos($part, 't=') === 0) {
        $timestamp = substr($part, 2);
    } elseif (strpos($part, 'v1=') === 0) {
        $signatures[] = substr($part, 3);
    }
}

if (!$timestamp || empty($signatures)) {
    logMessage('stripe.log', 'Webhook signature missing or malformed.');
    http_response_code(400);
    echo json_encode(['error' => 'Invalid signature.']);
    exit;
}

// Reject if timestamp is older than 5 minutes (tolerance for clock drift)
if (abs(time() - (int)$timestamp) > 300) {
    logMessage('stripe.log', 'Webhook timestamp too old.');
    http_response_code(400);
    echo json_encode(['error' => 'Timestamp out of tolerance.']);
    exit;
}

$expectedSig = hash_hmac('sha256', $timestamp . '.' . $payload, STRIPE_WEBHOOK_SECRET);

$verified = false;
foreach ($signatures as $sig) {
    if (hash_equals($expectedSig, $sig)) {
        $verified = true;
        break;
    }
}

if (!$verified) {
    logMessage('stripe.log', 'Webhook signature verification failed.');
    http_response_code(400);
    echo json_encode(['error' => 'Signature verification failed.']);
    exit;
}

// Parse event
$event = json_decode($payload, true);
if (!$event || !isset($event['type'])) {
    logMessage('stripe.log', 'Webhook payload could not be parsed.');
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload.']);
    exit;
}

$db = DB::get();
$eventType = $event['type'];

logMessage('stripe.log', "Received event: $eventType");

switch ($eventType) {

    case 'checkout.session.completed':
        $session       = $event['data']['object'] ?? [];
        $subscriberId  = $session['client_reference_id'] ?? null;
        $customerId    = $session['customer'] ?? null;
        $subscriptionId = $session['subscription'] ?? null;

        if (!$subscriberId) {
            logMessage('stripe.log', 'checkout.session.completed: Missing client_reference_id.');
            break;
        }

        // Determine tier based on the line items price ID
        // We need to fetch the session's line items from Stripe to get the price ID
        // For now, check if the subscription metadata or amount matches
        $tier = 'professional'; // default upgrade

        // Try to determine tier from the session metadata or line_items
        if (!empty($session['metadata']['tier'])) {
            $tier = $session['metadata']['tier'];
        } else {
            // Fetch line items from Stripe API to determine price
            $lineItemsUrl = 'https://api.stripe.com/v1/checkout/sessions/' . $session['id'] . '/line_items';
            $ch = curl_init($lineItemsUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . STRIPE_SECRET_KEY],
                CURLOPT_TIMEOUT        => 10,
            ]);
            $lineItemsResponse = curl_exec($ch);
            curl_close($ch);

            $lineItems = json_decode($lineItemsResponse, true);
            if (!empty($lineItems['data'])) {
                foreach ($lineItems['data'] as $li) {
                    $priceId = $li['price']['id'] ?? '';
                    if ($priceId === STRIPE_FIRM_PRICE_ID) {
                        $tier = 'firm';
                        break;
                    } elseif ($priceId === STRIPE_PROFESSIONAL_PRICE_ID) {
                        $tier = 'professional';
                        break;
                    }
                }
            }
        }

        $stmt = $db->prepare(
            'UPDATE subscribers
             SET tier = ?, stripe_customer_id = ?, stripe_subscription_id = ?
             WHERE id = ?'
        );
        $stmt->execute([$tier, $customerId, $subscriptionId, (int)$subscriberId]);

        logMessage('stripe.log', "Subscriber $subscriberId upgraded to $tier (customer: $customerId, subscription: $subscriptionId).");
        break;

    case 'customer.subscription.deleted':
        $subscription = $event['data']['object'] ?? [];
        $subscriptionId = $subscription['id'] ?? null;

        if (!$subscriptionId) {
            logMessage('stripe.log', 'customer.subscription.deleted: Missing subscription ID.');
            break;
        }

        $stmt = $db->prepare(
            'UPDATE subscribers
             SET tier = ?, frequency = ?, stripe_subscription_id = NULL
             WHERE stripe_subscription_id = ?'
        );
        $stmt->execute(['free', 'weekly', $subscriptionId]);

        $affected = $stmt->rowCount();
        logMessage('stripe.log', "Subscription $subscriptionId deleted. Downgraded $affected subscriber(s) to free.");
        break;

    case 'invoice.payment_failed':
        $invoice      = $event['data']['object'] ?? [];
        $customerId   = $invoice['customer'] ?? 'unknown';
        $invoiceId    = $invoice['id'] ?? 'unknown';
        $amountDue    = $invoice['amount_due'] ?? 0;

        logMessage('stripe.log', "WARNING: Payment failed for customer $customerId, invoice $invoiceId, amount $amountDue cents.");

        // Notify admin
        notifyAdmin(
            'CouncilRadar: Payment Failed',
            "Payment failed for Stripe customer $customerId.\nInvoice: $invoiceId\nAmount: $" . number_format($amountDue / 100, 2)
        );
        break;

    default:
        logMessage('stripe.log', "Unhandled event type: $eventType");
        break;
}

http_response_code(200);
echo json_encode(['status' => 'ok']);
