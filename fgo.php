<?php
/**
 * Modul WHMCS pentru integrare FGO
 * Versiune corectată - compatibilă cu toate versiunile WHMCS
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * Configurarea modulului
 */
function fgo_config() {
    return array(
        'name' => 'FGO Integration Pro',
        'description' => 'Modul pentru integrarea WHMCS cu FGO pentru emiterea automată a facturilor',
        'version' => '2.0',
        'author' => 'WHMCS FGO Integration',
        'fields' => array(
            'api_environment' => array(
                'FriendlyName' => 'Mediu API',
                'Type' => 'dropdown',
                'Options' => array(
                    'production' => 'Producție (https://api.fgo.ro/v1)',
                    'test' => 'Test (https://api-testuat.fgo.ro/v1)',
                ),
                'Default' => 'test',
                'Description' => 'Selectați mediul API',
            ),
            'cui_furnizor' => array(
                'FriendlyName' => 'CUI Furnizor',
                'Type' => 'text',
                'Size' => '20',
                'Description' => 'Codul Unic de Înregistrare al companiei dumneavoastră (fără RO)',
            ),
            'cheie_privata' => array(
                'FriendlyName' => 'Cheie Privată API',
                'Type' => 'password',
                'Size' => '50',
                'Description' => 'Cheia privată obținută din FGO -> Setări -> Utilizatori',
            ),
            'platform_url' => array(
                'FriendlyName' => 'URL Platformă',
                'Type' => 'text',
                'Size' => '50',
                'Default' => 'https://clienti.microdata.ro',
                'Description' => 'URL-ul platformei dumneavoastră',
            ),
            'serie_factura' => array(
                'FriendlyName' => 'Serie Factură',
                'Type' => 'text',
                'Size' => '10',
                'Default' => 'FV',
                'Description' => 'Seria pentru facturi fiscale',
            ),
            'serie_proforma' => array(
                'FriendlyName' => 'Serie Proformă',
                'Type' => 'text',
                'Size' => '10',
                'Default' => 'PRF',
                'Description' => 'Seria pentru facturi proformă',
            ),
            'tip_factura' => array(
                'FriendlyName' => 'Tip Factură Implicit',
                'Type' => 'dropdown',
                'Options' => array(
                    'Factura' => 'Factură',
                    'Proforma' => 'Proformă',
                ),
                'Default' => 'Factura',
            ),
            'valuta' => array(
                'FriendlyName' => 'Valută Implicită',
                'Type' => 'dropdown',
                'Options' => array(
                    'RON' => 'RON',
                    'EUR' => 'EUR',
                    'USD' => 'USD',
                ),
                'Default' => 'RON',
            ),
            'cota_tva' => array(
                'FriendlyName' => 'Cotă TVA Implicită (%)',
                'Type' => 'text',
                'Size' => '5',
                'Default' => '19',
                'Description' => 'Cota TVA standard (ex: 19, 9, 0)',
            ),
            'um_default' => array(
                'FriendlyName' => 'Unitate de Măsură',
                'Type' => 'text',
                'Size' => '10',
                'Default' => 'BUC',
                'Description' => 'Unitatea de măsură implicită',
            ),
            'emitere_automata' => array(
                'FriendlyName' => 'Emitere Automată',
                'Type' => 'yesno',
                'Description' => 'Emite automat factura în FGO când se creează în WHMCS',
            ),
            'text_suplimentar' => array(
                'FriendlyName' => 'Text Suplimentar',
                'Type' => 'textarea',
                'Rows' => '3',
                'Cols' => '60',
                'Description' => 'Text suplimentar care va apărea pe factură',
            ),
        )
    );
}

/**
 * Activare modul
 */
function fgo_activate() {
    try {
        // Creare tabel pentru log-uri
        if (!Capsule::schema()->hasTable('mod_fgo_logs')) {
            Capsule::schema()->create('mod_fgo_logs', function ($table) {
                $table->increments('id');
                $table->integer('invoice_id');
                $table->string('fgo_serie', 50)->nullable();
                $table->string('fgo_numar', 50)->nullable();
                $table->string('fgo_link', 255)->nullable();
                $table->enum('status', ['success', 'error']);
                $table->text('message')->nullable();
                $table->text('request_data')->nullable();
                $table->text('response_data')->nullable();
                $table->timestamps();
                
                $table->index('invoice_id');
                $table->index(['fgo_serie', 'fgo_numar']);
            });
        }
        
        return array('status' => 'success', 'description' => 'Modulul FGO a fost activat cu succes');
    } catch (Exception $e) {
        return array('status' => 'error', 'description' => 'Eroare la activare: ' . $e->getMessage());
    }
}

