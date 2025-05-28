/**
 * FGO Module JavaScript
 */

// Namespace
var FGO = FGO || {};

// Initialize
FGO.init = function() {
    // Initialize tooltips
    $('[data-toggle="tooltip"]').tooltip();
    
    // Initialize select2 if available
    if ($.fn.select2) {
        $('.fgo-select2').select2();
    }
    
    // Bind events
    FGO.bindEvents();
};

// Bind events
FGO.bindEvents = function() {
    // Check all checkboxes
    $('#checkAll').on('change', function() {
        $('.invoice-check').prop('checked', $(this).prop('checked'));
    });
    
    // Filter form auto-submit
    $('.fgo-auto-submit').on('change', function() {
        $(this).closest('form').submit();
    });
    
    // Confirm actions
    $('[data-confirm]').on('click', function(e) {
        if (!confirm($(this).data('confirm'))) {
            e.preventDefault();
            return false;
        }
    });
};

// AJAX helper
FGO.ajax = function(url, data, callback) {
    $.ajax({
        url: url,
        type: 'POST',
        data: data,
        dataType: 'json',
        success: function(response) {
            if (callback) callback(response);
        },
        error: function(xhr, status, error) {
            FGO.showError('Eroare AJAX: ' + error);
        }
    });
};

// Show loading
FGO.showLoading = function(element) {
    $(element).html('<div class="fgo-loading"><i class="fas fa-spinner fa-spin"></i></div>');
};

// Show success message
FGO.showSuccess = function(message) {
    var alert = $('<div class="alert alert-success alert-dismissible fgo-alert">' +
        '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
        message + '</div>');
    $('.fgo-content').prepend(alert);
    
    setTimeout(function() {
        alert.fadeOut();
    }, 5000);
};

// Show error message
FGO.showError = function(message) {
    var alert = $('<div class="alert alert-danger alert-dismissible fgo-alert">' +
        '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
        message + '</div>');
    $('.fgo-content').prepend(alert);
};

// Format bytes
FGO.formatBytes = function(bytes, decimals = 2) {
    if (bytes === 0) return '0 Bytes';
    
    const k = 1024;
    const dm = decimals < 0 ? 0 : decimals;
    const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    
    return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
};

// Save gateway mapping
FGO.saveGatewayMapping = function(gateway) {
    var tip_incasare = $('select[name="tip_incasare_' + gateway + '"]').val();
    var cont_incasare = $('input[name="cont_incasare_' + gateway + '"]').val();
    
    if (!tip_incasare) {
        alert('Selectați un tip de încasare!');
        return;
    }
    
    FGO.ajax(WHMCS.adminBaseRoutePath + '/addonmodules.php?module=fgo&action=ajax&method=saveGatewayMapping', {
        gateway: gateway,
        tip_incasare: tip_incasare,
        cont_incasare: cont_incasare
    }, function(response) {
        if (response.success) {
            FGO.showSuccess('Mapare salvată cu succes!');
        } else {
            FGO.showError('Eroare la salvare: ' + response.message);
        }
    });
};

// Save TVA mapping
FGO.saveTvaMapping = function(category_id) {
    var cota_tva = $('input[name="cota_tva_' + category_id + '"]').val();
    var cod_centru_cost = $('input[name="cod_centru_cost_' + category_id + '"]').val();
    var cod_gestiune = $('input[name="cod_gestiune_' + category_id + '"]').val();
    
    FGO.ajax(WHMCS.adminBaseRoutePath + '/addonmodules.php?module=fgo&action=ajax&method=saveTvaMapping', {
        category_id: category_id,
        cota_tva: cota_tva,
        cod_centru_cost: cod_centru_cost,
        cod_gestiune: cod_gestiune
    }, function(response) {
        if (response.success) {
            FGO.showSuccess('Mapare salvată cu succes!');
        } else {
            FGO.showError('Eroare la salvare: ' + response.message);
        }
    });
};

// View queue data
FGO.viewQueueData = function(id) {
    $.get(WHMCS.adminBaseRoutePath + '/addonmodules.php?module=fgo&action=ajax&method=getQueueData&id=' + id, function(data) {
        $('#queueDataContent').text(JSON.stringify(JSON.parse(data), null, 2));
        $('#queueDataModal').modal('show');
    });
};

