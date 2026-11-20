<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/stripepartial/lib.php';

use StripePartial\Client;
use StripePartial\StripeError;

function stripepartial_MetaData()
{
    return [
        'DisplayName' => 'Stripe (Partial Authorization)',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => true,
    ];
}

function stripepartial_config()
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'Stripe (Partial Authorization)',
        ],
        'publishableKey' => [
            'FriendlyName' => 'Publishable Key',
            'Type' => 'text',
            'Size' => '60',
        ],
        'secretKey' => [
            'FriendlyName' => 'Secret Key',
            'Type' => 'password',
            'Size' => '60',
            'Description' => 'Secret or restricted key (needs PaymentIntents, SetupIntents, Customers, PaymentMethods, Refunds write).',
        ],
        'webhookSecret' => [
            'FriendlyName' => 'Webhook Signing Secret',
            'Type' => 'password',
            'Size' => '60',
            'Description' => 'whsec_ value from the Stripe webhook endpoint pointing at https://YOUR-WHMCS/modules/gateways/callback/stripepartial_webhook.php. Events: charge.refunded, charge.refund.updated, charge.dispute.created, charge.dispute.updated, charge.dispute.closed, charge.dispute.funds_withdrawn, charge.dispute.funds_reinstated, payment_intent.succeeded, payment_intent.payment_failed. Other events are accepted and ignored.',
        ],
        'apiVersion' => [
            'FriendlyName' => 'Stripe API Version',
            'Type' => 'text',
            'Size' => '40',
            'Description' => 'Optional. Sent as the Stripe-Version header. Set this to the version Stripe gives you when it enables partial authorization on your account. Blank uses the account default.',
        ],
        'descriptorSuffix' => [
            'FriendlyName' => 'Statement Descriptor Suffix',
            'Type' => 'text',
            'Size' => '30',
            'Default' => '{CompanyName} {InvoiceNumber}',
            'Description' => 'Added to the statement descriptor on the customer\'s card statement. Merge fields: {CompanyName} {InvoiceNumber}. Max 22 characters; < > \\ \' " * are removed.',
        ],
        'allowPartial' => [
            'FriendlyName' => 'Accept Partial Authorizations',
            'Type' => 'yesno',
            'Default' => 'on',
            'Description' => 'Capture whatever the issuer approves and leave the rest due on the invoice. When off, partial approvals are voided and treated as declines.',
        ],
        'minPartialPercent' => [
            'FriendlyName' => 'Minimum Partial %',
            'Type' => 'text',
            'Size' => '5',
            'Default' => '0',
            'Description' => 'Void a partial approval that covers less than this percent of the amount due (0 accepts any amount).',
        ],
        'insufficientFundsPercent' => [
            'FriendlyName' => 'Decline Retry %',
            'Type' => 'text',
            'Size' => '5',
            'Default' => '25',
            'Description' => 'When a charge is declined, immediately try this percent of the amount due and leave the rest on the invoice (0 disables). Not used for stolen, lost, expired, fraudulent or invalid cards.',
        ],
    ];
}

