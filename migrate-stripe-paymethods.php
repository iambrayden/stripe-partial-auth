<?php

if (PHP_SAPI !== 'cli') {
    die('Run from the command line');
}

$opts = getopt('', ['whmcs:', 'apply', 'revert', 'clients:', 'from:']);
$root = isset($opts['whmcs']) ? rtrim($opts['whmcs'], '/') : getcwd();
$apply = isset($opts['apply']);
$revert = isset($opts['revert']);
$clientFilter = isset($opts['clients'])
    ? array_filter(array_map('intval', explode(',', $opts['clients'])))
    : [];

if (!is_file($root . '/init.php')) {
    fwrite(STDERR, "init.php not found in {$root}. Pass --whmcs=/path/to/whmcs\n");
    exit(1);
}

require $root . '/init.php';

use WHMCS\Database\Capsule;

$source = isset($opts['from']) ? $opts['from'] : 'stripe';
if (!preg_match('/^[a-z0-9_]+$/', $source) || $source === 'stripepartial') {
    fwrite(STDERR, "Invalid --from gateway\n");
    exit(1);
}
$from = $revert ? 'stripepartial' : $source;
$to = $revert ? $source : 'stripepartial';

$scope = function ($query, $column) use ($clientFilter) {
    if ($clientFilter) {
        $query->whereIn($column, $clientFilter);
    }
    return $query;
};

$payMethods = $scope(
    Capsule::table('tblpaymethods')->where('gateway_name', $from)->whereNull('deleted_at'),
    'userid'
);
$clients = $scope(Capsule::table('tblclients')->where('defaultgateway', $from), 'id');
$services = $scope(Capsule::table('tblhosting')->where('paymentmethod', $from), 'userid');
$domains = $scope(Capsule::table('tbldomains')->where('paymentmethod', $from), 'userid');
$invoices = $scope(
    Capsule::table('tblinvoices')->where('paymentmethod', $from)->whereIn('status', ['Unpaid', 'Payment Pending']),
    'userid'
);

printf("%s -> %s%s\n", $from, $to, $clientFilter ? ' (clients ' . implode(',', $clientFilter) . ')' : '');
printf("  saved pay methods:   %d\n", (clone $payMethods)->count());
printf("  client defaults:     %d\n", (clone $clients)->count());
printf("  services:            %d\n", (clone $services)->count());
printf("  domains:             %d\n", (clone $domains)->count());
printf("  open invoices:       %d\n", (clone $invoices)->count());

if (!$apply) {
    echo "\nDry run. Re-run with --apply to make the change.\n";
    exit(0);
}

Capsule::connection()->transaction(function () use ($payMethods, $clients, $services, $domains, $invoices, $to) {
    $payMethods->update(['gateway_name' => $to]);
    $clients->update(['defaultgateway' => $to]);
    $services->update(['paymentmethod' => $to]);
    $domains->update(['paymentmethod' => $to]);
    $invoices->update(['paymentmethod' => $to]);
});

echo "\nDone.\n";
