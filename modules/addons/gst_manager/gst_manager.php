<?php

use WHMCS\Database\Capsule;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function gst_manager_config()
{
    return [
        'name' => 'GST Manager',
        'description' => 'GST Solution with Custom Rules, SAC Management, and CSV Reports.',
        'author' => 'RelyWeb',
        'language' => 'english',
        'version' => '2.5',
    ];
}

function gst_manager_activate()
{
    try {
        // 1. Settings Table
        if (!Capsule::schema()->hasTable('mod_gst_config')) {
            Capsule::schema()->create('mod_gst_config', function ($table) {
                $table->string('setting', 64)->primary();
                $table->text('value')->nullable();
            });
            
            // Seed Defaults
            $defaults = [
                'sac_hosting' => '998315', 
                'sac_domain' => '998319', 
                'sac_addon' => '998313', 
                'sac_setup' => '998313',
                'sac_upgrade' => '998315',
                'sac_latefee' => '998313',
                'sac_default' => '998313', 
                
                // Footer
                'footer_tel' => '+91-1234567890', 
                'footer_email' => 'billing@example.com',
                'footer_cin_type' => 'CIN', 
                'footer_cin_val' => 'U12345MH2024PTC123456', 
                'footer_pan' => 'ABCDE1234F',
                
                // Labels
                'label_tel' => 'Tel',
                'label_email' => 'E-Mail',
                'label_pan' => 'PAN',
                'label_cin' => 'CIN',
            ];
            foreach ($defaults as $key => $val) {
                Capsule::table('mod_gst_config')->insert(['setting' => $key, 'value' => $val]);
            }
        }

        // 2. Rules Table
        if (!Capsule::schema()->hasTable('mod_gst_rules')) {
            Capsule::schema()->create('mod_gst_rules', function ($table) {
                $table->increments('id');
                $table->string('keyword', 100);
                $table->string('display_name', 100);
                $table->string('sac_code', 20);
            });
        }

        return gst_manager_sync_templates();

    } catch (Exception $e) {
        return ['status' => 'error', 'description' => 'Activation Failed: ' . $e->getMessage()];
    }
}

function gst_manager_deactivate()
{
    return ['status' => 'success', 'description' => 'GST Manager Deactivated. Tables preserved.'];
}

