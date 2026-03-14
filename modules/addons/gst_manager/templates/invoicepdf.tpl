<?php
use Illuminate\Database\Capsule\Manager as Capsule;

// --- GST MANAGER: Fetch Config & Rules ---
$addonConfig = [];
$gstRules = [];
try {
    $settings = Capsule::table('mod_gst_config')->pluck('value', 'setting');
    if(is_object($settings) && method_exists($settings, 'toArray')) $addonConfig = $settings->toArray();
    elseif (is_array($settings)) $addonConfig = $settings;
    else $addonConfig = $settings;

    $gstRules = Capsule::table('mod_gst_rules')->get();
} catch (Exception $e) {
    $addonConfig = ['sac_default' => '998313'];
}
// -------------------------------------

# Highest Purpose Code Calculation for Export Invoices
$highestPurposeCode = '';
if ($clientsdetails["country"] != "India") {
    $maxAmount = -1;
    foreach ($invoiceitems as $item) {
        if ($item['description'] == 'Round Off') continue;
        
        $amount = floatval(preg_replace("/[^0-9\.]/", '', $item['amount']));
        if ($amount > $maxAmount) {
            $maxAmount = $amount;
            
            $pCode = $addonConfig['purp_default'] ?? 'P0807';
            $descLower = nl2br(strtolower($item['description']));
            $ruleMatched = false;

            if (!empty($gstRules)) {
                foreach ($gstRules as $rule) {
                    if (strpos($descLower, strtolower($rule->keyword)) !== false) {
                        $pCode = !empty($rule->purpose_code) ? $rule->purpose_code : $pCode;
                        $ruleMatched = true;
                        break; 
                    }
                }
            }

            if (!$ruleMatched) {
                $itemType = $item['type'];
                if ($itemType == 'Hosting' || $itemType == 'PromoHosting') $pCode = $addonConfig['purp_hosting'] ?? 'P0807';
                elseif ($itemType == 'Upgrade') $pCode = $addonConfig['purp_upgrade'] ?? 'P0807';
                elseif (strpos($itemType, 'Domain') !== false) $pCode = $addonConfig['purp_domain'] ?? 'P0807';
                elseif ($itemType == 'Addon') $pCode = $addonConfig['purp_addon'] ?? 'P0807';
                elseif ($itemType == 'Setup') $pCode = $addonConfig['purp_setup'] ?? 'P0807';
                elseif ($itemType == 'LateFee') $pCode = $addonConfig['purp_latefee'] ?? 'P0807';
            }
            $highestPurposeCode = $pCode;
        }
    }
}

# Logo Logic
$logoFilename = 'placeholder.png';
if (file_exists(ROOTDIR . '/assets/img/logo.png')) { $logoFilename = 'logo.png'; }
elseif (file_exists(ROOTDIR . '/assets/img/logo.jpg')) { $logoFilename = 'logo.jpg'; }
elseif (file_exists(ROOTDIR . '/assets/img/logo.jpeg')) { $logoFilename = 'logo.jpeg'; }
$pdf->Image(ROOTDIR . '/assets/img/' . $logoFilename, 15, 37, 75);

# Tax Heading
$pdf->SetXY(12, 25);
if ($clientsdetails["country"] == "India") {
    $pdf->SetFont($pdfFont, 'B', 15);
    if ($status == 'Paid') { $pdf->Cell(0, 15, 'TAX INVOICE', 0, false, 'C', 0, '', 0, false, 'M', 'T'); }
    elseif($status == 'Unpaid') { $pdf->Cell(0, 15, 'PROFORMA INVOICE', 0, false, 'C', 0, '', 0, false, 'M', 'T'); }
    else { $pdf->Cell(0, 15, 'INVOICE', 0, false, 'C', 0, '', 0, false, 'M', 'T'); }
} else {
    // Export Formatting
    $pdf->SetFont($pdfFont, 'B', 15);
    if ($status == 'Paid') { $pdf->Cell(0, 8, 'EXPORT INVOICE', 0, 1, 'C'); }
    elseif($status == 'Unpaid') { $pdf->Cell(0, 8, 'PROFORMA INVOICE', 0, 1, 'C'); }
    else { $pdf->Cell(0, 8, 'INVOICE', 0, 1, 'C'); }
    
    $pdf->SetFont($pdfFont, 'I', 9);
    $pdf->Cell(0, 5, '(Export of Services without payment of GST under LUT)', 0, 1, 'C');
}