/**
 * Dezactivare modul
 */
function fgo_deactivate() {
    return array('status' => 'success', 'description' => 'Modulul FGO a fost dezactivat');
}

/**
 * Output principal
 */
function fgo_output($vars) {
    $action = isset($_GET['action']) ? $_GET['action'] : 'dashboard';
    
    // CSS inline
    echo '<style>
        .fgo-nav { margin-bottom: 20px; }
        .fgo-nav li a { padding: 12px 20px; }
        .fgo-nav li a i { margin-right: 5px; }
        .fgo-stat-card { background: #fff; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.12); padding: 20px; text-align: center; margin-bottom: 20px; }
        .fgo-stat-card h3 { margin: 10px 0; font-size: 32px; font-weight: 300; }
        .fgo-stat-card p { margin: 0; color: #666; }
        .fgo-actions .btn { margin: 0 2px; }
    </style>';
    
    echo '<h1>FGO Integration Pro <small>v' . $vars['version'] . '</small></h1>';
    
    // Verificare configurare
    if (empty($vars['cui_furnizor']) || empty($vars['cheie_privata'])) {
        echo '<div class="alert alert-warning">
            <strong>Atenție!</strong> Modulul nu este configurat complet. 
            Completați CUI Furnizor și Cheia Privată API în <a href="configaddonmods.php#fgo">setările modulului</a>.
        </div>';
    }
    
    // Meniu navigare
    echo '<ul class="nav nav-tabs fgo-nav">
        <li class="' . ($action == 'dashboard' ? 'active' : '') . '">
            <a href="addonmodules.php?module=fgo">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
        </li>
        <li class="' . ($action == 'logs' ? 'active' : '') . '">
            <a href="addonmodules.php?module=fgo&action=logs">
                <i class="fas fa-list"></i> Log-uri
            </a>
        </li>
        <li class="' . ($action == 'test' ? 'active' : '') . '">
            <a href="addonmodules.php?module=fgo&action=test">
                <i class="fas fa-plug"></i> Test Conexiune
            </a>
        </li>
        <li class="' . ($action == 'manual' ? 'active' : '') . '">
            <a href="addonmodules.php?module=fgo&action=manual">
                <i class="fas fa-paper-plane"></i> Emitere Manuală
            </a>
        </li>
    </ul>';
    
    switch ($action) {
        case 'logs':
            fgo_show_logs($vars);
            break;
            
        case 'test':
            fgo_test_connection($vars);
            break;
            
        case 'manual':
            fgo_manual_emit($vars);
            break;
            
        default:
            fgo_dashboard($vars);
            break;
    }
}

/**
 * Dashboard
 */
function fgo_dashboard($vars) {
    echo '<h2>Dashboard</h2>';
    
    // Statistici
    $total_logs = Capsule::table('mod_fgo_logs')->count();
    $success_logs = Capsule::table('mod_fgo_logs')->where('status', 'success')->count();
    $error_logs = Capsule::table('mod_fgo_logs')->where('status', 'error')->count();
    $today_logs = Capsule::table('mod_fgo_logs')
        ->whereDate('created_at', date('Y-m-d'))
        ->where('status', 'success')
        ->count();
    
    echo '<div class="row">
        <div class="col-md-3">
            <div class="fgo-stat-card">
                <i class="fas fa-file-invoice fa-2x text-primary"></i>
                <h3>' . $total_logs . '</h3>
                <p>Total Facturi Procesate</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="fgo-stat-card">
                <i class="fas fa-check-circle fa-2x text-success"></i>
                <h3 class="text-success">' . $success_logs . '</h3>
                <p>Emise cu Succes</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="fgo-stat-card">
                <i class="fas fa-exclamation-triangle fa-2x text-danger"></i>
                <h3 class="text-danger">' . $error_logs . '</h3>
                <p>Erori</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="fgo-stat-card">
                <i class="fas fa-calendar-day fa-2x text-info"></i>
                <h3 class="text-info">' . $today_logs . '</h3>
                <p>Emise Astăzi</p>
            </div>
        </div>
    </div>';
    
    // Facturi neemise
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
        echo '<div class="alert alert-info">
            <i class="fas fa-info-circle"></i> Există <strong>' . $unissued . '</strong> facturi neemise în FGO. 
            <a href="addonmodules.php?module=fgo&action=manual" class="btn btn-sm btn-primary pull-right">
                Vezi Facturi
            </a>
            <div class="clearfix"></div>
        </div>';
    }
    
    // Ultimele operațiuni
    echo '<h3>Ultimele Operațiuni</h3>';
    echo '<div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>ID Factură</th>
                    <th>Serie/Număr FGO</th>
                    <th>Status</th>
                    <th>Mesaj</th>
                    <th>Data</th>
                    <th>Acțiuni</th>
                </tr>
            </thead>
            <tbody>';
    
    $recent_logs = Capsule::table('mod_fgo_logs')
        ->orderBy('created_at', 'desc')
        ->limit(10)
        ->get();
    
    if (count($recent_logs) > 0) {
        foreach ($recent_logs as $log) {
            $status_class = $log->status == 'success' ? 'success' : 'danger';
            echo '<tr>
                <td>
                    <a href="invoices.php?action=edit&id=' . $log->invoice_id . '">
                        #' . $log->invoice_id . '
                    </a>
                </td>
                <td>' . ($log->fgo_serie ?? '-') . '/' . ($log->fgo_numar ?? '-') . '</td>
                <td><span class="label label-' . $status_class . '">' . ucfirst($log->status) . '</span></td>
                <td>' . htmlspecialchars($log->message ?? '') . '</td>
                <td>' . $log->created_at . '</td>
                <td class="fgo-actions">';
            
            if ($log->fgo_link) {
                echo '<a href="' . $log->fgo_link . '" target="_blank" class="btn btn-xs btn-info">
                    <i class="fas fa-external-link-alt"></i> Vezi
                </a>';
            }
            
            if ($log->status == 'error') {
                echo '<a href="addonmodules.php?module=fgo&action=manual&invoice_id=' . $log->invoice_id . '" 
                    class="btn btn-xs btn-warning">
                    <i class="fas fa-redo"></i> Reîncearcă
                </a>';
            }
            
            echo '</td>
            </tr>';
        }
    } else {
        echo '<tr><td colspan="6" class="text-center">Nu există înregistrări</td></tr>';
    }
    
    echo '</tbody></table></div>';
}

/**
 * Afișare log-uri - VERSIUNE CORECTATĂ
 */
function fgo_show_logs($vars) {
    echo '<h2>Log-uri FGO</h2>';
    
    // Filtre
    $status_filter = isset($_GET['status']) ? $_GET['status'] : '';
    $invoice_filter = isset($_GET['invoice_id']) ? intval($_GET['invoice_id']) : '';
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $per_page = 20;
    
    echo '<form method="get" class="form-inline" style="margin-bottom: 20px;">
        <input type="hidden" name="module" value="fgo">
        <input type="hidden" name="action" value="logs">
        
        <div class="form-group">
            <label>Status:</label>
            <select name="status" class="form-control">
                <option value="">Toate</option>
                <option value="success"' . ($status_filter == 'success' ? ' selected' : '') . '>Success</option>
                <option value="error"' . ($status_filter == 'error' ? ' selected' : '') . '>Error</option>
            </select>
        </div>
        
        <div class="form-group">
            <label>ID Factură:</label>
            <input type="number" name="invoice_id" class="form-control" value="' . $invoice_filter . '">
        </div>
        
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-search"></i> Filtrează
        </button>
        <a href="addonmodules.php?module=fgo&action=logs" class="btn btn-default">
            <i class="fas fa-times"></i> Resetează
        </a>
    </form>';
    
    // Query pentru total
    $query = Capsule::table('mod_fgo_logs');
    
    if ($status_filter) {
        $query->where('status', $status_filter);
    }
    
    if ($invoice_filter) {
        $query->where('invoice_id', $invoice_filter);
    }
    
    $total = $query->count();
    $total_pages = ceil($total / $per_page);
    $offset = ($page - 1) * $per_page;
    
    // Query pentru date
    $logs = $query->orderBy('created_at', 'desc')
        ->skip($offset)
        ->take($per_page)
        ->get();
    
    // Tabel
    echo '<div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>ID Factură</th>
                    <th>Serie/Număr FGO</th>
                    <th>Status</th>
                    <th>Mesaj</th>
                    <th>Link</th>
                    <th>Data</th>
                    <th>Acțiuni</th>
                </tr>
            </thead>
            <tbody>';
    
    if (count($logs) > 0) {
        foreach ($logs as $log) {
            $status_class = $log->status == 'success' ? 'success' : 'danger';
            echo '<tr>
                <td>' . $log->id . '</td>
                <td>
                    <a href="invoices.php?action=edit&id=' . $log->invoice_id . '">
                        #' . $log->invoice_id . '
                    </a>
                </td>
                <td>' . ($log->fgo_serie ?? '-') . '/' . ($log->fgo_numar ?? '-') . '</td>
                <td><span class="label label-' . $status_class . '">' . ucfirst($log->status) . '</span></td>
                <td>' . htmlspecialchars(substr($log->message ?? '', 0, 50)) . '...</td>
                <td>' . ($log->fgo_link ? '<a href="' . $log->fgo_link . '" target="_blank">Vezi</a>' : '-') . '</td>
                <td>' . $log->created_at . '</td>
                <td>
                    <button type="button" class="btn btn-xs btn-info" onclick="viewLogDetails(' . $log->id . ')">
                        <i class="fas fa-eye"></i> Detalii
                    </button>
                </td>
            </tr>';
        }
    } else {
        echo '<tr><td colspan="8" class="text-center">Nu au fost găsite înregistrări</td></tr>';
    }
    
    echo '</tbody></table></div>';
    
    // Paginare manuală
    if ($total_pages > 1) {
        echo '<div class="text-center">
            <ul class="pagination">';
        
        // Link anterior
        if ($page > 1) {
            echo '<li><a href="addonmodules.php?module=fgo&action=logs&page=' . ($page - 1) . 
                 '&status=' . $status_filter . '&invoice_id=' . $invoice_filter . '">&laquo;</a></li>';
        }
        
        // Pagini
        for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++) {
            echo '<li' . ($i == $page ? ' class="active"' : '') . '>
                <a href="addonmodules.php?module=fgo&action=logs&page=' . $i . 
                '&status=' . $status_filter . '&invoice_id=' . $invoice_filter . '">' . $i . '</a>
            </li>';
        }
        
        // Link următor
        if ($page < $total_pages) {
            echo '<li><a href="addonmodules.php?module=fgo&action=logs&page=' . ($page + 1) . 
                 '&status=' . $status_filter . '&invoice_id=' . $invoice_filter . '">&raquo;</a></li>';
        }
        
        echo '</ul>
        </div>';
    }
    
    // Modal pentru detalii
    echo '<div class="modal fade" id="logDetailsModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                    <h4 class="modal-title">Detalii Log</h4>
                </div>
                <div class="modal-body" id="logDetailsContent">
                    Se încarcă...
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Închide</button>
                </div>
            </div>
        </div>
    </div>';
    
    // JavaScript
    echo '<script>
    function viewLogDetails(id) {
        $("#logDetailsModal").modal("show");
        $("#logDetailsContent").load("addonmodules.php?module=fgo&action=ajax&method=log_details&id=" + id);
    }
    </script>';
}