function stripepartial_capture($params)
{
    $token = StripePartial\parseToken($params['gatewayid']);
    if (!$token) {
        $token = stripepartial_findToken((int) $params['clientdetails']['userid'], (int) $params['invoiceid']);
    }
    if (!$token) {
        return [
            'status' => 'declined',
            'declinereason' => 'No Stripe payment method on file',
            'rawdata' => 'Missing or invalid remote token',
        ];
    }

    $stripe = new Client($params['secretKey'], $params['apiVersion']);
    $currency = $params['currency'];
    $requested = StripePartial\toMinorUnits($params['amount'], $currency);
    $invoiceId = (int) $params['invoiceid'];

    $request = [
        'amount' => $requested,
        'currency' => strtolower($currency),
        'customer' => $token['customer'],
        'payment_method' => $token['method'],
        'off_session' => 'true',
        'confirm' => 'true',
        'capture_method' => 'manual',
        'payment_method_options' => [
            'card' => ['request_partial_authorization' => 'if_available'],
        ],
        'description' => 'Invoice #' . $invoiceId,
        'metadata' => [
            'whmcs_invoice_id' => $invoiceId,
            'whmcs_client_id' => (int) $params['clientdetails']['userid'],
        ],
        'expand' => ['latest_charge'],
    ];
    $suffix = stripepartial_descriptorSuffix($params);
    if ($suffix !== '') {
        $request['statement_descriptor_suffix'] = $suffix;
    }

    try {
        try {
            $intent = $stripe->post('payment_intents', $request);
        } catch (StripeError $e) {
            if (strpos($e->getMessage(), 'request_partial_authorization') === false) {
                throw $e;
            }
            logModuleCall('stripepartial', 'partial_authorization_unavailable', $invoiceId, $e->getMessage());
            unset($request['payment_method_options']);
            $intent = $stripe->post('payment_intents', $request);
        }
    } catch (StripeError $e) {
        $declineCode = isset($e->response['error']['decline_code']) ? $e->response['error']['decline_code'] : '';
        $retryPct = (float) $params['insufficientFundsPercent'];
        $retryAmount = (int) floor($requested * $retryPct / 100);

        $errorType = isset($e->response['error']['type']) ? $e->response['error']['type'] : '';
        $hardDeclines = [
            'stolen_card', 'lost_card', 'pickup_card', 'fraudulent', 'merchant_blacklist', 'restricted_card',
            'expired_card', 'incorrect_number', 'invalid_number', 'incorrect_cvc', 'invalid_cvc', 'invalid_expiry_month',
            'invalid_expiry_year', 'invalid_account', 'new_account_information_available', 'authentication_required',
            'card_not_supported', 'currency_not_supported', 'security_violation', 'revocation_of_authorization',
            'revocation_of_all_authorizations', 'stop_payment_order', 'testmode_decline', 'do_not_try_again',
        ];
        $retryable = $errorType === 'card_error' && !in_array($declineCode, $hardDeclines, true);

        $firstDecline = $e->getMessage() . ($declineCode !== '' ? ' (' . $declineCode . ')' : '');

        if (!$retryable || $retryPct <= 0 || $retryPct >= 100
            || $retryAmount < StripePartial\minimumCharge($currency)) {
            return [
                'status' => 'declined',
                'declinereason' => $firstDecline,
                'rawdata' => $e->response ?: $e->getMessage(),
            ];
        }

        if (!stripepartial_claimRetry($invoiceId)) {
            return [
                'status' => 'declined',
                'declinereason' => $firstDecline . ' Partial retry skipped: already tried for this invoice in the last 24 hours.',
                'rawdata' => $e->response ?: $e->getMessage(),
            ];
        }

        $retryLabel = 'Retry at ' . $retryPct . '% (' . StripePartial\fromMinorUnits($retryAmount, $currency) . ' '
            . strtoupper($currency) . ')';
        logModuleCall('stripepartial', 'decline_retry', $invoiceId . ' @ ' . $retryPct . '%', $e->getMessage());
        unset($request['payment_method_options']);
        $request['amount'] = $retryAmount;
        $request['description'] = 'Invoice #' . $invoiceId . ' (partial ' . $retryPct . '%)';

        try {
            $intent = $stripe->post('payment_intents', $request);
        } catch (StripeError $retryError) {
            $retryCode = isset($retryError->response['error']['decline_code']) ? $retryError->response['error']['decline_code'] : '';
            return [
                'status' => 'declined',
                'declinereason' => 'Full amount declined: ' . $firstDecline . ' ' . $retryLabel . ' declined: '
                    . $retryError->getMessage() . ($retryCode !== '' ? ' (' . $retryCode . ')' : ''),
                'rawdata' => $retryError->response ?: $retryError->getMessage(),
            ];
        }
        $isRetry = true;
    }

    if ($intent['status'] !== 'requires_capture') {
        return [
            'status' => 'declined',
            'declinereason' => 'Unexpected PaymentIntent status: ' . $intent['status'],
            'rawdata' => $intent,
        ];
    }

    $capturable = (int) $intent['amount_capturable'];
    $isPartial = $capturable < $requested;

    if ($isPartial && empty($isRetry)) {
        $minPct = (float) $params['minPartialPercent'];
        $tooSmall = $minPct > 0 && ($capturable / $requested * 100) < $minPct;
        if ($params['allowPartial'] !== 'on' || $tooSmall || $capturable <= 0) {
            try {
                $stripe->post('payment_intents/' . $intent['id'] . '/cancel');
            } catch (StripeError $e) {
            }
            return [
                'status' => 'declined',
                'declinereason' => 'Partially authorized for '
                    . StripePartial\fromMinorUnits($capturable, $currency) . ' ' . $currency
                    . ' and voided',
                'rawdata' => $intent,
            ];
        }
    }

    try {
        $captured = $stripe->post('payment_intents/' . $intent['id'] . '/capture', [
            'amount_to_capture' => $capturable,
            'expand' => ['latest_charge.balance_transaction'],
        ]);
    } catch (StripeError $e) {
        return [
            'status' => 'declined',
            'declinereason' => 'Capture failed: ' . $e->getMessage(),
            'rawdata' => $e->response ?: $e->getMessage(),
        ];
    }

    $amountReceived = StripePartial\fromMinorUnits($captured['amount_received'], $currency);
    $fee = StripePartial\feeFromCharge($captured['latest_charge'], $currency);

    if (!$isPartial) {
        return [
            'status' => 'success',
            'transid' => $captured['id'],
            'fee' => $fee,
            'rawdata' => $captured,
        ];
    }

    addInvoicePayment($invoiceId, $captured['id'], $amountReceived, $fee, 'stripepartial');

    $remaining = round($params['amount'] - $amountReceived, 2);
    logTransaction('stripepartial', $captured, empty($isRetry) ? 'Partial Authorization' : 'Decline Retry Partial Charge');

    return [
        'status' => 'pending',
        'transid' => $captured['id'],
        'declinereason' => (empty($isRetry) ? 'Partially authorized' : 'Full amount declined: ' . $firstDecline . ' Charged ' . (float) $params['insufficientFundsPercent'] . '% instead') . ': captured ' . $amountReceived . ' ' . $currency
            . ', ' . $remaining . ' ' . $currency . ' still due',
        'rawdata' => $captured,
    ];
}

