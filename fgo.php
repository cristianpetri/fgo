<?php
/**
 * Modul WHMCS pentru integrare FGO
 * Versiune: 1.0
 * Autor: WHMCS FGO Integration
 * 
 * Instalare:
 * 1. Creați folderul /modules/addons/fgo/ în directorul WHMCS
 * 2. Plasați acest fișier ca fgo.php în acel folder
 * 3. Activați modulul din WHMCS Admin -> Setup -> Addon Modules
 * 4. Configurați setările modulului
 * 5. Creați hook-urile necesare (vezi fișierul hooks.php)
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
        'name' => 'FGO Integration',
        'description' => 'Modul pentru integrarea WHMCS cu FGO pentru emiterea automată a facturilor',
        'version' => '1.0',
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
                'Description' => 'Codul Unic de Înregistrare al companiei dumneavoastră',
            ),
            'cheie_privata' => array(
                'FriendlyName' => 'Cheie Privată API',
                'Type' => 'password',
                'Size' => '50',
                'Description' => 'Cheia privată obținută din FGO -> Setări -> Utilizatori',
            ),
            'serie_factura' => array(
                'FriendlyName' => 'Serie Factură',
                'Type' => 'text',
                'Size' => '10',
                'Default' => 'FV',
                'Description' => 'Seria definită în FGO -> Setări -> Serii documente',
            ),
            'valuta' => array(
                'FriendlyName' => 'Valută',
                'Type' => 'dropdown',
                'Options' => array(
                    'RON' => 'RON',
                    'EUR' => 'EUR',
                    'USD' => 'USD',
                ),
                'Default' => 'RON',
            ),
            'tip_factura' => array(
                'FriendlyName' => 'Tip Factură',
                'Type' => 'dropdown',
                'Options' => array(
                    'Factura' => 'Factură',
                    'Proforma' => 'Proformă',
                ),
                'Default' => 'Factura',
            ),
            'cota_tva' => array(
                'FriendlyName' => 'Cotă TVA (%)',
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
            'allow_incasare' => array(
                'FriendlyName' => 'Înregistrare Încasări',
                'Type' => 'yesno',
                'Description' => 'Înregistrează automat încasările în FGO (doar pentru Premium/Enterprise)',
            ),
            'delay_email' => array(
                'FriendlyName' => 'Amână Email Factură',
                'Type' => 'yesno',
                'Description' => 'Trimite email-ul cu factura doar după emiterea cu succes în FGO',
            ),
            'direct_invoice_categories' => array(
                'FriendlyName' => 'Categorii Factură Directă',
                'Type' => 'text',
                'Size' => '50',
                'Description' => 'ID-uri categorii separate prin virgulă (ex: 1,3,5) pentru care se emit direct facturi fiscale',
            ),
            'serie_proforma' => array(
                'FriendlyName' => 'Serie Proformă',
                'Type' => 'text',
                'Size' => '10',
                'Default' => 'PRF',
                'Description' => 'Seria pentru proforme (dacă diferă de facturi)',
            ),
            'text_suplimentar' => array(
                'FriendlyName' => 'Text Suplimentar',
                'Type' => 'textarea',
                'Rows' => '3',
                'Cols' => '60',
                'Description' => 'Text suplimentar care va apărea pe factură',
            ),
            'platform_url' => array(
                'FriendlyName' => 'URL Platformă',
                'Type' => 'text',
                'Size' => '50',
                'Default' => $_SERVER['HTTP_HOST'] ?? 'https://www.site.ro',
                'Description' => 'URL-ul platformei dumneavoastră',
            ),
        )
    );
}

/**
 * Activare modul
 */
