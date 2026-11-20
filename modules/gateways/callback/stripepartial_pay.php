<?php

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../stripepartial.php';

$gatewayParams = getGatewayVariables('stripepartial');
if (!$gatewayParams['type']) {
    die('Module Not Activated');
}

$invoiceId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$clientId = (int) WHMCS\Session::get('uid');
$systemUrl = rtrim($gatewayParams['systemurl'], '/');

if (!$clientId) {
    header('Location: ' . $systemUrl . '/login.php');
    exit;
}

$client = localAPI('GetClientsDetails', ['clientid' => $clientId]);
if (!isset($client['result']) || $client['result'] !== 'success') {
    die('Client not found');
}

$params = array_merge($gatewayParams, [
    'clientdetails' => [
        'userid' => $clientId,
        'email' => $client['email'],
        'firstname' => $client['firstname'],
        'lastname' => $client['lastname'],
    ],
    'currency' => isset($client['currency_code']) ? $client['currency_code'] : '',
    'companyname' => WHMCS\Config\Setting::getValue('CompanyName'),
]);

$form = stripepartial_renderPayForm($params, $invoiceId, $clientId);
$title = htmlspecialchars('Pay Invoice #' . $invoiceId, ENT_QUOTES);
$back = htmlspecialchars($systemUrl . '/viewinvoice.php?id=' . $invoiceId, ENT_QUOTES);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $title; ?></title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f5f6f8; color: #222; margin: 0; padding: 32px 16px; }
  .card { max-width: 520px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
  h1 { font-size: 20px; margin: 0 0 16px; }
  .btn { background: #1f6feb; color: #fff; border: 0; border-radius: 4px; padding: 10px 18px; font-size: 15px; cursor: pointer; }
  .btn:disabled { opacity: .6; }
  .text-danger { color: #c62828; }
  .alert { padding: 12px; border-radius: 4px; background: #eef2f7; }
  .alert-danger { background: #fdecea; }
  a { color: #1f6feb; }
</style>
</head>
<body>
<div class="card">
  <h1><?php echo $title; ?></h1>
  <?php echo $form; ?>
  <p style="margin-top:20px"><a href="<?php echo $back; ?>">Back to invoice</a></p>
</div>
</body>
</html>
