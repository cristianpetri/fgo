<?php
/**
 * FGO Queue - Sistem de coadă pentru procesare asincronă
 */

namespace FGO;

use WHMCS\Database\Capsule;

class FGOQueue {
    protected $config;
    protected $helper;
    
    public function __construct($config, $helper = null) {
        $this->config = $config;
        $this->helper = $helper;
    }
    
    /**
     * Adaugă element în coadă
     */
    public function add($invoice_id, $action, $data = null, $priority = 0, $delay = null) {
        $process_after = null;
        if ($delay) {
            $process_after = date('Y-m-d H:i:s', strtotime("+{$delay} minutes"));
        }
        
        Capsule::table('mod_fgo_queue')->insert([
            'invoice_id' => $invoice_id,
            'action' => $action,
            'status' => 'pending',
            'priority' => $priority,
            'data' => json_encode($data),
            'process_after' => $process_after,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        return true;
    }
    
    /**
     * Procesează coada
     */
    public function process($limit = 10) {
        $processed = 0;
        
        // Obține elemente din coadă
        $items = $this->getQueueItems($limit);
        
        foreach ($items as $item) {
            if ($this->processItem($item)) {
                $processed++;
            }
        }
        
        return $processed;
    }
    
    /**
     * Obține elemente din coadă
     */
    protected function getQueueItems($limit) {
        return Capsule::table('mod_fgo_queue')
            ->where('status', 'pending')
            ->where(function($query) {
                $query->whereNull('process_after')
                    ->orWhere('process_after', '<=', date('Y-m-d H:i:s'));
            })
            ->orderBy('priority', 'desc')
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get();
    }
    
    /**
     * Procesează un element
     */
    protected function processItem($item) {
        // Marchează ca în procesare
        $this->updateStatus($item->id, 'processing');
        
        try {
            $result = $this->executeAction($item);
            
            if ($result && $result['success']) {
                $this->updateStatus($item->id, 'completed');
                return true;
            } else {
                throw new \Exception($result['message'] ?? 'Eroare necunoscută');
            }
        } catch (\Exception $e) {
            return $this->handleError($item, $e->getMessage());
        }
    }
    
    /**
     * Execută acțiunea
     */
    protected function executeAction($item) {
        if (!$this->helper) {
            throw new \Exception('Helper not initialized');
        }
        
        switch ($item->action) {
            case 'emit':
                return $this->helper->emitInvoice($item->invoice_id);
                
            case 'cancel':
                $data = json_decode($item->data, true);
                return $this->helper->cancelInvoice(
                    $item->invoice_id,
                    $data['serie'] ?? '',
                    $data['numar'] ?? ''
                );
                
            case 'payment':
                $data = json_decode($item->data, true);
                return $this->helper->registerPayment($item->invoice_id, $data);
                
            case 'convert':
                return $this->helper->convertProformaToInvoice($item->invoice_id);
                
            default:
                throw new \Exception('Acțiune necunoscută: ' . $item->action);
        }
    }
    
    /**
     * Gestionează erori
     */
    protected function handleError($item, $error_message) {
        $attempts = $item->attempts + 1;
        $max_attempts = 5;
        
        if ($attempts >= $max_attempts) {
            // Marchează ca eșuat definitiv
            $this->updateStatus($item->id, 'failed', $attempts);
            logActivity("FGO Queue: Item #{$item->id} failed after {$attempts} attempts: {$error_message}");
            return false;
        } else {
            // Reprogramează cu backoff exponențial
            $delay = pow(2, $attempts); // 2, 4, 8, 16, 32 minute
            $next_retry = date('Y-m-d H:i:s', strtotime("+{$delay} minutes"));
            
            Capsule::table('mod_fgo_queue')
                ->where('id', $item->id)
                ->update([
                    'status' => 'pending',
                    'attempts' => $attempts,
                    'process_after' => $next_retry,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            
            logActivity("FGO Queue: Item #{$item->id} rescheduled for retry #{$attempts}");
            return false;
        }
    }
    
    /**
     * Actualizează status
     */
    protected function updateStatus($id, $status, $attempts = null) {
        $update = [
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        
        if ($attempts !== null) {
            $update['attempts'] = $attempts;
        }
        
        Capsule::table('mod_fgo_queue')
            ->where('id', $id)
            ->update($update);
    }
    
    /**
     * Obține statistici coadă
     */
    public function getStats() {
        return [
            'pending' => Capsule::table('mod_fgo_queue')->where('status', 'pending')->count(),
            'processing' => Capsule::table('mod_fgo_queue')->where('status', 'processing')->count(),
            'completed' => Capsule::table('mod_fgo_queue')->where('status', 'completed')->count(),
            'failed' => Capsule::table('mod_fgo_queue')->where('status', 'failed')->count(),
        ];
    }
    
    /**
     * Curăță coada veche
     */
    public function cleanup($days = 30) {
        $date = date('Y-m-d', strtotime("-{$days} days"));
        
        return Capsule::table('mod_fgo_queue')
            ->whereIn('status', ['completed', 'failed'])
            ->whereDate('updated_at', '<', $date)
            ->delete();
    }
    
    /**
     * Reîncearcă elemente eșuate
     */
    public function retryFailed() {
        return Capsule::table('mod_fgo_queue')
            ->where('status', 'failed')
            ->update([
                'status' => 'pending',
                'attempts' => 0,
                'process_after