/**
 * Test conexiune
 */
function fgo_test_connection($vars) {
    echo '<h2>Test Conexiune FGO</h2>';
    
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $api_url = $vars['api_environment'] == 'production' 
            ? 'https://api.fgo.ro/v1' 
            : 'https://api-testuat.fgo.ro/v1';
        
        echo '<h4>Test 1: Verificare conectivitate API</h4>';
        
        $test_url = $api_url . '/nomenclator/tva';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $test_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            echo '<div class="alert alert-danger">
                <i class="fas fa-times-circle"></i> Eroare cURL: ' . htmlspecialchars($error) . '
            </div>';
        } elseif ($http_code == 200) {
            echo '<div class="alert alert-success">
                <i class="fas fa-check-circle"></i> Conexiune reușită! API-ul FGO este accesibil.
            </div>';
            
            $data = json_decode($response, true);
            if ($data && isset($data['List'])) {
                echo '<h5>Cote TVA disponibile:</h5>
                <div class="well">';
                foreach ($data['List'] as $tva) {
                    echo '<span class="label label-info" style="margin: 2px;">' . 
                         htmlspecialchars($tva['Nume'] ?? $tva) . '</span> ';
                }
                echo '</div>';
            }
        } else {
            echo '<div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i> Cod HTTP: ' . $http_code . '
            </div>';
            echo '<pre>' . htmlspecialchars($response) . '</pre>';
        }
        
        // Test 2: Verificare hash
        echo '<h4>Test 2: Generare hash</h4>';
        $test_client = 'Test Client SRL';
        $hash = strtoupper(sha1($vars['cui_furnizor'] . $vars['cheie_privata'] . $test_client));
        
        echo '<div class="well">
            <strong>CUI:</strong> ' . htmlspecialchars($vars['cui_furnizor']) . '<br>
            <strong>Cheie (primele 4 caractere):</strong> ' . substr($vars['cheie_privata'], 0, 4) . '***<br>
            <strong>Client test:</strong> ' . $test_client . '<br>
            <strong>Hash generat:</strong> <code>' . $hash . '</code>
        </div>';
    }
    
    echo '<form method="post">
        <p>Acest test va verifica:</p>
        <ul>
            <li>Conectivitatea la API-ul FGO</li>
            <li>Validitatea mediului selectat (' . $vars['api_environment'] . ')</li>
            <li>Generarea corectă a hash-ului</li>
        </ul>
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-plug"></i> Testează Conexiunea
        </button>
    </form>';
}

