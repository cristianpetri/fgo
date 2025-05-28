<?php
/**
 * Dashboard Controller
 */

namespace FGO\Controllers;

use WHMCS\Database\Capsule;

class DashboardController extends BaseController {
    
    public function index() {
        // Obține statistici
        $stats = $this->helper->getDashboardStats();
        
        // Facturi care necesită atenție
        $attention_needed = $this->getInvoicesNeedingAttention();
        
        // Date pentru grafice
        $chart_data = $this->getChartData();
        
        // Recent logs
        $recent_logs = $this->getRecentLogs();
        
        // Afișează template
        $this->display('dashboard.tpl', [
            'stats' => $stats,
            'attention_needed' => $attention_needed,
            'chart_data' => $chart_data,
            'recent_logs' => $recent_logs,
        ]);
    }
    
    /**
     * Obține facturi care necesită atenție
     */
    protected function getInvoicesNeedingAttention() {
        $invoices = [];
        
        // Facturi cu erori repetate
        $failed = Capsule::table('mod_fgo_logs as l')
            ->select('l.invoice_id', 'i.total', 'i.currency', 'i.date', 
                     'c.firstname', 'c.lastname', 'c.companyname',
                     Capsule::raw('COUNT(l.id) as error_count'),
                     Capsule::raw('MAX(l.message) as last_error'))
            ->join('tblinvoices as i', 'l.invoice_id', '=', 'i.id')
            ->join('tblclients as c', 'i.userid', '=', 'c.id')
            ->where('l.status', 'error')
            ->where('l.created_at', '>', date('Y-m-d H:i:s', strtotime('-7 days')))
            ->groupBy('l.invoice_id')
            ->having('error_count', '>=', 3)
            ->limit(5)
            ->get();
        
        foreach ($failed as $invoice) {
            $invoice->client_name = $invoice->companyname ?: $invoice->firstname . ' ' . $invoice->lastname;
            $invoice->issue = 'Erori multiple';
            $invoice->issue_type = 'error';
            $invoices[] = $invoice;
        }
        
        // Facturi vechi neemise
        $old_unpaid = Capsule::table('tblinvoices as i')
            ->select('i.id', 'i.total', 'i.currency', 'i.date', 
                     'c.firstname', 'c.lastname', 'c.companyname')
            ->leftJoin('mod_fgo_logs as l', function($join) {
                $join->on('i.id', '=', 'l.invoice_id')
                    ->where('l.status', '=', 'success');
            })
            ->join('tblclients as c', 'i.userid', '=', 'c.id')
            ->whereNull('l.id')
            ->where('i.status', '!=', 'Draft')
            ->where('i.status', '!=', 'Cancelled')
            ->where('i.date', '<', date('Y-m-d', strtotime('-30 days')))
            ->limit(5)
            ->get();
        
        foreach ($old_unpaid as $invoice) {
            $invoice->client_name = $invoice->companyname ?: $invoice->firstname . ' ' . $invoice->lastname;
            $invoice->issue = 'Neemisă >30 zile';
            $invoice->issue_type = 'warning';
            $invoices[] = $invoice;
        }
        
        return array_slice($invoices, 0, 10);
    }
    
    /**
     * Obține date pentru grafice
     */
    protected function getChartData() {
        $days = 30;
        $data = [
            'labels' => [],
            'success' => [],
            'errors' => [],
            'types' => [],
        ];
        
        // Date zilnice
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $data['labels'][] = date('d M', strtotime($date));
            
            $success = Capsule::table('mod_fgo_logs')
                ->where('status', 'success')
                ->whereDate('created_at', $date)
                ->count();
            
            $errors = Capsule::table('mod_fgo_logs')
                ->where('status', 'error')
                ->whereDate('created_at', $date)
                ->count();
            
            $data['success'][] = $success;
            $data['errors'][] = $errors;
        }
        
        // Tipuri documente
        $types = Capsule::table('mod_fgo_logs')
            ->select('document_type', Capsule::raw('COUNT(*) as count'))
            ->where('status', 'success')
            ->where('created_at', '>', date('Y-m-d H:i:s', strtotime('-30 days')))
            ->groupBy('document_type')
            ->get();
        
        foreach ($types as $type) {
            $data['types'][] = [
                'label' => ucfirst($type->document_type),
                'value' => $type->count,
            ];
        }
        
        return $data;
    }
    
    /**
     * Obține log-uri recente
     */
    protected function getRecentLogs() {
        return Capsule::table('mod_fgo_logs as l')
            ->select('l.*', 'i.total', 'i.currency', 'c.firstname', 'c.lastname', 'c.companyname')
            ->join('tblinvoices as i', 'l.invoice_id', '=', 'i.id')
            ->join('tblclients as c', 'i.userid', '=', 'c.id')
            ->orderBy('l.created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function($log) {
                $log->client_name = $log->companyname ?: $log->firstname . ' ' . $log->lastname;
                return $log;
            });
    }
}