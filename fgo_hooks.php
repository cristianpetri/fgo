<?php
/**
 * Hook-uri WHMCS pentru integrare FGO - Versiune Completă
 * Versiune: 2.0
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
    
    // Verifică dacă trebuie amânat email-ul
    if ($module_config['delay_email'] == 'on') {
        // Salvează că email-ul trebuie trimis mai târziu
        Capsule::table('mod_fgo_delayed_emails')->insert([
            'invoice_id' => $invoice_id,
            'template' => 'Invoice Created',
            'sent' => false,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Oprește trimiterea email-ului standard
        return ['abortsend' => true];
    }
    
    // Adaugă în coadă sau emite direct
    if ($module_config['use_queue'] ?? false) {
        Capsule::table('mod_fgo_queue')->insert([
            'invoice_id' => $invoice_id,
            'action' => 'emit',
            'status' => 'pending',
            'priority' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        
        logActivity("FGO: Factură adăugată în coada de emitere - ID: {$invoice_id}");
    } else {
        // Emite direct
        $result = $helper->emitInvoice($invoice_id);
        
        if ($result['success']) {
            logActivity("FGO: Factură emisă automat - ID: {$invoice_id}, Serie: {$result['serie']}, Număr: {$result['numar']}");
        } else {
            logActivity("FGO: Eroare emitere automată factură ID {$invoice_id}: " . $result['message']);
        }
    }
});

/**
 * Hook pentru blocarea email-urilor până la emiterea în FGO
 */
add_hook('EmailPreSend', 1, function($vars) {
    if (!in_array($vars['messagename'], ['Invoice Created', 'Invoice Payment Confirmation'])) {
        return;
    }
    
    $invoice_id = $vars['relid'];
    
    // Verifică dacă email-ul trebuie amânat
    $delayed = Capsule::table('mod_fgo_delayed_emails')
        ->where('invoice_id', $invoice_id)
        ->where('template', $vars['messagename'])
        ->where('sent', false)
        ->exists();
    
    if ($delayed) {
        // Verifică dacă factura a fost emisă în FGO
        $fgo_invoice = Capsule::table('mod_fgo_logs')
            ->where('invoice_id', $invoice_id)
            ->where('status', 'success')
            ->first();
        
        if (!$fgo_invoice) {
            // Factura nu a fost încă emisă, oprește email-ul
            return ['abortsend' => true];
        }
        
        // Marchează ca trimis
        Capsule::table('mod_fgo_delayed_emails')
            ->where('invoice_id', $invoice_id)
            ->where('template', $vars['messagename'])
            ->update(['sent' => true, 'updated_at' => date('Y-m-d H:i:s')]);
    }
});

/**
 * Hook pentru emitere factură când se marchează ca plătită
 */
