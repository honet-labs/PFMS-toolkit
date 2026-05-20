<!-- Page Header -->
<div class="mb-4">
    <div class="d-flex align-items-center justify-content-between gap-3">
        <div>
            <h1 class="h3 fw-700 gradient-text mb-1">
                <i class="fas fa-cloud-upload-alt me-2"></i>PandoraFMS Provisioning Result
            </h1>
            <p class="text-muted small mb-0">Direct database insert summary for selected sensors</p>
        </div>
        <a class="btn btn-outline-primary" href="<?= e($baseUrl) ?>/index.php">
            <i class="fas fa-arrow-left me-2"></i>Back to Inventory
        </a>
    </div>
</div>

<?php if ($error): ?>
    <!-- Error Alert -->
    <div class="alert alert-danger alert-lg mb-4" role="alert">
        <div class="d-flex align-items-start">
            <div class="flex-shrink-0">
                <i class="fas fa-exclamation-triangle fa-lg"></i>
            </div>
            <div class="flex-grow-1 ms-3">
                <h5 class="alert-heading">Provisioning Failed</h5>
                <p class="mb-0"><?= e($error) ?></p>
            </div>
        </div>
    </div>

<?php elseif ($result): ?>
    <!-- Success Stats -->
    <div class="row g-4 mb-4">
        <div class="col-12 col-md-6 col-lg-3">
            <div class="stat-card border-success">
                <div class="stat-label">
                    <i class="fas fa-plus-circle text-success me-2"></i>Created
                </div>
                <div class="stat-value text-success">
                    <?= e($result['created']) ?>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6 col-lg-3">
            <div class="stat-card border-info">
                <div class="stat-label">
                    <i class="fas fa-redo-alt text-info me-2"></i>Already Existing
                </div>
                <div class="stat-value text-info">
                    <?= e($result['existing']) ?>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6 col-lg-3">
            <div class="stat-card border-warning">
                <div class="stat-label">
                    <i class="fas fa-skip-forward text-warning me-2"></i>Skipped
                </div>
                <div class="stat-value text-warning">
                    <?= e($result['skipped']) ?>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6 col-lg-3">
            <div class="stat-card border-primary">
                <div class="stat-label">
                    <i class="fas fa-list text-primary me-2"></i>Total
                </div>
                <div class="stat-value text-primary">
                    <?= e($result['created'] + $result['existing'] + $result['skipped']) ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Provisioning Results Table -->
    <div class="card shadow-lg">
        <div class="card-header">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <i class="fas fa-list-check me-2"></i>Provisioning Details
                </div>
                <span class="badge badge-info"><?= e(count($result['results'])) ?> Total</span>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>
                            <i class="fas fa-heading text-primary me-1"></i>Sensor Name
                        </th>
                        <th>
                            <i class="fas fa-info-circle text-primary me-1"></i>Status
                        </th>
                        <th>
                            <i class="fas fa-cube text-primary me-1"></i>Module ID
                        </th>
                        <th>
                            <i class="fas fa-comment text-primary me-1"></i>Message
                        </th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($result['results'] as $row): ?>
                    <tr class="fade-in">
                        <td>
                            <strong class="text-break">
                                <?= e($row['sensor_name'] ?? '-') ?>
                            </strong>
                        </td>
                        <td>
                            <?php 
                                $status = strtolower($row['status'] ?? '');
                                if ($status === 'created') {
                                    $badgeClass = 'bg-success';
                                    $icon = 'fa-check-circle';
                                } elseif ($status === 'existing') {
                                    $badgeClass = 'bg-info';
                                    $icon = 'fa-redo-alt';
                                } elseif ($status === 'skipped') {
                                    $badgeClass = 'bg-warning';
                                    $icon = 'fa-skip-forward';
                                } else {
                                    $badgeClass = 'bg-secondary';
                                    $icon = 'fa-question-circle';
                                }
                            ?>
                            <span class="badge <?= $badgeClass ?>">
                                <i class="fas <?= $icon ?> me-1"></i>
                                <?= ucfirst(e($status)) ?>
                            </span>
                        </td>
                        <td>
                            <?php if (!empty($row['module_id'])): ?>
                                <code class="bg-light p-1 rounded">
                                    <?= e($row['module_id']) ?>
                                </code>
                            <?php else: ?>
                                <span class="text-muted small">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <small class="text-muted">
                                <?= e($row['message'] ?? 'No message') ?>
                            </small>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card-footer text-muted small">
            <i class="fas fa-info-circle me-2"></i>
            Provisioning complete. Sensors have been inserted into the PandoraFMS database. Check PandoraFMS console to verify module creation.
        </div>
    </div>

<?php endif; ?>
