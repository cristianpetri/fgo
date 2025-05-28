<?php
/**
 * Hook-uri WHMCS pentru integrare FGO
 * 
 * Instalare:
 * 1. Plasați acest fișier în /includes/hooks/
 * 2. Redenumițilo ca fgo_hooks.php
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * Hook pentru emitere automată factură când se creează o factură nouă
 */
add_hook('InvoiceCreated', 1, function($vars) {
    // Verifică dacă modulul este activ și emiterea automată este activată
    $module_config = Capsule::table('tbladdonmodules')
        ->where('module', 'fgo')
        ->pluck('value', 'setting');
    
    if (empty($module_config) || !isset($module_config['emitere_automata']) || $module_config['emitere_automata'] != 'on') {
        return;
    }
    
    // Include clasa helper
    require_once ROOTDIR . '/modules/addons/fgo/fgo.php';
    
    $helper = new FGOHelper($module_config);
    $invoice_id = $vars['invoiceid'];
    
    // Emite factura
    $result = $helper->emitInvoice($invoice_id);
    
    if ($result['success']) {
        logActivity("FGO: Factură emisă automat - ID: {$invoice_id}, Serie: {$result['serie']}, Număr: {$result['numar']}");
    } else {
        logActivity("FGO: Eroare emitere automată factură ID {$invoice_id}: " . $result['message']);
    }
});

/**
 * Hook pentru emitere factură când se marchează ca plătită
 */
add_hook('InvoicePaid', 1, function($vars) {
    $invoice_id = $vars['invoiceid'];
    
    // Verifică dacă factura a fost deja emisă
    $existing = Capsule::table('mod_fgo_logs')
        ->where('invoice_id', $invoice_id)
        ->where('status', 'success')
        ->first();
    
    if ($existing) {
        return; // Factura a fost deja emisă
    }
    
    // Verifică configurația modulului
    $module_config = Capsule::table('tbladdonmodules')
        ->where('module', 'fgo')
        ->pluck('value', 'setting');
    
    if (empty($module_config)) {
        return;
    }
    
    // Include clasa helper
    require_once ROOTDIR . '/modules/addons/fgo/fgo.php';
    
    $helper = new FGOHelper($module_config);
    
    // Emite factura
    $result = $helper->emitInvoice($invoice_id);
    
    if ($result['success']) {
        logActivity("FGO: Factură emisă la plată - ID: {$invoice_id}, Serie: {$result['serie']}, Număr: {$result['numar']}");
        
        // Adaugă notă la factură
        Capsule::table('tblinvoices')
            ->where('id', $invoice_id)
            ->update([
                'notes' => Capsule::raw("CONCAT(notes, '\n\nFGO: Emisă cu seria {$result['serie']} numărul {$result['numar']}')")
            ]);
    }
});

/**
 * Hook pentru adăugare link factură FGO în pagina de vizualizare factură
 */
add_hook('ClientAreaPageViewInvoice', 1, function($vars) {
    $invoice_id = $vars['invoiceid'];
    
    // Verifică dacă există factură emisă în FGO
    $fgo_invoice = Capsule::table('mod_fgo_logs')
        ->where('invoice_id', $invoice_id)
        ->where('status', 'success')
        ->orderBy('id', 'desc')
        ->first();
    
    if ($fgo_invoice && $fgo_invoice->fgo_link) {
        return [
            'fgo_link' => $fgo_invoice->fgo_link,
            'fgo_serie' => $fgo_invoice->fgo_serie,
            'fgo_numar' => $fgo_invoice->fgo_numar,
        ];
    }
});

/**
 * Hook pentru adăugare buton în admin area factură
 */
add_hook('AdminInvoicesControlsOutput', 1, function($vars) {
    $invoice_id = $vars['invoiceid'];
    
    // Verifică dacă modulul este activ
    $module_exists = Capsule::table('tbladdonmodules')
        ->where('module', 'fgo')
        ->exists();
    
    if (!$module_exists) {
        return '';
    }
    
    // Verifică dacă factura a fost emisă
    $fgo_invoice = Capsule::table('mod_fgo_logs')
        ->where('invoice_id', $invoice_id)
        ->where('status', 'success')
        ->orderBy('id', 'desc')
        ->first();
    
    $output = '<div style="margin: 10px 0;">';
    
    if ($fgo_invoice) {
        $output .= '<button type="button" class="btn btn-success btn-sm" onclick="window.open(\'' . $fgo_invoice->fgo_link . '\', \'_blank\')">
            <i class="fas fa-file-invoice"></i> Vezi în FGO (' . $fgo_invoice->fgo_serie . '/' . $fgo_invoice->fgo_numar . ')
        </button> ';
    }
    
    $output .= '<a href="addonmodules.php?module=fgo&action=manual&invoice_id=' . $invoice_id . '" class="btn btn-primary btn-sm">
        <i class="fas fa-paper-plane"></i> ' . ($fgo_invoice ? 'Re-emite' : 'Emite') . ' în FGO
    </a>';
    
    $output .= '</div>';
    
    return $output;
});

/**
 * Hook pentru adăugare coloană în lista de facturi
 */
add_hook('AdminInvoicesListTableHeadings', 1, function($vars) {
    return '<th>FGO</th>';
});

add_hook('AdminInvoicesListTableRow', 1, function($vars) {
    $invoice_id = $vars['id'];
    
    $fgo_invoice = Capsule::table('mod_fgo_logs')
        ->where('invoice_id', $invoice_id)
        ->where('status', 'success')
        ->orderBy('id', 'desc')
        ->first();
    
    if ($fgo_invoice) {
        return '<td><span class="label label-success" title="' . $fgo_invoice->fgo_serie . '/' . $fgo_invoice->fgo_numar . '">
            <i class="fas fa-check"></i> Emisă
        </span></td>';
    } else {
        return '<td><span class="label label-default">-</span></td>';
    }
});