# Status Watermark
$pdf->SetXY(0, 0);
$pdf->SetFont($pdfFont, 'B', 28);
$pdf->SetTextColor(255);
$pdf->SetLineWidth(0.75);
$pdf->StartTransform();
$pdf->Rotate(-35, 100, 225);
if ($status == 'Draft') { $pdf->SetFillColor(200); $pdf->SetDrawColor(140); }
elseif ($status == 'Paid') { $pdf->SetFillColor(151, 223, 74); $pdf->SetDrawColor(110, 192, 70); }
elseif ($status == 'Cancelled') { $pdf->SetFillColor(200); $pdf->SetDrawColor(140); }
elseif ($status == 'Refunded') { $pdf->SetFillColor(131, 182, 218); $pdf->SetDrawColor(91, 136, 182); }
elseif ($status == 'Collections') { $pdf->SetFillColor(3, 3, 2); $pdf->SetDrawColor(127); }
else { $pdf->SetFillColor(223, 85, 74); $pdf->SetDrawColor(171, 49, 43); }
$pdf->Cell(100, 18, strtoupper(Lang::trans('invoices'.str_replace(' ','',strtolower($status)))), 'TB', 0, 'C', '1');
$pdf->StopTransform();
$pdf->SetTextColor(0);

# Company Details
$pdf->SetXY(15, 39);
$pdf->SetFont($pdfFont, 'B', 9);
foreach ($companyaddress as $addressLine) {
    $pdf->Cell(180, 4, trim($addressLine), 0, 1, 'R');
    $pdf->SetFont($pdfFont, '', 9);
}
if ($taxCode) { $pdf->Cell(180, 4, $taxIdLabel . ': ' . trim($taxCode), 0, 1, 'R'); }
$pdf->Ln(5);

# Header
$startY = $pdf->GetY();
$pdf->SetXY(15, $startY);
$pdf->SetFont($pdfFont, 'B', 10);
$pdf->Cell(90, 4, Lang::trans('invoicesinvoicedto'), 0, 1);
$pdf->SetFont($pdfFont, '', 9);

if ($clientsdetails["companyname"]) {
    $pdf->SetX(15);
    $pdf->Cell(90, 4, $clientsdetails["companyname"], 0, 1, 'L');
    if(!$clientsdetails["tax_id"]){ $pdf->SetX(15);
    $pdf->Cell(90, 4, Lang::trans('invoicesattn') . ': ' . $clientsdetails["firstname"] . ' ' . $clientsdetails["lastname"], 0, 1, 'L'); }
} else { 
    $pdf->SetX(15);
    $pdf->Cell(90, 4, $clientsdetails["firstname"] . " " . $clientsdetails["lastname"], 0, 1, 'L'); 
}
$pdf->SetX(15); $pdf->MultiCell(90, 4, $clientsdetails["address1"], 0, 'L', 0, 1);
if ($clientsdetails["address2"]) { $pdf->SetX(15); $pdf->MultiCell(90, 4, $clientsdetails["address2"], 0, 'L', 0, 1); }
$pdf->SetX(15);
$pdf->Cell(90, 4, $clientsdetails["city"] . ", " . $clientsdetails["state"] . ", " . $clientsdetails["postcode"], 0, 1, 'L');
$pdf->SetX(15);
$pdf->Cell(90, 4, $clientsdetails["country"], 0, 1, 'L');
$pdf->Ln(2);

// Location-specific Fields
if ($clientsdetails["country"] == "India") {
    if (array_key_exists('tax_id', $clientsdetails) && $clientsdetails['tax_id']) {
        $pdf->SetX(15);
        $pdf->Cell(90, 4, $taxIdLabel . ': ' . $clientsdetails['tax_id'], 0, 1, 'L');
        $pdf->SetX(15);
        $pdf->Cell(90, 4, 'Place of supply: ' . substr($clientsdetails['tax_id'], 0, 2) . ' - ' . $clientsdetails["state"], 0, 1, 'L');
    }
} else {
    $pdf->SetX(15);
    $pdf->Cell(90, 4, 'Purpose Code: ' . $highestPurposeCode, 0, 1, 'L');
    $pdf->SetX(15);
    $pdf->Cell(90, 4, 'Place of supply: Outside India', 0, 1, 'L');
}

if ($customfields) {
    foreach ($customfields as $customfield) { $pdf->SetX(15);
    $pdf->Cell(90, 4, $customfield['fieldname'] . ': ' . $customfield['value'], 0, 1, 'L'); }
}
$endY = $pdf->GetY();

