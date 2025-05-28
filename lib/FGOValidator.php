<?php
/**
 * FGO Validator - Validări CUI/CNP și alte validări fiscale
 */

namespace FGO;

use WHMCS\Database\Capsule;

class FGOValidator {
    protected $config;
    
    public function __construct($config) {
        $this->config = $config;
    }
    
    /**
     * Validează cod fiscal (CUI sau CNP)
     */
    public function validateFiscalCode($code) {
        $code = trim($code);
        
        // Verifică dacă e CUI sau CNP
        if (preg_match('/^RO\d+$/', $code)) {
            // CUI cu RO
            return $this->validateCUI($code);
        } elseif (preg_match('/^\d{13}$/', $code)) {
            // CNP
            return $this->validateCNP($code);
        } elseif (preg_match('/^\d+$/', $code)) {
            // CUI fără RO
            return $this->validateCUI('RO' . $code);
        } else {
            return [
                'valid' => false,
                'message' => 'Format invalid. Introduceți un CUI (cu sau fără RO) sau un CNP.',
                'type' => 'unknown',
            ];
        }
    }
    
    /**
     * Validează CUI
     */
    public function validateCUI($cui) {
        // Elimină RO din față
        $cui_number = preg_replace('/^RO/', '', $cui);
        
        if (!preg_match('/^\d+$/', $cui_number)) {
            return [
                'valid' => false,
                'message' => 'CUI conține caractere invalide',
                'type' => 'cui',
            ];
        }
        
        // Verifică lungime
        if (strlen($cui_number) < 2 || strlen($cui_number) > 10) {
            return [
                'valid' => false,
                'message' => 'CUI trebuie să aibă între 2 și 10 cifre',
                'type' => 'cui',
            ];
        }
        
        // Algoritm de validare CUI
        $control_key = [7, 5, 3, 2, 1, 7, 5, 3, 2];
        $cui_array = str_split(strrev($cui_number));
        $control_digit = array_shift($cui_array);
        $cui_array = array_reverse($cui_array);
        
        if (count($cui_array) > 9) {
            return [
                'valid' => false,
                'message' => 'CUI prea lung',
                'type' => 'cui',
            ];
        }
        
        $sum = 0;
        foreach ($cui_array as $index => $digit) {
            $sum += $digit * $control_key[$index];
        }
        
        $calculated_control = ($sum * 10) % 11;
        if ($calculated_control == 10) {
            $calculated_control = 0;
        }
        
        $valid = $calculated_control == $control_digit;
        
        return [
            'valid' => $valid,
            'message' => $valid ? "CUI valid: RO{$cui_number}" : 'CUI invalid - cifra de control incorectă',
            'type' => 'cui',
            'formatted' => $valid ? "RO{$cui_number}" : null,
        ];
    }
    
    /**
     * Validează CNP
     */
    public function validateCNP($cnp) {
        if (strlen($cnp) != 13 || !is_numeric($cnp)) {
            return [
                'valid' => false,
                'message' => 'CNP trebuie să aibă exact 13 cifre',
                'type' => 'cnp',
            ];
        }
        
        // Verifică prima cifră (sex și secol)
        $first_digit = intval($cnp[0]);
        if ($first_digit < 1 || $first_digit > 8) {
            return [
                'valid' => false,
                'message' => 'Prima cifră a CNP-ului este invalidă',
                'type' => 'cnp',
            ];
        }
        
        // Verifică data nașterii
        $year = substr($cnp, 1, 2);
        $month = substr($cnp, 3, 2);
        $day = substr($cnp, 5, 2);
        
        // Determină secolul
        $century = in_array($first_digit, [1, 2]) ? '19' : '20';
        $full_year = $century . $year;
        
        if (!checkdate(intval($month), intval($day), intval($full_year))) {
            return [
                'valid' => false,
                'message' => 'Data nașterii din CNP este invalidă',
                'type' => 'cnp',
            ];
        }
        
        // Algoritm de validare CNP
        $control_key = [2, 7, 9, 1, 4, 6, 3, 5, 8, 2, 7, 9];
        $sum = 0;
        
        for ($i = 0; $i < 12; $i++) {
            $sum += intval($cnp[$i]) * $control_key[$i];
        }
        
        $control_digit = $sum % 11;
        if ($control_digit == 10) {
            $control_digit = 1;
        }
        
        $valid = $control_digit == intval($cnp[12]);
        
        return [
            'valid' => $valid,
            'message' => $valid ? 'CNP valid' : 'CNP invalid - cifra de control incorectă',
            'type' => 'cnp',
            'info' => $valid ? $this->extractCNPInfo($cnp) : null,
        ];
    }
    