/**
 * Hook pentru actualizare automată status incasare în FGO
 */
add_hook('AddInvoicePayment', 1, function($vars) {
    $invoice_id = $vars['invoiceid'];
    
    // Verifică dacă factura există în FGO
    $fgo_invoice = Capsule::table('mod_fgo_logs')
        ->where('invoice_id', $invoice_id)
        ->where('status', 'success')
        ->orderBy('id', 'desc')
        ->first();
    
    if (!$fgo_invoice || !$fgo_invoice->fgo_serie || !$fgo_invoice->fgo_numar) {
        return;
    }
    
    // Obține configurația
    $module_config = Capsule::table('tbladdonmodules')
        ->where('module', 'fgo')
        ->pluck('value', 'setting');
    
    if (empty($module_config)) {
        return;
    }
    
    // Pregătește datele pentru încasare
    $data = [
        'CodUnic' => $module_config['cui_furnizor'],
        'Hash' => strtoupper(sha1($module_config['cui_furnizor'] . $module_config['cheie_privata'] . $fgo_invoice->fgo_numar)),
        'NumarFactura' => $fgo_invoice->fgo_numar,
        'SerieFactura' => $fgo_invoice->fgo_serie,
        'TipIncasare' => 'Banca', // Sau din configurare
        'SumaIncasata' => $vars['amount'],
        'DataIncasare' => date('Y-m-d'),
        'PlatformaUrl' => $module_config['platform_url'],
    ];
    
    // API URL
    $api_url = $module_config['api_environment'] == 'production' 
        ? 'https://api.fgo.ro/v1' 
        : 'https://api-testuat.fgo.ro/v1';
    
    // Trimite request
    $ch = curl_init($api_url . '/factura/incasare');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json',
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code == 200) {
        $result = json_decode($response, true);
        if (isset($result['Success']) && $result['Success']) {
            logActivity("FGO: Încasare înregistrată pentru factura {$fgo_invoice->fgo_serie}/{$fgo_invoice->fgo_numar}");
        }
    }
});

/**
 * Hook pentru anulare factură în FGO când se anulează în WHMCS
 */
add_hook('InvoiceCancelled', 1, function($vars) {
    $invoice_id = $vars['invoiceid'];
    
    // Verifică dacă factura există în FGO
    $fgo_invoice = Capsule::table('mod_fgo_logs')
        ->where('invoice_id', $invoice_id)
        ->where('status', 'success')
        ->orderBy('id', 'desc')
        ->first();
    
    if (!$fgo_invoice || !$fgo_invoice->fgo_serie || !$fgo_invoice->fgo_numar) {
        return;
    }
    
    // Obține configurația
    $module_config = Capsule::table('tbladdonmodules')
        ->where('module', 'fgo')
        ->pluck('value', 'setting');
    
    if (empty($module_config)) {
        return;
    }
    
    // Pregătește datele pentru anulare
    $data = [
        'CodUnic' => $module_config['cui_furnizor'],
        'Hash' => strtoupper(sha1($module_config['cui_furnizor'] . $module_config['cheie_privata'] . $fgo_invoice->fgo_numar)),
        'Numar' => $fgo_invoice->fgo_numar,
        'Serie' => $fgo_invoice->fgo_serie,
        'PlatformaUrl' => $module_config['platform_url'],
    ];
    
    // API URL
    $api_url = $module_config['api_environment'] == 'production' 
        ? 'https://api.fgo.ro/v1' 
        : 'https://api-testuat.fgo.ro/v1';
    
    // Trimite request
    $ch = curl_init($api_url . '/factura/anulare');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json',
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code == 200) {
        $result = json_decode($response, true);
        if (isset($result['Success']) && $result['Success']) {
            logActivity("FGO: Factură anulată {$fgo_invoice->fgo_serie}/{$fgo_invoice->fgo_numar}");
            
            // Actualizează log-ul
            Capsule::table('mod_fgo_logs')->insert([
                'invoice_id' => $invoice_id,
                'status' => 'success',
                'message' => 'Factură anulată',
                'fgo_serie' => $fgo_invoice->fgo_serie,
                'fgo_numar' => $fgo_invoice->fgo_numar,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }
});

/**
 * Hook pentru adăugare meniu în sidebar admin
 */
add_hook('AdminAreaNavBarOutput', 1, function($vars) {
    // Verifică dacă modulul este activ
    $module_exists = Capsule::table('tbladdonmodules')
        ->where('module', 'fgo')
        ->exists();
    
    if (!$module_exists) {
        return '';
    }
    
    // Număr facturi neemise
    $unissued = Capsule::table('tblinvoices as i')
        ->leftJoin('mod_fgo_logs as l', function($join) {
            $join->on('i.id', '=', 'l.invoice_id')
                ->where('l.status', '=', 'success');
        })
        ->whereNull('l.id')
        ->where('i.status', '!=', 'Draft')
        ->where('i.status', '!=', 'Cancelled')
        ->count();
    
    if ($unissued > 0) {
        return '<li>
            <a href="addonmodules.php?module=fgo">
                <i class="fas fa-exclamation-circle" style="color: #f0ad4e;"></i> 
                FGO: ' . $unissued . ' facturi neemise
            </a>
        </li>';
    }
    
    return '';
});