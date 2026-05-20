<!-- Page Header -->
<div class="mb-4">
    <div class="d-flex align-items-center justify-content-between gap-3">
        <div>
            <h1 class="h3 fw-700 gradient-text mb-1">
                <i class="fas fa-check-circle me-2 text-success"></i>Scan Completed Successfully
            </h1>
            <p class="text-muted small mb-0">Discovery completed and inventory has been updated</p>
        </div>
        <a class="btn btn-primary" href="<?= e($baseUrl) ?>/index.php">
            <i class="fas fa-box me-2"></i>Review Inventory
        </a>
    </div>
</div>

<!-- Summary Stats -->
<div class="row g-4 mb-4">
    <div class="col-12 col-md-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-label">
                <i class="fas fa-server text-primary me-2"></i>Device IP
            </div>
            <div class="stat-value">
                <?= e($result['device']['ip_address']) ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-md-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-label">
                <i class="fas fa-industry text-secondary me-2"></i>Vendor
            </div>
            <div class="stat-value">
                <span class="badge badge-primary"><?= e($result['vendor']) ?></span>
            </div>
        </div>
    </div>

    <div class="col-12 col-md-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-label">
                <i class="fas fa-network-wired text-info me-2"></i>Hostname
            </div>
            <div class="stat-value small">
                <?= e($result['device']['hostname']) ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-md-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-label">
                <i class="fas fa-database text-success me-2"></i>Sensors Discovered
            </div>
            <div class="stat-value">
                <?= e(count($result['sensors'])) ?>
            </div>
        </div>
    </div>
</div>

<!-- Sensors Table Card -->
<div class="card shadow-lg">
    <div class="card-header">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <i class="fas fa-list me-2"></i>Discovered Sensors
            </div>
            <span class="badge badge-success"><?= e(count($result['sensors'])) ?> Total</span>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th><i class="fas fa-tag text-primary me-2"></i>Class</th>
                    <th><i class="fas fa-heading text-primary me-2"></i>Sensor Name</th>
                    <th><i class="fas fa-plug text-primary me-2"></i>Interface</th>
                    <th><i class="fas fa-chart-line text-primary me-2"></i>Value</th>
                    <th><i class="fas fa-ruler text-primary me-2"></i>Unit</th>
                    <th><i class="fas fa-code text-primary me-2"></i>OID</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($result['sensors'] as $sensor): ?>
                <tr class="fade-in">
                    <td>
                        <span class="badge badge-primary">
                            <?= e($sensor['sensor_class']) ?>
                        </span>
                    </td>
                    <td>
                        <strong><?= e($sensor['sensor_name']) ?></strong>
                    </td>
                    <td>
                        <?php if (!empty($sensor['interface_name'])): ?>
                            <code class="bg-light p-1 rounded"><?= e($sensor['interface_name']) ?></code>
                        <?php else: ?>
                            <span class="text-muted small">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong class="text-success">
                            <?= e($sensor['normalized_value'] ?? $sensor['raw_value'] ?? '-') ?>
                        </strong>
                    </td>
                    <td>
                        <?php if (!empty($sensor['unit'])): ?>
                            <span class="badge bg-light text-dark">
                                <?= e($sensor['unit']) ?>
                            </span>
                        <?php else: ?>
                            <span class="text-muted small">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <code class="small text-muted">
                            <?= substr(e($sensor['oid']), 0, 30) ?>...
                        </code>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card-footer text-muted small">
        <i class="fas fa-info-circle me-2"></i>
        All sensors have been saved to the internal inventory database and are ready for provisioning to PandoraFMS.
    </div>
</div>