function fgo_activate() {
    // Creare tabel pentru log-uri
    try {
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
    } catch (Exception $e) {
        // Tabelul există deja
    }
    
    // Creare tabel pentru email-uri amânate
    try {
        Capsule::schema()->create('mod_fgo_delayed_emails', function ($table) {
            $table->increments('id');
            $table->integer('invoice_id');
            $table->string('template', 100);
            $table->boolean('sent')->default(false);
            $table->timestamps();
            
            $table->index('invoice_id');
            $table->index('sent');
        });
    } catch (Exception $e) {
        // Tabelul există deja
    }
    
    // Creare câmp personalizat pentru clienți
    $field_exists = Capsule::table('tblcustomfields')
        ->where('type', 'client')
        ->where('fieldname', 'Factură Fiscală Directă')
        ->exists();
    
    if (!$field_exists) {
        Capsule::table('tblcustomfields')->insert([
            'type' => 'client',
            'relid' => 0,
            'fieldname' => 'Factură Fiscală Directă',
            'fieldtype' => 'tickbox',
            'description' => 'Bifați pentru a primi direct factură fiscală în loc de proformă',
            'fieldoptions' => '',
            'regexpr' => '',
            'adminonly' => '',
            'required' => '',
            'showorder' => 'on',
            'showinvoice' => '',
            'sortorder' => 99,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
    
    return array('status' => 'success', 'description' => 'Modulul FGO a fost activat cu succes');
}

/**
 * Dezactivare modul
 */
function fgo_deactivate() {
    // Opțional: păstrăm tabelul pentru istoric
    // Capsule::schema()->dropIfExists('mod_fgo_logs');
    
    return array('status' => 'success', 'description' => 'Modulul FGO a fost dezactivat');
}

/**
 * Actualizare modul
 */
function fgo_upgrade($vars) {
    $currentversion = $vars['version'];
    
    // Adăugați aici logica pentru actualizări viitoare
    
    return array('status' => 'success', 'description' => 'Modulul a fost actualizat');
}

/**
 * Pagina principală admin
 */
function fgo_output($vars) {
    $modulelink = $vars['modulelink'];
    $version = $vars['version'];
    
    // Verificare acțiune
    $action = isset($_GET['action']) ? $_GET['action'] : 'dashboard';
    
    echo '<div class="container-fluid">';
    
    // Meniu navigare
    echo '<ul class="nav nav-tabs" style="margin-bottom: 20px;">
        <li class="' . ($action == 'dashboard' ? 'active' : '') . '"><a href="' . $modulelink . '">Dashboard</a></li>
        <li class="' . ($action == 'logs' ? 'active' : '') . '"><a href="' . $modulelink . '&action=logs">Log-uri</a></li>
        <li class="' . ($action == 'test' ? 'active' : '') . '"><a href="' . $modulelink . '&action=test">Test Conexiune</a></li>
        <li class="' . ($action == 'manual' ? 'active' : '') . '"><a href="' . $modulelink . '&action=manual">Emitere Manuală</a></li>
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
    
    echo '</div>';
}

/**
 * Dashboard
 */
function fgo_dashboard($vars) {
    echo '<h2>FGO Integration Dashboard</h2>';
    
    // Statistici
    $total_logs = Capsule::table('mod_fgo_logs')->count();
    $success_logs = Capsule::table('mod_fgo_logs')->where('status', 'success')->count();
    $error_logs = Capsule::table('mod_fgo_logs')->where('status', 'error')->count();
    
    echo '<div class="row">
        <div class="col-md-4">
            <div class="panel panel-default">
                <div class="panel-heading">
                    <h3 class="panel-title">Total Facturi Procesate</h3>
                </div>
                <div class="panel-body">
                    <h2 class="text-center">' . $total_logs . '</h2>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="panel panel-success">
                <div class="panel-heading">
                    <h3 class="panel-title">Emise cu Succes</h3>
                </div>
                <div class="panel-body">
                    <h2 class="text-center text-success">' . $success_logs . '</h2>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="panel panel-danger">
                <div class="panel-heading">
                    <h3 class="panel-title">Erori</h3>
                </div>
                <div class="panel-body">
                    <h2 class="text-center text-danger">' . $error_logs . '</h2>
                </div>
            </div>
        </div>
    </div>';
    
    // Ultimele 10 log-uri
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
                </tr>
            </thead>
            <tbody>';
    
    $recent_logs = Capsule::table('mod_fgo_logs')
        ->orderBy('created_at', 'desc')
        ->limit(10)
        ->get();
    
    foreach ($recent_logs as $log) {
        $status_class = $log->status == 'success' ? 'success' : 'danger';
        echo '<tr>
            <td>' . $log->invoice_id . '</td>
            <td>' . ($log->fgo_serie ?? '-') . '/' . ($log->fgo_numar ?? '-') . '</td>
            <td><span class="label label-' . $status_class . '">' . ucfirst($log->status) . '</span></td>
            <td>' . htmlspecialchars($log->message ?? '') . '</td>
            <td>' . $log->created_at . '</td>
        </tr>';
    }
    
    echo '</tbody></table></div>';
}

/**
 * Vizualizare log-uri
 */
function fgo_show_logs($vars) {
    echo '<h2>Log-uri FGO</h2>';
    
    // Filtre
    echo '<form method="get" class="form-inline" style="margin-bottom: 20px;">
        <input type="hidden" name="module" value="fgo">
        <input type="hidden" name="action" value="logs">
        <div class="form-group">
            <label>Status:</label>
            <select name="status" class="form-control">
                <option value="">Toate</option>
                <option value="success" ' . (($_GET['status'] ?? '') == 'success' ? 'selected' : '') . '>Success</option>
                <option value="error" ' . (($_GET['status'] ?? '') == 'error' ? 'selected' : '') . '>Error</option>
            </select>
        </div>
        <div class="form-group">
            <label>ID Factură:</label>
            <input type="text" name="invoice_id" class="form-control" value="' . htmlspecialchars($_GET['invoice_id'] ?? '') . '">
        </div>
        <button type="submit" class="btn btn-primary">Filtrează</button>
        <a href="' . $vars['modulelink'] . '&action=logs" class="btn btn-default">Resetează</a>
    </form>';
    
    // Query
    $query = Capsule::table('mod_fgo_logs');
    
    if (!empty($_GET['status'])) {
        $query->where('status', $_GET['status']);
    }
    
    if (!empty($_GET['invoice_id'])) {
        $query->where('invoice_id', $_GET['invoice_id']);
    }
    
    // Paginare manuală
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $perPage = 20;
    $offset = ($page - 1) * $perPage;
    
    // Total pentru paginare
    $total = $query->count();
    $totalPages = ceil($total / $perPage);
    
    // Obține log-urile pentru pagina curentă
    $logs = $query->orderBy('created_at', 'desc')
                  ->offset($offset)
                  ->limit($perPage)
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
    
    foreach ($logs as $log) {
        $status_class = $log->status == 'success' ? 'success' : 'danger';
        echo '<tr>
            <td>' . $log->id . '</td>
            <td><a href="invoices.php?action=edit&id=' . $log->invoice_id . '">' . $log->invoice_id . '</a></td>
            <td>' . ($log->fgo_serie ?? '-') . '/' . ($log->fgo_numar ?? '-') . '</td>
            <td><span class="label label-' . $status_class . '">' . ucfirst($log->status) . '</span></td>
            <td>' . htmlspecialchars(substr($log->message ?? '', 0, 50)) . '...</td>
            <td>' . ($log->fgo_link ? '<a href="' . $log->fgo_link . '" target="_blank">Vezi</a>' : '-') . '</td>
            <td>' . $log->created_at . '</td>
            <td>
                <button type="button" class="btn btn-xs btn-info" data-toggle="modal" data-target="#logModal' . $log->id . '">
                    Detalii
                </button>
            </td>
        </tr>';
        
        // Modal pentru detalii
        echo '<div class="modal fade" id="logModal' . $log->id . '" tabindex="-1" role="dialog">
            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                        <h4 class="modal-title">Detalii Log #' . $log->id . '</h4>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong>ID Factură:</strong> ' . $log->invoice_id . '</p>
                                <p><strong>Status:</strong> <span class="label label-' . $status_class . '">' . ucfirst($log->status) . '</span></p>
                                <p><strong>Data:</strong> ' . $log->created_at . '</p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>FGO Serie/Număr:</strong> ' . ($log->fgo_serie ?? '-') . '/' . ($log->fgo_numar ?? '-') . '</p>
                                <p><strong>Link:</strong> ' . ($log->fgo_link ? '<a href="' . $log->fgo_link . '" target="_blank">' . $log->fgo_link . '</a>' : '-') . '</p>
                            </div>
                        </div>
                        <hr>
                        <p><strong>Mesaj:</strong><br>' . nl2br(htmlspecialchars($log->message ?? '')) . '</p>';
        
        // Request data
        if ($log->request_data) {
            $request = json_decode($log->request_data, true);
            if ($request) {
                echo '<hr><h5>Date trimise:</h5>';
                echo '<pre style="max-height: 300px; overflow-y: auto;">';
                // Ascunde date sensibile
                if (isset($request['Hash'])) {
                    $request['Hash'] = substr($request['Hash'], 0, 8) . '...';
                }
                if (isset($request['cheie_privata'])) {
                    $request['cheie_privata'] = '***';
                }
                echo htmlspecialchars(json_encode($request, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                echo '</pre>';
            }
        }
        
        // Response data
        if ($log->response_data) {
            $response = json_decode($log->response_data, true);
            if ($response) {
                echo '<hr><h5>Răspuns primit:</h5>';
                echo '<pre style="max-height: 300px; overflow-y: auto;">';
                echo htmlspecialchars(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                echo '</pre>';
            }
        }
        
        echo '</div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-default" data-dismiss="modal">Închide</button>
                    </div>
                </div>
            </div>
        </div>';
    }
    
    echo '</tbody></table></div>';
    
    // Paginare
    if ($totalPages > 1) {
        echo '<div class="text-center">
            <ul class="pagination">';
        
        // Link anterior
        if ($page > 1) {
            echo '<li><a href="' . $vars['modulelink'] . '&action=logs&page=' . ($page - 1) . '">&laquo;</a></li>';
        }
        
        // Pagini
        for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++) {
            echo '<li class="' . ($i == $page ? 'active' : '') . '">
                <a href="' . $vars['modulelink'] . '&action=logs&page=' . $i . '">' . $i . '</a>
            </li>';
        }
        
        // Link următor
        if ($page < $totalPages) {
            echo '<li><a href="' . $vars['modulelink'] . '&action=logs&page=' . ($page + 1) . '">&raquo;</a></li>';
        }
        
        echo '</ul>
        </div>';
    }
    
    echo '<p class="text-center">Afișare ' . (($offset + 1) . '-' . min($offset + $perPage, $total)) . ' din ' . $total . ' înregistrări</p>';
}

/**
 * Test conexiune
 */
function fgo_test_connection($vars) {
    echo '<h2>Test Conexiune FGO</h2>';
    
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $helper = new FGOHelper($vars);
        
        // Test simplu - încercăm să obținem nomenclatorul de TVA
        $api_url = $helper->getApiUrl() . '/nomenclator/tva';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            echo '<div class="alert alert-danger">Eroare cURL: ' . htmlspecialchars($error) . '</div>';
        } elseif ($http_code == 200) {
            echo '<div class="alert alert-success">Conexiune reușită! API-ul FGO este accesibil.</div>';
            
            $data = json_decode($response, true);
            if ($data && isset($data['List'])) {
                echo '<h4>Cote TVA disponibile:</h4><ul>';
                foreach ($data['List'] as $tva) {
                    echo '<li>' . htmlspecialchars($tva['Nume'] ?? $tva) . '</li>';
                }
                echo '</ul>';
            }
        } else {
            echo '<div class="alert alert-warning">Cod HTTP: ' . $http_code . '</div>';
            echo '<pre>' . htmlspecialchars($response) . '</pre>';
        }
        
        // Test hash
        echo '<h4>Test generare hash:</h4>';
        $test_hash = $helper->generateHash('Test Client');
        echo '<div class="well">
            <strong>CUI:</strong> ' . htmlspecialchars($vars['cui_furnizor']) . '<br>
            <strong>Cheie (primele 4 caractere):</strong> ' . substr($vars['cheie_privata'], 0, 4) . '***<br>
            <strong>Client test:</strong> Test Client<br>
            <strong>Hash generat:</strong> ' . $test_hash . '
        </div>';
    }
    
    echo '<form method="post">
        <p>Acest test va verifica:</p>
        <ul>
            <li>Conectivitatea la API-ul FGO</li>
            <li>Validitatea mediului selectat</li>
            <li>Generarea corectă a hash-ului</li>
        </ul>
        <button type="submit" class="btn btn-primary">Testează Conexiunea</button>
    </form>';
}

/**
 * Emitere manuală
 */
function fgo_manual_emit($vars) {
    echo '<h2>Emitere Manuală Factură în FGO</h2>';
    
    $invoice_id = isset($_GET['invoice_id']) ? intval($_GET['invoice_id']) : 0;
    
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && $invoice_id) {
        $helper = new FGOHelper($vars);
        $result = $helper->emitInvoice($invoice_id);
        
        if ($result['success']) {
            echo '<div class="alert alert-success">
                Factura a fost emisă cu succes în FGO!<br>
                Serie: ' . htmlspecialchars($result['serie']) . '<br>
                Număr: ' . htmlspecialchars($result['numar']) . '<br>
                <a href="' . htmlspecialchars($result['link']) . '" target="_blank" class="btn btn-sm btn-primary">Vezi Factura</a>
            </div>';
        } else {
            echo '<div class="alert alert-danger">Eroare: ' . htmlspecialchars($result['message']) . '</div>';
        }
    }
    
    // Formular selectare factură
    echo '<form method="get">
        <input type="hidden" name="module" value="fgo">
        <input type="hidden" name="action" value="manual">
        <div class="form-group">
            <label>ID Factură WHMCS:</label>
            <input type="number" name="invoice_id" class="form-control" value="' . $invoice_id . '" required>
        </div>
        <button type="submit" class="btn btn-primary">Încarcă Factură</button>
    </form>';
    
    if ($invoice_id) {
        // Obține detalii factură
        $invoice = Capsule::table('tblinvoices')->where('id', $invoice_id)->first();
        
        if ($invoice) {
            $client = Capsule::table('tblclients')->where('id', $invoice->userid)->first();
            $items = Capsule::table('tblinvoiceitems')->where('invoiceid', $invoice_id)->get();
            
            echo '<hr><h3>Detalii Factură #' . $invoice_id . '</h3>';
            echo '<div class="row">
                <div class="col-md-6">
                    <h4>Date Client:</h4>
                    <p>
                        <strong>Nume:</strong> ' . htmlspecialchars($client->companyname ?: $client->firstname . ' ' . $client->lastname) . '<br>
                        <strong>Email:</strong> ' . htmlspecialchars($client->email) . '<br>
                        <strong>Telefon:</strong> ' . htmlspecialchars($client->phonenumber) . '<br>
                        <strong>Adresă:</strong> ' . htmlspecialchars($client->address1 . ' ' . $client->address2) . '<br>
                        <strong>Oraș:</strong> ' . htmlspecialchars($client->city) . '<br>
                        <strong>Țară:</strong> ' . htmlspecialchars($client->country) . '
                    </p>
                </div>
                <div class="col-md-6">
                    <h4>Detalii Factură:</h4>
                    <p>
                        <strong>Data:</strong> ' . $invoice->date . '<br>
                        <strong>Data scadență:</strong> ' . $invoice->duedate . '<br>
                        <strong>Total:</strong> ' . $invoice->total . ' ' . $invoice->currency . '<br>
                        <strong>Status:</strong> ' . $invoice->status . '
                    </p>
                </div>
            </div>';
            
            echo '<h4>Articole:</h4>
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
                    <td>' . $item->amount . '</td>
                </tr>';
            }
            
            echo '</tbody></table>';
            
            // Verifică dacă a fost deja emisă
            $existing = Capsule::table('mod_fgo_logs')
                ->where('invoice_id', $invoice_id)
                ->where('status', 'success')
                ->first();
            
            if ($existing) {
                echo '<div class="alert alert-warning">
                    Această factură a fost deja emisă în FGO!<br>
                    Serie: ' . $existing->fgo_serie . '<br>
                    Număr: ' . $existing->fgo_numar . '
                </div>';
            }
            
            echo '<form method="post">
                <button type="submit" class="btn btn-success" ' . ($existing ? 'onclick="return confirm(\'Factura a fost deja emisă. Continuați?\')"' : '') . '>
                    Emite în FGO
                </button>
            </form>';
        } else {
            echo '<div class="alert alert-danger">Factura nu a fost găsită!</div>';
        }
    }
}

/**
 * Clasă helper pentru operațiuni FGO
 */
class FGOHelper {
    private $config;
    private $api_url;
    
    public function __construct($config) {
        $this->config = $config;
        $this->api_url = $config['api_environment'] == 'production' 
            ? 'https://api.fgo.ro/v1' 
            : 'https://api-testuat.fgo.ro/v1';
    }
    
    public function getApiUrl() {
        return $this->api_url;
    }
    
    /**
     * Generare hash SHA1
     */
    public function generateHash($client_name) {
        $string = $this->config['cui_furnizor'] . $this->config['cheie_privata'] . $client_name;
        return strtoupper(sha1($string));
    }
    
    /**
     * Emite factură în FGO
     */
    public function emitInvoice($invoice_id, $force_invoice_type = null) {
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
        
        // Verifică dacă clientul dorește factură directă
        $client_direct_invoice = false;
        $custom_field = Capsule::table('tblcustomfieldsvalues as cfv')
            ->join('tblcustomfields as cf', 'cf.id', '=', 'cfv.fieldid')
            ->where('cf.type', 'client')
            ->where('cf.fieldname', 'Factură Fiscală Directă')
            ->where('cfv.relid', $client->id)
            ->first();
        
        if ($custom_field && $custom_field->value == 'on') {
            $client_direct_invoice = true;
        }
        
        // Obține articolele facturii
        $items = Capsule::table('tblinvoiceitems')->where('invoiceid', $invoice_id)->get();
        
        // Verifică dacă toate produsele sunt din categorii cu factură directă
        $all_direct_invoice = false;
        if (!empty($this->config['direct_invoice_categories'])) {
            $direct_categories = explode(',', $this->config['direct_invoice_categories']);
            $direct_categories = array_map('trim', $direct_categories);
            
            $all_direct_invoice = true;
            foreach ($items as $item) {
                if ($item->type == 'Hosting' || $item->type == 'Domain') {
                    $product = Capsule::table('tblhosting')
                        ->where('id', $item->relid)
                        ->first();
                    
                    if ($product) {
                        $product_info = Capsule::table('tblproducts')
                            ->where('id', $product->packageid)
                            ->first();
                        
                        if ($product_info && !in_array($product_info->gid, $direct_categories)) {
                            $all_direct_invoice = false;
                            break;
                        }
                    }
                }
            }
        }
        
        // Determină tipul de factură
        $tip_factura = $this->config['tip_factura'];
        $serie = $this->config['serie_factura'];
        
        // Override tip factură dacă este cazul
        if ($force_invoice_type) {
            $tip_factura = $force_invoice_type;
        } elseif ($client_direct_invoice || $all_direct_invoice) {
            $tip_factura = 'Factura';
        }
        
        // Folosește serie diferită pentru proformă dacă este configurată
        if ($tip_factura == 'Proforma' && !empty($this->config['serie_proforma'])) {
            $serie = $this->config['serie_proforma'];
        }
        
        // Pregătește datele pentru API
        $client_name = $client->companyname ?: $client->firstname . ' ' . $client->lastname;
        
        $data = [
            'CodUnic' => $this->config['cui_furnizor'],
            'Hash' => $this->generateHash($client_name),
            'Serie' => $serie,
            'Valuta' => $this->config['valuta'],
            'TipFactura' => $tip_factura,
            'DataEmitere' => date('Y-m-d', strtotime($invoice->date)),
            'DataScadenta' => date('Y-m-d', strtotime($invoice->duedate)),
            'Text' => $this->config['text_suplimentar'],
            'PlatformaUrl' => $this->config['platform_url'],
            'VerificareDuplicat' => true,
            'ValideazaCodUnicRo' => true,
            'IdExtern' => strval($invoice_id),
            
            // Date client
            'Client' => [
                'Denumire' => $client_name,
                'CodUnic' => $client->tax_id ?: '',
                'Email' => $client->email,
                'Telefon' => $client->phonenumber,
                'Tara' => $this->mapCountry($client->country),
                'Judet' => $this->mapCounty($client->state),
                'Localitate' => $client->city,
                'Adresa' => trim($client->address1 . ' ' . $client->address2),
                'Tip' => $client->companyname ? 'PJ' : 'PF',
                'IdExtern' => $client->id,
                'PlatitorTVA' => !empty($client->tax_id),
            ],
            
            // Articole
            'Continut' => []
        ];
        
        // Adaugă articolele
        foreach ($items as $index => $item) {
            $data['Continut'][] = [
                'Denumire' => $item->description,
                'CodArticol' => 'WHMCS-' . $item->id,
                'Descriere' => $item->notes ?: $item->description,
                'PretUnitar' => floatval($item->amount),
                'UM' => $this->config['um_default'],
                'NrProduse' => 1,
                'CotaTVA' => floatval($this->config['cota_tva']),
            ];
        }
        
        // Log request
        $this->logRequest($invoice_id, $data);
        
        // Trimite request
        $ch = curl_init($this->api_url . '/factura/emitere');
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
        
        if ($error) {
            $this->logResponse($invoice_id, 'error', 'Eroare cURL: ' . $error, null);
            return ['success' => false, 'message' => 'Eroare de conexiune: ' . $error];
        }
        
        $result = json_decode($response, true);
        
        if ($http_code == 200 && isset($result['Success']) && $result['Success']) {
            // Succes
            $this->logResponse(
                $invoice_id, 
                'success', 
                'Factură emisă cu succes', 
                $result,
                $result['Factura']['Serie'] ?? '',
                $result['Factura']['Numar'] ?? '',
                $result['Factura']['Link'] ?? ''
            );
            
            // Actualizează numărul facturii în WHMCS cu cel din FGO
            if (!empty($result['Factura']['Numar'])) {
                $invoice_num = $result['Factura']['Serie'] . $result['Factura']['Numar'];
                Capsule::table('tblinvoices')
                    ->where('id', $invoice_id)
                    ->update(['invoicenum' => $invoice_num]);
                
                logActivity("FGO: Număr factură actualizat pentru ID {$invoice_id}: {$invoice_num}");
            }
            
            // Trimite email dacă a fost amânat
            if ($this->config['delay_email'] == 'on') {
                $this->sendDelayedInvoiceEmail($invoice_id);
            }
            
            return [
                'success' => true,
                'serie' => $result['Factura']['Serie'],
                'numar' => $result['Factura']['Numar'],
                'link' => $result['Factura']['Link'],
                'tip_factura' => $tip_factura,
            ];
        } else {
            // Eroare
            $error_message = $result['Message'] ?? 'Răspuns invalid de la server';
            $this->logResponse($invoice_id, 'error', $error_message, $result);
            
            return ['success' => false, 'message' => $error_message];
        }
    }
    
    /**
     * Trimite email-ul amânat pentru factură
     */
    private function sendDelayedInvoiceEmail($invoice_id) {
        // Obține detalii factură
        $invoice = Capsule::table('tblinvoices')->where('id', $invoice_id)->first();
        if (!$invoice) {
            return false;
        }
        
        // Trimite email folosind template-ul standard
        $email_template = ($invoice->status == 'Unpaid') ? 'Invoice Created' : 'Invoice Payment Confirmation';
        
        $command = 'SendEmail';
        $postData = array(
            'messagename' => $email_template,
            'id' => $invoice_id,
        );
        
        $results = localAPI($command, $postData);
        
        if ($results['result'] == 'success') {
            logActivity("FGO: Email factură trimis pentru ID {$invoice_id}");
            return true;
        } else {
            logActivity("FGO: Eroare trimitere email pentru factura {$invoice_id}: " . $results['message']);
            return false;
        }
    }
    
    /**
     * Mapare țară WHMCS -> FGO
     */
    private function mapCountry($country) {
        $map = [
            'RO' => 'Romania',
            'US' => 'United States',
            'GB' => 'United Kingdom',
            'DE' => 'Germany',
            'FR' => 'France',
            // Adăugați alte mapări după necesitate
        ];
        
        return $map[$country] ?? $country;
    }
    
    /**
     * Mapare județ
     */
    private function mapCounty($state) {
        // Implementați maparea județelor dacă este necesar
        return $state;
    }
    
    /**
     * Log request
     */
    private function logRequest($invoice_id, $data) {
        Capsule::table('mod_fgo_logs')->insert([
            'invoice_id' => $invoice_id,
            'status' => 'pending',
            'request_data' => json_encode($data),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
    
    /**
     * Log response
     */
    private function logResponse($invoice_id, $status, $message, $response_data, $serie = null, $numar = null, $link = null) {
        $log = Capsule::table('mod_fgo_logs')
            ->where('invoice_id', $invoice_id)
            ->where('status', 'pending')
            ->orderBy('id', 'desc')
            ->first();
        
        if ($log) {
            Capsule::table('mod_fgo_logs')
                ->where('id', $log->id)
                ->update([
                    'status' => $status,
                    'message' => $message,
                    'response_data' => json_encode($response_data),
                    'fgo_serie' => $serie,
                    'fgo_numar' => $numar,
                    'fgo_link' => $link,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
        } else {
            Capsule::table('mod_fgo_logs')->insert([
                'invoice_id' => $invoice_id,
                'status' => $status,
                'message' => $message,
                'response_data' => json_encode($response_data),
                'fgo_serie' => $serie,
                'fgo_numar' => $numar,
                'fgo_link' => $link,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }
}

/**
 * Hook-uri disponibile pentru client area
 */
function fgo_clientarea($vars) {
    // Implementare pentru zona client dacă este necesar
    return [];
}

/**
 * Sidebar pentru admin
 */
function fgo_sidebar($vars) {
    $sidebar = '<p>Modul FGO Integration</p>
    <p>Versiune: ' . $vars['version'] . '</p>
    <p>
        <a href="https://www.fgo.ro" target="_blank" class="btn btn-sm btn-info">
            <i class="fa fa-external-link"></i> FGO.ro
        </a>
    </p>';
    
    return $sidebar;
}