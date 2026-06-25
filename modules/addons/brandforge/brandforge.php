<?php
/**
 * BrandForge WHMCS Addon Module — Package Sync
 *
 * Exposes an admin page under Setup → Addon Modules → BrandForge Package Sync
 * that fetches package definitions from the Godmode API, maintains a local
 * mapping table, and auto-creates or links WHMCS products.
 */

if (!defined('WHMCS')) {
    die('Access denied.');
}

// Shared lib (server module)
$_serverLib = __DIR__ . '/../../servers/brandforge/lib/';
require_once $_serverLib . 'Exceptions.php';
require_once $_serverLib . 'Logger.php';
require_once $_serverLib . 'GodmodeClient.php';
require_once $_serverLib . 'ServiceRepository.php';

// Addon lib
$_addonLib = __DIR__ . '/lib/';
require_once $_addonLib . 'PackageRepository.php';
require_once $_addonLib . 'PackageSync.php';
require_once $_addonLib . 'WhmcsProductManager.php';

use BrandForge\GodmodeClient;
use BrandForge\Logger;
use BrandForge\ServiceRepository;
use BrandForge\Addon\PackageRepository;
use BrandForge\Addon\PackageSync;
use BrandForge\Addon\WhmcsProductManager;

// ---------------------------------------------------------------------------
// Module registration
// ---------------------------------------------------------------------------

function brandforge_config(): array
{
    return [
        'name'        => 'BrandForge Package Sync',
        'description' => 'Synchronise Godmode packages with WHMCS products and maintain the provisioning mapping table.',
        'version'     => '1.0.0',
        'author'      => 'BrandForge',
        'fields'      => [
            'godmode_api_url' => [
                'FriendlyName' => 'Godmode API URL',
                'Type'         => 'text',
                'Size'         => 60,
                'Default'      => 'https://brandforge.software',
                'Description'  => 'Base URL for the Godmode API (no trailing slash)',
            ],
            'godmode_api_key' => [
                'FriendlyName' => 'Godmode API Key',
                'Type'         => 'password',
                'Size'         => 60,
                'Default'      => '',
                'Description'  => 'Bearer token used for all Godmode API requests',
            ],
            'debug_mode' => [
                'FriendlyName' => 'Debug Mode',
                'Type'         => 'yesno',
                'Default'      => 'no',
                'Description'  => 'Write verbose API logs to the WHMCS Module Log',
            ],
        ],
    ];
}

// ---------------------------------------------------------------------------
// Lifecycle
// ---------------------------------------------------------------------------

function brandforge_activate(): array
{
    try {
        PackageRepository::createTable();
        ServiceRepository::ensureTable();
        return [
            'status'      => 'success',
            'description' => 'BrandForge Package Sync activated. Package and service mapping tables created.',
        ];
    } catch (\Exception $e) {
        return [
            'status'      => 'error',
            'description' => 'Activation failed: ' . $e->getMessage(),
        ];
    }
}

function brandforge_deactivate(): array
{
    // Table is kept on deactivation to preserve mappings across reinstalls.
    return [
        'status'      => 'success',
        'description' => 'BrandForge Package Sync deactivated. Mapping data preserved.',
    ];
}

function brandforge_upgrade(array $vars): void
{
    // Reserved for future schema migrations.
}

// ---------------------------------------------------------------------------
// Admin output
// ---------------------------------------------------------------------------