function stripepartial_claimRetry($invoiceId)
{
    $schema = \WHMCS\Database\Capsule::schema();
    if (!$schema->hasTable('mod_stripepartial_retries')) {
        $schema->create('mod_stripepartial_retries', function ($table) {
            $table->integer('invoice_id')->unsigned()->primary();
            $table->timestamp('attempted_at')->nullable();
        });
    }

    $table = \WHMCS\Database\Capsule::table('mod_stripepartial_retries');
    $cutoff = date('Y-m-d H:i:s', time() - 86400);
    $now = date('Y-m-d H:i:s');

    $last = $table->where('invoice_id', $invoiceId)->value('attempted_at');
    if ($last && $last > $cutoff) {
        return false;
    }

    if ($last) {
        \WHMCS\Database\Capsule::table('mod_stripepartial_retries')->where('invoice_id', $invoiceId)
            ->update(['attempted_at' => $now]);
    } else {
        \WHMCS\Database\Capsule::table('mod_stripepartial_retries')->insert(['invoice_id' => $invoiceId, 'attempted_at' => $now]);
    }

    return true;
}

function stripepartial_descriptorSuffix($params)
{
    $template = isset($params['descriptorSuffix']) ? trim((string) $params['descriptorSuffix']) : '';
    if ($template === '') {
        return '';
    }

    $invoiceNumber = !empty($params['invoicenum']) ? $params['invoicenum'] : $params['invoiceid'];
    $suffix = str_replace(
        ['{CompanyName}', '{InvoiceNumber}'],
        [isset($params['companyname']) ? $params['companyname'] : '', $invoiceNumber],
        $template
    );
    $suffix = preg_replace('/[<>\\\\\'"*]/', '', $suffix);
    $suffix = trim(preg_replace('/\s+/', ' ', $suffix));

    if (!preg_match('/[A-Za-z]/', $suffix)) {
        return '';
    }

    return substr($suffix, 0, 22);
}

