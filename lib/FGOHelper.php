<?php
/**
 * FGO Helper - Clasă principală pentru operațiuni FGO
 */

namespace FGO;

use WHMCS\Database\Capsule;

class FGOHelper {
    protected $config;
    protected $api;
    protected $cache;
    protected $validator;
    
    public function __construct($config) {
        $this->config = $config;
        
        // Inițializează componentele
        require_once __DIR__ . '/FGOApi.php';
        require_once __DIR__ . '/FGOCache.php';
        require_once __DIR__ . '/FGOValidator.php';
        
        $this->api = new FGOApi($config);
        $this->cache = new FGOCache($config);
        $this->validator = new FGOValidator($config);
    }
    
    /**
     * Emite factură în FGO
     */
    public function emitInvoice($invoice_id, $force_invoice_type = null) {
        // Verifică dacă emiterea este permisă
        if (!$this->shouldEmitInvoice($invoice_id)) {
            return ['success' => false, 'message' => 'Factura nu îndeplinește criteriile de emitere'];
        }
        
        // Obține datele facturii
        $invoice = $this->getInvoiceData($invoice_id);
        if (!$invoice) {
            return ['success' => false, 'message' => 'Factura nu a fost găsită'];
        }
        
        // Obține datele clientului
        $client = $this->getClientData($invoice->userid);
        if (!$client) {
            return ['success' => false, 'message' => 'Clientul nu a fost găsit'];
        }
        
        // Verifică restricții
        if (!$this->checkRestrictions($invoice, $client)) {
            return ['success' => false, 'message' => 'Factura nu îndeplinește criteriile de emitere'];
        }
        
        // Determină tipul și seria
        $invoice_details = $this->determineInvoiceTypeAndSeries($invoice, $client, $force_invoice_type);
        
        // Pregătește datele
        $data = $this->prepareInvoiceData($invoice, $client, $invoice_details);
        
        // Trimite la API
        return $this->api->emitInvoice($data, $invoice_id, $invoice_details['document_type']);
    }
    