/**
 * Emitere manuală
 */
function fgo_manual_emit($vars) {
    echo '<h2>Emitere Manuală Factură în FGO</h2>';
    
    $invoice_id = isset($_GET['invoice_id']) ? intval($_GET['invoice_id']) : 0;
    
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && $invoice_id) {
        $result = fgo_emit_invoice($invoice_id, $vars);
        
        if ($result['success']) {
            echo '<div class="alert alert-success">
                <i class="fas fa-check-circle"></i> Factura a fost emisă cu succes în FGO!<br>
                <strong>Serie:</strong> ' . htmlspecialchars($result['serie']) . '<br>
                <strong>Număr:</strong> ' . htmlspecialchars($result['numar']) . '<br>';
            if ($result['link']) {
                echo '<a href="' . htmlspecialchars($result['link']) . '" target="_blank" class="btn btn-sm btn-primary">
                    <i class="fas fa-external-link-alt"></i> Vezi Factura
                </a>';
            }
            echo '</div>';
        } else {
            echo '<div class="alert alert-danger">
                <i class="fas fa-times-circle"></i> Eroare: ' . htmlspecialchars($result['message']) . '
            </div>';
        }
    }
    
    // Formular selectare factură
    echo '<form method="get" class="form-inline">
        <input type="hidden" name="module" value="fgo">
        <input type="hidden" name="action" value="manual">
        <div class="form-group">
            <label>ID Factură WHMCS:</label>
            <input type="number" name="invoice_id" class="form-control" value="' . $invoice_id . '" required>
        </div>
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-search"></i> Încarcă Factură
        </button>
    </form>';
    
    if ($invoice_id) {
        // Obține detalii factură
        $invoice = Capsule::table('tblinvoices')->where('id', $invoice_id)->first();
        
        if ($invoice) {
            $client = Capsule::table('tblclients')->where('id', $invoice->userid)->first();
            $items = Capsule::table('tblinvoiceitems')->where('invoiceid', $invoice_id)->get();
            
            // Verifică dacă a fost deja emisă
            $existing = Capsule::table('mod_fgo_logs')
                ->where('invoice_id', $invoice_id)
                ->where('status', 'success')
                ->orderBy('id', 'desc')
                ->first();
            
            echo '<hr>';
            
            if ($existing) {
                echo '<div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i> 
                    Această factură a fost deja emisă în FGO!<br>
                    <strong>Serie:</strong> ' . $existing->fgo_serie . '<br>
                    <strong>Număr:</strong> ' . $existing->fgo_numar . '<br>';
                if ($existing->fgo_link) {
                    echo '<a href="' . $existing->fgo_link . '" target="_blank" class="btn btn-sm btn-info">
                        <i class="fas fa-external-link-alt"></i> Vezi Factura Existentă
                    </a>';
                }
                echo '</div>';
            }
            
            echo '<h3>Detalii Factură #' . $invoice_id . '</h3>';
            
            echo '<div class="row">
                <div class="col-md-6">
                    <div class="panel panel-default">
                        <div class="panel-heading">
                            <h4 class="panel-title">Date Client</h4>
                        </div>
                        <div class="panel-body">
                            <p><strong>Nume:</strong> ' . 
                                htmlspecialchars($client->companyname ?: $client->firstname . ' ' . $client->lastname) . '</p>
                            <p><strong>Email:</strong> ' . htmlspecialchars($client->email) . '</p>
                            <p><strong>Telefon:</strong> ' . htmlspecialchars($client->phonenumber) . '</p>
                            <p><strong>CUI/CNP:</strong> ' . 
                                ($client->tax_id ? htmlspecialchars($client->tax_id) : '<span class="text-danger">Lipsește!</span>') . '</p>
                            <p><strong>Adresă:</strong> ' . htmlspecialchars($client->address1 . ' ' . $client->address2) . '</p>
                            <p><strong>Oraș:</strong> ' . htmlspecialchars($client->city) . '</p>
                            <p><strong>Județ:</strong> ' . htmlspecialchars($client->state) . '</p>
                            <p><strong>Țară:</strong> ' . htmlspecialchars($client->country) . '</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="panel panel-default">
                        <div class="panel-heading">
                            <h4 class="panel-title">Detalii Factură</h4>
                        </div>
                        <div class="panel-body">
                            <p><strong>Data:</strong> ' . $invoice->date . '</p>
                            <p><strong>Data scadență:</strong> ' . $invoice->duedate . '</p>
                            <p><strong>Total:</strong> ' . $invoice->total . ' ' . $invoice->currency . '</p>
                            <p><strong>Status:</strong> ' . $invoice->status . '</p>
                            <p><strong>Număr WHMCS:</strong> ' . ($invoice->invoicenum ?: 'N/A') . '</p>
                        </div>
                    </div>
                </div>
            </div>';
            
            echo '<h4>Articole Factură</h4>
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Descriere</th>
                        <th>Cantitate</th>
                        <th>Preț</th>
                    </tr>
                </thead>
                <tbody>';
            
            foreach ($items as $item) {
                echo '<tr>
                    <td>' . htmlspecialchars($item->description) . '</td>
                    <td>1</td>
                    <td>' . $item->amount . ' ' . $invoice->currency . '</td>
                </tr>';
            }
            
            echo '</tbody></table>';
            
            // Verificări preliminare
            $warnings = array();
            
            if (empty($client->tax_id) && $client->country == 'RO') {
                $warnings[] = 'Clientul nu are CUI/CNP completat';
            }
            
            if (empty($client->state) && $client->country == 'RO') {
                $warnings[] = 'Clientul nu are județ completat';
            }
            
            if ($invoice->total == 0) {
                $warnings[] = 'Factura are valoare 0';
            }
            
            if (!empty($warnings)) {
                echo '<div class="alert alert-warning">
                    <strong>Avertizări:</strong>
                    <ul>';
                foreach ($warnings as $warning) {
                    echo '<li>' . $warning . '</li>';
                }
                echo '</ul>
                </div>';
            }
            
            echo '<form method="post">
                <button type="submit" class="btn btn-success' . ($existing ? '' : ' btn-lg') . '" ' . 
                ($existing ? 'onclick="return confirm(\'Factura a fost deja emisă. Continuați?\')"' : '') . '>
                    <i class="fas fa-paper-plane"></i> ' . ($existing ? 'Re-emite' : 'Emite') . ' în FGO
                </button>
            </form>';
        } else {
            echo '<div class="alert alert-danger">Factura nu a fost găsită!</div>';
        }
    } else {
        // Afișează facturi recente neemise
        echo '<hr><h4>Facturi Recente Neemise</h4>';
        
        $unissued_invoices = Capsule::table('tblinvoices as i')
            ->select('i.*', 'c.firstname', 'c.lastname', 'c.companyname')
            ->leftJoin('mod_fgo_logs as l', function($join) {
                $join->on('i.id', '=', 'l.invoice_id')
                    ->where('l.status', '=', 'success');
            })
            ->join('tblclients as c', 'i.userid', '=', 'c.id')
            ->whereNull('l.id')
            ->where('i.status', '!=', 'Draft')
            ->where('i.status', '!=', 'Cancelled')
            ->orderBy('i.id', 'desc')
            ->limit(10)
            ->get();
        
        if (count($unissued_invoices) > 0) {
            echo '<table class="table table-striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Client</th>
                        <th>Data</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Acțiuni</th>
                    </tr>
                </thead>
                <tbody>';
            
            foreach ($unissued_invoices as $inv) {
                $client_name = $inv->companyname ?: $inv->firstname . ' ' . $inv->lastname;
                echo '<tr>
                    <td>#' . $inv->id . '</td>
                    <td>' . htmlspecialchars($client_name) . '</td>
                    <td>' . $inv->date . '</td>
                    <td>' . $inv->total . ' ' . $inv->currency . '</td>
                    <td>' . $inv->status . '</td>
                    <td>
                        <a href="addonmodules.php?module=fgo&action=manual&invoice_id=' . $inv->id . '" 
                           class="btn btn-xs btn-primary">
                            <i class="fas fa-paper-plane"></i> Emite
                        </a>
                    </td>
                </tr>';
            }
            
            echo '</tbody></table>';
        } else {
            echo '<p class="text-muted">Nu există facturi neemise.</p>';
        }
    }
}