# Meta
$pdf->SetXY(120, $startY);
$pdf->SetFont($pdfFont, 'B', 15);
$pdf->SetFillColor(239);
$pdf->Cell(75, 8, $pagetitle, 0, 1, 'L', 1);
$pdf->SetFont($pdfFont, '', 10);
$pdf->SetX(120);
$pdf->Cell(75, 6, Lang::trans('invoicesdatecreated') . ': ' . $datecreated, 0, 1, 'L', 1);
if($status != 'Paid'){ $pdf->SetX(120);
$pdf->Cell(75, 6, Lang::trans('invoicesdatedue') . ': ' . $duedate, 0, 1, 'L', 1); }
$pdf->SetXY(15, $endY);
$pdf->Ln(5);
$currencycode = Capsule::table('tblcurrencies')->where('id', $clientsdetails['currency'])->first()->code;
$currencyprefix = Capsule::table('tblcurrencies')->where('id', $clientsdetails['currency'])->first()->prefix;

# Items
$tblhtml = '<table width="100%" bgcolor="#ccc" cellspacing="1" cellpadding="2" border="0">
<tr height="30" bgcolor="#efefef" style="font-weight:bold;text-align:center;">
    <td width="35%">' . Lang::trans('invoicesdescription') . '</td>
    <td width="25%"><strong>Item type</strong></td>
    <td width="20%"><strong>SAC Code</strong></td>
    <td width="20%">' . Lang::trans('quotelinetotal') . ' (' . $currencycode . ')' . '</td>
</tr>';

foreach ($invoiceitems as $item) {
    if($item['description'] != 'Round Off'){
        $descLower = nl2br(strtolower($item['description']));
        $itemType = $item['type'];
        
        $displayType = $itemType;
        $sacCode = $addonConfig['sac_default'] ?? '998313';
        $ruleMatched = false;

        if (!empty($gstRules)) {
            foreach ($gstRules as $rule) {
                if (strpos($descLower, strtolower($rule->keyword)) !== false) {
                    $displayType = $rule->display_name;
                    $sacCode = $rule->sac_code;
                    $ruleMatched = true; break; 
                }
            }
        }
        if (!$ruleMatched) {
            if ($itemType == 'Hosting' || $itemType == 'PromoHosting') $sacCode = $addonConfig['sac_hosting'] ?? '998315';
            elseif ($itemType == 'Upgrade') $sacCode = $addonConfig['sac_upgrade'] ?? '998315';
            elseif (strpos($itemType, 'Domain') !== false) $sacCode = $addonConfig['sac_domain'] ?? '998319';
            elseif ($itemType == 'Addon') $sacCode = $addonConfig['sac_addon'] ?? '998313';
            elseif ($itemType == 'Setup') $sacCode = $addonConfig['sac_setup'] ?? '998313';
            elseif ($itemType == 'LateFee') { $sacCode = $addonConfig['sac_latefee'] ?? '998313'; $displayType = 'Late Fee'; }
        }
        
        $tblhtml .= '<tr bgcolor="#fff"><td align="left">'.nl2br($item['description']).'</td><td align="center">'.$displayType.'</td><td align="center">'.$sacCode.'</td><td align="center">'.$item['amount'].'</td></tr>';
    }
    if($item['description'] == 'Round Off'){
        $round_off = floatval(preg_replace("/[^0-9\.\-]/", '', $item['amount']));
    }
}

$tblhtml .= '<tr height="30" bgcolor="#efefef" style="font-weight:bold;"><td align="right">' . Lang::trans('invoicessubtotal') . '</td><td colspan="2"></td>';
if (isset($round_off) && $round_off != 0) $tblhtml .= '<td align="center">' . $currencyprefix . number_format(preg_replace("/[^0-9\.]/", '', $subtotal) - $round_off,2,".",",") . '</td>';
else $tblhtml .= '<td align="center">' . $subtotal . '</td>';
$tblhtml .= '</tr>';

