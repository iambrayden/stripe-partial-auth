<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

function stripepartialmigrate_config()
{
    return [
        'name' => 'Stripe Card Migration',
        'description' => 'Move saved Stripe cards, services and open invoices between the built-in Stripe gateway and Stripe (Partial Authorization).',
        'version' => '1.0',
        'fields' => [],
    ];
}

function stripepartialmigrate_activate()
{
    return ['status' => 'success', 'description' => 'Open it from Addons > Stripe Card Migration.'];
}

function stripepartialmigrate_deactivate()
{
    return ['status' => 'success'];
}

function stripepartialmigrate_queries($from, array $clientIds)
{
    $scope = function ($query, $column) use ($clientIds) {
        if ($clientIds) {
            $query->whereIn($column, $clientIds);
        }
        return $query;
    };

    return [
        'Saved pay methods' => [
            $scope(Capsule::table('tblpaymethods')->where('gateway_name', $from)->whereNull('deleted_at'), 'userid'),
            'gateway_name',
        ],
        'Client default gateways' => [
            $scope(Capsule::table('tblclients')->where('defaultgateway', $from), 'id'),
            'defaultgateway',
        ],
        'Services' => [
            $scope(Capsule::table('tblhosting')->where('paymentmethod', $from), 'userid'),
            'paymentmethod',
        ],
        'Domains' => [
            $scope(Capsule::table('tbldomains')->where('paymentmethod', $from), 'userid'),
            'paymentmethod',
        ],
        'Open invoices' => [
            $scope(
                Capsule::table('tblinvoices')->where('paymentmethod', $from)->whereIn('status', ['Unpaid', 'Payment Pending']),
                'userid'
            ),
            'paymentmethod',
        ],
    ];
}

function stripepartialmigrate_output($vars)
{
    $link = $vars['modulelink'];

    $clientsRaw = isset($_REQUEST['clients']) ? (string) $_REQUEST['clients'] : '';
    $clientIds = array_values(array_filter(array_map('intval', preg_split('/[\s,]+/', $clientsRaw))));
    $direction = (isset($_REQUEST['direction']) && $_REQUEST['direction'] === 'revert') ? 'revert' : 'forward';
    $from = $direction === 'revert' ? 'stripepartial' : 'stripe';
    $to = $direction === 'revert' ? 'stripe' : 'stripepartial';

    $message = '';
    if (isset($_POST['apply'])) {
        check_token('WHMCS.admin.default');

        if (!$clientIds && empty($_POST['confirm_all'])) {
            $message = '<div class="alert alert-warning">No client IDs given. Tick "Apply to all clients" to migrate everyone.</div>';
        } else {
            $changed = [];
            Capsule::connection()->transaction(function () use ($from, $to, $clientIds, &$changed) {
                foreach (stripepartialmigrate_queries($from, $clientIds) as $label => $item) {
                    list($query, $column) = $item;
                    $changed[$label] = $query->update([$column => $to]);
                }
            });

            $summary = [];
            foreach ($changed as $label => $count) {
                $summary[] = htmlspecialchars($label) . ': ' . (int) $count;
            }
            logActivity('Stripe card migration ' . $from . ' -> ' . $to
                . ($clientIds ? ' for clients ' . implode(',', $clientIds) : ' for all clients')
                . ' (' . strip_tags(implode(', ', $summary)) . ')');

            $message = '<div class="alert alert-success">Moved ' . htmlspecialchars($from) . ' &rarr; '
                . htmlspecialchars($to) . '. ' . implode(', ', $summary) . '</div>';
        }
    }

    $rows = '';
    foreach (stripepartialmigrate_queries($from, $clientIds) as $label => $item) {
        $rows .= '<tr><td>' . htmlspecialchars($label) . '</td><td>' . (int) $item[0]->count() . '</td></tr>';
    }

    $clientsVal = htmlspecialchars(implode(',', $clientIds));
    $selFwd = $direction === 'forward' ? ' selected' : '';
    $selRev = $direction === 'revert' ? ' selected' : '';
    $token = generate_token('plain');
    $scopeLabel = $clientIds ? 'clients ' . $clientsVal : 'all clients';

    echo $message;
    echo <<<HTML
<p>Both gateways must use the same Stripe account keys. Back up the database before migrating everyone.</p>

<form method="get" action="addonmodules.php" class="form-inline" style="margin-bottom:15px">
  <input type="hidden" name="module" value="stripepartialmigrate">
  <label>Direction&nbsp;
    <select name="direction" class="form-control">
      <option value="forward"{$selFwd}>Stripe &rarr; Stripe (Partial Authorization)</option>
      <option value="revert"{$selRev}>Stripe (Partial Authorization) &rarr; Stripe</option>
    </select>
  </label>
  &nbsp;
  <label>Client IDs&nbsp;
    <input type="text" name="clients" value="{$clientsVal}" class="form-control" placeholder="e.g. 1 or 1,25,40">
  </label>
  &nbsp;
  <button type="submit" class="btn btn-default">Preview</button>
</form>

<h4>{$from} &rarr; {$to} ({$scopeLabel})</h4>
<table class="table table-condensed" style="max-width:420px">
  <thead><tr><th>Records to move</th><th>Count</th></tr></thead>
  <tbody>{$rows}</tbody>
</table>

<form method="post" action="{$link}" onsubmit="return confirm('Move these records from {$from} to {$to}?');">
  <input type="hidden" name="token" value="{$token}">
  <input type="hidden" name="direction" value="{$direction}">
  <input type="hidden" name="clients" value="{$clientsVal}">
  <div class="checkbox"><label><input type="checkbox" name="confirm_all" value="1"> Apply to all clients (only needed when no client IDs are given)</label></div>
  <button type="submit" name="apply" value="1" class="btn btn-primary">Apply</button>
</form>
HTML;
}
