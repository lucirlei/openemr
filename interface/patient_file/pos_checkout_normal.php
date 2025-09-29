<?php

/**
 * Checkout Module.
 *
 * This module supports a popup window to handle patient checkout
 * as a point-of-sale transaction.  Support for in-house drug sales
 * is included.
 *
 * <pre>
 * Important notes about system design:
 * (1) Drug sales may or may not be associated with an encounter;
 *     they are if they are paid for concurrently with an encounter, or
 *     if they are "product" (non-prescription) sales via the Fee Sheet.
 * (2) Drug sales without an encounter will have 20YYMMDD, possibly
 *     with a suffix, as the encounter-number portion of their invoice
 *     number.
 * (3) Payments are saved as AR only, don't mess with the billing table.
 *     See library/classes/WSClaim.class.php for posting code.
 * (4) On checkout, the billing and drug_sales table entries are marked
 *     as billed and so become unavailable for further billing.
 * (5) Receipt printing must be a separate operation from payment,
 *     and repeatable.
 *
 * TBD:
 * If this user has 'irnpool' set
 *   on display of checkout form
 *     show pending next invoice number
 *   on applying checkout
 *     save next invoice number to form_encounter
 *     compute new next invoice number
 *   on receipt display
 *     show invoice number
 * </pre>
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Rod Roark <rod@sunsetsystems.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @author    Ranganath Pathak <pathak@scrs1.org>
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @author    Stephen Waite <stephen.waite@cmsvt.com>
 * @copyright Copyright (c) 2006-2020 Rod Roark <rod@sunsetsystems.com>
 * @copyright Copyright (c) 2017-2019 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2018 Ranganath Pathak <pathak@scrs1.org>
 * @copyright Copyright (c) 2019 Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2019 Stephen Waite <stephen.waite@cmsvt.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../globals.php");
require_once("$srcdir/patient.inc.php");
require_once("../../custom/code_types.inc.php");

use OpenEMR\Billing\BillingUtilities;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Core\Header;
use OpenEMR\OeUI\OemrUI;
use OpenEMR\Services\FacilityService;
use OpenEMR\Services\Checkout\CheckoutService;
use OpenEMR\Services\Checkout\Exception\PaymentProcessingException;
use OpenEMR\Services\Checkout\PaymentGatewayManager;
use OpenEMR\Services\Checkout\PaymentLedgerService;

if (!AclMain::aclCheckCore('acct', 'bill', '', 'write')) {
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => xl("Patient Checkout")]);
    exit;
}

$facilityService = new FacilityService();
$checkoutLogger = new SystemLogger();
$paymentGatewayManager = new PaymentGatewayManager($checkoutLogger);
$paymentLedgerService = new PaymentLedgerService($checkoutLogger);
$checkoutService = new CheckoutService($paymentGatewayManager, $paymentLedgerService, $checkoutLogger);

$currdecimals = $GLOBALS['currency_decimals'];

$details = empty($_GET['details']) ? 0 : 1;

$patient_id = empty($_GET['ptid']) ? $pid : 0 + $_GET['ptid'];

// This will be used for SQL timestamps that we write.
$this_bill_date = date('Y-m-d H:i:s');

// Get the patient's name and chart number.
$patdata = getPatientData($patient_id, 'fname,mname,lname,pubpid,street,city,state,postal_code');

$prevsvcdate = '';
/**
 * Output HTML for an invoice line item.
 *
 * @param string $svcdate
 * @param string $description
 * @param float $amount
 * @param int $quantity
 * @return void
 */
function receiptDetailLine(string $svcdate, string $description, float $amount, int $quantity): void
{
    global $prevsvcdate, $details;
    if (!$details) {
        return;
    }
    $amount = sprintf('%01.2f', $amount);
    if (empty($quantity)) {
        $quantity = 1;
    }
    $price = sprintf('%01.4f', $amount / $quantity);
    $tmp = sprintf('%01.2f', $price);
    if ($price == $tmp) {
        $price = $tmp;
    }
    echo " <tr>\n";
    echo "  <td>" . ($svcdate == $prevsvcdate ? '&nbsp;' : text(oeFormatShortDate($svcdate))) . "</td>\n";
    echo "  <td>" . text($description) . "</td>\n";
    echo "  <td class='text-right'>" . text(oeFormatMoney($price)) . "</td>\n";
    echo "  <td class='text-right'>" . text($quantity) . "</td>\n";
    echo "  <td class='text-right'>" . text(oeFormatMoney($amount)) . "</td>\n";
    echo " </tr>\n";
    $prevsvcdate = $svcdate;
}

// Output HTML for an invoice payment.
//
function receiptPaymentLine($paydate, $amount, $description = ''): void
{
    $amount = sprintf('%01.2f', 0 - $amount); // make it negative
    echo " <tr>\n";
    echo "  <td>" . text(oeFormatShortDate($paydate)) . "</td>\n";
    echo "  <td>" . xlt('Payment') . " " . text($description) . "</td>\n";
    echo "  <td colspan='2'>&nbsp;</td>\n";
    echo "  <td class='text-right'>" . text(oeFormatMoney($amount)) . "</td>\n";
    echo " </tr>\n";
}

