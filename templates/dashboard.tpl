{* Dashboard Template for FGO Module *}

<h2>{$_lang.dashboard_title}</h2>

{* Statistics Cards *}
<div class="row">
    <div class="col-md-4">
        <div class="fgo-stat-card">
            <i class="fas fa-file-invoice fa-3x text-primary"></i>
            <h3>{$stats.total_processed}</h3>
            <p>{$_lang.total_processed}</p>
        </div>
    </div>
    <div class="col-md-4">
        <div class="fgo-stat-card">
            <i class="fas fa-check-circle fa-3x text-success"></i>
            <h3>{$stats.success_count}</h3>
            <p>{$_lang.success_count}</p>
        </div>
    </div>
    <div class="col-md-4">
        <div class="fgo-stat-card">
            <i class="fas fa-exclamation-triangle fa-3x text-danger"></i>
            <h3>{$stats.error_count}</h3>
            <p>{$_lang.error_count}</p>
        </div>
    </div>
    <div class="col-md-4">
        <div class="fgo-stat-card">
            <i class="fas fa-clock fa-3x text-warning"></i>
            <h3>{$stats.pending_count}</h3>
            <p>{$_lang.pending_count}</p>
        </div>
    </div>
    <div class="col-md-4">
        <div class="fgo-stat-card">
            <i class="fas fa-money-bill fa-3x text-info"></i>
            <h3>{$stats.total_value} RON</h3>
            <p>{$_lang.total_value}</p>
        </div>
    </div>
    <div class="col-md-4">
        <div class="fgo-stat-card">
            <i class="fas fa-percentage fa-3x {if $stats.success_rate > 90}text-success{else}text-warning{/if}"></i>
            <h3>{$stats.success_rate}%</h3>
            <p>{$_lang.success_rate}</p>
        </div>
    </div>
</div>

{* Charts *}
<div class="row">
    <div class="col-md-8">
        <div class="fgo-chart-container">
            <h3>{$_lang.evolution_chart}</h3>
            <canvas id="chartEmitere" height="100"></canvas>
        </div>
    </div>
    <div class="col-md-4">
        <div class="fgo-chart-container">
            <h3>{$_lang.document_types}</h3>
            <canvas id="chartTipuri" height="200"></canvas>
        </div>
    </div>
</div>

{* Invoices needing attention *}
{if $attention_needed|@count > 0}
<div class="panel panel-warning">
    <div class="panel-heading">
        <h3 class="panel-title">{$_lang.attention_needed}</h3>
    </div>
    <div class="panel-body">
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>ID Factură</th>
                        <th>Client</th>
                        <th>Valoare</th>
                        <th>Problemă</th>
                        <th>Data</th>
                        <th>Acțiuni</th>
                    </tr>
                </thead>
                <tbody>
                    {foreach $attention_needed as $invoice}
                    <tr>
                        <td><a href="invoices.php?action=edit&id={$invoice->id}">#{$invoice->id}</a></td>
                        <td>{$invoice->client_name|escape}</td>
                        <td>{$invoice->total} {$invoice->currency}</td>
                        <td>
                            <span class="label label-{if $invoice->issue_type == 'error'}danger{else}warning{/if}">
                                {$invoice->issue}
                            </span>
                        </td>
                        <td>{$invoice->date}</td>
                        <td>
                            <a href="{$modulelink}&action=manual&invoice_id={$invoice->id}" 
                               class="btn btn-xs btn-primary">
                                {$_lang.action_emit}
                            </a>
                        </td>
                    </tr>
                    {/foreach}
                </tbody>
            </table>
        </div>
    </div>
</div>
{/if}

{* Recent operations *}
<div class="panel panel-default">
    <div class="panel-heading">
        <h3 class="panel-title">{$_lang.recent_operations}</h3>
    </div>
    <div class="panel-body">
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Factură</th>
                        <th>Client</th>
                        <th>Tip</th>
                        <th>Serie/Număr</th>
                        <th>Status</th>
                        <th>Mesaj</th>
                        <th>Data</th>
                    </tr>
                </thead>
                <tbody>
                    {foreach $recent_logs as $log}
                    <tr>
                        <td>{$log->id}</td>
                        <td><a href="invoices.php?action=edit&id={$log->invoice_id}">#{$log->invoice_id}</a></td>
                        <td>{$log->client_name|escape}</td>
                        <td>{$log->document_type|ucfirst}</td>
                        <td>{$log->fgo_serie|default:'-'}/{$log->fgo_numar|default:'-'}</td>
                        <td>
                            <span class="fgo-status fgo-status-{$log->status}">
                                {$_lang["status_`$log->status`"]}
                            </span>
                        </td>
                        <td>{$log->message|escape|truncate:50}</td>
                        <td>{$log->created_at}</td>
                    </tr>
                    {/foreach}
                </tbody>
            </table>
        </div>
    </div>
</div>

{* JavaScript for charts *}
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
window.chartData = {
    evolution: {
        labels: {$chart_data.labels|@json_encode},
        success: {$chart_data.success|@json_encode},
        errors: {$chart_data.errors|@json_encode}
    },
    types: {
        labels: {$chart_data.types|json_encode|default:'[]'},
        values: {$chart_data.types|json_encode|default:'[]'}
    }
};
</script>