// Tax
$tblhtml .= '<tr height="30" bgcolor="#fff"><td align="right" style="vertical-align: middle;"><strong>GST</strong></td><td colspan="2"><table width="100%" bgcolor="#ccc" cellspacing="0" cellpadding="3" border="1px" bordercolor="#ccc"><tr bgcolor="#efefef">';
$tblhtml .= '<td width="33.3%"><strong>CGST'.(($taxrate && $taxname == 'CGST') ? ' ('.$taxrate.'%)' : '').'</strong></td>';
$tblhtml .= '<td width="33.3%"><strong>SGST'.(($taxrate2 && $taxname2 == 'SGST') ? ' ('.$taxrate2.'%)' : '').'</strong></td>';
$tblhtml .= '<td width="33.3%"><strong>IGST'.(($taxrate && $taxname == 'IGST') ? ' ('.$taxrate.'%)' : '').'</strong></td></tr>';
$tblhtml .= '<tr bgcolor="#fff"><td width="33.3%">'.(($taxrate && $taxname == 'CGST') ? $tax : '&nbsp;').'</td>';
$tblhtml .= '<td width="33.3%">'.(($taxrate2 && $taxname2 == 'SGST') ? $tax2 : '&nbsp;').'</td>';
$tblhtml .= '<td width="33.3%">'.(($taxrate && $taxname == 'IGST') ? $tax : '&nbsp;').'</td></tr></table></td>';
$tblhtml .= '<td align="center">'.$currencyprefix.number_format(preg_replace("/[^0-9\.]/", '', $tax) + preg_replace("/[^0-9\.]/", '', $tax2),2,".",",").'</td></tr>';
if (isset($round_off) && $round_off != null) $tblhtml .='<tr bgcolor="#efefef" height="30" style="font-weight:bold;"><td align="right">Round Off</td><td colspan="2">&nbsp;</td><td align="center">'.$currencyprefix.$round_off.'</td></tr>';
$tblhtml .='<tr bgcolor="#efefef" height="30" style="font-weight:bold;"><td align="right">Total Amount Incl. GST</td><td colspan="2">&nbsp;</td>';
if ($credit != 0) $tblhtml .= '<td align="center">'.$currencyprefix.number_format(preg_replace("/[^0-9\.]/", '', $total) + preg_replace("/[^0-9\.]/", '', $credit),2,".",",").'</td>';
else $tblhtml .= '<td align="center">'.$total.'</td>';
$tblhtml .= '</tr><tr height="30" bgcolor="#efefef" style="font-weight:bold;"><td align="right"><strong>Funds Applied</strong></td><td colspan="2"></td><td align="center">'.$credit.'</td></tr></table>';


$pdf->writeHTML($tblhtml, true, false, false, false, '');

$pdf->Ln(5);
$pdf->SetFont($pdfFont, 'B', 12);
$pdf->Cell(0, 4, Lang::trans('invoicestransactions'), 0, 1);
$pdf->Ln(2);
$pdf->SetFont($pdfFont, '', 9);
$tblhtml = '<table width="100%" bgcolor="#ccc" cellspacing="1" cellpadding="2" border="0"><tr height="30" bgcolor="#efefef" style="font-weight:bold;text-align:center;"><td width="25%">' . Lang::trans('invoicestransdate') . '</td><td width="25%">' . Lang::trans('invoicestransgateway') . '</td><td width="30%">' . Lang::trans('invoicestransid') . '</td><td width="20%">' . Lang::trans('invoicestransamount') . '</td></tr>';
if (!count($transactions)) { $tblhtml .= '<tr bgcolor="#fff"><td colspan="4" align="center">' . Lang::trans('invoicestransnonefound') . '</td></tr>'; } 
else { foreach ($transactions AS $trans) { $tblhtml .= '<tr bgcolor="#fff"><td align="center">'.$trans['date'].'</td><td align="center">'.$trans['gateway'].'</td><td align="center">'.$trans['transid'].'</td><td align="center">'.$trans['amount'].'</td></tr>'; } }
$tblhtml .= '<tr height="30" bgcolor="#efefef" style="font-weight:bold;"><td colspan="3" align="right">' . Lang::trans('invoicesbalance') . '</td><td align="center">' . $balance . '</td></tr></table>';
$pdf->writeHTML($tblhtml, true, false, false, false, '');

// --- BANK DETAILS & EXPORT DECLARATIONS ---
$pdf->Ln(5);
$bankDetails = $addonConfig['bank_details_' . $currencycode] ?? '';

if (!empty($bankDetails)) {
    $pdf->SetFont($pdfFont, 'B', 10);
    $pdf->Cell(0, 6, 'Remittance Details:', 0, 1);
    $pdf->SetFont($pdfFont, '', 9);
    $pdf->MultiCell(0, 4, $bankDetails, 0, 'L', 0, 1);
    $pdf->Ln(2);
}

if ($clientsdetails["country"] != "India") {
    $pdf->SetFont($pdfFont, 'B', 9);
    $pdf->Cell(0, 5, 'Export Declaration:', 0, 1);
    $pdf->SetFont($pdfFont, '', 8);
    $exportDecl = $addonConfig['export_decl'] ?? 'Supply meant for export under Letter of Undertaking without payment of Integrated GST.';
    $pdf->MultiCell(0, 4, $exportDecl, 0, 'L', 0, 1);
    $pdf->Ln(2);

    $pdf->SetFont($pdfFont, 'B', 9);
    $pdf->Cell(0, 5, 'FEMA Declaration:', 0, 1);
    $pdf->SetFont($pdfFont, '', 8);
    $femaDecl = $addonConfig['fema_decl'] ?? 'We hereby declare that this invoice represents export of software and IT services from India, and the payment will be received in convertible foreign exchange.';
    $pdf->MultiCell(0, 4, $femaDecl, 0, 'L', 0, 1);
}

?>