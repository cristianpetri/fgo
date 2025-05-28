<?php
/**
 * FGO API - Gestionare comunicare cu API-ul FGO
 */

namespace FGO;

use WHMCS\Database\Capsule;

class FGOApi {
    protected $config;
    protected $api_url;
    
    public function __construct($config) {
        $this->config = $config;
        $this->api_url = $config['api_environment'] == 'production' 
            ? 'https://api.fgo.ro/v1' 
            : 'https://api-testuat.fgo.ro/v1';
    }
    
    /**
     * Generează hash pentru autentificare
     */
    public function generateHash($client_name) {
        $string = $this->config['cui_furnizor'] . $this->config['cheie_privata'] . $client_name;
        return strtoupper(sha1($string));
    }
    
    /**
     * Emite factură
     */
    public function emitInvoice($data, $invoice_id, $document_type) {
        $response = $this->makeRequest('/factura/emitere', $data);
        
        if ($response['success']) {
            $this->logSuccess($invoice_id, $response['data'], $document_type);
            
            // Actualizează număr factură în WHMCS
            if (!empty($response['data']['Factura']['Numar'])) {
                $this->updateInvoiceNumber($invoice_id, $response['data']['Factura']);
            }
            
            return [
                'success' => true,
                'serie' => $response['data']['Factura']['Serie'],
                'numar' => $response['data']['Factura']['Numar'],
                'link' => $response['data']['Factura']['Link'],
                'tip_factura' => $document_type,
            ];
        } else {
            $this->logError($invoice_id, $response['message'], $document_type);
            
            return [
                'success' => false,
                'message' => $response['message'],
            ];
        }
    }
    
    /**
     * Anulează document
     */
    public function cancelDocument($serie, $numar) {
        $data = [
            'CodUnic' => $this->config['cui_furnizor'],
            'Hash' => strtoupper(sha1($this->config['cui_furnizor'] . $this->config['cheie_privata'] . $numar)),
            'Serie' => $serie,
            'Numar' => $numar,
            'PlatformaUrl' => $this->config['platform_url'],
        ];
        
        return $this->makeRequest('/factura/anulare', $data);
    }
    
    /**
     * Înregistrează încasare
     */
    public function registerPayment($data) {
        return $this->makeRequest('/factura/incasare', $data);
    }
    
    /**
     * Obține status factură
     */
    public function getInvoiceStatus($serie, $numar) {
        $data = [
            'CodUnic' => $this->config['cui_furnizor'],
            'Hash' => strtoupper(sha1($this->config['cui_furnizor'] . $this->config['cheie_privata'] . $numar)),
            'Serie' => $serie,
            'Numar' => $numar,
            'PlatformaUrl' => $this->config['platform_url'],
        ];
        
        return $this->makeRequest('/factura/getstatus', $data);
    }
    
    /**
     * Obține nomenclator
     */
    public function getNomenclator($type) {
        $ch = curl_init($this->api_url . '/nomenclator/' . $type);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code == 200) {
            $data = json_decode($response, true);
            if (isset($data['List'])) {
                return $data['List'];
            }
        }
        
        return [];
    }
    
    /**
     * Face request către API
     */
    protected function makeRequest($endpoint, $data) {
        $ch = curl_init($this->api_url . $endpoint);
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
            return [
                'success' => false,
                'message' => 'Eroare cURL: ' . $error,
            ];
        }
        
        $result = json_decode($response, true);
        
        if ($http_code == 200 && isset($result['Success']) && $result['Success']) {
            return [
                'success' => true,
                'data' => $result,
            ];
        } else {
            return [
                'success' => false,
                'message' => $result['Message'] ?? 'Răspuns invalid de la server (HTTP ' . $http_code . ')',
                'data' => $result,
            ];
        }
    }
    
    /**
     * Log succes
     */
    protected function logSuccess($invoice_id, $response_data, $document_type) {
        Capsule::table('mod_fgo_logs')->insert([
            'invoice_id' => $invoice_id,
            'status' => 'success',
            'document_type' => $document_type,
            'message' => 'Document emis cu succes',
            'fgo_serie' => $response_data['Factura']['Serie'] ?? '',
            'fgo_numar' => $response_data['Factura']['Numar'] ?? '',
            'fgo_link' => $response_data['Factura']['Link'] ?? '',
            'request_data' => $this->shouldLogDetails() ? json_encode($this->sanitizeLogData($response_data)) : null,
            'response_data' => $this->shouldLogDetails() ? json_encode($response_data) : null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
    
    /**
     * Log eroare
     */
    protected function logError($invoice_id, $message, $document_type) {
        Capsule::table('mod_fgo_logs')->insert([
            'invoice_id' => $invoice_id,
            'status' => 'error',
            'document_type' => $document_type,
            'message' => $message,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
    
    /**
     * Actualizează număr factură
     */
    protected function updateInvoiceNumber($invoice_id, $factura_data) {
        $invoice_num = $factura_data['Serie'] . $factura_data['Numar'];
        
        Capsule::table('tblinvoices')
            ->where('id', $invoice_id)
            ->update(['invoicenum' => $invoice_num]);
        
        logActivity("FGO: Număr factură actualizat pentru ID {$invoice_id}: {$invoice_num}");
    }
    
    /**
     * Verifică dacă ar trebui să logheze detalii
     */
    protected function shouldLogDetails() {
        $log_level = $this->config['log_level'] ?? 'basic';
        return in_array($log_level, ['detailed', 'debug']);
    }
    
    /**
     * Sanitizează date pentru log
     */
    protected function sanitizeLogData($data) {
        if (isset($data['Hash'])) {
            $data['Hash'] = substr($data['Hash'], 0, 8) . '...';
        }
        if (isset($data['cheie_privata'])) {
            $data['cheie_privata'] = '***';
        }
        return $data;
    }
}