add_hook('InvoicePaid', 1, function($vars) {
    $invoice_id = $vars['invoiceid'];
    
    // Verifică dacă există proformă emisă
    $proforma = Capsule::table('mod_fgo_logs')
        ->where('invoice_id', $invoice_id)
        ->where('document_type', 'proforma')
        ->where('status', 'success')
        ->first();
    
    if ($proforma) {
        // Verifică configurația pentru conversie automată
        $module_config = Capsule::table('tbladdonmodules')
            ->where('module', 'fgo')
            ->pluck('value', 'setting');
        
        if ($module_config['conversie_proforma'] == 'on') {
            // Include clasa helper
            require_once ROOTDIR . '/modules/addons/fgo/fgo.php';
            
            $helper = new FGOHelper($module_config);
            
            // Adaugă în coadă conversie proformă -> factură
            Capsule::table('mod_fgo_queue')->insert([
                'invoice_id' => $invoice_id,
                'action' => 'convert',
                'status' => 'pending',
                'priority' => 5, // Prioritate mai mare
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            
            logActivity("FGO: Conversie proformă -> factură programată pentru ID {$invoice_id}");
        }
    } else {
        // Nu există proformă, verifică dacă trebuie emisă factură directă
        $existing = Capsule::table('mod_fgo_logs')
            ->where('invoice_id', $invoice_id)
            ->where('status', 'success')
            ->first();
        
        if (!$existing) {
            // Nu a fost emisă deloc, emite acum
            $module_config = Capsule::table('tbladdonmodules')
                ->where('module', 'fgo')
                ->pluck('value', 'setting');
            
            require_once ROOTDIR . '/modules/addons/fgo/fgo.php';
            
            $helper = new FGOHelper($module_config);
            $result = $helper->emitInvoice($invoice_id);
            
            if ($result['success']) {
                logActivity("FGO: Factură emisă la plată - ID: {$invoice_id}, Serie: {$result['serie']}, Număr: {$result['numar']}");
            }
        }
    }
});

/**
 * Hook pentru adăugare link factură FGO în pagina de vizualizare factură client
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
        // Adaugă buton pentru descărcare factură FGO
        $pdf_button = '<a href="' . $fgo_invoice->fgo_link . '" class="btn btn-success" target="_blank">
            <i class="fas fa-file-pdf"></i> Descarcă Factură Fiscală
        </a>';
        
        return [
            'fgo_link' => $fgo_invoice->fgo_link,
            'fgo_serie' => $fgo_invoice->fgo_serie,
            'fgo_numar' => $fgo_invoice->fgo_numar,
            'fgo_pdf_button' => $pdf_button,
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
    
    // Verifică erori recente
    $recent_error = Capsule::table('mod_fgo_logs')
        ->where('invoice_id', $invoice_id)
        ->where('status', 'error')
        ->orderBy('id', 'desc')
        ->first();
    
    $output = '<div style="margin: 10px 0;">';
    
    if ($fgo_invoice) {
        $doc_type = ucfirst($fgo_invoice->document_type);
        $output .= '<button type="button" class="btn btn-success btn-sm" onclick="window.open(\'' . $fgo_invoice->fgo_link . '\', \'_blank\')">
            <i class="fas fa-file-invoice"></i> Vezi ' . $doc_type . ' în FGO (' . $fgo_invoice->fgo_serie . '/' . $fgo_invoice->fgo_numar . ')
        </button> ';
        
        // Dacă e proformă plătită, arată buton de conversie
        $invoice = Capsule::table('tblinvoices')->where('id', $invoice_id)->first();
        if ($fgo_invoice->document_type == 'proforma' && $invoice->status == 'Paid') {
            $output .= '<a href="addonmodules.php?module=fgo&action=convert&invoice_id=' . $invoice_id . '" 
                         class="btn btn-warning btn-sm" onclick="return confirm(\'Convertiți proforma în factură fiscală?\')">
                <i class="fas fa-exchange-alt"></i> Convertește în Factură
            </a> ';
        }
    } else if ($recent_error) {
        $output .= '<span class="label label-danger" title="' . htmlspecialchars($recent_error->message) . '">
            <i class="fas fa-exclamation-triangle"></i> Eroare FGO
        </span> ';
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
        $doc_type = substr(ucfirst($fgo_invoice->document_type), 0, 1);
        return '<td><span class="label label-success" title="' . ucfirst($fgo_invoice->document_type) . ': ' . $fgo_invoice->fgo_serie . '/' . $fgo_invoice->fgo_numar . '">
            <i class="fas fa-check"></i> ' . $doc_type . ' Emisă
        </span></td>';
    } else {
        $error = Capsule::table('mod_fgo_logs')
            ->where('invoice_id', $invoice_id)
            ->where('status', 'error')
            ->orderBy('id', 'desc')
            ->first();
        
        if ($error) {
            return '<td><span class="label label-danger" title="' . htmlspecialchars($error->message) . '">
                <i class="fas fa-times"></i> Eroare
            </span></td>';
        }
        
        return '<td><span class="label label-default">-</span></td>';
    }
});

/**
 * Hook pentru actualizare automată status încasare în FGO
 */
add_hook('AddInvoicePayment', 1, function($vars) {
    $invoice_id = $vars['invoiceid'];
    
    // Verifică dacă factura există în FGO
    $fgo_invoice = Capsule::table('mod_fgo_logs')
        ->where('invoice_id', $invoice_id)
        ->where('status', 'success')
        ->where('document_type', '!=', 'proforma') // Nu înregistra încasări pe proforme
        ->orderBy('id', 'desc')
        ->first();
    
    if (!$fgo_invoice || !$fgo_invoice->fgo_serie || !$fgo_invoice->fgo_numar) {
        return;
    }
    
    // Obține configurația
    $module_config = Capsule::table('tbladdonmodules')
        ->where('module', 'fgo')
        ->pluck('value', 'setting');
    
    if (empty($module_config) || $module_config['allow_incasare'] != 'on') {
        return;
    }
    
    // Obține informații despre gateway
    $invoice = Capsule::table('tblinvoices')->where('id', $invoice_id)->first();
    $gateway_mapping = Capsule::table('mod_fgo_gateway_mapping')
        ->where('gateway', $invoice->paymentmethod)
        ->first();
    
    if (!$gateway_mapping) {
        logActivity("FGO: Nu există mapare pentru gateway {$invoice->paymentmethod}");
        return;
    }
    
    // Pregătește datele pentru încasare
    $data = [
        'CodUnic' => $module_config['cui_furnizor'],
        'Hash' => strtoupper(sha1($module_config['cui_furnizor'] . $module_config['cheie_privata'] . $fgo_invoice->fgo_numar)),
        'NumarFactura' => $fgo_invoice->fgo_numar,
        'SerieFactura' => $fgo_invoice->fgo_serie,
        'SerieChitanta' => $module_config['serie_chitanta'] ?? '',
        'ContIncasare' => $gateway_mapping->cont_incasare ?? '',
        'TipIncasare' => $gateway_mapping->tip_incasare,
        'SumaIncasata' => $vars['amount'],
        'DataIncasare' => date('Y-m-d'),
        'PlatformaUrl' => $module_config['platform_url'],
        'TipFactura' => 'Factura',
    ];
    
    // Adaugă în coadă
    Capsule::table('mod_fgo_queue')->insert([
        'invoice_id' => $invoice_id,
        'action' => 'payment',
        'status' => 'pending',
        'priority' => 2,
        'data' => json_encode($data),
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    
    logActivity("FGO: Încasare adăugată în coadă pentru factura {$fgo_invoice->fgo_serie}/{$fgo_invoice->fgo_numar}");
});

/**
 * Hook pentru anulare factură în FGO când se anulează în WHMCS
 */
add_hook('InvoiceCancelled', 1, function($vars) {
    $invoice_id = $vars['invoiceid'];
    
    // Verifică toate documentele emise pentru această factură
    $fgo_documents = Capsule::table('mod_fgo_logs')
        ->where('invoice_id', $invoice_id)
        ->where('status', 'success')
        ->get();
    
    if ($fgo_documents->isEmpty()) {
        return;
    }
    
    // Obține configurația
    $module_config = Capsule::table('tbladdonmodules')
        ->where('module', 'fgo')
        ->pluck('value', 'setting');
    
    if (empty($module_config)) {
        return;
    }
    
    foreach ($fgo_documents as $document) {
        // Adaugă în coadă pentru anulare
        Capsule::table('mod_fgo_queue')->insert([
            'invoice_id' => $invoice_id,
            'action' => 'cancel',
            'status' => 'pending',
            'priority' => 3,
            'data' => json_encode([
                'serie' => $document->fgo_serie,
                'numar' => $document->fgo_numar,
                'document_type' => $document->document_type,
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
    
    logActivity("FGO: Anulare programată pentru factură #{$invoice_id}");
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
        ->where('i.date', '>', date('Y-m-d', strtotime('-7 days')))
        ->count();
    
    // Număr erori recente
    $recent_errors = Capsule::table('mod_fgo_logs')
        ->where('status', 'error')
        ->where('created_at', '>', date('Y-m-d H:i:s', strtotime('-24 hours')))
        ->count();
    
    // Elemente în coadă
    $queue_pending = Capsule::table('mod_fgo_queue')
        ->where('status', 'pending')
        ->count();
    
    $output = '';
    
    if ($unissued > 0) {
        $output .= '<li>
            <a href="addonmodules.php?module=fgo&action=bulk">
                <i class="fas fa-exclamation-circle" style="color: #f0ad4e;"></i> 
                FGO: ' . $unissued . ' facturi neemise
            </a>
        </li>';
    }
    
    if ($recent_errors > 0) {
        $output .= '<li>
            <a href="addonmodules.php?module=fgo&action=logs&status=error">
                <i class="fas fa-times-circle" style="color: #d9534f;"></i> 
                FGO: ' . $recent_errors . ' erori recente
            </a>
        </li>';
    }
    
    if ($queue_pending > 0) {
        $output .= '<li>
            <a href="addonmodules.php?module=fgo&action=queue">
                <i class="fas fa-hourglass-half" style="color: #5bc0de;"></i> 
                FGO: ' . $queue_pending . ' în așteptare
            </a>
        </li>';
    }
    
    return $output;
});

/**
 * Hook pentru adăugare widget în dashboard admin
 */
add_hook('AdminHomeWidgets', 1, function() {
    return new FGODashboardWidget();
});

/**
 * Widget pentru dashboard
 */
class FGODashboardWidget extends \WHMCS\Module\AbstractWidget
{
    protected $title = 'FGO Status';
    protected $description = 'Prezentare generală emitere facturi FGO';
    protected $weight = 150;
    protected $columns = 1;
    protected $cache = false;
    protected $cacheExpiry = 120;
    protected $requiredPermission = 'View Income Totals';
    
    public function getData()
    {
        $data = [];
        
        // Statistici pentru ultimele 24 ore
        $date_24h = date('Y-m-d H:i:s', strtotime('-24 hours'));
        
        $data['emise_24h'] = Capsule::table('mod_fgo_logs')
            ->where('status', 'success')
            ->where('created_at', '>', $date_24h)
            ->count();
        
        $data['erori_24h'] = Capsule::table('mod_fgo_logs')
            ->where('status', 'error')
            ->where('created_at', '>', $date_24h)
            ->count();
        
        $data['in_coada'] = Capsule::table('mod_fgo_queue')
            ->where('status', 'pending')
            ->count();
        
        // Top erori
        $data['top_errors'] = Capsule::table('mod_fgo_logs')
            ->select('message', Capsule::raw('COUNT(*) as count'))
            ->where('status', 'error')
            ->where('created_at', '>', $date_24h)
            ->groupBy('message')
            ->orderBy('count', 'desc')
            ->limit(3)
            ->get();
        
        return $data;
    }
    
    public function generateOutput($data)
    {
        $output = '<div class="widget-content-padded">';
        
        // Statistici principale
        $output .= '<div class="row">';
        $output .= '<div class="col-sm-4 text-center">
            <div class="item">
                <div class="data">' . $data['emise_24h'] . '</div>
                <div class="note">Emise (24h)</div>
            </div>
        </div>';
        $output .= '<div class="col-sm-4 text-center">
            <div class="item">
                <div class="data text-danger">' . $data['erori_24h'] . '</div>
                <div class="note">Erori (24h)</div>
            </div>
        </div>';
        $output .= '<div class="col-sm-4 text-center">
            <div class="item">
                <div class="data text-warning">' . $data['in_coada'] . '</div>
                <div class="note">În Coadă</div>
            </div>
        </div>';
        $output .= '</div>';
        
        // Top erori
        if (count($data['top_errors']) > 0) {
            $output .= '<hr>';
            $output .= '<h5>Top Erori (24h)</h5>';
            $output .= '<ul class="list-unstyled">';
            foreach ($data['top_errors'] as $error) {
                $output .= '<li>
                    <span class="badge">' . $error->count . '</span> 
                    ' . htmlspecialchars(substr($error->message, 0, 50)) . '...
                </li>';
            }
            $output .= '</ul>';
        }
        
        $output .= '<div class="text-center" style="margin-top: 15px;">
            <a href="addonmodules.php?module=fgo" class="btn btn-sm btn-default">
                <i class="fas fa-chart-line"></i> Vezi Dashboard Complet
            </a>
        </div>';
        
        $output .= '</div>';
        
        return $output;
    }
}

/**
 * Hook pentru procesare coadă prin cron
 */
add_hook('AfterCronJob', 1, function($vars) {
    // Verifică dacă e momentul să proceseze coada
    static $last_run = null;
    
    if ($last_run && (time() - $last_run) < 60) {
        return; // Rulează doar o dată pe minut
    }
    
    $last_run = time();
    
    // Obține configurația
    $module_config = Capsule::table('tbladdonmodules')
        ->where('module', 'fgo')
        ->pluck('value', 'setting');
    
    if (empty($module_config)) {
        return;
    }
    
    require_once ROOTDIR . '/modules/addons/fgo/fgo.php';
    
    $helper = new FGOHelper($module_config);
    
    // Procesează coada
    $processed = $helper->processQueue($module_config['batch_size'] ?? 10);
    
    if ($processed > 0) {
        logActivity("FGO Cron: Procesate {$processed} elemente din coadă");
    }
    
    // Curățare cache expirat
    $helper->clearExpiredCache();
    
    // Trimite notificări programate
    $notifications = Capsule::table('mod_fgo_notifications')
        ->where('sent', false)
        ->where('send_after', '<=', date('Y-m-d H:i:s'))
        ->get();
    
    foreach ($notifications as $notification) {
        if ($notification->type == 'report' && $module_config['daily_report'] == 'on') {
            // Generează și trimite raport zilnic
            $helper->sendDailyReport();
        } elseif ($notification->type == 'error' && !empty($module_config['admin_email'])) {
            // Trimite notificare erori
            mail(
                $module_config['admin_email'],
                $notification->subject,
                $notification->message,
                "From: noreply@" . $_SERVER['SERVER_NAME'] . "\r\n"
            );
        }
        
        // Marchează ca trimis
        Capsule::table('mod_fgo_notifications')
            ->where('id', $notification->id)
            ->update(['sent' => true]);
    }
});

/**
 * Hook pentru adăugare câmpuri personalizate în formular client
 */
add_hook('ClientDetailsValidation', 1, function($vars) {
    // Validare CUI/CNP dacă este configurat
    $module_config = Capsule::table('tbladdonmodules')
        ->where('module', 'fgo')
        ->pluck('value', 'setting');
    
    if (empty($module_config) || $module_config['validare_cui_ro'] != 'on') {
        return;
    }
    
    if (!empty($vars['tax_id']) && $vars['country'] == 'RO') {
        require_once ROOTDIR . '/modules/addons/fgo/fgo.php';
        
        $helper = new FGOHelper($module_config);
        $validation = $helper->validateFiscalCode($vars['tax_id']);
        
        if (!$validation['valid']) {
            return ['tax_id' => $validation['message']];
        }
    }
});

/**
 * Hook pentru programare raport zilnic
 */
add_hook('DailyCronJob', 1, function() {
    // Programează trimitere raport pentru ora 8:00
    $send_time = date('Y-m-d 08:00:00');
    
    $exists = Capsule::table('mod_fgo_notifications')
        ->where('type', 'report')
        ->whereDate('send_after', date('Y-m-d'))
        ->exists();
    
    if (!$exists) {
        Capsule::table('mod_fgo_notifications')->insert([
            'type' => 'report',
            'subject' => 'Raport zilnic FGO',
            'message' => '',
            'sent' => false,
            'send_after' => $send_time,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
});

/**
 * Hook pentru actualizare număr factură din FGO
 */
add_hook('InvoiceNumberGenerated', 1, function($vars) {
    // Dacă factura are deja număr din FGO, păstrează-l
    $fgo_invoice = Capsule::table('mod_fgo_logs')
        ->where('invoice_id', $vars['invoiceid'])
        ->where('status', 'success')
        ->orderBy('id', 'desc')
        ->first();
    
    if ($fgo_invoice && $fgo_invoice->fgo_numar) {
        return [
            'invoicenum' => $fgo_invoice->fgo_serie . $fgo_invoice->fgo_numar,
        ];
    }
});

/**
 * Hook pentru afișare avertizări în pagina de editare factură
 */
add_hook('AdminInvoicesEdit', 1, function($vars) {
    $invoice_id = $vars['invoiceid'];
    
    // Verifică probleme potențiale
    $warnings = [];
    
    // Client fără CUI/CNP
    $client = Capsule::table('tblclients')
        ->where('id', $vars['userid'])
        ->first();
    
    if ($client->country == 'RO' && empty($client->tax_id)) {
        $warnings[] = 'Clientul nu are CUI/CNP completat';
    }
    
    // Factură sub prag minim
    $module_config = Capsule::table('tbladdonmodules')
        ->where('module', 'fgo')
        ->pluck('value', 'setting');
    
    if (!empty($module_config['prag_minim']) && $vars['total'] < $module_config['prag_minim']) {
        $warnings[] = 'Valoare sub pragul minim FGO (' . $module_config['prag_minim'] . ' ' . $vars['currency'] . ')';
    }
    
    // Erori anterioare
    $last_error = Capsule::table('mod_fgo_logs')
        ->where('invoice_id', $invoice_id)
        ->where('status', 'error')
        ->orderBy('id', 'desc')
        ->first();
    
    if ($last_error) {
        $warnings[] = 'Ultima eroare FGO: ' . $last_error->message;
    }
    
    if (!empty($warnings)) {
        echo '<div class="alert alert-warning">
            <strong>Avertizări FGO:</strong>
            <ul>';
        foreach ($warnings as $warning) {
            echo '<li>' . htmlspecialchars($warning) . '</li>';
        }
        echo '</ul>
        </div>';
    }
});