function brandforge_output(array $vars): void
{
    $moduleLink = $vars['modulelink'];
    $apiUrl     = rtrim((string) ($vars['godmode_api_url'] ?? ''), '/');
    $apiKey     = (string) ($vars['godmode_api_key'] ?? '');
    $debugMode  = ($vars['debug_mode'] ?? 'no') === 'on';

    $client = new GodmodeClient($apiUrl, $apiKey, new Logger($debugMode));
    $sync   = new PackageSync($client);

    // CSRF token (WHMCS 7/8 compatible)
    $token = $_SESSION['token'] ?? '';

    // -----------------------------------------------------------------------
    // Handle POST actions
    // -----------------------------------------------------------------------
    $flash     = '';
    $flashType = 'info';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Basic CSRF check
        if (isset($_POST['token']) && $token && $_POST['token'] !== $token) {
            $flash     = 'Invalid security token. Please refresh the page and try again.';
            $flashType = 'danger';
        } else {
            $action    = $_POST['action']    ?? '';
            $packageId = $_POST['package_id'] ?? '';

            try {
                switch ($action) {

                    case 'sync_all':
                        $result = $sync->syncAll();
                        $flash  = "Synced {$result['synced']} package(s) from Godmode.";
                        if (!empty($result['errors'])) {
                            $flash    .= ' Errors: ' . implode('; ', $result['errors']);
                            $flashType = 'warning';
                        } else {
                            $flashType = 'success';
                        }
                        break;

                    case 'sync_single':
                        brandforge_requirePackageId($packageId);
                        $sync->syncSingle($packageId);
                        $flash     = 'Package synced successfully.';
                        $flashType = 'success';
                        break;

                    case 'rebuild_mapping':
                        $result    = $sync->rebuildMapping();
                        $flash     = "Mapping rebuilt — {$result['synced']} package(s) processed.";
                        $flashType = 'success';
                        break;

                    case 'auto_create_product':
                        brandforge_requirePackageId($packageId);
                        $mapping = PackageRepository::findByGodmodeId($packageId);
                        if (!$mapping) {
                            throw new \RuntimeException('Package not in local table. Run Sync All first.');
                        }
                        if (!empty($mapping->whmcs_product_id)) {
                            throw new \RuntimeException('Package already has a linked WHMCS product.');
                        }
                        $newId = WhmcsProductManager::createProduct(
                            $mapping->godmode_name,
                            $mapping->godmode_slug
                        );
                        PackageRepository::setWhmcsProduct($packageId, $newId);
                        $flash     = 'WHMCS product #' . $newId . ' "' . $mapping->godmode_name . '" created and linked.';
                        $flashType = 'success';
                        break;

                    case 'link_product':
                        brandforge_requirePackageId($packageId);
                        $whmcsId = (int) ($_POST['whmcs_product_id'] ?? 0);
                        if ($whmcsId <= 0) {
                            throw new \RuntimeException('Please select a WHMCS product to link.');
                        }
                        PackageRepository::setWhmcsProduct($packageId, $whmcsId);
                        $flash     = "Package linked to WHMCS product #{$whmcsId}.";
                        $flashType = 'success';
                        break;

                    case 'unlink_product':
                        brandforge_requirePackageId($packageId);
                        PackageRepository::setWhmcsProduct($packageId, null);
                        $flash     = 'Product link removed.';
                        $flashType = 'info';
                        break;

                    default:
                        $flash     = 'Unknown action.';
                        $flashType = 'warning';
                }
            } catch (\Exception $e) {
                $flash     = 'Error: ' . $e->getMessage();
                $flashType = 'danger';
            }
        }
    }

    // -----------------------------------------------------------------------
    // Gather data for rendering
    // -----------------------------------------------------------------------
    $mappings      = PackageRepository::all();
    $whmcsProducts = WhmcsProductManager::getAllProducts();

    $whmcsProductMap = [];
    foreach ($whmcsProducts as $p) {
        $whmcsProductMap[(int) $p->id] = $p->name;
    }

    $lastSync    = 'Never';
    $linkedCount = 0;
    if (!empty($mappings)) {
        $dates    = array_map(fn ($r) => $r->updated_at, $mappings);
        $lastSync = max($dates);
        foreach ($mappings as $r) {
            if (!empty($r->whmcs_product_id)) {
                $linkedCount++;
            }
        }
    }

    // -----------------------------------------------------------------------
    // Render
    // -----------------------------------------------------------------------
    brandforge_renderPage(
        $moduleLink,
        $token,
        $flash,
        $flashType,
        $mappings,
        $whmcsProducts,
        $whmcsProductMap,
        $lastSync,
        $linkedCount
    );
}