    /**
     * Extrage informații din CNP
     */
    protected function extractCNPInfo($cnp) {
        $first_digit = intval($cnp[0]);
        $year = substr($cnp, 1, 2);
        $month = substr($cnp, 3, 2);
        $day = substr($cnp, 5, 2);
        $county_code = substr($cnp, 7, 2);
        
        // Determină sex și secol
        $sex = in_array($first_digit, [1, 3, 5, 7]) ? 'M' : 'F';
        $century = in_array($first_digit, [1, 2]) ? '19' : '20';
        
        // Județe
        $counties = [
            '01' => 'Alba', '02' => 'Arad', '03' => 'Argeș', '04' => 'Bacău',
            '05' => 'Bihor', '06' => 'Bistrița-Năsăud', '07' => 'Botoșani', '08' => 'Brașov',
            '09' => 'Brăila', '10' => 'Buzău', '11' => 'Caraș-Severin', '12' => 'Cluj',
            '13' => 'Constanța', '14' => 'Covasna', '15' => 'Dâmbovița', '16' => 'Dolj',
            '17' => 'Galați', '18' => 'Gorj', '19' => 'Harghita', '20' => 'Hunedoara',
            '21' => 'Ialomița', '22' => 'Iași', '23' => 'Ilfov', '24' => 'Maramureș',
            '25' => 'Mehedinți', '26' => 'Mureș', '27' => 'Neamț', '28' => 'Olt',
            '29' => 'Prahova', '30' => 'Satu Mare', '31' => 'Sălaj', '32' => 'Sibiu',
            '33' => 'Suceava', '34' => 'Teleorman', '35' => 'Timiș', '36' => 'Tulcea',
            '37' => 'Vaslui', '38' => 'Vâlcea', '39' => 'Vrancea', '40' => 'București',
            '41' => 'București Sector 1', '42' => 'București Sector 2',
            '43' => 'București Sector 3', '44' => 'București Sector 4',
            '45' => 'București Sector 5', '46' => 'București Sector 6',
            '51' => 'Călărași', '52' => 'Giurgiu',
        ];
        
        return [
            'sex' => $sex,
            'birth_date' => "{$century}{$year}-{$month}-{$day}",
            'county' => $counties[$county_code] ?? 'Necunoscut',
        ];
    }
    
    /**
     * Validează toți clienții
     */
    public function validateAllClients() {
        $clients = Capsule::table('tblclients')
            ->select('id', 'firstname', 'lastname', 'companyname', 'tax_id', 'country')
            ->where('country', 'RO')
            ->whereNotNull('tax_id')
            ->where('tax_id', '!=', '')
            ->get();
        
        $results = [
            'total' => 0,
            'valid' => 0,
            'invalid' => 0,
            'errors' => 0,
            'invalid_clients' => [],
        ];
        
        foreach ($clients as $client) {
            $results['total']++;
            
            try {
                $validation = $this->validateFiscalCode($client->tax_id);
                
                if ($validation['valid']) {
                    $results['valid']++;
                    
                    // Actualizează formatul dacă e diferit
                    if ($validation['type'] == 'cui' && $validation['formatted'] && $validation['formatted'] != $client->tax_id) {
                        Capsule::table('tblclients')
                            ->where('id', $client->id)
                            ->update(['tax_id' => $validation['formatted']]);
                    }
                } else {
                    $results['invalid']++;
                    $client_name = $client->companyname ?: $client->firstname . ' ' . $client->lastname;
                    $results['invalid_clients'][] = "#{$client->id} - {$client_name} - {$client->tax_id} ({$validation['message']})";
                }
            } catch (\Exception $e) {
                $results['errors']++;
            }
        }
        
        return $results;
    }
    
    /**
     * Validează date factură înainte de emitere
     */
    public function validateInvoiceData($invoice_data) {
        $errors = [];
        
        // Validare client
        if (empty($invoice_data['Client']['Denumire'])) {
            $errors[] = 'Numele clientului este obligatoriu';
        }
        
        if (empty($invoice_data['Client']['Tara'])) {
            $errors[] = 'Țara clientului este obligatorie';
        }
        
        if ($invoice_data['Client']['Tara'] == 'Romania' || $invoice_data['Client']['Tara'] == 'RO') {
            if (empty($invoice_data['Client']['Judet'])) {
                $errors[] = 'Județul este obligatoriu pentru clienți din România';
            }
            
            // Validare CUI dacă e configurat
            if ($this->config['validare_cui_ro'] == 'on' && !empty($invoice_data['Client']['CodUnic'])) {
                $validation = $this->validateFiscalCode($invoice_data['Client']['CodUnic']);
                if (!$validation['valid']) {
                    $errors[] = 'CUI/CNP invalid: ' . $validation['message'];
                }
            }
        }
        
        // Validare articole
        if (empty($invoice_data['Continut']) || count($invoice_data['Continut']) == 0) {
            $errors[] = 'Factura trebuie să conțină cel puțin un articol';
        } else {
            foreach ($invoice_data['Continut'] as $index => $item) {
                if (empty($item['Denumire'])) {
                    $errors[] = "Articolul #{$index} nu are denumire";
                }
                if ($item['NrProduse'] <= 0) {
                    $errors[] = "Articolul #{$index} are cantitate invalidă";
                }
                if ($item['CotaTVA'] < 0) {
                    $errors[] = "Articolul #{$index} are cotă TVA invalidă";
                }
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }
}