<?php

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../stripepartial/lib.php';
require_once __DIR__ . '/../stripepartial.php';

use StripePartial\Client;
use StripePartial\StripeError;
use WHMCS\Database\Capsule;

$gatewayModuleName = 'stripepartial';
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    http_response_code(503);
    die('Module Not Activated');
}

$payload = file_get_contents('php://input');
$signature = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? $_SERVER['HTTP_STRIPE_SIGNATURE'] : '';

if (!stripepartial_webhook_verify($payload, $signature, $gatewayParams['webhookSecret'])) {
    http_response_code(400);
    die('Invalid signature');
}

$event = json_decode($payload, true);
if (!is_array($event) || empty($event['type'])) {
    http_response_code(400);
    die('Invalid payload');
}

$stripe = new Client($gatewayParams['secretKey'], $gatewayParams['apiVersion']);
$object = $event['data']['object'];

try {
    switch ($event['type']) {
        case 'charge.refunded':
        case 'charge.refund.updated':
            $chargeId = $event['type'] === 'charge.refunded' ? $object['id'] : $object['charge'];
            stripepartial_webhook_syncRefunds($stripe, $chargeId);
            break;

        case 'refund.created':
        case 'refund.updated':
            stripepartial_webhook_syncRefunds($stripe, $object['charge']);
            break;

        case 'charge.dispute.created':
            $invoice = stripepartial_webhook_invoiceFor($stripe, $object['payment_intent'], $object['charge']);
            if ($invoice) {
                logActivity('Stripe dispute ' . $object['id'] . ' opened on invoice #' . $invoice['id']
                    . ' (' . StripePartial\fromMinorUnits($object['amount'], $object['currency']) . ' '
                    . strtoupper($object['currency']) . ', reason: ' . $object['reason'] . ')', $invoice['userid']);
            }
            break;

        case 'charge.dispute.funds_withdrawn':
            $invoice = stripepartial_webhook_invoiceFor($stripe, $object['payment_intent'], $object['charge']);
            if ($invoice) {
                stripepartial_webhook_addTransaction($invoice, $object['id'] . '-withdrawn', 0,
                    StripePartial\fromMinorUnits($object['amount'], $object['currency']),
                    'Chargeback: ' . $object['reason']);
                localAPI('UpdateInvoice', ['invoiceid' => $invoice['id'], 'status' => 'Collections']);
            }
            break;

        case 'charge.dispute.funds_reinstated':
            $invoice = stripepartial_webhook_invoiceFor($stripe, $object['payment_intent'], $object['charge']);
            if ($invoice) {
                stripepartial_webhook_addTransaction($invoice, $object['id'] . '-reinstated',
                    StripePartial\fromMinorUnits($object['amount'], $object['currency']), 0,
                    'Chargeback reversed (dispute won)');
                stripepartial_webhook_updateStatus($invoice['id']);
            }
            break;

        case 'charge.dispute.updated':
        case 'charge.dispute.closed':
            $invoice = stripepartial_webhook_invoiceFor($stripe, $object['payment_intent'], $object['charge']);
            if ($invoice) {
                logActivity('Stripe dispute ' . $object['id'] . ' closed on invoice #' . $invoice['id']
                    . ' status: ' . $object['status'], $invoice['userid']);
            }
            break;

        case 'payment_intent.succeeded':
            if (!empty($object['metadata']['whmcs_onetime'])) {
                stripepartial_recordOneTimePayment($stripe, $object['id'], !empty($object['setup_future_usage']));
            }
            break;

        case 'payment_intent.payment_failed':
            $invoiceId = isset($object['metadata']['whmcs_invoice_id']) ? (int) $object['metadata']['whmcs_invoice_id'] : 0;
            if ($invoiceId) {
                $reason = isset($object['last_payment_error']['message']) ? $object['last_payment_error']['message'] : 'unknown';
                logActivity('Stripe payment failed for invoice #' . $invoiceId . ': ' . $reason);
            }
            break;
    }
} catch (StripeError $e) {
    logTransaction($gatewayModuleName, ['event' => $event['id'], 'error' => $e->getMessage()], 'Webhook Error');
    http_response_code(500);
    die('Stripe error');
}

