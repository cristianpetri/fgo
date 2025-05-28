<?php
/**
 * Base Controller - Clasă de bază pentru toate controller-ele
 */

namespace FGO\Controllers;

class BaseController {
    protected $vars;
    protected $helper;
    protected $smarty;
    
    public function __construct($vars) {
        $this->vars = $vars;
        
        // Inițializează helper
        require_once dirname(__DIR__) . '/lib/FGOHelper.php';
        $this->helper = new \FGOHelper($vars);
        
        // Inițializează Smarty
        global $templates_compiledir;
        $this->smarty = new \Smarty();
        $this->smarty->template_dir = dirname(__DIR__) . '/templates/';
        $this->smarty->compile_dir = $templates_compiledir;
        
        // Variabile globale pentru template
        $this->smarty->assign('modulelink', $vars['modulelink']);
        $this->smarty->assign('version', $vars['version']);
        $this->smarty->assign('_lang', $this->getLang());
    }
    
    /**
     * Afișează un template
     */
    protected function display($template, $vars = []) {
        foreach ($vars as $key => $value) {
            $this->smarty->assign($key, $value);
        }
        
        $this->smarty->display($template);
    }
    
    /**
     * Returnează JSON
     */
    protected function json($data) {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
    
    /**
     * Redirect
     */
    protected function redirect($url) {
        header('Location: ' . $url);
        exit;
    }
    
    /**
     * Obține parametru GET/POST
     */
    protected function getParam($name, $default = null) {
        return $_REQUEST[$name] ?? $default;
    }
    
    /**
     * Verifică dacă e request AJAX
     */
    protected function isAjax() {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    }
    
    /**
     * Obține texte traduse
     */
    protected function getLang() {
        $lang_file = dirname(__DIR__) . '/lang/romanian.php';
        if (file_exists($lang_file)) {
            return include $lang_file;
        }
        return [];
    }
    
    /**
     * Paginare
     */
    protected function paginate($total, $per_page = 20) {
        $page = max(1, intval($this->getParam('page', 1)));
        $total_pages = ceil($total / $per_page);
        $offset = ($page - 1) * $per_page;
        
        return [
            'page' => $page,
            'per_page' => $per_page,
            'total' => $total,
            'total_pages' => $total_pages,
            'offset' => $offset,
            'has_prev' => $page > 1,
            'has_next' => $page < $total_pages,
        ];
    }
    
    /**
     * Validare CSRF
     */
    protected function validateCSRF() {
        // WHMCS gestionează CSRF automat
        return true;
    }
    
    /**
     * Log activitate
     */
    protected function logActivity($message) {
        logActivity('FGO: ' . $message);
    }
}