// ---------------------------------------------------------------------------
// Render helpers
// ---------------------------------------------------------------------------

function brandforge_requirePackageId(string $id): void
{
    if ($id === '') {
        throw new \RuntimeException('No package ID supplied.');
    }
}

function brandforge_renderPage(
    string $moduleLink,
    string $token,
    string $flash,
    string $flashType,
    array  $mappings,
    array  $whmcsProducts,
    array  $whmcsProductMap,
    string $lastSync,
    int    $linkedCount
): void {
    $total    = count($mappings);
    $pending  = $total - $linkedCount;
    $mlHtml   = htmlspecialchars($moduleLink);
    $tkHtml   = htmlspecialchars($token);
    ?>
    <style>
        .bf-header   { display:flex; align-items:center; justify-content:space-between; margin-bottom:18px; }
        .bf-header h2{ margin:0; font-size:20px; }
        .bf-stats    { display:flex; gap:16px; margin-bottom:20px; }
        .bf-stat     { background:#f5f5f5; border:1px solid #ddd; border-radius:4px;
                       padding:12px 20px; text-align:center; min-width:110px; }
        .bf-stat-val { font-size:26px; font-weight:700; line-height:1; }
        .bf-stat-lbl { font-size:11px; color:#777; margin-top:4px; text-transform:uppercase; }
        .bf-stat.linked .bf-stat-val   { color:#5cb85c; }
        .bf-stat.pending .bf-stat-val  { color:#f0ad4e; }
        .bf-actions-cell { white-space:nowrap; }
        .bf-actions-cell .btn + .btn,
        .bf-actions-cell form + form   { margin-left:4px; }
        .bf-link-row { display:flex; align-items:center; gap:4px; margin-top:6px; }
        code         { font-size:12px; background:#f0f0f0; padding:1px 5px; border-radius:3px; }
    </style>

    <div class="bf-wrap">

        <!-- Header -->
        <div class="bf-header">
            <h2>BrandForge &mdash; Package Sync</h2>
            <div>
                <form method="post" action="<?= $mlHtml ?>" style="display:inline">
                    <input type="hidden" name="token"  value="<?= $tkHtml ?>">
                    <input type="hidden" name="action" value="sync_all">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-sync-alt"></i>&nbsp; Sync All
                    </button>
                </form>
                &nbsp;
                <form method="post" action="<?= $mlHtml ?>" style="display:inline">
                    <input type="hidden" name="token"  value="<?= $tkHtml ?>">
                    <input type="hidden" name="action" value="rebuild_mapping">
                    <button type="submit" class="btn btn-default"
                            onclick="return confirm('Rebuild the entire mapping from Godmode? Existing product links are preserved.')">
                        <i class="fas fa-database"></i>&nbsp; Rebuild Mapping
                    </button>
                </form>
            </div>
        </div>

        <!-- Flash message -->
        <?php if ($flash !== ''): ?>
            <div class="alert alert-<?= htmlspecialchars($flashType) ?> alert-dismissible">
                <button type="button" class="close" data-dismiss="alert">&times;</button>
                <?= htmlspecialchars($flash) ?>
            </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="bf-stats">
            <div class="bf-stat">
                <div class="bf-stat-val"><?= $total ?></div>
                <div class="bf-stat-lbl">Total</div>
            </div>
            <div class="bf-stat linked">
                <div class="bf-stat-val"><?= $linkedCount ?></div>
                <div class="bf-stat-lbl">Linked</div>
            </div>
            <div class="bf-stat pending">
                <div class="bf-stat-val"><?= $pending ?></div>
                <div class="bf-stat-lbl">Pending</div>
            </div>
        </div>

        <!-- Package table -->
        <?php if (empty($mappings)): ?>
            <div class="alert alert-info">
                No packages in the local mapping table yet.
                Click <strong>Sync All</strong> to pull packages from Godmode.
            </div>
        <?php else: ?>
            <table class="table table-bordered table-striped table-hover" style="font-size:13px">
                <thead>
                    <tr>
                        <th>Package Name</th>
                        <th>Slug</th>
                        <th>Godmode ID</th>
                        <th>WHMCS Product</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($mappings as $row):
                    $gid        = htmlspecialchars($row->godmode_package_id);
                    $isLinked   = !empty($row->whmcs_product_id);
                    $productLabel = $isLinked
                        ? htmlspecialchars($whmcsProductMap[(int)$row->whmcs_product_id]
                            ?? "Product #{$row->whmcs_product_id}")
                        : '';
                ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($row->godmode_name) ?></strong></td>
                        <td><code><?= htmlspecialchars($row->godmode_slug) ?></code></td>
                        <td><small class="text-muted"><?= $gid ?></small></td>
                        <td>
                            <?php if ($isLinked): ?>
                                <span class="label label-success"><?= $productLabel ?></span>
                            <?php else: ?>
                                <span class="text-muted">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isLinked): ?>
                                <span class="label label-success">Synced</span>
                            <?php else: ?>
                                <span class="label label-warning">Pending</span>
                            <?php endif; ?>
                        </td>
                        <td class="bf-actions-cell">
                            <!-- Sync single -->
                            <form method="post" action="<?= $mlHtml ?>" style="display:inline">
                                <input type="hidden" name="token"      value="<?= $tkHtml ?>">
                                <input type="hidden" name="action"     value="sync_single">
                                <input type="hidden" name="package_id" value="<?= $gid ?>">
                                <button type="submit" class="btn btn-xs btn-info"
                                        title="Re-pull this package from Godmode">
                                    <i class="fas fa-sync"></i> Sync
                                </button>
                            </form>

                            <?php if (!$isLinked): ?>
                                <!-- Auto-create WHMCS product -->
                                <form method="post" action="<?= $mlHtml ?>" style="display:inline">
                                    <input type="hidden" name="token"      value="<?= $tkHtml ?>">
                                    <input type="hidden" name="action"     value="auto_create_product">
                                    <input type="hidden" name="package_id" value="<?= $gid ?>">
                                    <button type="submit" class="btn btn-xs btn-success"
                                            title="Create a new WHMCS product and link it">
                                        <i class="fas fa-plus-circle"></i> Auto Create
                                    </button>
                                </form>

                                <!-- Link existing product -->
                                <form method="post" action="<?= $mlHtml ?>" class="bf-link-row">
                                    <input type="hidden" name="token"      value="<?= $tkHtml ?>">
                                    <input type="hidden" name="action"     value="link_product">
                                    <input type="hidden" name="package_id" value="<?= $gid ?>">
                                    <select name="whmcs_product_id" class="form-control input-sm"
                                            style="width:175px;display:inline-block">
                                        <option value="">Link existing&hellip;</option>
                                        <?php foreach ($whmcsProducts as $p): ?>
                                            <option value="<?= (int) $p->id ?>">
                                                <?= htmlspecialchars($p->name) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-xs btn-primary">Link</button>
                                </form>

                            <?php else: ?>
                                <!-- Unlink -->
                                <form method="post" action="<?= $mlHtml ?>" style="display:inline">
                                    <input type="hidden" name="token"      value="<?= $tkHtml ?>">
                                    <input type="hidden" name="action"     value="unlink_product">
                                    <input type="hidden" name="package_id" value="<?= $gid ?>">
                                    <button type="submit" class="btn btn-xs btn-danger"
                                            onclick="return confirm('Remove product link for \'<?= htmlspecialchars(addslashes($row->godmode_name)) ?>\'?')">
                                        <i class="fas fa-unlink"></i> Unlink
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <hr>
        <p class="text-muted" style="font-size:11px">
            <?= $total ?> package(s) in local mapping &mdash;
            last updated: <?= htmlspecialchars($lastSync) ?>
        </p>
    </div>
    <?php
}
