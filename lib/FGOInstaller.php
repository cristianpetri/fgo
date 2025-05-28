<?php
/**
 * FGO Installer - Gestionare instalare și actualizare
 */

use WHMCS\Database\Capsule;

class FGOInstaller {
    
    /**
     * Instalare modul
     */
    public function install() {
        try {
            // Creează tabele
            $this->createTables();
            
            // Creează câmpuri personalizate
            $this->createCustomFields();
            
            // Creează directoare necesare
            $this->createDirectories();
            
            return [
                'status' => 'success',
                'description' => 'Modulul FGO Pro a fost instalat cu succes!'
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'description' => 'Eroare la instalare: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Dezactivare modul
     */
    public function deactivate() {
        // Nu ștergem tabelele pentru a păstra datele
        return [
            'status' => 'success',
            'description' => 'Modulul FGO a fost dezactivat. Datele au fost păstrate.'
        ];
    }
    
    /**
     * Actualizare modul
     */
    public function upgrade($current_version) {
        try {
            // Actualizări în funcție de versiune
            if (version_compare($current_version, '2.0', '<')) {
                $this->upgradeToV2();
            }
            
            return [
                'status' => 'success',
                'description' => 'Modulul a fost actualizat cu succes!'
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'description' => 'Eroare la actualizare: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Creează tabele
     */
    protected function createTables() {
        // Tabel principal log-uri
        if (!Capsule::schema()->hasTable('mod_fgo_logs')) {
            Capsule::schema()->create('mod_fgo_logs', function ($table) {
                $table->increments('id');
                $table->integer('invoice_id')->index();
                $table->string('fgo_serie', 50)->nullable();
                $table->string('fgo_numar', 50)->nullable();
                $table->string('fgo_link', 255)->nullable();
                $table->enum('status', ['success', 'error', 'pending', 'cancelled'])->index();
                $table->enum('document_type', ['factura', 'proforma', 'storno', 'chitanta'])->default('factura');
                $table->text('message')->nullable();
                $table->longText('request_data')->nullable();
                $table->longText('response_data')->nullable();
                $table->integer('retry_count')->default(0);
                $table->datetime('next_retry')->nullable();
                $table->timestamps();
                
                $table->index(['fgo_serie', 'fgo_numar']);
                $table->index('created_at');
            });
        }
        
        // Tabel mapări gateway
        if (!Capsule::schema()->hasTable('mod_fgo_gateway_mapping')) {
            Capsule::schema()->create('mod_fgo_gateway_mapping', function ($table) {
                $table->increments('id');
                $table->string('gateway', 50)->unique();
                $table->string('tip_incasare', 50);
                $table->string('cont_incasare', 50)->nullable();
                $table->timestamps();
            });
        }
        
        // Tabel mapări TVA
        if (!Capsule::schema()->hasTable('mod_fgo_tva_mapping')) {
            Capsule::schema()->create('mod_fgo_tva_mapping', function ($table) {
                $table->increments('id');
                $table->integer('category_id')->unique();
                $table->decimal('cota_tva', 5, 2);
                $table->string('cod_centru_cost', 50)->nullable();
                $table->string('cod_gestiune', 50)->nullable();
                $table->timestamps();
            });
        }
        
        // Tabel cache
        if (!Capsule::schema()->hasTable('mod_fgo_cache')) {
            Capsule::schema()->create('mod_fgo_cache', function ($table) {
                $table->increments('id');
                $table->string('cache_key', 100)->unique();
                $table->longText('cache_value');
                $table->datetime('expires_at')->index();
                $table->timestamps();
            });
        }
        
        // Tabel coadă procesare
        if (!Capsule::schema()->hasTable('mod_fgo_queue')) {
            Capsule::schema()->create('mod_fgo_queue', function ($table) {
                $table->increments('id');
                $table->integer('invoice_id')->index();
                $table->enum('action', ['emit', 'cancel', 'payment', 'convert']);
                $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
                $table->integer('priority')->default(0);
                $table->longText('data')->nullable();
                $table->integer('attempts')->default(0);
                $table->datetime('process_after')->nullable();
                $table->timestamps();
                
                $table->index(['status', 'priority', 'process_after']);
            });
        }
        
        // Tabel notificări
        if (!Capsule::schema()->hasTable('mod_fgo_notifications')) {
            Capsule::schema()->create('mod_fgo_notifications', function ($table) {
                $table->increments('id');
                $table->enum('type', ['error', 'warning', 'info', 'report']);
                $table->string('subject', 255);
                $table->text('message');
                $table->boolean('sent')->default(false);
                $table->datetime('send_after')->nullable();
                $table->timestamps();
                
                $table->index(['sent', 'send_after']);
            });
        }
        
        // Tabel email-uri amânate
        if (!Capsule::schema()->hasTable('mod_fgo_delayed_emails')) {
            Capsule::schema()->create('mod_fgo_delayed_emails', function ($table) {
                $table->increments('id');
                $table->integer('invoice_id')->index();
                $table->string('template', 100);
                $table->boolean('sent')->default(false)->index();
                $table->timestamps();
            });
        }
    }
    
    /**
     * Creează câmpuri personalizate
     */
    protected function createCustomFields() {
        // Câmpuri pentru clienți
        $client_fields = [
            [
                'fieldname' => 'Factură Fiscală Directă',
                'fieldtype' => 'tickbox',
                'description' => 'Bifați pentru a primi direct factură fiscală în loc de proformă',
                'showorder' => 'on',
            ],
            [
                'fieldname' => 'Exclude Facturare FGO',
                'fieldtype' => 'tickbox',
                'description' => 'Bifați pentru a exclude acest client de la facturare automată',
                'adminonly' => 'on',
            ],
            [
                'fieldname' => 'Cod Centru Cost',
                'fieldtype' => 'text',
                'description' => 'Cod centru de cost pentru acest client',
                'adminonly' => 'on',
            ],
        ];
        
        foreach ($client_fields as $field) {
            $exists = Capsule::table('tblcustomfields')
                ->where('type', 'client')
                ->where('fieldname', $field['fieldname'])
                ->exists();
            
            if (!$exists) {
                Capsule::table('tblcustomfields')->insert(array_merge([
                    'type' => 'client',
                    'relid' => 0,
                    'fieldoptions' => '',
                    'regexpr' => '',
                    'adminonly' => $field['adminonly'] ?? '',
                    'required' => '',
                    'showorder' => $field['showorder'] ?? '',
                    'showinvoice' => '',
                    'sortorder' => 99,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ], $field));
            }
        }
        
        // Câmpuri pentru produse
        $product_fields = [
            [
                'fieldname' => 'Cod Articol FGO',
                'fieldtype' => 'text',
                'description' => 'Cod articol pentru sincronizare cu FGO',
                'adminonly' => 'on',
            ],
            [
                'fieldname' => 'Descriere FGO',
                'fieldtype' => 'textarea',
                'description' => 'Descriere personalizată pentru factură',
                'adminonly' => 'on',
            ],
        ];
        
        foreach ($product_fields as $field) {
            $exists = Capsule::table('tblcustomfields')
                ->where('type', 'product')
                ->where('fieldname', $field['fieldname'])
                ->exists();
            
            if (!$exists) {
                Capsule::table('tblcustomfields')->insert(array_merge([
                    'type' => 'product',
                    'relid' => 0,
                    'fieldoptions' => '',
                    'regexpr' => '',
                    'adminonly' => 'on',
                    'required' => '',
                    'showorder' => '',
                    'showinvoice' => '',
                    'sortorder' => 99,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ], $field));
            }
        }
    }
    
    /**
     * Creează directoare necesare
     */
    protected function createDirectories() {
        $dirs = [
            ROOTDIR . '/modules/addons/fgo/templates/',
            ROOTDIR . '/modules/addons/fgo/assets/',
            ROOTDIR . '/modules/addons/fgo/assets/css/',
            ROOTDIR . '/modules/addons/fgo/assets/js/',
            ROOTDIR . '/modules/addons/fgo/lang/',
        ];
        
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }
    
    /**
     * Actualizare la versiunea 2.0
     */
    protected function upgradeToV2() {
        // Adaugă coloane noi
        if (!Capsule::schema()->hasColumn('mod_fgo_logs', 'document_type')) {
            Capsule::schema()->table('mod_fgo_logs', function ($table) {
                $table->enum('document_type', ['factura', 'proforma', 'storno', 'chitanta'])
                    ->default('factura')
                    ->after('status');
            });
        }
        
        if (!Capsule::schema()->hasColumn('mod_fgo_logs', 'retry_count')) {
            Capsule::schema()->table('mod_fgo_logs', function ($table) {
                $table->integer('retry_count')->default(0)->after('response_data');
                $table->datetime('next_retry')->nullable()->after('retry_count');
            });
        }
    }
}