function stripepartial_findToken($clientId, $invoiceId)
{
    if (!$clientId || !class_exists('\WHMCS\Payment\PayMethod\Model')) {
        return null;
    }

    $query = \WHMCS\Payment\PayMethod\Model::where('userid', $clientId)
        ->where('gateway_name', 'stripepartial');

    $preferred = (int) \WHMCS\Database\Capsule::table('tblinvoices')->where('id', $invoiceId)->value('paymethodid');
    $candidates = $preferred ? array_merge([$preferred], $query->orderBy('order_preference')->pluck('id')->all())
        : $query->orderBy('order_preference')->pluck('id')->all();

    foreach (array_unique($candidates) as $id) {
        $pm = \WHMCS\Payment\PayMethod\Model::find($id);
        if (!$pm || $pm->userid != $clientId || $pm->gateway_name !== 'stripepartial') {
            continue;
        }
        $token = StripePartial\parseToken($pm->payment->getRemoteToken());
        if ($token) {
            return $token;
        }
    }

    return null;
}

function stripepartial_refund($params)
{
    $stripe = new Client($params['secretKey'], $params['apiVersion']);

    try {
        $refund = $stripe->post('refunds', [
            'payment_intent' => $params['transid'],
            'amount' => StripePartial\toMinorUnits($params['amount'], $params['currency']),
        ]);
    } catch (StripeError $e) {
        return [
            'status' => 'error',
            'rawdata' => $e->response ?: $e->getMessage(),
        ];
    }

    return [
        'status' => $refund['status'] === 'failed' ? 'error' : 'success',
        'transid' => $refund['id'],
        'rawdata' => $refund,
    ];
}

function stripepartial_remoteinput($params)
{
    $invoiceId = isset($params['invoiceid']) ? (int) $params['invoiceid'] : 0;
    if ($invoiceId) {
        return stripepartial_renderPayForm($params, $invoiceId, (int) $params['clientdetails']['userid']);
    }
    return stripepartial_renderCardForm($params);
}

function stripepartial_invoiceBalance($invoiceId)
{
    $invoice = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
    if (!isset($invoice['result']) || $invoice['result'] !== 'success') {
        return null;
    }
    return [
        'userid' => (int) $invoice['userid'],
        'status' => $invoice['status'],
        'balance' => round((float) $invoice['balance'], 2),
        'invoicenum' => !empty($invoice['invoicenum']) ? $invoice['invoicenum'] : $invoiceId,
    ];
}

