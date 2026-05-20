<!-- Page Header -->
<div class="mb-4">
    <div class="d-flex align-items-center justify-content-between gap-3">
        <div>
            <h1 class="h3 fw-700 gradient-text mb-1">
                <i class="fas fa-box me-2"></i>Sensor Inventory
            </h1>
            <p class="text-muted small mb-0">Discovered SNMP sensors ready for PandoraFMS provisioning</p>
        </div>
        <a class="btn btn-primary btn-lg" href="<?= e($baseUrl) ?>/index.php/scan">
            <i class="fas fa-plus-circle me-2"></i>Scan Device
        </a>
    </div>
</div>

<!-- Filter Card -->
<div class="card shadow-md mb-4">
    <div class="card-body">
        <form method="get" action="<?= e($baseUrl) ?>/index.php">
            <div class="row g-3 align-items-end">
                <div class="col-12 col-md-4">
                    <label class="form-label" for="vendor">
                        <i class="fas fa-industry text-primary me-1"></i>Filter by Vendor
                    </label>
                    <select class="form-select" id="vendor" name="vendor">
                        <option value="">All vendors</option>
                        <?php foreach ($vendors as $vendor): ?>
                            <option value="<?= e($vendor) ?>" <?= ($filters['vendor'] ?? '') === $vendor ? 'selected' : '' ?>>
                                <?= e($vendor) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label" for="ip_address">
                        <i class="fas fa-globe text-primary me-1"></i>Filter by IP
                    </label>
                    <input class="form-control" id="ip_address" name="ip_address" placeholder="192.168.x.x" value="<?= e($filters['ip_address'] ?? '') ?>">
                </div>

                <div class="col-12 col-md-4">
                    <button class="btn btn-secondary w-100" type="submit">
                        <i class="fas fa-search me-2"></i>Apply Filters
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Provisioning Form -->
<form method="post" action="<?= e($baseUrl) ?>/index.php/pandora/provision">
    <input type="hidden" name="_token" value="<?= e($_SESSION['_token']) ?>">

    <!-- Toolbar Card -->
    <div class="card shadow-md mb-4">
        <div class="card-header">
            <i class="fas fa-tools me-2"></i>Provisioning Toolbar
        </div>
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-12 col-md-4">
                    <label class="form-label" for="agent_id">
                        <i class="fas fa-robot text-primary me-1"></i>Select PandoraFMS Agent
                    </label>
                    <select class="form-select form-select-lg" id="agent_id" name="agent_id" required>
                        <option value="">Choose an agent...</option>
                        <?php foreach ($agents as $agent): ?>
                            <option value="<?= e($agent['id_agente']) ?>">
                                <i class="fas fa-check-circle me-1"></i>
                                <?= e($agent['nombre']) ?>
                                <?= $agent['direccion'] ? ' (' . e($agent['direccion']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-8">
                    <div class="btn-group w-100" role="group">
                        <button class="btn btn-outline-secondary flex-grow-1" type="button" id="selectAll">
                            <i class="fas fa-check-double me-2"></i>Select All
                        </button>
                        <button class="btn btn-outline-secondary flex-grow-1" type="button" id="unselectAll">
                            <i class="fas fa-times-circle me-2"></i>Unselect All
                        </button>
                        <button class="btn btn-success flex-grow-1" type="submit">
                            <i class="fas fa-cloud-upload-alt me-2"></i>Provision Selected
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Sensors Table Card -->
    <div class="card shadow-lg">
        <div class="card-header">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <i class="fas fa-table me-2"></i>Sensor Registry
                </div>
                <span class="badge badge-info"><?= e(count($sensors)) ?> Sensors</span>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle" id="inventoryTable">
                <thead>
                    <tr>
                        <th style="width: 40px;">
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="checkbox" id="selectAllCheck">
                            </div>
                        </th>
                        <th>
                            <i class="fas fa-industry text-primary me-1"></i>Vendor
                        </th>
                        <th>
                            <i class="fas fa-server text-primary me-1"></i>IP Address
                        </th>
                        <th>
                            <i class="fas fa-globe text-primary me-1"></i>Hostname
                        </th>
                        <th>
                            <i class="fas fa-tag text-primary me-1"></i>Class
                        </th>
                        <th>
                            <i class="fas fa-heading text-primary me-1"></i>Sensor Name
                        </th>
                        <th>
                            <i class="fas fa-plug text-primary me-1"></i>Interface
                        </th>
                        <th>
                            <i class="fas fa-chart-line text-primary me-1"></i>Value
                        </th>
                        <th>
                            <i class="fas fa-ruler text-primary me-1"></i>Unit
                        </th>
                        <th>
                            <i class="fas fa-code text-primary me-1"></i>OID
                        </th>
                        <th>
                            <i class="fas fa-cloud text-primary me-1"></i>Pandora Status
                        </th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($sensors as $sensor): ?>
                    <?php $provisionable = $sensor['normalized_value'] !== null; ?>
                    <tr class="fade-in <?= $provisionable ? '' : 'opacity-50' ?>">
                        <td>
                            <div class="form-check mb-0">
                                <input class="form-check-input sensor-check" type="checkbox" name="sensor_ids[]" value="<?= e($sensor['id']) ?>" <?= $provisionable ? '' : 'disabled' ?>>
                            </div>
                        </td>
                        <td>
                            <span class="badge bg-primary">
                                <?= e($sensor['vendor']) ?>
                            </span>
                        </td>
                        <td>
                            <code><?= e($sensor['ip_address']) ?></code>
                        </td>
                        <td>
                            <strong><?= e($sensor['hostname']) ?></strong>
                        </td>
                        <td>
                            <span class="badge bg-light text-dark">
                                <?= e($sensor['sensor_class']) ?>
                            </span>
                        </td>
                        <td>
                            <strong class="text-break"><?= e($sensor['sensor_name']) ?></strong>
                        </td>
                        <td>
                            <?php if (!empty($sensor['interface_name'])): ?>
                                <code class="small"><?= e($sensor['interface_name']) ?></code>
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
                                <span class="badge bg-info text-white">
                                    <?= e($sensor['unit']) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted small">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <code class="text-muted small">
                                <?= substr(e($sensor['oid']), 0, 20) ?>...
                            </code>
                        </td>
                        <td>
                            <?php if ((int) $sensor['provisioned'] === 1): ?>
                                <span class="badge bg-success">
                                    <i class="fas fa-check me-1"></i>Module <?= e($sensor['pandora_module_id']) ?>
                                </span>
                            <?php else: ?>
                                <span class="badge bg-secondary">
                                    <i class="fas fa-hourglass-half me-1"></i>Pending
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card-footer text-muted small">
            <i class="fas fa-info-circle me-2"></i>
            Select sensors and choose a PandoraFMS agent to provision modules. Only sensors with normalized values can be provisioned.
        </div>
    </div>
</form>