    /**
     * Verifică dacă factura ar trebui emisă
     */
    protected function shouldEmitInvoice($invoice_id) {
        // Verifică dacă există deja
        $existing = Capsule::table('mod_fgo_logs')
            ->where('invoice_id', $invoice_id)
            ->where('status', 'success')
            ->exists();
        
        if ($existing && !isset($_POST['force_reemit'])) {
            return false;
        }
        
        // Verifică status factură
        $invoice = Capsule::table('tblinvoices')->where('id', $invoice_id)->first();
        if (in_array($invoice->status, ['Draft', 'Cancelled'])) {
            return false;
        }
        
        // Verifică prag minim
        if ($invoice->total < floatval($this->config['prag_minim'])) {
            return false;
        }
        
        // Verifică excludere gratuită
        if ($this->config['exclude_gratuite'] == 'on' && $invoice->total == 0) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Verifică restricții client
     */
    protected function checkRestrictions($invoice, $client) {
        // Verifică țară
        if ($this->config['emite_doar_romania'] == 'on' && $client->country != 'RO') {
            return false;
        }
        
        // Verifică tip client
        if ($this->config['emite_doar_pj'] == 'on' && empty($client->companyname)) {
            return false;
        }
        
        // Verifică CUI/CNP
        if ($this->config['require_tax_id'] == 'on' && empty($client->tax_id)) {
            return false;
        }
        
        // Verifică excludere client
        $excluded = $this->isClientExcluded($client->id);
        if ($excluded) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Verifică dacă clientul e exclus
     */
    protected function isClientExcluded($client_id) {
        $exclude_field = Capsule::table('tblcustomfieldsvalues as cfv')
            ->join('tblcustomfields as cf', 'cf.id', '=', 'cfv.fieldid')
            ->where('cf.type', 'client')
            ->where('cf.fieldname', 'Exclude Facturare FGO')
            ->where('cfv.relid', $client_id)
            ->value('cfv.value');
        
        return $exclude_field == 'on';
    }
    
    /**
     * Determină tipul și seria facturii
     */
    protected function determineInvoiceTypeAndSeries($invoice, $client, $force_type = null) {
        $tip_factura = $this->config['tip_factura'] ?? 'Factura';
        $serie = $this->config['serie_factura'];
        
        if ($force_type) {
            $tip_factura = $force_type;
        } else {
            // Verifică preferință client
            if ($this->clientWantsDirectInvoice($client->id)) {
                $tip_factura = 'Factura';
            }
            
            // Verifică categorii produse
            if ($this->hasDirectInvoiceProducts($invoice->id)) {
                $tip_factura = 'Factura';
            }
        }
        
        // Ajustează seria după tip
        switch ($tip_factura) {
            case 'Proforma':
                $serie = $this->config['serie_proforma'] ?? $serie;
                break;
            case 'Storno':
                $serie = $this->config['serie_storno'] ?? $serie;
                break;
        }
        
        // Adaugă suffix valută dacă e configurat
        if ($this->config['serie_per_valuta'] == 'on') {
            $serie .= '-' . $invoice->currency;
        }
        
        return [
            'tip_factura' => $tip_factura,
            'serie' => $serie,
            'document_type' => strtolower($tip_factura),
        ];
    }
    
    /**
     * Verifică dacă clientul dorește factură directă
     */
    protected function clientWantsDirectInvoice($client_id) {
        $field_value = Capsule::table('tblcustomfieldsvalues as cfv')
            ->join('tblcustomfields as cf', 'cf.id', '=', 'cfv.fieldid')
            ->where('cf.type', 'client')
            ->where('cf.fieldname', 'Factură Fiscală Directă')
            ->where('cfv.relid', $client_id)
            ->value('cfv.value');
        
        return $field_value == 'on';
    }
    
    /**
     * Verifică dacă factura conține produse pentru factură directă
     */
    protected function hasDirectInvoiceProducts($invoice_id) {
        if (empty($this->config['categorii_factura_directa'])) {
            return false;
        }
        
        $direct_categories = explode(',', $this->config['categorii_factura_directa']);
        $direct_categories = array_map('trim', $direct_categories);
        
        $count = Capsule::table('tblinvoiceitems as ii')
            ->join('tblhosting as h', function($join) {
                $join->on('ii.relid', '=', 'h.id')
                    ->whereIn('ii.type', ['Hosting', 'Domain']);
            })
            ->join('tblproducts as p', 'h.packageid', '=', 'p.id')
            ->where('ii.invoiceid', $invoice_id)
            ->whereIn('p.gid', $direct_categories)
            ->count();
        
        return $count > 0;
    }
    
    /**
     * Pregătește datele facturii pentru API
     */
    protected function prepareInvoiceData($invoice, $client, $invoice_details) {
        $client_name = $client->companyname ?: $client->firstname . ' ' . $client->lastname;
        
        $data = [
            'CodUnic' => $this->config['cui_furnizor'],
            'Hash' => $this->api->generateHash($client_name),
            'Serie' => $invoice_details['serie'],
            'Valuta' => $invoice->currency ?: $this->config['valuta'],
            'TipFactura' => $invoice_details['tip_factura'],
            'DataEmitere' => date('Y-m-d', strtotime($invoice->date)),
            'DataScadenta' => date('Y-m-d', strtotime($invoice->duedate)),
            'Text' => $this->config['text_suplimentar'] ?? '',
            'PlatformaUrl' => $this->config['platform_url'],
            'VerificareDuplicat' => true,
            'ValideazaCodUnicRo' => $this->config['validare_cui_ro'] == 'on',
            'IdExtern' => strval($invoice->id),
            'TvaLaIncasare' => $this->config['tva_la_incasare'] == 'on',
            'Client' => $this->prepareClientData($client),
            'Continut' => $this->prepareInvoiceItems($invoice->id),
        ];
        
        return $data;
    }
    
    /**
     * Pregătește datele clientului
     */
    protected function prepareClientData($client) {
        // Obține câmpuri personalizate
        $custom_fields = $this->getClientCustomFields($client->id);
        
        return [
            'Denumire' => $client->companyname ?: $client->firstname . ' ' . $client->lastname,
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
            'CodCentruCost' => $custom_fields['Cod Centru Cost'] ?? '',
        ];
    }
    
    /**
     * Pregătește articolele facturii
     */
    protected function prepareInvoiceItems($invoice_id) {
        $items = Capsule::table('tblinvoiceitems')->where('invoiceid', $invoice_id)->get();
        $result = [];
        
        // Obține mapări
        $tva_mappings = $this->getTvaMappings();
        $excluded_categories = $this->getExcludedCategories();
        
        foreach ($items as $item) {
            // Obține informații produs
            $product_info = $this->getProductInfo($item);
            
            // Skip dacă e în categorie exclusă
            if ($product_info && in_array($product_info->gid, $excluded_categories)) {
                continue;
            }
            
            // Determină TVA și alte setări
            $item_settings = $this->getItemSettings($item, $product_info, $tva_mappings);
            
            $result[] = [
                'Denumire' => $item->description,
                'CodArticol' => $item_settings['cod_articol'],
                'CodGestiune' => $item_settings['cod_gestiune'],
                'CodCentruCost' => $item_settings['cod_centru_cost'],
                'Descriere' => $this->prepareItemDescription($item, $product_info),
                'PretUnitar' => floatval($item->amount),
                'UM' => $this->config['um_default'],
                'NrProduse' => 1,
                'CotaTVA' => $item_settings['cota_tva'],
            ];
        }
        
        return $result;
    }
    
    /**
     * Obține informații produs
     */
    protected function getProductInfo($item) {
        if (!in_array($item->type, ['Hosting', 'Domain'])) {
            return null;
        }
        
        return Capsule::table('tblhosting as h')
            ->join('tblproducts as p', 'h.packageid', '=', 'p.id')
            ->where('h.id', $item->relid)
            ->first();
    }
    
    /**
     * Obține setări articol
     */
    protected function getItemSettings($item, $product_info, $tva_mappings) {
        $settings = [
            'cod_articol' => 'WHMCS-' . $item->id,
            'cod_gestiune' => $this->config['gestiune_default'] ?? '',
            'cod_centru_cost' => '',
            'cota_tva' => floatval($this->config['cota_tva_default']),
        ];
        
        // Aplică mapări dacă există
        if ($product_info && isset($tva_mappings[$product_info->gid])) {
            $mapping = $tva_mappings[$product_info->gid];
            $settings['cota_tva'] = $mapping->cota_tva;
            $settings['cod_centru_cost'] = $mapping->cod_centru_cost;
            $settings['cod_gestiune'] = $mapping->cod_gestiune ?: $settings['cod_gestiune'];
        }
        
        // Verifică câmpuri personalizate produs
        if ($product_info) {
            $product_fields = $this->getProductCustomFields($product_info->id);
            if (!empty($product_fields['Cod Articol FGO'])) {
                $settings['cod_articol'] = $product_fields['Cod Articol FGO'];
            }
        }
        
        return $settings;
    }
    
    /**
     * Pregătește descrierea articolului
     */
    protected function prepareItemDescription($item, $product_info = null) {
        // Verifică descriere personalizată
        if ($product_info) {
            $custom_desc = $this->getProductCustomField($product_info->id, 'Descriere FGO');
            if ($custom_desc) {
                return $custom_desc;
            }
        }
        
        // Folosește template dacă e configurat
        if (!empty($this->config['description_template'])) {
            return $this->applyDescriptionTemplate($item, $product_info);
        }
        
        // Folosește texte specifice
        $description = $item->description;
        
        if (strpos($item->description, 'Hosting') !== false && !empty($this->config['text_hosting'])) {
            $description = $this->config['text_hosting'];
        } elseif (strpos($item->description, 'Domain') !== false && !empty($this->config['text_domenii'])) {
            $description = $this->config['text_domenii'];
        } elseif (strpos($item->description, 'SSL') !== false && !empty($this->config['text_ssl'])) {
            $description = $this->config['text_ssl'];
        }
        
        return $description;
    }
    
    /**
     * Obține date factură
     */
    protected function getInvoiceData($invoice_id) {
        return Capsule::table('tblinvoices')->where('id', $invoice_id)->first();
    }
    
    /**
     * Obține date client
     */
    protected function getClientData($client_id) {
        return Capsule::table('tblclients')->where('id', $client_id)->first();
    }
    
    /**
     * Obține câmpuri personalizate client
     */
    protected function getClientCustomFields($client_id) {
        return Capsule::table('tblcustomfieldsvalues as cfv')
            ->join('tblcustomfields as cf', 'cf.id', '=', 'cfv.fieldid')
            ->where('cf.type', 'client')
            ->where('cfv.relid', $client_id)
            ->pluck('cfv.value', 'cf.fieldname')
            ->toArray();
    }
    
    /**
     * Obține câmpuri personalizate produs
     */
    protected function getProductCustomFields($product_id) {
        return Capsule::table('tblcustomfieldsvalues as cfv')
            ->join('tblcustomfields as cf', 'cf.id', '=', 'cfv.fieldid')
            ->where('cf.type', 'product')
            ->where('cfv.relid', $product_id)
            ->pluck('cfv.value', 'cf.fieldname')
            ->toArray();
    }
    
    /**
     * Obține un câmp personalizat specific
     */
    protected function getProductCustomField($product_id, $field_name) {
        return Capsule::table('tblcustomfieldsvalues as cfv')
            ->join('tblcustomfields as cf', 'cf.id', '=', 'cfv.fieldid')
            ->where('cf.type', 'product')
            ->where('cf.fieldname', $field_name)
            ->where('cfv.relid', $product_id)
            ->value('cfv.value');
    }
    
    /**
     * Obține mapări TVA
     */
    protected function getTvaMappings() {
        $cached = $this->cache->get('tva_mappings');
        if ($cached !== false) {
            return $cached;
        }
        
        $mappings = Capsule::table('mod_fgo_tva_mapping')->get();
        $result = [];
        
        foreach ($mappings as $mapping) {
            $result[$mapping->category_id] = $mapping;
        }
        
        $this->cache->set('tva_mappings', $result, 3600);
        return $result;
    }
    
    /**
     * Obține categorii excluse
     */
    protected function getExcludedCategories() {
        if (empty($this->config['categorii_excluse'])) {
            return [];
        }
        
        $categories = explode(',', $this->config['categorii_excluse']);
        return array_map('trim', $categories);
    }
    
    /**
     * Mapare țară
     */
    protected function mapCountry($country_code) {
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
    
    /**
     * Mapare județ
     */
    protected function mapCounty($state) {
        // Implementează mapare județe dacă e necesar
        return $state;
    }
    
    /**
     * Aplică template descriere
     */
    protected function applyDescriptionTemplate($item, $product_info) {
        $template = $this->config['description_template'];
        
        // Obține informații hosting dacă există
        $hosting_info = null;
        if ($item->relid && in_array($item->type, ['Hosting', 'Domain'])) {
            $hosting_info = Capsule::table('tblhosting')->where('id', $item->relid)->first();
        }
        
        $replacements = [
            '{service_name}' => $item->description,
            '{domain}' => $hosting_info->domain ?? '',
            '{billing_cycle}' => $hosting_info->billingcycle ?? '',
            '{date_start}' => $hosting_info ? date('d.m.Y', strtotime($hosting_info->regdate)) : '',
            '{date_end}' => $hosting_info ? date('d.m.Y', strtotime($hosting_info->nextduedate)) : '',
        ];
        
        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }
    
    /**
     * Obține statistici rapide pentru sidebar
     */
    public function getQuickStats() {
        $today = date('Y-m-d');
        
        return [
            'today_count' => Capsule::table('mod_fgo_logs')
                ->where('status', 'success')
                ->whereDate('created_at', $today)
                ->count(),
            
            'queue_count' => Capsule::table('mod_fgo_queue')
                ->where('status', 'pending')
                ->count(),
            
            'error_count' => Capsule::table('mod_fgo_logs')
                ->where('status', 'error')
                ->where('created_at', '>', date('Y-m-d H:i:s', strtotime('-24 hours')))
                ->count(),
        ];
    }
    
    /**
     * Obține statistici dashboard
     */
    public function getDashboardStats() {
        $total = Capsule::table('mod_fgo_logs')->count();
        $success = Capsule::table('mod_fgo_logs')->where('status', 'success')->count();
        $error = Capsule::table('mod_fgo_logs')->where('status', 'error')->count();
        $pending = Capsule::table('mod_fgo_logs')->where('status', 'pending')->count();
        
        $total_value = Capsule::table('mod_fgo_logs as l')
            ->join('tblinvoices as i', 'l.invoice_id', '=', 'i.id')
            ->where('l.status', 'success')
            ->sum('i.total');
        
        $success_rate = $total > 0 ? round(($success / $total) * 100, 2) : 0;
        
        return [
            'total_processed' => $total,
            'success_count' => $success,
            'error_count' => $error,
            'pending_count' => $pending,
            'total_value' => number_format($total_value, 2),
            'success_rate' => $success_rate,
        ];
    }
}