function stripepartial_renderPayForm($params, $invoiceId, $clientId)
{
    $invoice = stripepartial_invoiceBalance($invoiceId);
    if (!$invoice || $invoice['userid'] !== $clientId) {
        return '<div class="alert alert-danger">Invoice not found.</div>';
    }
    if ($invoice['status'] !== 'Unpaid' || $invoice['balance'] <= 0) {
        return '<div class="alert alert-info">This invoice has no balance due.</div>';
    }

    $currency = $params['currency'];
    if (!$currency) {
        $currency = \WHMCS\Database\Capsule::table('tblcurrencies')
            ->join('tblclients', 'tblclients.currency', '=', 'tblcurrencies.id')
            ->where('tblclients.id', $clientId)->value('tblcurrencies.code');
    }

    $stripe = new Client($params['secretKey'], $params['apiVersion']);
    $amount = StripePartial\toMinorUnits($invoice['balance'], $currency);

    $request = [
        'amount' => $amount,
        'currency' => strtolower($currency),
        'payment_method_types' => ['card'],
        'setup_future_usage' => 'off_session',
        'description' => 'Invoice #' . $invoiceId,
        'metadata' => [
            'whmcs_invoice_id' => $invoiceId,
            'whmcs_client_id' => $clientId,
            'whmcs_onetime' => '1',
        ],
    ];
    $suffix = stripepartial_descriptorSuffix(array_merge($params, [
        'invoiceid' => $invoiceId,
        'invoicenum' => $invoice['invoicenum'],
    ]));
    if ($suffix !== '') {
        $request['statement_descriptor_suffix'] = $suffix;
    }

    try {
        $customer = $stripe->post('customers', [
            'email' => $params['clientdetails']['email'],
            'name' => trim($params['clientdetails']['firstname'] . ' ' . $params['clientdetails']['lastname']),
            'metadata' => ['whmcs_client_id' => $clientId],
        ]);
        $request['customer'] = $customer['id'];
        $intent = $stripe->post('payment_intents', $request);
    } catch (StripeError $e) {
        logModuleCall('stripepartial', 'payform', $invoiceId, $e->response ?: $e->getMessage());
        return '<div class="alert alert-danger">Unable to load the payment form. Please try again later.</div>';
    }

    $systemUrl = rtrim($params['systemurl'], '/');
    $pk = htmlspecialchars($params['publishableKey'], ENT_QUOTES);
    $secret = htmlspecialchars($intent['client_secret'], ENT_QUOTES);
    $cb = htmlspecialchars($systemUrl . '/modules/gateways/callback/stripepartial.php', ENT_QUOTES);
    $label = htmlspecialchars(number_format($invoice['balance'], 2) . ' ' . strtoupper($currency), ENT_QUOTES);
    $name = htmlspecialchars(trim($params['clientdetails']['firstname'] . ' ' . $params['clientdetails']['lastname']), ENT_QUOTES);

    return <<<HTML
<div id="sp-pay-wrap" style="max-width:480px">
  <div id="sp-pay-element" style="padding:12px;border:1px solid #ccc;border-radius:4px"></div>
  <div class="checkbox" style="margin-top:10px"><label><input type="checkbox" id="sp-save-card" checked> Save this card for future payments</label></div>
  <div id="sp-pay-error" class="text-danger" style="margin-top:8px"></div>
  <button id="sp-pay-submit" type="button" class="btn btn-primary" style="margin-top:12px">Pay {$label}</button>
</div>
<form id="sp-pay-done" method="post" action="{$cb}">
  <input type="hidden" name="action" value="paid">
  <input type="hidden" name="payment_intent" id="sp-payment-intent">
  <input type="hidden" name="save_card" id="sp-save-flag" value="1">
</form>
<script src="https://js.stripe.com/v3/"></script>
<script>
(function () {
  var stripe = Stripe('{$pk}');
  var card = stripe.elements().create('card');
  card.mount('#sp-pay-element');
  var btn = document.getElementById('sp-pay-submit');
  var err = document.getElementById('sp-pay-error');
  btn.addEventListener('click', function () {
    btn.disabled = true;
    err.textContent = '';
    stripe.confirmCardPayment('{$secret}', {
      payment_method: { card: card, billing_details: { name: '{$name}' } }
    }).then(function (r) {
      if (r.error) {
        err.textContent = r.error.message;
        btn.disabled = false;
        return;
      }
      document.getElementById('sp-payment-intent').value = r.paymentIntent.id;
      document.getElementById('sp-save-flag').value = document.getElementById('sp-save-card').checked ? '1' : '0';
      document.getElementById('sp-pay-done').submit();
    });
  });
})();
</script>
HTML;
}