logTransaction($gatewayModuleName, ['event' => $event['id'], 'type' => $event['type']], 'Webhook Received');
http_response_code(200);
echo 'OK';

function stripepartial_webhook_verify($payload, $header, $secret)
{
    $secret = trim((string) $secret);
    if ($secret === '' || $header === '') {
        return false;
    }

    $timestamp = null;
    $signatures = [];
    foreach (explode(',', $header) as $part) {
        $kv = explode('=', trim($part), 2);
        if (count($kv) !== 2) {
            continue;
        }
        if ($kv[0] === 't') {
            $timestamp = (int) $kv[1];
        } elseif ($kv[0] === 'v1') {
            $signatures[] = $kv[1];
        }
    }

    if (!$timestamp || !$signatures || abs(time() - $timestamp) > 300) {
        return false;
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) {
            return true;
        }
    }

    return false;
}

function stripepartial_webhook_invoiceFor(Client $stripe, $paymentIntentId, $chargeId)
{
    if (!$paymentIntentId && $chargeId) {
        $charge = $stripe->get('charges/' . $chargeId);
        $paymentIntentId = $charge['payment_intent'];
    }
    if (!$paymentIntentId) {
        return null;
    }

    $intent = $stripe->get('payment_intents/' . $paymentIntentId);
    $invoiceId = isset($intent['metadata']['whmcs_invoice_id']) ? (int) $intent['metadata']['whmcs_invoice_id'] : 0;

    if (!$invoiceId) {
        $invoiceId = (int) Capsule::table('tblaccounts')->where('transid', $paymentIntentId)->value('invoiceid');
    }
    if (!$invoiceId) {
        return null;
    }

    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first(['id', 'userid']);
    if (!$invoice) {
        return null;
    }

    return ['id' => (int) $invoice->id, 'userid' => (int) $invoice->userid, 'payment_intent' => $paymentIntentId];
}

function stripepartial_webhook_addTransaction(array $invoice, $transId, $amountIn, $amountOut, $description)
{
    if (Capsule::table('tblaccounts')->where('transid', $transId)->exists()) {
        return false;
    }

    localAPI('AddTransaction', [
        'paymentmethod' => 'stripepartial',
        'userid' => $invoice['userid'],
        'invoiceid' => $invoice['id'],
        'transid' => $transId,
        'amountin' => $amountIn,
        'amountout' => $amountOut,
        'fees' => 0,
        'description' => $description,
    ]);

    return true;
}

function stripepartial_webhook_syncRefunds(Client $stripe, $chargeId)
{
    if (!$chargeId) {
        return;
    }

    $refunds = $stripe->get('refunds', ['charge' => $chargeId, 'limit' => 100]);
    $invoice = null;

    foreach ($refunds['data'] as $refund) {
        if ($refund['status'] !== 'succeeded') {
            continue;
        }
        if (!$invoice) {
            $invoice = stripepartial_webhook_invoiceFor($stripe, $refund['payment_intent'], $chargeId);
            if (!$invoice) {
                return;
            }
        }
        stripepartial_webhook_addTransaction($invoice, $refund['id'], 0,
            StripePartial\fromMinorUnits($refund['amount'], $refund['currency']),
            'Refund' . (!empty($refund['reason']) ? ': ' . $refund['reason'] : ''));
    }

    if ($invoice) {
        stripepartial_webhook_updateStatus($invoice['id']);
    }
}

function stripepartial_webhook_updateStatus($invoiceId)
{
    $totals = Capsule::table('tblaccounts')->where('invoiceid', $invoiceId)
        ->selectRaw('COALESCE(SUM(amountin),0) AS paidin, COALESCE(SUM(amountout),0) AS paidout')
        ->first();
    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first(['total', 'status']);
    if (!$invoice || !$totals) {
        return;
    }

    $net = round($totals->paidin - $totals->paidout, 2);

    if ($totals->paidin > 0 && $net <= 0) {
        $status = 'Refunded';
    } elseif ($net >= round($invoice->total, 2)) {
        $status = 'Paid';
    } else {
        $status = $invoice->status === 'Collections' ? 'Collections' : 'Unpaid';
    }

    if ($status !== $invoice->status) {
        localAPI('UpdateInvoice', ['invoiceid' => $invoiceId, 'status' => $status]);
    }
}