// Generate a receipt from the last-billed invoice for this patient,
// or for the encounter specified as a GET parameter.
//
function generate_receipt($patient_id, $encounter = 0): void
{
 //REMEMBER the entire receipt is generated here, have to echo DOC type etc and closing tags to create a valid webpsge
    global $sl_err, $sl_cash_acc, $details, $facilityService;

    // Get details for what we guess is the primary facility.
    $frow = $facilityService->getPrimaryBusinessEntity(array("useLegacyImplementation" => true));

    $patdata = getPatientData($patient_id, 'fname,mname,lname,pubpid,street,city,state,postal_code,providerID');

    // Get the most recent invoice data or that for the specified encounter.
    //
    // Adding a provider check so that their info can be displayed on receipts
    if ($encounter) {
        $ferow = sqlQuery("SELECT id, date, encounter, provider_id FROM form_encounter " .
        "WHERE pid = ? AND encounter = ?", array($patient_id,$encounter));
    } else {
        $ferow = sqlQuery("SELECT id, date, encounter, provider_id FROM form_encounter " .
        "WHERE pid = ? " .
        "ORDER BY id DESC LIMIT 1", array($patient_id));
    }
    if (empty($ferow)) {
        die(xlt("This patient has no activity."));
    }
    $trans_id = $ferow['id'];
    $encounter = $ferow['encounter'];
    $svcdate = substr($ferow['date'], 0, 10);

    if ($GLOBALS['receipts_by_provider']) {
        if (isset($ferow['provider_id'])) {
            $encprovider = $ferow['provider_id'];
        } elseif (isset($patdata['providerID'])) {
            $encprovider = $patdata['providerID'];
        } else {
            $encprovider = -1;
        }
    }

    if (!empty($encprovider)) {
        $providerrow = sqlQuery("SELECT fname, mname, lname, title, street, streetb, " .
        "city, state, zip, phone, fax FROM users WHERE id = ?", array($encprovider));
    }

    // Get invoice reference number.
    $encrow = sqlQuery("SELECT invoice_refno FROM form_encounter WHERE " .
    "pid = ? AND encounter = ? LIMIT 1", array($patient_id,$encounter));
    $invoice_refno = $encrow['invoice_refno'];
    ?>
    <!-- being deliberately echoed to indicate it is part of the php function generate_receipt -->

    <!DOCTYPE html>
    <html>
    <head>
        <?php Header::setupHeader(['datetime-picker']);?>
        <title><?php echo xlt('Receipt for Payment'); ?></title>
        <script>

        <?php require($GLOBALS['srcdir'] . "/restoreSession.php"); ?>

        $(function () {
            var win = top.printLogSetup ? top : opener.top;
            win.printLogSetup(document.getElementById('printbutton'));
        });

        // Process click on Print button.
        function printlog_before_print() {
            var divstyle = document.getElementById('hideonprint').style;
            divstyle.display = 'none';
        }

        // Process click on Delete button.
        function deleteme() {
            dlgopen('deleter.php?billing=' + <?php echo js_url($patient_id . "." . $encounter); ?> + '&csrf_token_form=' + <?php echo js_url(CsrfUtils::collectCsrfToken()); ?>, '_blank', 500, 450);
            return false;
        }

        // Called by the deleteme.php window on a successful delete.
        function imdeleted() {
            window.close();
        }

        </script>

        <style>
        @media (min-width: 992px){
            .modal-lg {
                width: 1000px !Important;
            }
        }
        body {
            width: 100% !important;
        }

        .grid-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            grid-gap: 20px;

        }

        #address_left {
        text-align: left !important;
        padding-top: 20px;
        padding-left: 20px;
        }

        .mini_table {
            margin-top: 15px;
            width: 95%;
            text-align: center;
        }

        table.mini_table>tbody>tr>th {
            background-color: var(--secondary);
            text-align: center;
        }

        body>table.mini_table>tbody>tr>td {
            text-align: center;
        }

        body>table.mini_table>tbody>tr>td {
            border: 1px solid #fff;
        }

        body>table.mini_table>tbody>tr>th {
            border: 1px solid #93cef9;
        }

        table,
        td,
        th {
            border: 1px solid #000;
            text-align: left;
        }

        table {
            margin-top: 30px !important;
            border-collapse: collapse;
            width: 98%;
        }

        th,
        td {
            padding: 5px;
        }

        body > div:nth-child(2) > div:nth-child(3) > div > table > thead > tr {
            background-color: var(--secondary);
        }

        body > div:nth-child(3) > div:nth-child(3) > div > table > thead {
            background-color: var(--secondary);
        }

        body > div:nth-child(3) > div:nth-child(3) > div > table:nth-child(1) > tbody > tr:nth-child(3) {
            border: none!important;
        }

        .bg-blue {
            background-color:var(--secondary);
        }

        .fac-name {
            background-color:var(--secondary);
            width: 99px;
        }
        .bg-color {
            background-color: var(--secondary);
            padding: 2px; font-weight: 600;
            -webkit-print-color-adjust: exact;
        }
        </style>
        <title><?php echo xlt('Patient Checkout'); ?></title>
    </head>
    <body>
    <div class="container mt-3">
            <div class="row">
                <div class="col-6">
                    <?php echo text($patdata['fname']) . ' ' . text($patdata['mname']) . ' ' . text($patdata['lname']); ?><br />
                    <?php echo text($patdata['street']) ?><br />
                    <?php echo text($patdata['city']) . ', ' . text($patdata['state']) . ' ' . text($patdata['postal_code']); ?><br />
                </div>
            </div>
            <div class="">
                <div class="">
                    <table class="">
                        <thead>
                            <tr>
                                <th><strong><?php echo xlt('Date of Service'); ?></strong></th>
                                <th><strong><?php echo xlt('Description'); ?></strong></th>
                                <th class='text-right'><strong><?php echo $details ? xlt('Price') : '&nbsp;'; ?></strong></th>
                                <th class='text-right'><strong><?php echo $details ? xlt('Qty') : '&nbsp;'; ?></strong></th>
                                <th class='text-right' ><strong><?php echo xlt('Total'); ?></strong></th>
                            </tr>
                        </thead>
                        <?php
                        $charges = 0.00;

                        // Product sales
                        $inres = sqlStatement(
                            "SELECT s.sale_id, s.sale_date, s.fee, " .
                            "s.quantity, s.drug_id, d.name " .
                            "FROM drug_sales AS s LEFT JOIN drugs AS d ON d.drug_id = s.drug_id " .
                            // "WHERE s.pid = '$patient_id' AND s.encounter = '$encounter' AND s.fee != 0 " .
                            "WHERE s.pid = ? AND s.encounter = ? " .
                            "ORDER BY s.sale_id",
                            array($patient_id,$encounter)
                        );
                        while ($inrow = sqlFetchArray($inres)) {
                            $charges += sprintf('%01.2f', $inrow['fee']);
                            receiptDetailLine(
                                $inrow['sale_date'],
                                $inrow['name'],
                                $inrow['fee'],
                                $inrow['quantity']
                            );
                        }

                        // Service and tax items
                        $inres = sqlStatement(
                            "SELECT * FROM billing WHERE " .
                            "pid = ? AND encounter = ? AND " .
                            // "code_type != 'COPAY' AND activity = 1 AND fee != 0 " .
                            "code_type != 'COPAY' AND activity = 1 " .
                            "ORDER BY id",
                            array($patient_id,$encounter)
                        );
                        while ($inrow = sqlFetchArray($inres)) {
                            $charges += sprintf('%01.2f', $inrow['fee']);
                            receiptDetailLine(
                                $svcdate,
                                $inrow['code_text'],
                                $inrow['fee'],
                                $inrow['units']
                            );
                        }

                        // Adjustments.
                        $inres = sqlStatement(
                            "SELECT " .
                            "a.code_type, a.code, a.modifier, a.memo, a.payer_type, a.adj_amount, a.pay_amount, " .
                            "s.payer_id, s.reference, s.check_date, s.deposit_date " .
                            "FROM ar_activity AS a " .
                            "LEFT JOIN ar_session AS s ON s.session_id = a.session_id WHERE " .
                            "a.pid = ? AND a.encounter = ? AND a.deleted IS NULL AND " .
                            "a.adj_amount != 0 " .
                            "ORDER BY s.check_date, a.sequence_no",
                            array($patient_id,$encounter)
                        );
                        while ($inrow = sqlFetchArray($inres)) {
                            $charges -= sprintf('%01.2f', $inrow['adj_amount']);
                            $payer = empty($inrow['payer_type']) ? 'Pt' : ('Ins' . $inrow['payer_type']);
                            receiptDetailLine(
                                $svcdate,
                                $payer . ' ' . $inrow['memo'],
                                0 - $inrow['adj_amount'],
                                1
                            );
                        }
                        ?>
                        <tr style="border:none !important">
                            <td style="border:0px solid red !important"><?php echo text(oeFormatShortDate($svcdispdate ?? '')); ?></td>
                            <td class='text-right' style="border:none !important">&nbsp;</td>
                            <td class='text-right' style="border:none !important">&nbsp;</td>
                            <td class='text-right bg-blue' style="border: 1px solid;"><b><?php echo xlt('Total Charges'); ?></b></td>

                            <td class='text-right bg-blue' style="border: 1px solid;"><?php echo text(oeFormatMoney($charges, true)) ?></td>
                        </tr>
                        <tr>
                            <td colspan='5'>&nbsp;</td>
                        </tr>
                        <?php
                        // Get co-pays.
                        $inres = sqlStatement(
                            "SELECT fee, code_text FROM billing WHERE " .
                            "pid = ? AND encounter = ?  AND " .
                            "code_type = 'COPAY' AND activity = 1 AND fee != 0 " .
                            "ORDER BY id",
                            array($patient_id,$encounter)
                        );
                        while ($inrow = sqlFetchArray($inres)) {
                            $charges += sprintf('%01.2f', $inrow['fee']);
                            receiptPaymentLine($svcdate, 0 - $inrow['fee'], $inrow['code_text']);
                        }
                        // Get other payments.
                        $inres = sqlStatement(
                            "SELECT " .
                            "a.code_type, a.code, a.modifier, a.memo, a.payer_type, a.adj_amount, a.pay_amount, " .
                            "s.payer_id, s.reference, s.check_date, s.deposit_date " .
                            "FROM ar_activity AS a " .
                            "LEFT JOIN ar_session AS s ON s.session_id = a.session_id WHERE " .
                            "a.pid = ? AND a.encounter = ? AND a.deleted IS NULL AND " .
                            "a.pay_amount != 0 " .
                            "ORDER BY s.check_date, a.sequence_no",
                            array($patient_id,$encounter)
                        );
                        while ($inrow = sqlFetchArray($inres)) {
                            $payer = empty($inrow['payer_type']) ? 'Pt' : ('Ins' . $inrow['payer_type']);
                            $charges -= sprintf('%01.2f', $inrow['pay_amount']);
                            receiptPaymentLine(
                                $svcdate,
                                $inrow['pay_amount'],
                                $payer . ' ' . $inrow['reference']
                            );
                        }
                        ?>
                        <tr>
                            <td colspan='5'>&nbsp;</td>
                        </tr>
                        <tr>
                            <td style="border:none !important">&nbsp;</td>
                            <td style="border:none !important">&nbsp;</td>
                            <td style="border:none !important">&nbsp;</td>
                            <td class="font-weight-bold text-right bg-blue" style="border: 1px solid;" colspan='2'><?php echo xlt('Balance Due'); ?>: <?php echo text(oeFormatMoney($charges, true)) ?></td>
                        </tr>
                    </table>
                </div>
            </div>
            <br />
            <div class="row">
                <div class="col-sm-12 mb-5" id="hideonprint">
                    <div class="btn-group" role="group">
                        <button class="btn btn-primary btn-print"  id='printbutton'><?php echo xlt('Print'); ?></button>
                        <?php if (AclMain::aclCheckCore('acct', 'disc')) { ?>
                            <button class="btn btn-secondary btn-undo" onclick='return deleteme();'><?php echo xlt('Undo Checkout'); ?></button>
                        <?php } ?>
                        <?php if ($details) { ?>
                            <button class="btn btn-secondary btn-hide" onclick="top.restoreSession(); window.location.href = 'pos_checkout.php?details=0&ptid=<?php echo attr_url($patient_id); ?>&enc=<?php echo attr_url($encounter); ?>'"><?php echo xlt('Hide Details'); ?></button>
                        <?php } else { ?>
                            <button class="btn btn-secondary btn-show" onclick="top.restoreSession(); window.location.href = 'pos_checkout.php?details=1&ptid=<?php echo attr_url($patient_id); ?>&enc=<?php echo attr_url($encounter); ?>'"><?php echo xlt('Show Details'); ?></button>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </div><!--end of receipt container div-->
    </body>
    </html>
    <?php // echoing the closing tags for receipts
} // end function generate_receipt()
?>
    <?php


    // Function to output a line item for the input form.
    //
    $lino = 0;
    function write_form_line(
        $code_type,
        $code,
        $id,
        $date,
        $description,
        $amount,
        $units,
        $taxrates
    ): void {
        global $lino;
        $amount = sprintf("%01.2f", $amount);
        if (empty($units)) {
            $units = 1;
        }
        $price = $amount / $units; // should be even cents, but ok here if not
        if ($code_type == 'COPAY' && !$description) {
            $description = xl('Payment');
        }
        echo " <tr class='checkout-line' data-line-index='" . attr($lino) . "'>\n";
        echo "  <td>" . text(oeFormatShortDate($date));
        echo "<input type='hidden' name='line[$lino][code_type]' value='" . attr($code_type) . "' />";
        echo "<input type='hidden' name='line[$lino][billing_code_type]' value='" . attr($code_type) . "' />";
        echo "<input type='hidden' name='line[$lino][code]' value='" . attr($code) . "' />";
        echo "<input type='hidden' name='line[$lino][id]' value='" . attr($id) . "' />";
        echo "<input type='hidden' name='line[$lino][description]' value='" . attr($description) . "' />";
        echo "<input type='hidden' name='line[$lino][taxrates]' value='" . attr($taxrates) . "' />";
        echo "<input type='hidden' name='line[$lino][price]' value='" . attr($price) . "' />";
        echo "<input type='hidden' name='line[$lino][units]' value='" . attr($units) . "' />";
        echo "<input type='hidden' name='line[$lino][is_new]' value='0' />";
        echo "<input type='hidden' name='line[$lino][is_deleted]' value='0' />";
        echo "</td>\n";
        echo "  <td>" . text($description) . "</td>";
        echo "  <td class='text-right'>" . text($units) . "</td>";
        echo "  <td class='text-right'><input type='text' class='form-control' name='line[$lino][amount]' " .
           "value='" . attr($amount) . "' size='6' maxlength='8'";
        // Modifying prices requires the acct/disc permission.
        // if ($code_type == 'TAX' || ($code_type != 'COPAY' && !AclMain::aclCheckCore('acct','disc')))
        echo "  readonly";
        // else echo " style='text-align:right' onkeyup='computeTotals()'";
        echo "></td>\n";
        echo "  <td class='text-center'><button type='button' class='btn btn-link text-danger p-0 d-none' data-remove-line='" . attr($lino) . "' aria-label='" . attr(xlt('Remove line')) . "'>&times;</button></td>\n";
        echo " </tr>\n";
        ++$lino;
    }

    // Create the taxes array.  Key is tax id, value is
    // (description, rate, accumulated total).
    $taxes = array();
    $pres = sqlStatement("SELECT option_id, title, option_value " .
      "FROM list_options WHERE list_id = 'taxrate' AND activity = 1 ORDER BY seq, title, option_id");
    while ($prow = sqlFetchArray($pres)) {
        $taxes[$prow['option_id']] = array($prow['title'], $prow['option_value'], 0);
    }

    // Print receipt header for facility
    function printFacilityHeader($frow): void
    {
        echo text($frow['name'] ?? '') .
        "<br />" . text($frow['street'] ?? '') .
        "<br />" . text($frow['city'] ?? '') . ', ' . text($frow['state'] ?? '') . ' ' . text($frow['postal_code'] ?? '') .
        "<br />" . text($frow['phone'] ?? '') .
        "<br />&nbsp" .
        "<br />";
    }

    // Pring receipt header for Provider
    function printProviderHeader($pvdrow): void
    {
        echo text($pvdrow['title']) . " " . text($pvdrow['fname']) . " " . text($pvdrow['mname']) . " " . text($pvdrow['lname']) . " " .
        "<br />" . text($pvdrow['street']) .
        "<br />" . text($pvdrow['city']) . ', ' . text($pvdrow['state']) . ' ' . text($pvdrow['postal_code']) .
        "<br />" . text($pvdrow['phone']) .
        "<br />&nbsp" .
        "<br />";
    }

    // Mark the tax rates that are referenced in this invoice.
    function markTaxes($taxrates): void
    {
        global $taxes;
        $arates = explode(':', $taxrates);
        if (empty($arates)) {
            return;
        }
        foreach ($arates as $value) {
            if (!empty($taxes[$value])) {
                $taxes[$value][2] = '1';
            }
        }
    }

    $alertmsg = ''; // anything here pops up in an alert box

    $payment_method_options = array();
    $paymentMethodRes = sqlStatement(
        "SELECT option_id, title, is_default FROM list_options WHERE list_id = ? ORDER BY seq, title",
        array('payment_method')
    );
    while ($methodRow = sqlFetchArray($paymentMethodRes)) {
        if ($methodRow['option_id'] == 'electronic' || $methodRow['option_id'] == 'bank_draft') {
            continue;
        }
        $payment_method_options[] = array(
            'value' => $methodRow['option_id'],
            'label' => xl_list_label($methodRow['title']),
            'is_default' => !empty($methodRow['is_default'])
        );
    }

    // If the Save button was clicked...
    //
    if (!empty($_POST['form_save'])) {
        if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
            CsrfUtils::csrfNotVerified();
        }

      // On a save, do the following:
      // Flag drug_sales and billing items as billed.
      // Post the corresponding invoice with its payment(s) to sql-ledger
      // and be careful to use a unique invoice number.
      // Call the generate-receipt function.
      // Exit.

        $form_pid = $_POST['form_pid'];
        $form_encounter = $_POST['form_encounter'];

      // Get the posting date from the form as yyyy-mm-dd.
        $dosdate = substr($this_bill_date, 0, 10);
        if (preg_match("/(\d\d\d\d)\D*(\d\d)\D*(\d\d)/", $_POST['form_date'], $matches)) {
            $dosdate = $matches[1] . '-' . $matches[2] . '-' . $matches[3];
        }

      // If there is no associated encounter (i.e. this invoice has only
      // prescriptions) then assign an encounter number of the service
      // date, with an optional suffix to ensure that it's unique.
      //
        if (! $form_encounter) {
            $form_encounter = substr($dosdate, 0, 4) . substr($dosdate, 5, 2) . substr($dosdate, 8, 2);
            $tmp = '';
            while (true) {
                $ferow = sqlQuery("SELECT id FROM form_encounter WHERE " .
                "pid = ? AND encounter = ?", array($form_pid, $form_encounter . $tmp));
                if (empty($ferow)) {
                    break;
                }
                $tmp = $tmp ? $tmp + 1 : 1;
            }
            $form_encounter .= $tmp;
        }

        // Delete any TAX rows from billing because they will be recalculated.
        sqlStatement("UPDATE billing SET activity = 0 WHERE " .
          "pid = ? AND encounter = ? AND " .
          "code_type = 'TAX'", array($form_pid,$form_encounter));

        $form_amount = $_POST['form_amount'];
        $lines = $_POST['line'] ?? array();
        if (!is_array($lines)) {
            $lines = array();
        }

        $totalDue = 0.00;
        $providerForNewItems = $_POST['form_provider'] ?? '';

        foreach ($lines as $line) {
            if (empty($line['code_type'])) {
                continue;
            }
            if (!empty($line['is_deleted']) && (int)$line['is_deleted'] === 1) {
                continue;
            }

            $code_type = $line['code_type'];
            $billingCodeType = $line['billing_code_type'] ?? $code_type;
            $id        = $line['id'] ?? '';
            $amount    = sprintf('%01.2f', trim((string)($line['amount'] ?? 0)));
            $totalDue += (float)$amount;

            if (!empty($line['is_new'])) {
                $units = !empty($line['units']) ? (float)$line['units'] : 1;
                $description = $line['description'] ?? '';
                BillingUtilities::addBilling(
                    $form_encounter,
                    $billingCodeType,
                    $line['code'] ?? '',
                    $description,
                    $form_pid,
                    '1',
                    $providerForNewItems,
                    '',
                    $units,
                    $amount,
                    '',
                    '',
                    1
                );
                continue;
            }

            if ($billingCodeType == 'PROD') {
                // Product sales. The fee and encounter ID may have changed.
                $query = "update drug_sales SET fee = ?, " .
                "encounter = ?, billed = 1 WHERE " .
                "sale_id = ?";
                sqlQuery($query, array($amount,$form_encounter,$id));
            } elseif ($billingCodeType == 'TAX') {
                // In the SL case taxes show up on the invoice as line items.
                // Otherwise we gotta save them somewhere, and in the billing
                // table with a code type of TAX seems easiest.
                // They will have to be stripped back out when building this
                // script's input form.
                BillingUtilities::addBilling(
                    $form_encounter,
                    'TAX',
                    'TAX',
                    'Taxes',
                    $form_pid,
                    0,
                    0,
                    '',
                    '',
                    $amount,
                    '',
                    '',
                    1
                );
            } else {
                // Because there is no insurance here, there is no need for a claims
                // table entry and so we do not call updateClaim().  Note we should not
                // eliminate billed and bill_date from the billing table!
                $query = "UPDATE billing SET fee = ?, billed = 1, " .
                "bill_date = ? WHERE id = ?";
                sqlQuery($query, array($amount, $this_bill_date, $id));
            }
        }

      // Post discount.
        if ($_POST['form_discount']) {
            if ($GLOBALS['discount_by_money']) {
                $amount  = sprintf('%01.2f', trim($_POST['form_discount']));
            } else {
                $form_discount = trim($_POST['form_discount']) ?? 0;
                if ($form_discount < 100) {
                    $total_discount = $form_discount * $form_amount / (100 - $form_discount);
                    $amount = sprintf('%01.2f', $total_discount);
                }
            }
            $memo = xl('Discount');
            sqlBeginTrans();
            $sequence_no = sqlQuery("SELECT IFNULL(MAX(sequence_no),0) + 1 AS increment FROM ar_activity WHERE pid = ? AND encounter = ?", array($form_pid, $form_encounter));
            $query = "INSERT INTO ar_activity ( " .
            "pid, encounter, sequence_no, code, modifier, payer_type, post_user, post_time, " .
            "session_id, memo, adj_amount " .
            ") VALUES ( " .
            "?, " .
            "?, " .
            "?, " .
            "'', " .
            "'', " .
            "'0', " .
            "?, " .
            "?, " .
            "'0', " .
            "?, " .
            "? " .
            ")";
            sqlStatement(
                $query,
                array($form_pid, $form_encounter, $sequence_no['increment'], $_SESSION['authUserID'], $this_bill_date, $memo, $amount)
            );
            sqlCommitTrans();
        }

      // Post payment(s).
        $paymentsPayload = array();
        if (!empty($_POST['form_payments'])) {
            $decodedPayments = json_decode($_POST['form_payments'], true);
            if (is_array($decodedPayments)) {
                $paymentsPayload = $decodedPayments;
            }
        }

        if (empty($paymentsPayload) && !empty($_POST['form_amount'])) {
            $paymentsPayload[] = array(
                'amount' => sprintf('%01.2f', trim((string)$_POST['form_amount'])),
                'reference' => trim($_POST['form_source'] ?? ''),
                'method' => trim($_POST['form_method'] ?? ''),
                'installments' => 1
            );
        }

        $Codetype = '';
        $Code = '';
        $Modifier = '';
        if (!empty($paymentsPayload)) {
            $ResultSearchNew = sqlStatement(
                "SELECT * FROM billing LEFT JOIN code_types ON billing.code_type=code_types.ct_key " .
                "WHERE code_types.ct_fee=1 AND billing.activity!=0 AND billing.pid =? AND encounter=? ORDER BY billing.code,billing.modifier",
                array($form_pid,$form_encounter)
            );
            if ($RowSearch = sqlFetchArray($ResultSearchNew)) {
                $Codetype = $RowSearch['code_type'];
                $Code = $RowSearch['code'];
                $Modifier = $RowSearch['modifier'];
            }

            try {
                $checkoutService->processPayments(
                    (int)$form_pid,
                    (int)$form_encounter,
                    $paymentsPayload,
                    $dosdate,
                    $this_bill_date,
                    array(
                        'code_type' => $Codetype,
                        'code' => $Code,
                        'modifier' => $Modifier
                    )
                );
            } catch (PaymentProcessingException $exception) {
                $checkoutLogger->error($exception->getMessage(), ['exception' => $exception]);
                $_SESSION['checkout_error'] = $exception->getMessage();
                $redirectUrl = 'pos_checkout.php?ptid=' . urlencode((string)$form_pid);
                if (!empty($_GET['framed'])) {
                    $redirectUrl .= '&framed=1';
                }
                header("Location: " . $redirectUrl);
                exit();
            }
        }

      // If applicable, set the invoice reference number.
        $invoice_refno = '';
        if (isset($_POST['form_irnumber'])) {
            $invoice_refno = trim($_POST['form_irnumber']);
        } else {
            $invoice_refno = BillingUtilities::updateInvoiceRefNumber();
        }
        if ($invoice_refno) {
            sqlStatement("UPDATE form_encounter " .
            "SET invoice_refno = ? " .
            "WHERE pid = ? AND encounter = ?", array($invoice_refno,$form_pid,$form_encounter));
        }

        generate_receipt($form_pid, $form_encounter);
        exit();
    }

    // If an encounter ID was given, then we must generate a receipt.
    //
    if (!empty($_GET['enc'])) {
        generate_receipt($patient_id, $_GET['enc']);
        exit();
    }

    // Get the unbilled billing table items for this patient.
    $query = "SELECT id, date, code_type, code, modifier, code_text, " .
      "provider_id, payer_id, units, fee, encounter " .
      "FROM billing WHERE pid = ? AND activity = 1 AND " .
      "billed = 0 AND code_type != 'TAX' " .
      "ORDER BY encounter DESC, id ASC";
    $bres = sqlStatement($query, array($patient_id));

    // Get the product sales for this patient.
    $query = "SELECT s.sale_id, s.sale_date, s.prescription_id, s.fee, " .
      "s.quantity, s.encounter, s.drug_id, d.name, r.provider_id " .
      "FROM drug_sales AS s " .
      "LEFT JOIN drugs AS d ON d.drug_id = s.drug_id " .
      "LEFT OUTER JOIN prescriptions AS r ON r.id = s.prescription_id " .
      "WHERE s.pid = ? AND s.billed = 0 " .
      "ORDER BY s.encounter DESC, s.sale_id ASC";
    $dres = sqlStatement($query, array($patient_id));

    $aesthetic_kits = array();
    $kitRes = sqlStatement(
        "SELECT option_id, title, option_value, notes FROM list_options WHERE list_id = ? AND activity = 1 ORDER BY seq, title",
        array('aesthetic_kits')
    );
    while ($kitRow = sqlFetchArray($kitRes)) {
        $rawItems = trim((string)($kitRow['notes'] ?? ''));
        $items = $rawItems === '' ? array() : array_filter(array_map('trim', explode(',', $rawItems)));
        $aesthetic_kits[] = array(
            'id' => $kitRow['option_id'],
            'label' => xl_list_label($kitRow['title']),
            'price' => (float)$kitRow['option_value'],
            'items' => $items
        );
    }

    // If there are none, just redisplay the last receipt and exit.
    //
    if (sqlNumRows($bres) == 0 && sqlNumRows($dres) == 0) {
        generate_receipt($patient_id);
        exit();
    }

    // Get the valid practitioners, including those not active.
    $arr_users = array();
    $ures = sqlStatement("SELECT id, username FROM users WHERE " .
      "( authorized = 1 OR info LIKE '%provider%' ) AND username != ''");
    while ($urow = sqlFetchArray($ures)) {
        $arr_users[$urow['id']] = '1';
    }

    // Now write a data entry form:
    // List unbilled billing items (cpt, hcpcs, copays) for the patient.
    // List unbilled product sales for the patient.
    // Present an editable dollar amount for each line item, a total
    // which is also the default value of the input payment amount,
    // and OK and Cancel buttons.
    ?>