// Retry queue item
FGO.retryQueueItem = function(id) {
    if (confirm('Sigur doriți să reîncercați acest element?')) {
        FGO.ajax(WHMCS.adminBaseRoutePath + '/addonmodules.php?module=fgo&action=ajax&method=retryQueue', {
            id: id
        }, function() {
            location.reload();
        });
    }
};

// View cache entry
FGO.viewCacheEntry = function(key) {
    $.get(WHMCS.adminBaseRoutePath + '/addonmodules.php?module=fgo&action=ajax&method=getCacheEntry&key=' + encodeURIComponent(key), function(data) {
        $('#cacheContent').text(JSON.stringify(JSON.parse(data), null, 2));
        $('#cacheModal').modal('show');
    });
};

// Delete cache entry
FGO.deleteCacheEntry = function(key) {
    if (confirm('Sigur doriți să ștergeți această intrare?')) {
        FGO.ajax(WHMCS.adminBaseRoutePath + '/addonmodules.php?module=fgo&action=ajax&method=deleteCacheEntry', {
            key: key
        }, function() {
            location.reload();
        });
    }
};

// Validate code
FGO.validateCode = function() {
    var code = $('#validatorForm input[name="code"]').val();
    
    FGO.ajax(WHMCS.adminBaseRoutePath + '/addonmodules.php?module=fgo&action=ajax&method=validateCode', {
        code: code
    }, function(response) {
        var html = '';
        if (response.valid) {
            html = '<div class="alert alert-success"><strong>Valid!</strong> ' + response.message + '</div>';
        } else {
            html = '<div class="alert alert-danger"><strong>Invalid!</strong> ' + response.message + '</div>';
        }
        $('#validationResult').html(html);
    });
    
    return false;
};

// Validate all clients
FGO.validateAllClients = function() {
    $('#massValidationResult').html('<div class="alert alert-info">Se procesează...</div>');
    
    FGO.ajax(WHMCS.adminBaseRoutePath + '/addonmodules.php?module=fgo&action=ajax&method=validateAllClients', {}, function(response) {
        var html = '<div class="alert alert-' + (response.errors > 0 ? 'warning' : 'success') + '">';
        html += 'Total verificați: ' + response.total + '<br>';
        html += 'Valizi: ' + response.valid + '<br>';
        html += 'Invalizi: ' + response.invalid + '<br>';
        if (response.errors > 0) {
            html += '<br><strong>Clienți cu CUI/CNP invalid:</strong><br>';
            html += response.invalid_clients.join('<br>');
        }
        html += '</div>';
        $('#massValidationResult').html(html);
    });
};

// Export report
FGO.exportReport = function(format) {
    var params = window.location.search + '&export=' + format;
    window.open(WHMCS.adminBaseRoutePath + '/addonmodules.php?module=fgo&action=export' + params);
};

// Clean old logs
FGO.cleanOldLogs = function() {
    if (confirm('Sigur doriți să ștergeți log-urile vechi?')) {
        FGO.ajax(WHMCS.adminBaseRoutePath + '/addonmodules.php?module=fgo&action=ajax&method=cleanOldLogs', {}, function(response) {
            alert('Au fost șterse ' + response.deleted + ' înregistrări!');
            location.reload();
        });
    }
};

// Reset failed invoices
FGO.resetFailedInvoices = function() {
    if (confirm('Sigur doriți să resetați facturile eșuate pentru reîncercare?')) {
        FGO.ajax(WHMCS.adminBaseRoutePath + '/addonmodules.php?module=fgo&action=ajax&method=resetFailedInvoices', {}, function(response) {
            alert('Au fost resetate ' + response.reset + ' facturi!');
        });
    }
};

// Clear all data
FGO.clearAllData = function() {
    if (confirm('ATENȚIE! Această acțiune va șterge TOATE datele modulului FGO!\n\nSigur doriți să continuați?')) {
        if (confirm('Confirmați din nou ștergerea TUTUROR datelor?')) {
            FGO.ajax(WHMCS.adminBaseRoutePath + '/addonmodules.php?module=fgo&action=ajax&method=clearAllData', {}, function(response) {
                alert('Toate datele au fost șterse!');
                location.reload();
            });
        }
    }
};

//