/**
 * Funcție pentru emitere factură
 */
function fgo_emit_invoice($invoice_id, $config) {
    // Obține datele facturii
    $invoice = Capsule::table('tblinvoices')->where('id', $invoice_id)->first();
    if (!$invoice) {
        return ['success' => false, 'message' => 'Factura nu a fost găsită'];
    }
    
    // Obține datele clientului
    $client = Capsule::table('tblclients')->where('id', $invoice->userid)->first();
    if (!$client) {
        return ['success' => false, 'message' => 'Clientul nu a fost găsit'];
    }
    
    // Obține articolele facturii
    $items = Capsule::table('tblinvoiceitems')->where('invoiceid', $invoice_id)->get();
    
    // Pregătește datele pentru API
    $client_name = $client->companyname ?: $client->firstname . ' ' . $client->lastname;
    $hash = strtoupper(sha1($config['cui_furnizor'] . $config['cheie_privata'] . $client_name));
    
    $data = [
        'CodUnic' => $config['cui_furnizor'],
        'Hash' => $hash,
        'Serie' => $config['serie_factura'],
        'Valuta' => $invoice->currency ?: $config['valuta'],
        'TipFactura' => $config['tip_factura'],
        'DataEmitere' => date('Y-m-d', strtotime($invoice->date)),
        'DataScadenta' => date('Y-m-d', strtotime($invoice->duedate)),
        'Text' => $config['text_suplimentar'],
        'PlatformaUrl' => $config['platform_url'],
        'VerificareDuplicat' => true,
        'IdExtern' => strval($invoice_id),
        
        // Date client
        'Client' => [
            'Denumire' => $client_name,
            'CodUnic' => $client->tax_id ?: '',
            'Email' => $client->email,
            'Telefon' => $client->phonenumber,
            'Tara' => fgo_map_country($client->country),
            'Judet' => $client->state,
            'Localitate' => $client->city,
            'Adresa' => trim($client->address1 . ' ' . $client->address2),
            'Tip' => $client->companyname ? 'PJ' : 'PF',
            'IdExtern' => $client->id,
        ],
        
        // Articole
        'Continut' => []
    ];
    
    // Adaugă articolele
    foreach ($items as $item) {
        $data['Continut'][] = [
            'Denumire' => $item->description,
            'CodArticol' => 'WHMCS-' . $item->id,
            'Descriere' => $item->notes ?: $item->description,
            'PretUnitar' => floatval($item->amount),
            'UM' => $config['um_default'],
            'NrProduse' => 1,
            'CotaTVA' => floatval($config['cota_tva']),
        ];
    }
    
    // API URL
    $api_url = $config['api_environment'] == 'production' 
        ? 'https://api.fgo.ro/v1' 
        : 'https://api-testuat.fgo.ro/v1';
    
    // Trimite request
    $ch = curl_init($api_url . '/factura/emitere');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json',
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    // Log request pentru debugging
    $log_data = [
        'invoice_id' => $invoice_id,
        'request_data' => json_encode($data),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    
    if ($error) {
        $log_data['status'] = 'error';
        $log_data['message'] = 'Eroare cURL: ' . $error;
        Capsule::table('mod_fgo_logs')->insert($log_data);
        return ['success' => false, 'message' => 'Eroare de conexiune: ' . $error];
    }
    
    $result = json_decode($response, true);
    
    if ($http_code == 200 && isset($result['Success']) && $result['Success']) {
        // Succes
        $log_data['status'] = 'success';
        $log_data['message'] = 'Factură emisă cu succes';
        $log_data['fgo_serie'] = $result['Factura']['Serie'] ?? '';
        $log_data['fgo_numar'] = $result['Factura']['Numar'] ?? '';
        $log_data['fgo_link'] = $result['Factura']['Link'] ?? '';
        $log_data['response_data'] = json_encode($result);
        
        Capsule::table('mod_fgo_logs')->insert($log_data);
        
        // Actualizează numărul facturii în WHMCS
        if (!empty($result['Factura']['Numar'])) {
            $invoice_num = $result['Factura']['Serie'] . $result['Factura']['Numar'];
            Capsule::table('tblinvoices')
                ->where('id', $invoice_id)
                ->update(['invoicenum' => $invoice_num]);
        }
        
        return [
            'success' => true,
            'serie' => $result['Factura']['Serie'],
            'numar' => $result['Factura']['Numar'],
            'link' => $result['Factura']['Link'] ?? '',
        ];
    } else {
        // Eroare
        $error_message = $result['Message'] ?? 'Răspuns invalid de la server (HTTP ' . $http_code . ')';
        
        $log_data['status'] = 'error';
        $log_data['message'] = $error_message;
        $log_data['response_data'] = $response;
        
        Capsule::table('mod_fgo_logs')->insert($log_data);
        
        return ['success' => false, 'message' => $error_message];
    }
}

/**
 * Mapare țară
 */
function fgo_map_country($country_code) {
    $map = [
        'RO' => 'Romania',
        'US' => 'United States',
        'GB' => 'United Kingdom',
        'DE' => 'Germany',
        'FR' => 'France',
        'IT' => 'Italy',
        'ES' => 'Spain',
        // Adaugă alte mapări după necesitate
    ];
    
    return $map[$country_code] ?? $country_code;
}

// Handler pentru request-uri AJAX
if (isset($_GET['action']) && $_GET['action'] == 'ajax') {
    $method = $_GET['method'] ?? '';
    
    if ($method == 'log_details' && isset($_GET['id'])) {
        $log = Capsule::table('mod_fgo_logs')->where('id', intval($_GET['id']))->first();
        
        if ($log) {
            echo '<div class="row">
                <div class="col-md-6">
                    <p><strong>ID Factură:</strong> ' . $log->invoice_id . '</p>
                    <p><strong>Status:</strong> <span class="label label-' . 
                        ($log->status == 'success' ? 'success' : 'danger') . '">' . 
                        ucfirst($log->status) . '</span></p>
                    <p><strong>Data:</strong> ' . $log->created_at . '</p>
                </div>
                <div class="col-md-6">
                    <p><strong>FGO Serie/Număr:</strong> ' . 
                        ($log->fgo_serie ?? '-') . '/' . ($log->fgo_numar ?? '-') . '</p>
                    <p><strong>Link:</strong> ' . 
                        ($log->fgo_link ? '<a href="' . $log->fgo_link . '" target="_blank">' . 
                        $log->fgo_link . '</a>' : '-') . '</p>
                </div>
            </div>
            <hr>
            <p><strong>Mesaj:</strong><br>' . nl2br(htmlspecialchars($log->message ?? '')) . '</p>';
            
            if ($log->request_data) {
                $request = json_decode($log->request_data, true);
                if ($request) {
                    // Ascunde date sensibile
                    if (isset($request['Hash'])) {
                        $request['Hash'] = substr($request['Hash'], 0, 8) . '...';
                    }
                    echo '<hr><h5>Date trimise:</h5>
                    <pre style="max-height: 300px; overflow-y: auto;">' . 
                    htmlspecialchars(json_encode($request, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . 
                    '</pre>';
                }
            }
            
            if ($log->response_data) {
                echo '<hr><h5>Răspuns primit:</h5>
                <pre style="max-height: 300px; overflow-y: auto;">' . 
                htmlspecialchars($log->response_data) . 
                '</pre>';
            }
        } else {
            echo '<div class="alert alert-danger">Log-ul nu a fost găsit!</div>';
        }
    }
    
    exit;
}