function gst_manager_output($vars)
{
    $modulelink = $vars['modulelink'];
    $action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'dashboard';

    // --- ACTIONS ---

    if ($action == 'save_settings') {
        $settings = [
            'sac_hosting', 'sac_domain', 'sac_addon', 'sac_setup', 'sac_upgrade', 'sac_latefee', 'sac_default',
            'footer_tel', 'footer_email', 'footer_cin_type', 'footer_cin_val', 'footer_pan',
            'label_tel', 'label_email', 'label_pan', 'label_cin'
        ];
        foreach ($settings as $setting) {
            Capsule::table('mod_gst_config')->updateOrInsert(['setting' => $setting], ['value' => trim($_POST[$setting] ?? '')]);
        }
        header("Location: " . $modulelink . "&action=settings&success=true");
        exit;
    }

    if ($action == 'add_rule') {
        if (!empty($_POST['keyword'])) {
            Capsule::table('mod_gst_rules')->insert([
                'keyword' => strtolower(trim($_POST['keyword'])),
                'display_name' => trim($_POST['display_name']),
                'sac_code' => trim($_POST['sac_code'])
            ]);
        }
        header("Location: " . $modulelink . "&action=rules&success=added");
        exit;
    }

    if ($action == 'delete_rule') {
        Capsule::table('mod_gst_rules')->where('id', $_REQUEST['id'])->delete();
        header("Location: " . $modulelink . "&action=rules&success=deleted");
        exit;
    }
    
    if ($action == 'sync') {
        $res = gst_manager_sync_templates();
        $alertClass = ($res['status'] == 'success') ? 'success' : 'danger';
        echo '<div class="alert alert-'.$alertClass.'">'.$res['description'].'</div>';
    }
    
    if ($action == 'export') { gst_manager_export_csv(); }

    if (isset($_REQUEST['success'])) {
        echo '<div class="alert alert-success">Configuration Saved Successfully!</div>';
    }

    // --- FETCH DATA ---
    $config = Capsule::table('mod_gst_config')->pluck('value', 'setting');
    if(is_object($config) && method_exists($config, 'toArray')) $config = $config->toArray();
    elseif (is_array($config)) $config = $config; 

    $rules = Capsule::table('mod_gst_rules')->orderBy('id', 'asc')->get();

    // --- UI (Reverted to Card Style) ---
    echo '<style>
        .gst-card { background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; margin-bottom: 20px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .gst-card h3 { margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 15px; color: #333; font-size: 18px; }
        .nav-tabs { margin-bottom: 15px; }
        .table-rules th { background: #f9f9f9; }
        .help-block { font-size: 12px; color: #777; margin-bottom: 0; }
    </style>';

    $tab = isset($_REQUEST['action']) && in_array($_REQUEST['action'], ['settings', 'rules']) ? $_REQUEST['action'] : 'dashboard';
    
    echo '<ul class="nav nav-tabs">
            <li role="presentation" class="' . ($tab == 'dashboard' ? 'active' : '') . '"><a href="' . $modulelink . '">Dashboard</a></li>
            <li role="presentation" class="' . ($tab == 'rules' ? 'active' : '') . '"><a href="' . $modulelink . '&action=rules">Item Rules</a></li>
            <li role="presentation" class="' . ($tab == 'settings' ? 'active' : '') . '"><a href="' . $modulelink . '&action=settings">Global Settings</a></li>
          </ul>';

    if ($tab == 'rules') {
        // --- RULES TAB ---
        echo '<div class="gst-card">
            <h3><i class="fas fa-list-ul"></i> Custom Item Type Rules</h3>
            <p class="text-muted">Define keywords to look for in the invoice item description.</p>
            <table class="table table-bordered table-striped table-rules">
                <thead><tr><th width="30%">Keyword</th><th width="30%">Display Name</th><th width="20%">SAC Code</th><th width="10%"></th></tr></thead>
                <tbody>';
        foreach ($rules as $rule) {
            echo '<tr>
                <td>Contains: <code>'.htmlspecialchars($rule->keyword).'</code></td>
                <td>'.htmlspecialchars($rule->display_name).'</td>
                <td>'.htmlspecialchars($rule->sac_code).'</td>
                <td><a href="'.$modulelink.'&action=delete_rule&id='.$rule->id.'" class="btn btn-xs btn-danger" onclick="return confirm(\'Delete?\')"><i class="fas fa-trash"></i></a></td>
            </tr>';
        }
        echo '</tbody><tfoot><form method="post" action="'.$modulelink.'&action=add_rule"><tr class="info"><td><input type="text" name="keyword" class="form-control input-sm" placeholder="e.g. workplace" required></td><td><input type="text" name="display_name" class="form-control input-sm" placeholder="e.g. Zoho" required></td><td><input type="text" name="sac_code" class="form-control input-sm" placeholder="SAC" required></td><td><button type="submit" class="btn btn-success btn-sm btn-block"><i class="fas fa-plus"></i></button></td></tr></form></tfoot></table></div>';

    } elseif ($tab == 'settings') {
        // --- SETTINGS TAB ---
        echo '<form method="post" action="' . $modulelink . '&action=save_settings">
        <div class="row">
            <div class="col-md-6">
                <div class="gst-card">
                    <h3>Default SAC Codes</h3>
                    <div class="form-group"><label>Hosting (Shared/VPS)</label><input type="text" name="sac_hosting" class="form-control" value="'.($config['sac_hosting']??'').'"></div>
                    <div class="form-group"><label>Product Upgrades</label><input type="text" name="sac_upgrade" class="form-control" value="'.($config['sac_upgrade']??'').'"></div>
                    <div class="form-group"><label>Domains</label><input type="text" name="sac_domain" class="form-control" value="'.($config['sac_domain']??'').'"></div>
                    <div class="form-group"><label>Addons</label><input type="text" name="sac_addon" class="form-control" value="'.($config['sac_addon']??'').'"></div>
                    <div class="form-group"><label>Setup Fees</label><input type="text" name="sac_setup" class="form-control" value="'.($config['sac_setup']??'').'"></div>
                    <div class="form-group">
                        <label>Late Fees Only</label>
                        <input type="text" name="sac_latefee" class="form-control" value="'.($config['sac_latefee']??'').'">
                        <p class="help-block">Specifically for "Late Fee" line items.</p>
                    </div>
                    <div class="form-group">
                        <label>Fallback (Default)</label>
                        <input type="text" name="sac_default" class="form-control" value="'.($config['sac_default']??'').'">
                        <p class="help-block">Used if no other rule matches.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="gst-card">
                    <h3>Footer Configuration</h3>
                    
                    <div class="form-group">
                        <label>Phone Number</label>
                        <div class="row">
                            <div class="col-xs-4"><input type="text" name="label_tel" class="form-control" placeholder="Label" value="'.($config['label_tel']??'Tel').'"></div>
                            <div class="col-xs-8"><input type="text" name="footer_tel" class="form-control" placeholder="Value" value="'.($config['footer_tel']??'').'"></div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Email Address</label>
                        <div class="row">
                            <div class="col-xs-4"><input type="text" name="label_email" class="form-control" placeholder="Label" value="'.($config['label_email']??'E-Mail').'"></div>
                            <div class="col-xs-8"><input type="text" name="footer_email" class="form-control" placeholder="Value" value="'.($config['footer_email']??'').'"></div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>PAN Number</label>
                        <div class="row">
                            <div class="col-xs-4"><input type="text" name="label_pan" class="form-control" placeholder="Label" value="'.($config['label_pan']??'PAN').'"></div>
                            <div class="col-xs-8"><input type="text" name="footer_pan" class="form-control" placeholder="Value" value="'.($config['footer_pan']??'').'"></div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>CIN / LLPIN</label>
                        <div class="row">
                            <div class="col-xs-4">
                                <select name="footer_cin_type" class="form-control">
                                    <option value="CIN" '.($config['footer_cin_type']=='CIN'?'selected':'').'>CIN</option>
                                    <option value="LLPIN" '.($config['footer_cin_type']=='LLPIN'?'selected':'').'>LLPIN</option>
                                    <option value="Disable" '.($config['footer_cin_type']=='Disable'?'selected':'').'>Disable</option>
                                </select>
                            </div>
                            <div class="col-xs-8"><input type="text" name="footer_cin_val" class="form-control" placeholder="Value" value="'.($config['footer_cin_val']??'').'"></div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
        <button type="submit" class="btn btn-success btn-lg"><i class="fas fa-save"></i> Save Configuration</button>
        </form>';

    } else {
        // --- DASHBOARD ---
        $health = gst_manager_check_health();
        echo ($health['status'] === 'ok') 
            ? '<div class="alert alert-success"><strong><i class="fas fa-check-circle"></i> System Healthy:</strong> Templates are synced.</div>' 
            : '<div class="alert alert-danger"><strong><i class="fas fa-exclamation-triangle"></i> Mismatch:</strong> Template files are outdated. <a href="'.$modulelink.'&action=sync" class="btn btn-danger btn-xs">Sync Now</a></div>';

        echo '<div class="gst-card">
            <h3>Export GST Report</h3>
            <p>Download CSV of paid invoices.</p>
            <form method="post" action="'.$modulelink.'&action=export" class="form-inline">
                <div class="form-group"><label>Start: </label> <input type="date" name="startdate" class="form-control" value="'.date('Y-m-01').'"></div>
                <div class="form-group"><label>End: </label> <input type="date" name="enddate" class="form-control" value="'.date('Y-m-t').'"></div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-download"></i> Export CSV</button>
            </form>
        </div>';
    }
}

// ... (Helper functions remain unchanged) ...
function gst_manager_check_health() {
    $activeTemplate = Capsule::table('tblconfiguration')->where('setting', 'Template')->value('value');
    $rootDir = gst_manager_get_root_dir();
    $themeDir = $rootDir . '/templates/' . $activeTemplate . '/';
    $addonDir = __DIR__ . '/templates/';
    foreach (['invoicepdf.tpl', 'invoicepdffooter.tpl'] as $file) {
        if (!file_exists($themeDir . $file)) return ['status' => 'error', 'theme' => $activeTemplate];
        $h1 = md5(preg_replace('/\s+/', '', file_get_contents($themeDir . $file)));
        $h2 = md5(preg_replace('/\s+/', '', file_get_contents($addonDir . $file)));
        if ($h1 !== $h2) return ['status' => 'error', 'theme' => $activeTemplate];
    }
    return ['status' => 'ok', 'theme' => $activeTemplate];
}

function gst_manager_sync_templates() {
    try {
        $activeTemplate = Capsule::table('tblconfiguration')->where('setting', 'Template')->value('value');
        $rootDir = gst_manager_get_root_dir();
        $targetDir = $rootDir . '/templates/' . $activeTemplate . '/';
        $sourceDir = __DIR__ . '/templates/';
        if (!is_dir($targetDir)) throw new Exception("Theme dir not found: $targetDir");
        foreach (['invoicepdf.tpl', 'invoicepdffooter.tpl'] as $file) {
            if (file_exists($targetDir . $file)) @copy($targetDir . $file, $targetDir . $file . '.bak.' . date('Ymd_His'));
            if (!copy($sourceDir . $file, $targetDir . $file)) throw new Exception("Failed to copy $file");
        }
        return ['status' => 'success', 'description' => "Synced templates to '$activeTemplate'."];
    } catch (Exception $e) { return ['status' => 'error', 'description' => $e->getMessage()]; }
}

function gst_manager_get_root_dir() {
    $dir = dirname(dirname(dirname(__DIR__)));
    return (file_exists($dir . '/configuration.php')) ? $dir : $_SERVER['DOCUMENT_ROOT'];
}

function gst_manager_export_csv() {
    $startdate = $_REQUEST['startdate'];
    $enddate = $_REQUEST['enddate'];

    if (ob_get_level()) ob_end_clean();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=GST_Report_' . $startdate . '_to_' . $enddate . '.csv');

    $output = fopen('php://output', 'w');

    // Headers
    fputcsv($output, [
        'Invoice Number', 'Date', 'Client Name', 'Client Company', 'Client GSTIN', 'State', 
        'Taxable Amount', 'CGST Amount', 'SGST Amount', 'IGST Amount', 'Total', 'Status'
    ]);

    $invoices = Capsule::table('tblinvoices')
        ->join('tblclients', 'tblinvoices.userid', '=', 'tblclients.id')
        ->select(
            'tblinvoices.id',
            'tblinvoices.invoicenum',
            'tblinvoices.date',
            'tblinvoices.subtotal',
            'tblinvoices.tax',
            'tblinvoices.tax2',
            'tblinvoices.total',
            'tblinvoices.status',
            'tblclients.firstname',
            'tblclients.lastname',
            'tblclients.companyname',
            'tblclients.state',
            'tblclients.tax_id', 
            'tblclients.country'
        )
        ->whereBetween('tblinvoices.date', [$startdate, $enddate])
        ->where('tblinvoices.status', 'Paid')
        ->orderBy('tblinvoices.date', 'asc')
        ->get();

    foreach ($invoices as $inv) {
        $cgst = 0; $sgst = 0; $igst = 0;
        if ($inv->tax2 > 0) {
            $cgst = $inv->tax;
            $sgst = $inv->tax2;
        } elseif ($inv->tax > 0) {
            $igst = $inv->tax;
        }
        $invoiceNum = !empty($inv->invoicenum) ? $inv->invoicenum : $inv->id;

        fputcsv($output, [
            $invoiceNum, $inv->date, $inv->firstname . ' ' . $inv->lastname, $inv->companyname, $inv->tax_id, $inv->state,
            $inv->subtotal, $cgst, $sgst, $igst, $inv->total, $inv->status
        ]);
    }
    fclose($output);
    exit();
}
?>