function stripepartial_recordOneTimePayment(Client $stripe, $paymentIntentId, $saveCard)
{
    $intent = $stripe->get('payment_intents/' . $paymentIntentId, [
        'expand' => ['payment_method', 'latest_charge.balance_transaction'],
    ]);

    if ($intent['status'] !== 'succeeded' || empty($intent['metadata']['whmcs_onetime'])) {
        return null;
    }

    $invoiceId = (int) $intent['metadata']['whmcs_invoice_id'];
    $clientId = (int) $intent['metadata']['whmcs_client_id'];
    $currency = $intent['currency'];

    if (!\WHMCS\Database\Capsule::table('tblaccounts')->where('transid', $intent['id'])->exists()) {
        addInvoicePayment(
            $invoiceId,
            $intent['id'],
            StripePartial\fromMinorUnits($intent['amount_received'], $currency),
            StripePartial\feeFromCharge($intent['latest_charge'], $currency),
            'stripepartial'
        );
    }

    $pm = $intent['payment_method'];
    if ($saveCard && is_array($pm) && isset($pm['card'])) {
        $token = json_encode(['customer' => $intent['customer'], 'method' => $pm['id']]);
        $exists = false;
        foreach (\WHMCS\Payment\PayMethod\Model::where('userid', $clientId)->where('gateway_name', 'stripepartial')->get() as $existing) {
            if ($existing->payment->getRemoteToken() === $token) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $card = $pm['card'];
            createCardPayMethod(
                $clientId,
                'stripepartial',
                $card['last4'],
                str_pad($card['exp_month'], 2, '0', STR_PAD_LEFT) . substr((string) $card['exp_year'], -2),
                ucfirst($card['brand']),
                null,
                null,
                $token,
                'billing',
                ucfirst($card['brand']) . ' ending ' . $card['last4']
            );
        }
    }

    return ['invoice_id' => $invoiceId, 'client_id' => $clientId];
}

function stripepartial_remoteupdate($params)
{
    return stripepartial_renderCardForm($params);
}

function stripepartial_renderCardForm($params)
{
    $clientId = (int) $params['clientdetails']['userid'];
    $stripe = new Client($params['secretKey'], $params['apiVersion']);

    try {
        $customer = $stripe->post('customers', [
            'email' => $params['clientdetails']['email'],
            'name' => trim($params['clientdetails']['firstname'] . ' ' . $params['clientdetails']['lastname']),
            'metadata' => ['whmcs_client_id' => $clientId],
        ]);
        $setup = $stripe->post('setup_intents', [
            'customer' => $customer['id'],
            'usage' => 'off_session',
            'payment_method_types' => ['card'],
            'metadata' => ['whmcs_client_id' => $clientId],
        ]);
    } catch (StripeError $e) {
        logModuleCall('stripepartial', 'remoteinput', $clientId, $e->response ?: $e->getMessage());
        return '<div class="alert alert-danger">Unable to load the card form. Please try again later.</div>';
    }

    $systemUrl = rtrim($params['systemurl'], '/');
    $callback = $systemUrl . '/modules/gateways/callback/stripepartial.php';
    $returnUrl = $systemUrl . '/index.php?rp=/account/paymentmethods';

    $pk = htmlspecialchars($params['publishableKey'], ENT_QUOTES);
    $secret = htmlspecialchars($setup['client_secret'], ENT_QUOTES);
    $cb = htmlspecialchars($callback, ENT_QUOTES);
    $ret = htmlspecialchars($returnUrl, ENT_QUOTES);

    return <<<HTML
<div id="sp-card-wrap" style="max-width:480px">
  <div id="sp-card-element" style="padding:12px;border:1px solid #ccc;border-radius:4px"></div>
  <div id="sp-card-error" class="text-danger" style="margin-top:8px"></div>
  <button id="sp-card-submit" type="button" class="btn btn-primary" style="margin-top:12px">Save Card</button>
</div>
<form id="sp-card-done" method="post" action="{$cb}">
  <input type="hidden" name="action" value="savecard">
  <input type="hidden" name="setup_intent" id="sp-setup-intent">
  <input type="hidden" name="return" value="{$ret}">
</form>
<script src="https://js.stripe.com/v3/"></script>
<script>
(function () {
  var stripe = Stripe('{$pk}');
  var elements = stripe.elements();
  var card = elements.create('card');
  card.mount('#sp-card-element');
  var btn = document.getElementById('sp-card-submit');
  var err = document.getElementById('sp-card-error');
  btn.addEventListener('click', function () {
    btn.disabled = true;
    err.textContent = '';
    stripe.confirmCardSetup('{$secret}', { payment_method: { card: card } }).then(function (r) {
      if (r.error) {
        err.textContent = r.error.message;
        btn.disabled = false;
        return;
      }
      document.getElementById('sp-setup-intent').value = r.setupIntent.id;
      document.getElementById('sp-card-done').submit();
    });
  });
})();
</script>
HTML;
}
