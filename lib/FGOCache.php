<?php
/**
 * FGO Cache - Sistem de cache pentru performanță
 */

namespace FGO;

use WHMCS\Database\Capsule;

class FGOCache {
    protected $config;
    protected $enabled;
    protected $default_ttl;
    
    public function __construct($config) {
        $this->config = $config;
        $this->enabled = $config['enable_cache'] == 'on';
        $this->default_ttl = intval($config['cache_ttl'] ?? 24) * 3600;
    }
    
    /**
     * Obține din cache
     */
    public function get($key) {
        if (!$this->enabled) {
            return false;
        }
        
        $entry = Capsule::table('mod_fgo_cache')
            ->where('cache_key', $key)
            ->where('expires_at', '>', date('Y-m-d H:i:s'))
            ->first();
        
        if ($entry) {
            return json_decode($entry->cache_value, true);
        }
        
        return false;
    }
    
    /**
     * Salvează în cache
     */
    public function set($key, $value, $ttl = null) {
        if (!$this->enabled) {
            return false;
        }
        
        if ($ttl === null) {
            $ttl = $this->default_ttl;
        }
        
        $expires_at = date('Y-m-d H:i:s', time() + $ttl);
        
        $exists = Capsule::table('mod_fgo_cache')
            ->where('cache_key', $key)
            ->exists();
        
        if ($exists) {
            Capsule::table('mod_fgo_cache')
                ->where('cache_key', $key)
                ->update([
                    'cache_value' => json_encode($value),
                    'expires_at' => $expires_at,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
        } else {
            Capsule::table('mod_fgo_cache')->insert([
                'cache_key' => $key,
                'cache_value' => json_encode($value),
                'expires_at' => $expires_at,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
        
        return true;
    }
    
    /**
     * Șterge din cache
     */
    public function delete($key) {
        return Capsule::table('mod_fgo_cache')
            ->where('cache_key', $key)
            ->delete();
    }
    
    /**
     * Șterge cache după pattern
     */
    public function deletePattern($pattern) {
        return Capsule::table('mod_fgo_cache')
            ->where('cache_key', 'like', $pattern)
            ->delete();
    }
    
    /**
     * Curăță tot cache-ul
     */
    public function clear() {
        return Capsule::table('mod_fgo_cache')->truncate();
    }
    
    /**
     * Curăță intrări expirate
     */
    public function clearExpired() {
        return Capsule::table('mod_fgo_cache')
            ->where('expires_at', '<', date('Y-m-d H:i:s'))
            ->delete();
    }
    
    /**
     * Obține statistici cache
     */
    public function getStats() {
        $total = Capsule::table('mod_fgo_cache')->count();
        $active = Capsule::table('mod_fgo_cache')
            ->where('expires_at', '>', date('Y-m-d H:i:s'))
            ->count();
        $expired = $total - $active;
        
        $total_size = Capsule::table('mod_fgo_cache')
            ->sum(Capsule::raw('LENGTH(cache_value)'));
        
        return [
            'total_entries' => $total,
            'active_entries' => $active,
            'expired_entries' => $expired,
            'total_size' => $total_size ?: 0,
        ];
    }
    
    /**
     * Obține intrări cache
     */
    public function getEntries($limit = 100) {
        return Capsule::table('mod_fgo_cache')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }
    
    /**
     * Remember - obține din cache sau execută callback
     */
    public function remember($key, $ttl, $callback) {
        $value = $this->get($key);
        
        if ($value === false) {
            $value = $callback();
            $this->set($key, $value, $ttl);
        }
        
        return $value;
    }
    
    /**
     * Cache pentru nomenclatoare
     */
    public function getNomenclator($type, $api) {
        return $this->remember('nomenclator_' . $type, 86400, function() use ($type, $api) {
            return $api->getNomenclator($type);
        });
    }
    
    /**
     * Refresh nomenclatoare
     */
    public function refreshNomenclators($api) {
        $nomenclators = ['tara', 'judet', 'tva', 'banca', 'tipincasare', 'tipfactura', 'tipclient'];
        
        foreach ($nomenclators as $nom) {
            $this->delete('nomenclator_' . $nom);
            $this->getNomenclator($nom, $api);
        }
    }
}