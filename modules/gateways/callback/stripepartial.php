<?php

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../stripepartial/lib.php';

use StripePartial\Client;
use StripePartial\StripeError;

$gatewayModuleName = 'stripepartial';
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    die('Module Not Activated');
}

require_once __DIR__ . '/../stripepartial.php';

$action = isset($_POST['action']) ? $_POST['action'] : '';

if ($action === 'paid') {
    $paymentIntentId = isset($_POST['payment_intent']) ? trim($_POST['payment_intent']) : '';
    if (!preg_match('/^pi_[A-Za-z0-9]+$/', $paymentIntentId)) {
        http_response_code(400);
        die('Invalid request');
    }

    $stripe = new Client($gatewayParams['secretKey'], $gatewayParams['apiVersion']);
    try {
        $intent = $stripe->get('payment_intents/' . $paymentIntentId);
    } catch (StripeError $e) {
        logModuleCall($gatewayModuleName, 'paid', $paymentIntentId, $e->response ?: $e->getMessage());
        die('Unable to verify payment');
    }

    $clientId = isset($intent['metadata']['whmcs_client_id']) ? (int) $intent['metadata']['whmcs_client_id'] : 0;
    $sessionClientId = (int) WHMCS\Session::get('uid');
    if (!$clientId || (!WHMCS\Session::get('adminid') && $sessionClientId !== $clientId)) {
        http_response_code(403);
        die('Not authorised');
    }

    try {
        $result = stripepartial_recordOneTimePayment($stripe, $paymentIntentId, !empty($_POST['save_card']));
    } catch (StripeError $e) {
        logModuleCall($gatewayModuleName, 'paid', $paymentIntentId, $e->response ?: $e->getMessage());
        die('Unable to record payment');
    }

    $invoiceId = $result ? $result['invoice_id'] : (int) $intent['metadata']['whmcs_invoice_id'];
    $query = $result ? '&paymentsuccess=true' : '&paymentfailed=true';
    header('Location: ' . rtrim($gatewayParams['systemurl'], '/') . '/viewinvoice.php?id=' . $invoiceId . $query);
    exit;
}

$setupIntentId = isset($_POST['setup_intent']) ? trim($_POST['setup_intent']) : '';
$returnUrl = rtrim($gatewayParams['systemurl'], '/') . '/index.php?rp=/account/paymentmethods';

if ($action !== 'savecard' || !preg_match('/^seti_[A-Za-z0-9]+$/', $setupIntentId)) {
    http_response_code(400);
    die('Invalid request');
}

$stripe = new Client($gatewayParams['secretKey'], $gatewayParams['apiVersion']);

try {
    $setup = $stripe->get('setup_intents/' . $setupIntentId, ['expand' => ['payment_method']]);
} catch (StripeError $e) {
    logModuleCall($gatewayModuleName, 'savecard', $setupIntentId, $e->response ?: $e->getMessage());
    die('Unable to verify card');
}

$clientId = isset($setup['metadata']['whmcs_client_id']) ? (int) $setup['metadata']['whmcs_client_id'] : 0;

if ($setup['status'] !== 'succeeded' || !$clientId || !empty($setup['metadata']['whmcs_saved'])) {
    die('Card setup is not valid');
}

if ((time() - (int) $setup['created']) > 3600) {
    die('Card setup has expired, please try again');
}

$sessionClientId = (int) WHMCS\Session::get('uid');
$isAdmin = (bool) WHMCS\Session::get('adminid');
if (!$isAdmin && $sessionClientId !== $clientId) {
    http_response_code(403);
    die('Not authorised');
}

$pm = $setup['payment_method'];
$card = $pm['card'];

$remoteToken = json_encode([
    'customer' => $setup['customer'],
    'method' => $pm['id'],
]);

$expiry = str_pad($card['exp_month'], 2, '0', STR_PAD_LEFT) . substr((string) $card['exp_year'], -2);

createCardPayMethod(
    $clientId,
    $gatewayModuleName,
    $card['last4'],
    $expiry,
    ucfirst($card['brand']),
    null,
    null,
    $remoteToken,
    'billing',
    ucfirst($card['brand']) . ' ending ' . $card['last4']
);

try {
    $stripe->post('setup_intents/' . $setupIntentId, ['metadata' => ['whmcs_saved' => '1']]);
} catch (StripeError $e) {
}

logTransaction($gatewayModuleName, ['setup_intent' => $setupIntentId, 'client' => $clientId], 'Card Saved');

header('Location: ' . $returnUrl);
exit;
