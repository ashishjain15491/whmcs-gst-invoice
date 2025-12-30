<?php
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Dynamic Footer Template
 * Fetches config from CUSTOM TABLE (mod_gst_config)
 */

$addonConfig = [];
try {
    $settings = Capsule::table('mod_gst_config')->pluck('value', 'setting');
    if(is_object($settings) && method_exists($settings, 'toArray')) $addonConfig = $settings->toArray();
    elseif (is_array($settings)) $addonConfig = $settings;
    else $addonConfig = $settings;
} catch (Exception $e) { $addonConfig = []; }

// Values
$f_tel = $addonConfig['footer_tel'] ?? '+91-1234567890';
$f_email = $addonConfig['footer_email'] ?? 'billing@example.com';
$f_cin_type = $addonConfig['footer_cin_type'] ?? 'CIN';
$f_cin_val = $addonConfig['footer_cin_val'] ?? 'U12345MH2024PTC123456';
$f_pan = $addonConfig['footer_pan'] ?? 'ABCDE1234F';

// Labels
$l_tel = $addonConfig['label_tel'] ?? 'Tel';
$l_email = $addonConfig['label_email'] ?? 'E-Mail';
$l_pan = $addonConfig['label_pan'] ?? 'PAN';
// CIN Label is effectively handled by the Type selection (CIN/LLPIN), but you could add a label override if desired. 
// For now, we rely on the selector type as per typical Indian invoice standards.

$footerParts = [];
if (!empty($f_tel)) $footerParts[] = $l_tel . ": " . $f_tel;
if (!empty($f_email)) $footerParts[] = $l_email . ": " . $f_email;
if ($f_cin_type != 'Disable' && !empty($f_cin_val)) $footerParts[] = $f_cin_type . ": " . $f_cin_val;
if (!empty($f_pan)) $footerParts[] = $l_pan . ": " . $f_pan;

$footertext = implode(' | ', $footerParts);

$pdf->SetAutoPageBreak(true, 30);
$pdf->SetY(-20);
$pdf->SetFont($pdfFont, '', 8);

if (isset($status) && $status == 'Paid') {
    $pdf->Cell(0, 4, 'This is a system generated receipt. No signature required.', 0, 1, 'C');
}
$pdf->Cell(0, 4, Lang::trans('invoicepdfgenerated') . ' ' . getTodaysDate(1) . ' ' . date("H:i:s \(\G\M\TP\)"), 0, 1, 'C');
$pdf->Cell(0, 4, $footertext, 0, 1, 'C');
?>