<!DOCTYPE html>
    <html>
    <head>
        <?php Header::setupHeader(['datetime-picker']);?>

        <script>
            var mypcc = <?php echo js_escape($GLOBALS['phone_country_code']); ?>;

            <?php require($GLOBALS['srcdir'] . "/restoreSession.php"); ?>

            var checkoutNextLineIndex = <?php echo js_escape($lino); ?>;
            var checkoutInvoiceTotal = 0;
            var checkoutPaymentIndex = 0;
            var checkoutPaymentOptions = <?php echo json_encode($payment_method_options, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
            var checkoutKits = <?php echo json_encode($aesthetic_kits, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
            var gatewayOptions = [
                {value: '', label: '<?php echo xlt('No Gateway'); ?>'},
                {value: 'stripe', label: 'Stripe'},
                {value: 'pagseguro', label: 'PagSeguro'}
            ];

            function clearTax(visible) {
                var f = document.forms[0];
                for (var lino = 0; true; ++lino) {
                    var pfx = 'line[' + lino + ']';
                    if (!f[pfx + '[code_type]']) {
                        break;
                    }
                    if (f[pfx + '[code_type]'].value !== 'TAX') {
                        continue;
                    }
                    f[pfx + '[price]'].value = '0.00';
                    if (visible) {
                        f[pfx + '[amount]'].value = '0.00';
                    }
                }
            }

            function addTax(rateid, amount, visible) {
                if (rateid.length === 0) {
                    return 0;
                }
                var f = document.forms[0];
                for (var lino = 0; true; ++lino) {
                    var pfx = 'line[' + lino + ']';
                    if (!f[pfx + '[code_type]']) {
                        break;
                    }
                    if (f[pfx + '[code_type]'].value !== 'TAX') {
                        continue;
                    }
                    if (f[pfx + '[code]'].value !== rateid) {
                        continue;
                    }
                    var tax = amount * parseFloat(f[pfx + '[taxrates]'].value);
                    tax = parseFloat(tax.toFixed(<?php echo js_escape($currdecimals); ?>));
                    var cumtax = parseFloat(f[pfx + '[price]'].value) + tax;
                    f[pfx + '[price]'].value = cumtax.toFixed(<?php echo js_escape($currdecimals); ?>);
                    if (visible) {
                        f[pfx + '[amount]'].value = cumtax.toFixed(<?php echo js_escape($currdecimals); ?>);
                    }
                    if (isNaN(tax)) {
                        alert('Tax rate not numeric at line ' + lino);
                    }
                    return tax;
                }
                return 0;
            }

            function computeDiscountedTotals(discount, visible) {
                clearTax(visible);
                var f = document.forms[0];
                var total = 0.00;
                for (var lino = 0; f['line[' + lino + '][code_type]']; ++lino) {
                    if (f['line[' + lino + '][is_deleted]'] && f['line[' + lino + '][is_deleted]'].value === '1') {
                        continue;
                    }
                    var code_type = f['line[' + lino + '][code_type]'].value;
                    var price = parseFloat(f['line[' + lino + '][price]'].value);
                    if (isNaN(price)) {
                        alert('Price not numeric at line ' + lino);
                    }
                    if (code_type === 'COPAY' || code_type === 'TAX') {
                        total += parseFloat(price.toFixed(<?php echo js_escape($currdecimals); ?>));
                        continue;
                    }
                    var units = f['line[' + lino + '][units]'].value;
                    var amount = price * units;
                    amount = parseFloat(amount.toFixed(<?php echo js_escape($currdecimals); ?>));
                    if (visible) {
                        f['line[' + lino + '][amount]'].value = amount.toFixed(<?php echo js_escape($currdecimals); ?>);
                    }
                    total += amount;
                    var taxrates = f['line[' + lino + '][taxrates]'].value;
                    var taxids = taxrates.split(':');
                    for (var j = 0; j < taxids.length; ++j) {
                        addTax(taxids[j], amount, visible);
                    }
                }
                return total - discount;
            }

            function computeTotals() {
                var f = document.forms[0];
                var discount = parseFloat(f.form_discount.value);
                if (isNaN(discount)) {
                    discount = 0;
                }
                <?php if (!$GLOBALS['discount_by_money']) { ?>
                if (discount > 100) {
                    discount = 100;
                }
                if (discount < 0) {
                    discount = 0;
                }
                discount = 0.01 * discount * computeDiscountedTotals(0, false);
                <?php } ?>
                var total = computeDiscountedTotals(discount, true);
                f.form_amount.value = total.toFixed(<?php echo js_escape($currdecimals); ?>);
                checkoutInvoiceTotal = parseFloat(total.toFixed(<?php echo js_escape($currdecimals); ?>));
                refreshPaymentSummary();
                return true;
            }

            function collectPayments() {
                var rows = document.querySelectorAll('#payment-table-body tr');
                var results = [];
                rows.forEach(function (row) {
                    var amountInput = row.querySelector('[data-payment-field="amount"]');
                    if (!amountInput) {
                        return;
                    }
                    var amount = parseFloat(amountInput.value);
                    if (isNaN(amount) || amount <= 0) {
                        return;
                    }
                    var method = row.querySelector('[data-payment-field="method"]');
                    var gateway = row.querySelector('[data-payment-field="gateway"]');
                    var reference = row.querySelector('[data-payment-field="reference"]');
                    var installmentsInput = row.querySelector('[data-payment-field="installments"]');
                    var installments = parseInt(installmentsInput && installmentsInput.value ? installmentsInput.value : '1', 10);
                    if (isNaN(installments) || installments < 1) {
                        installments = 1;
                    }
                    results.push({
                        method: method ? method.value : '',
                        gateway: gateway ? gateway.value : '',
                        reference: reference ? reference.value : '',
                        installments: installments,
                        amount: parseFloat(amount.toFixed(<?php echo js_escape($currdecimals); ?>))
                    });
                });
                return results;
            }

            function refreshPaymentSummary() {
                var payments = collectPayments();
                var totalCaptured = 0;
                payments.forEach(function (payment) {
                    totalCaptured += payment.amount;
                });
                var dueEl = document.getElementById('payment-total-due');
                if (dueEl) {
                    dueEl.textContent = checkoutInvoiceTotal.toFixed(<?php echo js_escape($currdecimals); ?>);
                }
                var capturedEl = document.getElementById('payment-total-captured');
                if (capturedEl) {
                    capturedEl.textContent = totalCaptured.toFixed(<?php echo js_escape($currdecimals); ?>);
                }
                var balance = checkoutInvoiceTotal - totalCaptured;
                var balanceEl = document.getElementById('payment-balance');
                if (balanceEl) {
                    balanceEl.textContent = balance.toFixed(<?php echo js_escape($currdecimals); ?>);
                    balanceEl.setAttribute('data-value', balance.toFixed(<?php echo js_escape($currdecimals); ?>));
                }
            }

            function serializePayments() {
                var payments = collectPayments();
                var normalized = payments.map(function (payment) {
                    return {
                        method: payment.method,
                        gateway: payment.gateway,
                        reference: payment.reference,
                        installments: payment.installments,
                        amount: payment.amount.toFixed(<?php echo js_escape($currdecimals); ?>)
                    };
                });
                var paymentsField = document.getElementById('form_payments');
                if (paymentsField) {
                    paymentsField.value = JSON.stringify(normalized);
                }
                var formMethod = document.getElementById('form_method');
                var formSource = document.getElementById('form_source');
                if (normalized.length > 0) {
                    if (formMethod) {
                        formMethod.value = normalized[0].method || '';
                    }
                    if (formSource) {
                        formSource.value = normalized[0].reference || '';
                    }
                } else {
                    if (formMethod) {
                        formMethod.value = '';
                    }
                    if (formSource) {
                        formSource.value = '';
                    }
                }
                return normalized;
            }

            function prepareCheckout() {
                var payload = serializePayments();
                if (payload.length === 0) {
                    alert('<?php echo xlt('Add at least one payment before finalizing the checkout.'); ?>');
                    return false;
                }
                var balance = parseFloat(document.getElementById('payment-balance').getAttribute('data-value'));
                if (isNaN(balance)) {
                    balance = 0;
                }
                if (Math.abs(balance) > 0.009) {
                    if (!confirm('<?php echo xlt('Payments do not match the total due. Continue anyway?'); ?>')) {
                        return false;
                    }
                }
                return true;
            }

            function getDefaultPaymentMethod() {
                if (!checkoutPaymentOptions || checkoutPaymentOptions.length === 0) {
                    return '';
                }
                for (var i = 0; i < checkoutPaymentOptions.length; ++i) {
                    if (checkoutPaymentOptions[i].is_default) {
                        return checkoutPaymentOptions[i].value;
                    }
                }
                return checkoutPaymentOptions[0].value;
            }

            function addPaymentRow(payment) {
                payment = payment || {};
                var tbody = document.getElementById('payment-table-body');
                if (!tbody) {
                    return;
                }
                var index = checkoutPaymentIndex++;
                var tr = document.createElement('tr');
                tr.setAttribute('data-index', index);

                var methodCell = document.createElement('td');
                var methodSelect = document.createElement('select');
                methodSelect.className = 'form-control';
                methodSelect.setAttribute('data-payment-field', 'method');
                (checkoutPaymentOptions || []).forEach(function (option) {
                    var opt = document.createElement('option');
                    opt.value = option.value;
                    opt.textContent = option.label;
                    if (payment.method && payment.method === option.value) {
                        opt.selected = true;
                    }
                    methodSelect.appendChild(opt);
                });
                if (!methodSelect.value) {
                    methodSelect.value = payment.method || getDefaultPaymentMethod();
                }
                methodCell.appendChild(methodSelect);
                tr.appendChild(methodCell);

                var gatewayCell = document.createElement('td');
                var gatewaySelect = document.createElement('select');
                gatewaySelect.className = 'form-control';
                gatewaySelect.setAttribute('data-payment-field', 'gateway');
                gatewayOptions.forEach(function (option) {
                    var opt = document.createElement('option');
                    opt.value = option.value;
                    opt.textContent = option.label;
                    if (payment.gateway && payment.gateway === option.value) {
                        opt.selected = true;
                    }
                    gatewaySelect.appendChild(opt);
                });
                gatewayCell.appendChild(gatewaySelect);
                tr.appendChild(gatewayCell);

                var referenceCell = document.createElement('td');
                var referenceInput = document.createElement('input');
                referenceInput.type = 'text';
                referenceInput.className = 'form-control';
                referenceInput.setAttribute('data-payment-field', 'reference');
                referenceInput.value = payment.reference || '';
                referenceCell.appendChild(referenceInput);
                tr.appendChild(referenceCell);

                var installmentsCell = document.createElement('td');
                var installmentsInput = document.createElement('input');
                installmentsInput.type = 'number';
                installmentsInput.className = 'form-control';
                installmentsInput.min = '1';
                installmentsInput.step = '1';
                installmentsInput.setAttribute('data-payment-field', 'installments');
                installmentsInput.value = payment.installments || 1;
                installmentsInput.addEventListener('input', function () {
                    var parsed = parseInt(this.value, 10);
                    if (isNaN(parsed) || parsed < 1) {
                        this.value = 1;
                    }
                });
                installmentsCell.appendChild(installmentsInput);
                tr.appendChild(installmentsCell);

                var amountCell = document.createElement('td');
                amountCell.className = 'text-right';
                var amountInput = document.createElement('input');
                amountInput.type = 'number';
                amountInput.className = 'form-control text-right';
                amountInput.min = '0';
                amountInput.step = '0.01';
                amountInput.setAttribute('data-payment-field', 'amount');
                var initialAmount = payment.amount !== undefined ? parseFloat(payment.amount) : 0;
                if (isNaN(initialAmount)) {
                    initialAmount = 0;
                }
                amountInput.value = initialAmount.toFixed(<?php echo js_escape($currdecimals); ?>);
                amountInput.addEventListener('input', refreshPaymentSummary);
                amountCell.appendChild(amountInput);
                tr.appendChild(amountCell);

                var actionCell = document.createElement('td');
                actionCell.className = 'text-center';
                var removeButton = document.createElement('button');
                removeButton.type = 'button';
                removeButton.className = 'btn btn-link text-danger p-0';
                removeButton.innerHTML = '&times;';
                removeButton.setAttribute('aria-label', '<?php echo xlt('Remove payment'); ?>');
                removeButton.addEventListener('click', function (event) {
                    event.preventDefault();
                    tr.remove();
                    refreshPaymentSummary();
                });
                actionCell.appendChild(removeButton);
                tr.appendChild(actionCell);

                tbody.appendChild(tr);
                refreshPaymentSummary();
            }

            function markLineDeleted(index, row) {
                var form = document.forms[0];
                if (form && form['line[' + index + '][is_deleted]']) {
                    form['line[' + index + '][is_deleted]'].value = 1;
                }
                if (row) {
                    row.setAttribute('data-deleted', '1');
                    row.style.display = 'none';
                }
                computeTotals();
            }

            function addKitLine(kit) {
                if (!kit) {
                    return;
                }
                var tbody = document.getElementById('checkout-lines-body');
                if (!tbody) {
                    return;
                }
                var index = checkoutNextLineIndex++;
                var tr = document.createElement('tr');
                tr.className = 'checkout-line';
                tr.setAttribute('data-line-index', index);

                var today = new Date();
                var formattedDate = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
                var description = kit.label || '';
                if (kit.items && kit.items.length) {
                    description += ' (' + kit.items.join(', ') + ')';
                }
                var amount = parseFloat(kit.price || 0);
                if (isNaN(amount)) {
                    amount = 0;
                }

                var dateCell = document.createElement('td');
                dateCell.textContent = formattedDate;
                var hiddenFields = [
                    {name: 'code_type', value: 'KIT'},
                    {name: 'billing_code_type', value: 'CPT4'},
                    {name: 'code', value: kit.id || ''},
                    {name: 'id', value: ''},
                    {name: 'description', value: description},
                    {name: 'taxrates', value: ''},
                    {name: 'price', value: amount.toFixed(<?php echo js_escape($currdecimals); ?>)},
                    {name: 'units', value: 1},
                    {name: 'is_new', value: 1},
                    {name: 'is_deleted', value: 0}
                ];
                hiddenFields.forEach(function (field) {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'line[' + index + '][' + field.name + ']';
                    input.value = field.value;
                    dateCell.appendChild(input);
                });
                tr.appendChild(dateCell);

                var descCell = document.createElement('td');
                descCell.textContent = description;
                tr.appendChild(descCell);

                var qtyCell = document.createElement('td');
                qtyCell.className = 'text-right';
                qtyCell.textContent = '1';
                tr.appendChild(qtyCell);

                var amountCellKit = document.createElement('td');
                amountCellKit.className = 'text-right';
                var amountInputKit = document.createElement('input');
                amountInputKit.type = 'number';
                amountInputKit.className = 'form-control text-right';
                amountInputKit.step = '0.01';
                amountInputKit.min = '0';
                amountInputKit.name = 'line[' + index + '][amount]';
                amountInputKit.value = amount.toFixed(<?php echo js_escape($currdecimals); ?>);
                amountInputKit.addEventListener('input', function () {
                    var priceField = document.forms[0]['line[' + index + '][price]'];
                    if (priceField) {
                        var updated = parseFloat(this.value);
                        priceField.value = (isNaN(updated) ? 0 : updated).toFixed(<?php echo js_escape($currdecimals); ?>);
                    }
                    refreshPaymentSummary();
                });
                amountInputKit.addEventListener('change', computeTotals);
                amountCellKit.appendChild(amountInputKit);
                tr.appendChild(amountCellKit);

                var actionCellKit = document.createElement('td');
                actionCellKit.className = 'text-center';
                var removeButtonKit = document.createElement('button');
                removeButtonKit.type = 'button';
                removeButtonKit.className = 'btn btn-link text-danger p-0';
                removeButtonKit.innerHTML = '&times;';
                removeButtonKit.setAttribute('aria-label', '<?php echo xlt('Remove line'); ?>');
                removeButtonKit.addEventListener('click', function (event) {
                    event.preventDefault();
                    markLineDeleted(index, tr);
                });
                actionCellKit.appendChild(removeButtonKit);
                tr.appendChild(actionCellKit);

                tbody.appendChild(tr);
                computeTotals();
            }

            $(function () {
                $('.datepicker').datetimepicker({
                   <?php $datetimepicker_timepicker = false; ?>
                   <?php $datetimepicker_showseconds = false; ?>
                   <?php $datetimepicker_formatInput = false; ?>
                   <?php require($GLOBALS['srcdir'] . '/js/xl/jquery-datetimepicker-2-5-4.js.php'); ?>
                });
                $('#add-payment-row').on('click', function (event) {
                    event.preventDefault();
                    addPaymentRow({});
                });
                if ($('#payment-table-body tr').length === 0) {
                    addPaymentRow({});
                }
                $('.oe-kit-card').on('click', function (event) {
                    event.preventDefault();
                    var rawData = this.getAttribute('data-kit');
                    var kit = null;
                    if (rawData) {
                        try {
                            kit = JSON.parse(rawData);
                        } catch (err) {
                            kit = null;
                        }
                    }
                    if (kit) {
                        addKitLine(kit);
                    }
                });
            });
        </script>

        <style>
            @media (min-width: 992px){
                .modal-lg {
                    width: 1000px !Important;
                }
            }
            .oe-kit-card {
                margin: 0.25rem;
                min-width: 12rem;
                text-align: left;
            }
            .oe-kit-card small {
                white-space: normal;
            }
            .oe-payment-summary span {
                font-weight: 600;
            }
            #payment-balance {
                font-weight: 700;
            }
        </style>
        <title><?php echo xlt('Patient Checkout'); ?></title>
    <?php
    $arrOeUiSettings = array(
        'heading_title' => xl('Patient Checkout'),
        'include_patient_name' => true,// use only in appropriate pages
        'expandable' => false,
        'expandable_files' => array(),//all file names need suffix _xpd
        'action' => "",//conceal, reveal, search, reset, link or back
        'action_title' => "",
        'action_href' => "",//only for actions - reset, link or back
        'show_help_icon' => false,
        'help_file_name' => ""
    );
    $oemr_ui = new OemrUI($arrOeUiSettings);
    ?>
    </head>
    <body>
        <div id="container_div" class="<?php echo $oemr_ui->oeContainer();?> mt-3">
            <div class="row">
                <div class="col-sm-12">
                    <?php echo  $oemr_ui->pageHeading() . "\r\n"; ?>
                </div>
            </div>
            <div class="row">
                <div class="col-sm-12">
                    <?php if (!empty($_SESSION['checkout_error'])) { ?>
                        <div class="alert alert-danger">
                            <?php echo text($_SESSION['checkout_error']); unset($_SESSION['checkout_error']); ?>
                        </div>
                    <?php } ?>
                    <form action='pos_checkout.php' method='post' id='checkout-form' onsubmit='return prepareCheckout();'>
                        <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
                        <input name='form_pid' type='hidden' value='<?php echo attr($patient_id) ?>' />
                        <input name='form_payments' type='hidden' id='form_payments' value='' />
                        <fieldset>
                            <legend><?php echo xlt('Item Details'); ?></legend>
                            <?php if (!empty($aesthetic_kits)) { ?>
                            <div class="oe-checkout-kit-grid mb-3">
                                <div class="d-flex flex-wrap">
                                    <?php foreach ($aesthetic_kits as $kit) { ?>
                                        <button type="button" class="btn btn-outline-secondary oe-kit-card" data-kit='<?php echo attr(json_encode($kit)); ?>'>
                                            <span class="d-block font-weight-bold"><?php echo text($kit['label']); ?></span>
                                            <span class="d-block text-muted"><?php echo text(oeFormatMoney($kit['price'])); ?></span>
                                            <?php if (!empty($kit['items'])) { ?>
                                                <small class="d-block text-left"><?php echo text(implode(', ', $kit['items'])); ?></small>
                                            <?php } ?>
                                        </button>
                                    <?php } ?>
                                </div>
                                <small class="form-text text-muted mt-2"><?php echo xlt('Use the quick selection buttons to add kits or standalone aesthetic products without an encounter.'); ?></small>
                            </div>
                            <?php } ?>
                            <div class="table-responsive">
                                <table class="table" id="checkout-items-table">
                                    <thead>
                                        <tr>
                                            <th class="font-weight-bold"><?php echo xlt('Date'); ?></th>
                                            <th class="font-weight-bold"><?php echo xlt('Description'); ?></th>
                                            <th class="font-weight-bold text-right"><?php echo xlt('Qty'); ?></th>
                                            <th class="font-weight-bold text-right"><?php echo xlt('Amount'); ?></th>
                                            <th class="font-weight-bold text-center"><?php echo xlt('Actions'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody id="checkout-lines-body">
                                    <?php
                                    $inv_encounter = '';
                                    $inv_date      = '';
                                    $inv_provider  = 0;
                                    $inv_payer     = 0;
                                    $gcac_related_visit = false;
                                    $gcac_service_provided = false;

                                    // Process billing table items.
                                    // Items that are not allowed to have a fee are skipped.
                                    //
                                    while ($brow = sqlFetchArray($bres)) {
                                        // Skip all but the most recent encounter.
                                        if ($inv_encounter && $brow['encounter'] != $inv_encounter) {
                                            continue;
                                        }

                                        $thisdate = substr($brow['date'], 0, 10);
                                        $code_type = $brow['code_type'];

                                        // Collect tax rates, related code and provider ID.
                                        $taxrates = '';
                                        $related_code = '';
                                        $sqlBindArray = array();
                                        if (!empty($code_types[$code_type]['fee'])) {
                                            $query = "SELECT taxrates, related_code FROM codes WHERE code_type = ? " .
                                            " AND " .
                                            "code = ? AND ";
                                            array_push($sqlBindArray, $code_types[$code_type]['id'], $brow['code']);
                                            if ($brow['modifier']) {
                                                $query .= "modifier = ?";
                                                array_push($sqlBindArray, $brow['modifier']);
                                            } else {
                                                $query .= "(modifier IS NULL OR modifier = '')";
                                            }
                                            $query .= " LIMIT 1";
                                            $tmp = sqlQuery($query, $sqlBindArray);
                                            $taxrates = $tmp['taxrates'] ?? '';
                                            $related_code = $tmp['related_code'] ?? '';
                                            markTaxes($taxrates);
                                        }

                                        write_form_line(
                                            $code_type,
                                            $brow['code'],
                                            $brow['id'],
                                            $thisdate,
                                            $brow['code_text'],
                                            $brow['fee'],
                                            $brow['units'],
                                            $taxrates
                                        );
                                        if (!$inv_encounter) {
                                            $inv_encounter = $brow['encounter'];
                                        }
                                        $inv_payer = $brow['payer_id'];
                                        if (!$inv_date || $inv_date < $thisdate) {
                                            $inv_date = $thisdate;
                                        }

                                        // Custom logic for IPPF to determine if a GCAC issue applies.
                                        if ($GLOBALS['ippf_specific'] && $related_code) {
                                            $relcodes = explode(';', $related_code);
                                            foreach ($relcodes as $codestring) {
                                                if ($codestring === '') {
                                                    continue;
                                                }
                                                list($codetype, $code) = explode(':', $codestring);
                                                if ($codetype !== 'IPPF') {
                                                    continue;
                                                }
                                                if (preg_match('/^25222/', $code)) {
                                                    $gcac_related_visit = true;
                                                    if (preg_match('/^25222[34]/', $code)) {
                                                        $gcac_service_provided = true;
                                                    }
                                                }
                                            }
                                        }
                                    }

                                    // Process copays
                                    //
                                    $totalCopay = BillingUtilities::getPatientCopay($patient_id, $encounter);
                                    if ($totalCopay < 0) {
                                        write_form_line("COPAY", "", "", "", "", $totalCopay, "", "");
                                    }

                                    // Process drug sales / products.
                                    //
                                    while ($drow = sqlFetchArray($dres)) {
                                        if ($inv_encounter && $drow['encounter'] && $drow['encounter'] != $inv_encounter) {
                                            continue;
                                        }

                                        $thisdate = $drow['sale_date'];
                                        if (!$inv_encounter) {
                                            $inv_encounter = $drow['encounter'];
                                        }

                                        if (!$inv_provider && !empty($arr_users[$drow['provider_id']])) {
                                            $inv_provider = $drow['provider_id'] + 0;
                                        }

                                        if (!$inv_date || $inv_date < $thisdate) {
                                            $inv_date = $thisdate;
                                        }

                                        // Accumulate taxes for this product.
                                        $tmp = sqlQuery("SELECT taxrates FROM drug_templates WHERE drug_id = ? " .
                                          " ORDER BY selector LIMIT 1", array($drow['drug_id']));
                                        // accumTaxes($drow['fee'], $tmp['taxrates']);
                                        $taxrates = $tmp['taxrates'];
                                        markTaxes($taxrates);

                                        write_form_line(
                                            'PROD',
                                            $drow['drug_id'],
                                            $drow['sale_id'],
                                            $thisdate,
                                            $drow['name'],
                                            $drow['fee'],
                                            $drow['quantity'],
                                            $taxrates
                                        );
                                    }

                                    // Write a form line for each tax that has money, adding to $total.
                                    foreach ($taxes as $key => $value) {
                                        if ($value[2]) {
                                            write_form_line('TAX', $key, $key, date('Y-m-d'), $value[0], 0, 1, $value[1]);
                                        }
                                    }

                                    // Besides copays, do not collect any other information from ar_activity,
                                    // since this is for appt checkout.

                                    if ($inv_encounter) {
                                        $erow = sqlQuery("SELECT provider_id FROM form_encounter WHERE " .
                                        "pid = ? AND encounter = ? " .
                                        "ORDER BY id DESC LIMIT 1", array($patient_id,$inv_encounter));
                                        $inv_provider = $erow['provider_id'] + 0;
                                    }
                                    ?>
                                    </tbody>
                                </table>
                            </div>
                        </fieldset>
                        <fieldset>
                            <legend><?php echo xlt('Collect Payment'); ?></legend>
                            <div class="row oe-custom-line">
                                <div class="col-3 offset-lg-3">
                                    <label class="control-label" for="form_discount"><?php echo $GLOBALS['discount_by_money'] ? xlt('Discount Amount') : xlt('Discount Percentage'); ?>:</label>
                                </div>
                                <div class="col-3">
                                    <input maxlength='8' name='form_discount' id='form_discount' onkeyup='computeTotals()' class= 'form-control' type='text' value='' />
                                </div>
                            </div>
                            <div class="row oe-custom-line">
                                <div class="col-lg-6 offset-lg-3">
                                    <div class="alert alert-info oe-payment-summary" id="payment-summary">
                                        <div class="d-flex justify-content-between">
                                            <span><?php echo xlt('Total Due'); ?></span>
                                            <span id="payment-total-due">0.00</span>
                                        </div>
                                        <div class="d-flex justify-content-between">
                                            <span><?php echo xlt('Payments Captured'); ?></span>
                                            <span id="payment-total-captured">0.00</span>
                                        </div>
                                        <div class="d-flex justify-content-between">
                                            <span><?php echo xlt('Balance After Payments'); ?></span>
                                            <span id="payment-balance" data-value="0.00">0.00</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="table-responsive mb-3">
                                <table class="table table-sm" id="checkout-payments-table">
                                    <thead>
                                        <tr>
                                            <th><?php echo xlt('Method'); ?></th>
                                            <th><?php echo xlt('Gateway'); ?></th>
                                            <th><?php echo xlt('Reference'); ?></th>
                                            <th><?php echo xlt('Installments'); ?></th>
                                            <th class="text-right"><?php echo xlt('Amount'); ?></th>
                                            <th class="text-center"><?php echo xlt('Actions'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody id="payment-table-body"></tbody>
                                </table>
                            </div>
                            <div class="row oe-custom-line">
                                <div class="col-12 text-center">
                                    <button type="button" class="btn btn-secondary" id="add-payment-row"><?php echo xlt('Add Payment Method'); ?></button>
                                    <small class="form-text text-muted"><?php echo xlt('Combine cash, PIX and card payments, allocating installments when applicable.'); ?></small>
                                </div>
                            </div>
                            <div class="d-none">
                                <input name='form_method' id='form_method' type='text' value='' />
                                <input name='form_source' id='form_source' type='text' value='' />
                                <input name='form_amount' id='form_amount' type='text' value='0.00' />
                            </div>
                            <div class="row oe-custom-line">
                                <div class="col-3 offset-lg-3">
                                    <label class="control-label" for="form_date"><?php echo xlt('Posting Date'); ?>:</label>
                                </div>
                                <div class="col-3">
                                    <input class='form-control datepicker' id='form_date' name='form_date' title='yyyy-mm-dd date of service' type='text' value='<?php echo attr($inv_date) ?>' />
                                </div>
                            </div>
                            <?php
                            // If this user has a non-empty irnpool assigned, show the pending
                            // invoice reference number.
                            $irnumber = BillingUtilities::getInvoiceRefNumber();
                            if (!empty($irnumber)) {
                                ?>
                            <div class="row oe-custom-line">
                                <div class="col-3 offset-lg-3">
                                    <label class="control-label" for="form_tentative"><?php echo xlt('Tentative Invoice Ref No'); ?>:</label>
                                </div>
                                <div class="col-3">
                                    <div name='form_source' id='form_tentative' id='form_tentative' class= 'form-control'><?php echo text($irnumber); ?></div>
                                </div>
                            </div>
                                <?php
                            } elseif (!empty($GLOBALS['gbl_mask_invoice_number'])) { // Otherwise if there is an invoice
                                // reference number mask, ask for the refno.
                                ?>
                            <div class="row oe-custom-line">
                                <div class="col-3 offset-lg-3">
                                    <label class="control-label" for="form_irnumber"><?php echo xlt('Invoice Reference Number'); ?>:</label>
                                </div>
                                <div class="col-3">
                                    <input type='text' name='form_irnumber' id='form_irnumber' class='form-control' value='' onkeyup='maskkeyup(this,<?php echo attr_js($GLOBALS['gbl_mask_invoice_number']); ?>)' onblur='maskblur(this,<?php echo attr_js($GLOBALS['gbl_mask_invoice_number']); ?>)' />
                                </div>
                            </div>
                                <?php
                            }
                            ?>
                        </fieldset>
                        <div class="form-group">
                            <div class="d-flex flex-row-reverse w-100">
                                <div class="btn-group" role="group">
                                    <button type='submit' class="btn btn-primary btn-save btn-lg" name='form_save' id='form_save' value='save'><?php echo xlt('Save');?></button>
                                    <?php if (empty($_GET['framed'])) { ?>
                                    <button type='button' class="btn btn-secondary btn-cancel" onclick='window.close()'><?php echo xlt('Cancel'); ?></button>
                                    <?php } ?>
                                    <input type='hidden' name='form_provider'  value='<?php echo attr($inv_provider)  ?>' />
                                    <input type='hidden' name='form_payer'     value='<?php echo attr($inv_payer)     ?>' />
                                    <input type='hidden' name='form_encounter' value='<?php echo attr($inv_encounter) ?>' />
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div><!-- end of div container-->
        <?php $oemr_ui->oeBelowContainerDiv();?>
        <script>
            computeTotals();
                <?php
                if ($gcac_related_visit && !$gcac_service_provided) {
                    // Skip this warning if the GCAC visit form is not allowed.
                    $grow = sqlQuery("SELECT COUNT(*) AS count FROM layout_group_properties " .
                      "WHERE grp_form_id = 'LBFgcac' grp_group_id = '' AND grp_activity = 1");
                    if (!empty($grow['count'])) { // if gcac is used
                        // Skip this warning if referral or abortion in TS.
                        $grow = sqlQuery("SELECT COUNT(*) AS count FROM transactions " .
                        "WHERE title = 'Referral' AND refer_date IS NOT NULL AND " .
                        "refer_date = ? AND pid = ?", array($inv_date,$patient_id));
                        if (empty($grow['count'])) { // if there is no referral
                            $grow = sqlQuery("SELECT COUNT(*) AS count FROM forms " .
                            "WHERE pid = ? AND encounter = ? AND " .
                             "deleted = 0 AND formdir = 'LBFgcac'", array($patient_id,$inv_encounter));
                            if (empty($grow['count'])) { // if there is no gcac form
                                echo " alert(" . xlj('This visit will need a GCAC form, referral or procedure service.') . ");\n";
                            }
                        }
                    }
                } // end if ($gcac_related_visit)
                ?>
        </script>
    </body>
</html>
