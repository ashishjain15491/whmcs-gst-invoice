<?php

use WHMCS\Database\Capsule;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function gst_manager_config()
{
    return [
        'name' => 'GST Manager',
        'description' => 'Complete GST Solution. Features: One-Click System Setup, Custom Rules, SAC Management, Export Compliance, and CSV Reports.',
        'author' => 'Relyweb',
        'language' => 'english',
        'version' => '2.7',
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
        }
        
        // Seed Defaults (Only if they don't already exist)
        $defaults = [
            'sac_hosting' => '998315', 'sac_domain' => '998319', 'sac_addon' => '998313', 'sac_setup' => '998313',
            'sac_upgrade' => '998315', 'sac_latefee' => '998313', 'sac_default' => '998313', 
            'purp_hosting' => 'P0807', 'purp_domain' => 'P0807', 'purp_addon' => 'P0807', 'purp_setup' => 'P0807',
            'purp_upgrade' => 'P0807', 'purp_latefee' => 'P0807', 'purp_default' => 'P0807', 
            'footer_tel' => '+91-1234567890', 'footer_email' => 'billing@example.com',
            'footer_cin_type' => 'CIN', 'footer_cin_val' => 'U12345MH2024PTC123456', 'footer_pan' => 'ABCDE1234F',
            'label_tel' => 'Tel', 'label_email' => 'E-Mail', 'label_pan' => 'PAN', 'label_cin' => 'CIN',
            'home_state' => 'Maharashtra',
            'export_decl' => 'Supply meant for export under Letter of Undertaking without payment of Integrated GST.',
            'fema_decl' => 'We hereby declare that this invoice represents export of software and IT services from India, and the payment will be received in convertible foreign exchange.'
        ];
        
        foreach ($defaults as $key => $val) {
            // Check if the setting already exists before inserting
            if (!Capsule::table('mod_gst_config')->where('setting', $key)->first()) {
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
                $table->string('purpose_code', 20)->nullable();
            });
        } else {
            // Upgrade path: Add purpose_code if it doesn't exist
            if (!Capsule::schema()->hasColumn('mod_gst_rules', 'purpose_code')) {
                Capsule::schema()->table('mod_gst_rules', function($table) {
                    $table->string('purpose_code', 20)->nullable()->after('sac_code');
                });
            }
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

    // Fetch active currencies for Bank Details
    $currencies = Capsule::table('tblcurrencies')->get();

    // --- ACTIONS ---

    // 1. Save Settings
    if ($action == 'save_settings') {
        $settings = [
            'sac_hosting', 'sac_domain', 'sac_addon', 'sac_setup', 'sac_upgrade', 'sac_latefee', 'sac_default',
            'purp_hosting', 'purp_domain', 'purp_addon', 'purp_setup', 'purp_upgrade', 'purp_latefee', 'purp_default',
            'footer_tel', 'footer_email', 'footer_cin_type', 'footer_cin_val', 'footer_pan',
            'label_tel', 'label_email', 'label_pan', 'label_cin', 'export_decl', 'fema_decl'
        ];
        
        // Add dynamic currency bank details to savable settings
        foreach ($currencies as $curr) {
            $settings[] = 'bank_details_' . $curr->code;
        }

        foreach ($settings as $setting) {
            Capsule::table('mod_gst_config')->updateOrInsert(['setting' => $setting], ['value' => trim($_POST[$setting] ?? '')]);
        }
        header("Location: " . $modulelink . "&action=settings&success=true");
        exit;
    }

    // 2. Rules
    if ($action == 'add_rule') {
        if (!empty($_POST['keyword'])) {
            Capsule::table('mod_gst_rules')->insert([
                'keyword' => strtolower(trim($_POST['keyword'])),
                'display_name' => trim($_POST['display_name']),
                'sac_code' => trim($_POST['sac_code']),
                'purpose_code' => trim($_POST['purpose_code'])
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
    
    // 3. Sync Templates
    if ($action == 'sync') {
        $res = gst_manager_sync_templates();
        $alertClass = ($res['status'] == 'success') ? 'success' : 'danger';
        echo '<div class="alert alert-'.$alertClass.'">'.$res['description'].'</div>';
    }
    
    // 4. Export
    if ($action == 'export') { gst_manager_export_csv(); }

    // 5. SETUP ACTIONS
    if ($action == 'setup_invoicing') {
        // Enable Proforma & Sequential
        Capsule::table('tblconfiguration')->updateOrInsert(['setting' => 'EnableProformaInvoicing'], ['value' => '1']);
        Capsule::table('tblconfiguration')->updateOrInsert(['setting' => 'SequentialInvoiceNumbering'], ['value' => '1']);
        
        $currentFormat = Capsule::table('tblconfiguration')->where('setting', 'SequentialInvoiceNumberFormat')->value('value');
        if (empty($currentFormat)) {
            Capsule::table('tblconfiguration')->updateOrInsert(['setting' => 'SequentialInvoiceNumberFormat'], ['value' => 'INV-{YEAR}{NUMBER}']);
        }
        
        header("Location: " . $modulelink . "&action=setup&success=invoicing");
        exit;
    }

    if ($action == 'setup_tax_config') {
        $gstin = trim($_POST['company_gstin']);
        $homeState = $_POST['home_state'];

        // A. Basic Tax Settings
        Capsule::table('tblconfiguration')->updateOrInsert(['setting' => 'TaxEnabled'], ['value' => 'on']);
        Capsule::table('tblconfiguration')->updateOrInsert(['setting' => 'TaxType'], ['value' => 'Exclusive']);
        Capsule::table('tblconfiguration')->updateOrInsert(['setting' => 'TaxL2Compound'], ['value' => '']); // Disable Compound
        Capsule::table('tblconfiguration')->updateOrInsert(['setting' => 'TaxPerLineItem'], ['value' => '1']); // Tax per Line Item
        Capsule::table('tblconfiguration')->updateOrInsert(['setting' => 'TaxInclusiveDeduct'], ['value' => '']); // Disable Inclusive Deduction
        Capsule::table('tblconfiguration')->updateOrInsert(['setting' => 'TaxSetInvoiceDateOnPayment'], ['value' => '1']); // Set Invoice Date on Payment

        // B. Tax ID Settings
        Capsule::table('tblconfiguration')->updateOrInsert(['setting' => 'TaxCode'], ['value' => $gstin]); // Company GSTIN
        Capsule::table('tblconfiguration')->updateOrInsert(['setting' => 'TaxIDDisabled'], ['value' => '']); // Client Tax IDs (Enable VAT Number)

        // C. Taxed Items (Advanced Settings)
        Capsule::table('tblconfiguration')->updateOrInsert(['setting' => 'TaxDomains'], ['value' => 'on']);
        Capsule::table('tblconfiguration')->updateOrInsert(['setting' => 'TaxBillableItems'], ['value' => 'on']);
        Capsule::table('tblconfiguration')->updateOrInsert(['setting' => 'TaxLateFee'], ['value' => 'on']);
        Capsule::table('tblconfiguration')->updateOrInsert(['setting' => 'TaxCustomInvoices'], ['value' => 'on']);
        Capsule::table('tblconfiguration')->updateOrInsert(['setting' => 'TaxAddons'], ['value' => 'on']); // Product Addons
        
        // D. Save Home State to Module Config
        Capsule::table('mod_gst_config')->updateOrInsert(['setting' => 'home_state'], ['value' => $homeState]);
        
        // E. Generate Rules
        gst_manager_generate_tax_rules($homeState);

        header("Location: " . $modulelink . "&action=setup&success=tax");
        exit;
    }

    if (isset($_REQUEST['success'])) {
        echo '<div class="alert alert-success">System Configuration Updated Successfully!</div>';
    }

    // --- FETCH DATA ---
    $config = Capsule::table('mod_gst_config')->pluck('value', 'setting');
    if(is_object($config) && method_exists($config, 'toArray')) $config = $config->toArray();
    elseif (is_array($config)) $config = $config; 
    
    $rules = Capsule::table('mod_gst_rules')->orderBy('id', 'asc')->get();

    // --- UI (Card Style) ---
    echo '<style>
        .gst-card { background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; margin-bottom: 20px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .gst-card h3 { margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 15px; color: #333; font-size: 18px; }
        .nav-tabs { margin-bottom: 15px; }
        .table-rules th { background: #f9f9f9; }
        .status-badge { padding: 3px 8px; border-radius: 3px; font-size: 11px; text-transform: uppercase; font-weight: bold; }
        .status-enabled { background: #dff0d8; color: #3c763d; border: 1px solid #d6e9c6; }
        .status-disabled { background: #f2dede; color: #a94442; border: 1px solid #ebccd1; }
    </style>';

    $tab = isset($_REQUEST['action']) && in_array($_REQUEST['action'], ['settings', 'rules', 'setup']) ? $_REQUEST['action'] : 'dashboard';
    
    echo '<ul class="nav nav-tabs">
            <li role="presentation" class="' . ($tab == 'dashboard' ? 'active' : '') . '"><a href="' . $modulelink . '">Dashboard</a></li>
            <li role="presentation" class="' . ($tab == 'setup' ? 'active' : '') . '"><a href="' . $modulelink . '&action=setup">System Setup</a></li>
            <li role="presentation" class="' . ($tab == 'rules' ? 'active' : '') . '"><a href="' . $modulelink . '&action=rules">Item Rules</a></li>
            <li role="presentation" class="' . ($tab == 'settings' ? 'active' : '') . '"><a href="' . $modulelink . '&action=settings">Global Settings</a></li>
          </ul>';

    if ($tab == 'setup') {
        // --- SETUP TAB ---
        
        $proforma = Capsule::table('tblconfiguration')->where('setting', 'EnableProformaInvoicing')->value('value') == '1';
        $sequential = Capsule::table('tblconfiguration')->where('setting', 'SequentialInvoiceNumbering')->value('value') == '1';
        $sequentialFormat = Capsule::table('tblconfiguration')->where('setting', 'SequentialInvoiceNumberFormat')->value('value');
        $taxEnabled = Capsule::table('tblconfiguration')->where('setting', 'TaxEnabled')->value('value') == 'on';

        // Fetch current Company Tax ID if set
        $currentTaxId = Capsule::table('tblconfiguration')->where('setting', 'TaxCode')->value('value');
        
        $indianStates = ['Andaman and Nicobar Islands','Andhra Pradesh','Arunachal Pradesh','Assam','Bihar','Chandigarh','Chhattisgarh','Dadra and Nagar Haveli','Daman and Diu','Delhi','Goa','Gujarat','Haryana','Himachal Pradesh','Jammu and Kashmir','Jharkhand','Karnataka','Kerala','Ladakh','Lakshadweep','Madhya Pradesh','Maharashtra','Manipur','Meghalaya','Mizoram','Nagaland','Odisha','Puducherry','Punjab','Rajasthan','Sikkim','Tamil Nadu','Telangana','Tripura','Uttar Pradesh','Uttarakhand','West Bengal'];
        
        echo '<div class="row">
            <div class="col-md-6">
                <div class="gst-card">
                    <h3>1. Invoicing Configuration</h3>
                    <p>Enables Proforma Invoicing and Sequential Numbering (e.g., INV-20251001).</p>
                    <table class="table table-condensed">
                        <tr><td>Proforma Invoicing</td><td>'.($proforma ? '<span class="status-badge status-enabled">Enabled</span>' : '<span class="status-badge status-disabled">Disabled</span>').'</td></tr>
                        <tr><td>Sequential Numbering</td><td>'.($sequential ? '<span class="status-badge status-enabled">Enabled</span>' : '<span class="status-badge status-disabled">Disabled</span>').'</td></tr>
                        <tr><td>Sequential Format</td><td>'.(!empty($sequentialFormat) ? htmlspecialchars($sequentialFormat) : '<em>Not Set</em>').'</td></tr>
                    </table>
                    <a href="'.$modulelink.'&action=setup_invoicing" class="btn btn-primary btn-block">Enable / Fix Invoicing Settings</a>
                </div>
            </div>
            <div class="col-md-6">
                <div class="gst-card">
                    <h3>2. Tax & GST Configuration</h3>
                    <p>Configures Tax Type, GSTIN, and generates Tax Rules.</p>
                    <form method="post" action="'.$modulelink.'&action=setup_tax_config">
                        <div class="form-group">
                            <label>Your Company GSTIN</label>
                            <input type="text" name="company_gstin" class="form-control" value="'.(!empty($currentTaxId) ? $currentTaxId : '').'" placeholder="27ABCDE1234F1Z5">
                        </div>
                        <div class="form-group">
                            <label>Your Registered State (Home State)</label>
                            <select name="home_state" class="form-control">';
                            foreach ($indianStates as $state) {
                                $sel = ($state == ($config['home_state']??'Maharashtra')) ? 'selected' : '';
                                echo '<option value="'.$state.'" '.$sel.'>'.$state.'</option>';
                            }
        echo '              </select>
                        </div>
                        <button type="submit" class="btn btn-warning btn-block" onclick="return confirm(\'This will update Tax Settings, Enable Client Tax IDs, and Reset Tax Rules for India. Continue?\')">Save GSTIN & Generate Rules</button>
                    </form>
                    <div style="margin-top:10px; font-size:11px; color:#666; line-height:1.4;">
                        <strong>This Action Will:</strong><br>
                        - Set Tax Type to <strong>Exclusive</strong>.<br>
                        - Enable Tax for <strong>Domains, Late Fees, Custom Invoices, Billable Items</strong>.<br>
                        - Disable Compound Tax.<br>
                        - Disable Inclusive Deduction.<br>
                        - Set Calculation Mode to <strong>Per Line Item</strong>.<br>
                        - Enable <strong>Client Tax ID</strong> field on checkout.<br>
                        - Set Invoice Date on Payment to ensure correct tax period.<br>
                        - Create <strong>IGST (18%)</strong> rule for India.<br>
                        - Create <strong>CGST (9%) + SGST (9%)</strong> rules for your Home State.
                    </div>
                </div>
            </div>
        </div>';

    } elseif ($tab == 'rules') {
        // --- RULES TAB ---
        echo '<div class="gst-card">
            <h3><i class="fas fa-list-ul"></i> Custom Item Type Rules</h3>
            <table class="table table-bordered table-striped table-rules">
                <thead><tr><th width="25%">Keyword</th><th width="25%">Display Name</th><th width="20%">SAC Code</th><th width="20%">Purpose Code</th><th width="10%"></th></tr></thead>
                <tbody>';
        foreach ($rules as $rule) {
            echo '<tr>
                <td>Contains: <code>'.htmlspecialchars($rule->keyword).'</code></td>
                <td>'.htmlspecialchars($rule->display_name).'</td>
                <td>'.htmlspecialchars($rule->sac_code).'</td>
                <td>'.htmlspecialchars($rule->purpose_code ?? '').'</td>
                <td><a href="'.$modulelink.'&action=delete_rule&id='.$rule->id.'" class="btn btn-xs btn-danger" onclick="return confirm(\'Delete?\')"><i class="fas fa-trash"></i></a></td>
            </tr>';
        }
        echo '</tbody><tfoot><form method="post" action="'.$modulelink.'&action=add_rule"><tr class="info">
            <td><input type="text" name="keyword" class="form-control input-sm" placeholder="e.g. workplace" required></td>
            <td><input type="text" name="display_name" class="form-control input-sm" placeholder="e.g. Zoho" required></td>
            <td><input type="text" name="sac_code" class="form-control input-sm" placeholder="SAC" required></td>
            <td><input type="text" name="purpose_code" class="form-control input-sm" placeholder="Purpose Code"></td>
            <td><button type="submit" class="btn btn-success btn-sm btn-block"><i class="fas fa-plus"></i></button></td>
            </tr></form></tfoot></table></div>';

    } elseif ($tab == 'settings') {
        // --- SETTINGS TAB ---
        echo '<form method="post" action="' . $modulelink . '&action=save_settings">
        <div class="row">
            <div class="col-md-6">
                <div class="gst-card">
                    <h3>Default SAC & Purpose Codes</h3>
                    <div class="row">
                        <div class="col-xs-6"><label>Service Type</label></div>
                        <div class="col-xs-3"><label>SAC Code</label></div>
                        <div class="col-xs-3"><label>Purpose</label></div>
                    </div>
                    <div class="form-group row"><div class="col-xs-6">Hosting (Shared/VPS)</div><div class="col-xs-3"><input type="text" name="sac_hosting" class="form-control input-sm" value="'.($config['sac_hosting']??'').'"></div><div class="col-xs-3"><input type="text" name="purp_hosting" class="form-control input-sm" value="'.($config['purp_hosting']??'').'"></div></div>
                    <div class="form-group row"><div class="col-xs-6">Product Upgrades</div><div class="col-xs-3"><input type="text" name="sac_upgrade" class="form-control input-sm" value="'.($config['sac_upgrade']??'').'"></div><div class="col-xs-3"><input type="text" name="purp_upgrade" class="form-control input-sm" value="'.($config['purp_upgrade']??'').'"></div></div>
                    <div class="form-group row"><div class="col-xs-6">Domains</div><div class="col-xs-3"><input type="text" name="sac_domain" class="form-control input-sm" value="'.($config['sac_domain']??'').'"></div><div class="col-xs-3"><input type="text" name="purp_domain" class="form-control input-sm" value="'.($config['purp_domain']??'').'"></div></div>
                    <div class="form-group row"><div class="col-xs-6">Addons</div><div class="col-xs-3"><input type="text" name="sac_addon" class="form-control input-sm" value="'.($config['sac_addon']??'').'"></div><div class="col-xs-3"><input type="text" name="purp_addon" class="form-control input-sm" value="'.($config['purp_addon']??'').'"></div></div>
                    <div class="form-group row"><div class="col-xs-6">Setup Fees</div><div class="col-xs-3"><input type="text" name="sac_setup" class="form-control input-sm" value="'.($config['sac_setup']??'').'"></div><div class="col-xs-3"><input type="text" name="purp_setup" class="form-control input-sm" value="'.($config['purp_setup']??'').'"></div></div>
                    <div class="form-group row"><div class="col-xs-6">Late Fees Only</div><div class="col-xs-3"><input type="text" name="sac_latefee" class="form-control input-sm" value="'.($config['sac_latefee']??'').'"></div><div class="col-xs-3"><input type="text" name="purp_latefee" class="form-control input-sm" value="'.($config['purp_latefee']??'').'"></div></div>
                    <div class="form-group row"><div class="col-xs-6">Fallback (Default)</div><div class="col-xs-3"><input type="text" name="sac_default" class="form-control input-sm" value="'.($config['sac_default']??'').'"></div><div class="col-xs-3"><input type="text" name="purp_default" class="form-control input-sm" value="'.($config['purp_default']??'').'"></div></div>
                </div>
                
                <div class="gst-card">
                    <h3>Export Settings & Declarations</h3>
                    <div class="form-group">
                        <label>Export Declaration</label>
                        <textarea name="export_decl" class="form-control" rows="2">'.htmlspecialchars($config['export_decl'] ?? '').'</textarea>
                    </div>
                    <div class="form-group">
                        <label>FEMA Declaration</label>
                        <textarea name="fema_decl" class="form-control" rows="2">'.htmlspecialchars($config['fema_decl'] ?? '').'</textarea>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="gst-card">
                    <h3>Footer Configuration</h3>
                    <div class="form-group"><label>Phone</label><div class="row"><div class="col-xs-4"><input type="text" name="label_tel" class="form-control" value="'.($config['label_tel']??'Tel').'"></div><div class="col-xs-8"><input type="text" name="footer_tel" class="form-control" value="'.($config['footer_tel']??'').'"></div></div></div>
                    <div class="form-group"><label>Email</label><div class="row"><div class="col-xs-4"><input type="text" name="label_email" class="form-control" value="'.($config['label_email']??'E-Mail').'"></div><div class="col-xs-8"><input type="text" name="footer_email" class="form-control" value="'.($config['footer_email']??'').'"></div></div></div>
                    <div class="form-group"><label>PAN</label><div class="row"><div class="col-xs-4"><input type="text" name="label_pan" class="form-control" value="'.($config['label_pan']??'PAN').'"></div><div class="col-xs-8"><input type="text" name="footer_pan" class="form-control" value="'.($config['footer_pan']??'').'"></div></div></div>
                    <div class="form-group"><label>CIN / LLPIN</label><div class="row"><div class="col-xs-4"><select name="footer_cin_type" class="form-control"><option value="CIN" '.($config['footer_cin_type']=='CIN'?'selected':'').'>CIN</option><option value="LLPIN" '.($config['footer_cin_type']=='LLPIN'?'selected':'').'>LLPIN</option><option value="Disable" '.($config['footer_cin_type']=='Disable'?'selected':'').'>Disable</option></select></div><div class="col-xs-8"><input type="text" name="footer_cin_val" class="form-control" value="'.($config['footer_cin_val']??'').'"></div></div></div>
                </div>

                <div class="gst-card">
                    <h3>Bank Details (Per Currency)</h3>
                    <p class="help-block">Rendered at the bottom of the invoice based on the active currency.</p>';
                    foreach ($currencies as $curr) {
                        $key = 'bank_details_' . $curr->code;
                        echo '<div class="form-group">
                            <label>Bank Details for '.$curr->code.'</label>
                            <textarea name="'.$key.'" class="form-control" rows="3" placeholder="Bank Name: XYZ Bank&#10;Account No: 123456789&#10;SWIFT: XYZB123">'.htmlspecialchars($config[$key] ?? '').'</textarea>
                        </div>';
                    }
                echo '</div>
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
                <div class="form-group date-picker-prepend-icon">
                    <label>Start: </label>
                    <label for="inputDate" class="field-icon">
                        <i class="fal fa-calendar-alt"></i>
                    </label>
                    <input type="text" name="startdate" class="form-control date-picker-single" value="'.date('01/m/Y').'">
                
                    <label>End: </label>
                    <label for="inputDate" class="field-icon">
                        <i class="fal fa-calendar-alt"></i>
                    </label>
                    <input type="text" name="enddate" class="form-control date-picker-single" value="'.date('t/m/Y').'">
                </div>
                <hr>
                <div class="row">
                    <div class="col-md-8 col-md-offset-4 col-sm-6 col-sm-offset-6">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-download"></i> Export CSV</button>
                    </div>
                </div>
            </form>
        </div>';
    }
}

// --- HELPER: Tax Rules Generation ---
function gst_manager_generate_tax_rules($homeState) {
    // 1. Clean existing rules for India 
    Capsule::table('tbltax')->where('country', 'India')->delete();
    
    // 2. Insert IGST (Level 1, India, Any State) -> 18%
    Capsule::table('tbltax')->insert(['level' => 1, 'name' => 'IGST', 'country' => 'India', 'state' => '', 'taxrate' => 18.00]);
    
    // 3. Insert CGST (Level 1, India, Home State) -> 9%
    Capsule::table('tbltax')->insert(['level' => 1, 'name' => 'CGST', 'country' => 'India', 'state' => $homeState, 'taxrate' => 9.00]);
    
    // 4. Insert SGST (Level 2, India, Home State) -> 9%
    Capsule::table('tbltax')->insert(['level' => 2, 'name' => 'SGST', 'country' => 'India', 'state' => $homeState, 'taxrate' => 9.00]);
}

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
    header('Content-Disposition: attachment; filename=GST_Report_' . str_replace('/', '-', $startdate) . '_to_' . str_replace('/', '-', $enddate) . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Invoice Number', 'Date', 'Client Name', 'Client Company', 'Client GSTIN', 'State', 'Taxable Amount', 'CGST Amount', 'SGST Amount', 'IGST Amount', 'Total', 'Status']);
    
    // Format dates for DB query if necessary depending on your environment. Assuming standard WHMCS standard date strings here.
    $invoices = Capsule::table('tblinvoices')->join('tblclients', 'tblinvoices.userid', '=', 'tblclients.id')->select('tblinvoices.id', 'tblinvoices.invoicenum', 'tblinvoices.date', 'tblinvoices.subtotal', 'tblinvoices.tax', 'tblinvoices.tax2', 'tblinvoices.total', 'tblinvoices.status', 'tblclients.firstname', 'tblclients.lastname', 'tblclients.companyname', 'tblclients.state', 'tblclients.tax_id', 'tblclients.country')->whereBetween('tblinvoices.date', [$startdate, $enddate])->where('tblinvoices.status', 'Paid')->orderBy('tblinvoices.date', 'asc')->get();
    
    foreach ($invoices as $inv) {
        $cgst = 0; $sgst = 0; $igst = 0;
        if ($inv->tax2 > 0) { $cgst = $inv->tax; $sgst = $inv->tax2; } elseif ($inv->tax > 0) { $igst = $inv->tax; }
        $invoiceNum = !empty($inv->invoicenum) ? $inv->invoicenum : $inv->id;
        fputcsv($output, [$invoiceNum, $inv->date, $inv->firstname . ' ' . $inv->lastname, $inv->companyname, $inv->tax_id, $inv->state, $inv->subtotal, $cgst, $sgst, $igst, $inv->total, $inv->status]);
    }
    fclose($output); exit();
}
?>