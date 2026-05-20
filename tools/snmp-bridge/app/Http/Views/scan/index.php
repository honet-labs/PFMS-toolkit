<!-- Page Header -->
<div class="mb-4">
    <div class="d-flex align-items-center justify-content-between gap-3">
        <div>
            <h1 class="h3 fw-700 gradient-text mb-1">
                <i class="fas fa-search me-2"></i>Device SNMP Scanner
            </h1>
            <p class="text-muted small mb-0">Run discovery pass and update internal sensor inventory</p>
        </div>
        <a class="btn btn-outline-primary" href="<?= e($baseUrl) ?>/index.php">
            <i class="fas fa-box me-2"></i>View Inventory
        </a>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger mb-4" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i>
        <strong>Scan Error</strong>
        <p class="mb-0 mt-2"><?= e($error) ?></p>
    </div>
<?php endif; ?>

<!-- Scan Form Card -->
<div class="card shadow-lg mb-4">
    <div class="card-header">
        <i class="fas fa-cog me-2"></i>Scan Configuration
    </div>
    <div class="card-body">
        <form method="post" action="<?= e($baseUrl) ?>/index.php/scan" class="scan-form">
            <input type="hidden" name="_token" value="<?= e($_SESSION['_token']) ?>">
            
            <div class="row g-4">
                <!-- Device Address -->
                <div class="col-12 col-md-6">
                    <div class="form-group">
                        <label class="form-label" for="ip_address">
                            <i class="fas fa-globe text-primary me-1"></i>Device IP or Hostname
                        </label>
                        <input 
                            class="form-control form-control-lg" 
                            id="ip_address" 
                            name="ip_address" 
                            placeholder="192.168.1.1 or router.example.com"
                            value="<?= e($defaults['ip_address'] ?? '') ?>" 
                            required>
                        <small class="form-text text-muted">IPv4 address or FQDN</small>
                    </div>
                </div>

                <!-- SNMP Version -->
                <div class="col-12 col-md-3">
                    <div class="form-group">
                        <label class="form-label" for="version">
                            <i class="fas fa-code-branch text-primary me-1"></i>SNMP Version
                        </label>
                        <select class="form-select form-select-lg" id="version" name="version">
                            <option value="2c" <?= ($defaults['version'] ?? '2c') === '2c' ? 'selected' : '' ?>>SNMPv2c</option>
                            <option value="1" <?= ($defaults['version'] ?? '') === '1' ? 'selected' : '' ?>>SNMPv1</option>
                        </select>
                    </div>
                </div>

                <!-- Community String -->
                <div class="col-12 col-md-3">
                    <div class="form-group">
                        <label class="form-label" for="community">
                            <i class="fas fa-key text-primary me-1"></i>Community String
                        </label>
                        <input 
                            class="form-control form-control-lg" 
                            id="community" 
                            name="community" 
                            type="password"
                            placeholder="Community string"
                            value="<?= e($defaults['community'] ?? 'public') ?>" 
                            required>
                    </div>
                </div>

                <!-- Port -->
                <div class="col-12 col-md-12">
                    <div class="form-group">
                        <label class="form-label" for="port">
                            <i class="fas fa-plug text-primary me-1"></i>SNMP Port
                        </label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-port"></i></span>
                            <input 
                                class="form-control" 
                                id="port" 
                                name="port" 
                                type="number" 
                                min="1" 
                                max="65535" 
                                placeholder="161"
                                value="<?= e($defaults['port'] ?? 161) ?>" 
                                required>
                        </div>
                        <small class="form-text text-muted">Default SNMP port is 161</small>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="col-12">
                    <button class="btn btn-primary btn-lg" type="submit">
                        <i class="fas fa-play-circle me-2"></i>Start Scan
                    </button>
                    <button class="btn btn-outline-secondary btn-lg ms-2" type="reset">
                        <i class="fas fa-redo me-2"></i>Reset
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Info Cards -->
<div class="row g-4">
    <div class="col-12 col-md-6">
        <div class="card hover-lift">
            <div class="card-body">
                <div class="d-flex align-items-start">
                    <div class="flex-shrink-0">
                        <div class="p-3 bg-primary-lighter rounded-lg">
                            <i class="fas fa-info-circle fa-lg text-primary"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <h5 class="card-title mb-2">Supported Devices</h5>
                        <p class="text-muted small mb-0">
                            Cisco, Huawei, ZTE, Raisecom, Alcatel/Nokia and any SNMP-compatible device
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-12 col-md-6">
        <div class="card hover-lift">
            <div class="card-body">
                <div class="d-flex align-items-start">
                    <div class="flex-shrink-0">
                        <div class="p-3 bg-success-lighter rounded-lg">
                            <i class="fas fa-database fa-lg text-success"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <h5 class="card-title mb-2">Discovery Includes</h5>
                        <p class="text-muted small mb-0">
                            Optical sensors, environmental data, CPU/memory, interfaces, system metrics and more
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
