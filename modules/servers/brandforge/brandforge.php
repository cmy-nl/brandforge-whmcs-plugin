<?php
/**
 * BrandForge WHMCS Provisioning Module
 *
 * Connects WHMCS product lifecycle events to the Godmode API so that
 * creating, suspending, and terminating a service automatically provisions
 * or deprovisions the corresponding BrandForge workspace.
 */

if (!defined('WHMCS')) {
    die('Access denied.');
}

// ---------------------------------------------------------------------------
// Autoload lib classes
// ---------------------------------------------------------------------------

$libDir = __DIR__ . '/lib/';
require_once $libDir . 'Exceptions.php';
require_once $libDir . 'Logger.php';
require_once $libDir . 'GodmodeClient.php';
require_once $libDir . 'Mapper.php';
require_once $libDir . 'PackageLookup.php';
require_once $libDir . 'ServiceRepository.php';
require_once $libDir . 'SsoHandler.php';

use BrandForge\GodmodeClient;
use BrandForge\Logger;
use BrandForge\Mapper;
use BrandForge\PackageLookup;
use BrandForge\ServiceRepository;
use BrandForge\SsoHandler;
use BrandForge\Exceptions\GodmodeApiException;

// ---------------------------------------------------------------------------
// Internal helpers
// ---------------------------------------------------------------------------

/**
 * Build a GodmodeClient from the module params.
 *
 * Config options (product-level) take precedence over server record fields
 * so that TestConnection (which only receives server fields) also works.
 */
function brandforge_buildClient(array $params): GodmodeClient
{
    $apiUrl    = trim((string) ($params['configoption1'] ?? $params['serverhostname'] ?? ''));
    $apiKey    = trim((string) ($params['configoption2'] ?? $params['serverpassword']  ?? ''));
    $debugMode = ($params['configoption3'] ?? 'no') === 'on';

    return new GodmodeClient($apiUrl, $apiKey, new Logger($debugMode));
}

/**
 * Resolve the Godmode package ID for a WHMCS product ID.
 * Returns null and populates $error when no mapping exists.
 */
function brandforge_resolvePackageId(int $whmcsProductId, string &$error): ?string
{
    $godmodePackageId = PackageLookup::godmodePackageId($whmcsProductId);

    if ($godmodePackageId === null) {
        $error = 'WHMCS product #' . $whmcsProductId . ' is not linked to a Godmode package. '
               . 'Open BrandForge Package Sync, link or auto-create the product, then retry.';
    }

    return $godmodePackageId;
}

/**
 * Load the service record for a WHMCS service ID.
 * Returns null and populates $error when no record exists.
 */
function brandforge_loadService(int $serviceId, string &$error): ?\stdClass
{
    $service = ServiceRepository::findByServiceId($serviceId);

    if ($service === null) {
        $error = 'No Godmode provisioning record found for service #' . $serviceId . '. '
               . 'The service may need to be re-provisioned.';
    }

    return $service;
}

// ---------------------------------------------------------------------------
// Module metadata
// ---------------------------------------------------------------------------

function brandforge_MetaData(): array
{
    return [
        'DisplayName'              => 'BrandForge',
        'APIVersion'               => '1.1',
        'RequiresServer'           => true,
        'DefaultNonSSLPort'        => '80',
        'DefaultSSLPort'           => '443',
        'ServiceSingleSignOnLabel' => 'Login to BrandForge',
    ];
}

// ---------------------------------------------------------------------------
// Server config options  (shown in WHMCS Product → Module Settings)
// ---------------------------------------------------------------------------

function brandforge_ConfigOptions(): array
{
    return [
        'Godmode API URL' => [
            'Type'        => 'text',
            'Size'        => 60,
            'Default'     => 'https://brandforge.software',
            'Description' => 'Base URL for the Godmode API (no trailing slash)',
        ],
        'Godmode API Key' => [
            'Type'        => 'password',
            'Size'        => 60,
            'Default'     => '',
            'Description' => 'Bearer token for Godmode API authentication',
        ],
        'Debug Mode' => [
            'Type'        => 'yesno',
            'Default'     => 'no',
            'Description' => 'Enable verbose logging to the WHMCS Module Log',
        ],
        'Brand Name' => [
            'Type'        => 'text',
            'Size'        => 40,
            'Default'     => 'BrandForge',
            'Description' => 'Displayed name in the client area (reseller branding)',
        ],
        'Brand Primary Color' => [
            'Type'        => 'text',
            'Size'        => 10,
            'Default'     => '#6366f1',
            'Description' => 'Hex colour for buttons and accents (e.g. #6366f1)',
        ],
    ];
}

// ---------------------------------------------------------------------------
// Test Connection
// ---------------------------------------------------------------------------

function brandforge_TestConnection(array $params): array
{
    try {
        $client = brandforge_buildClient($params);
        $client->testConnection();
        return ['success' => true];
    } catch (GodmodeApiException $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    } catch (\Exception $e) {
        return ['success' => false, 'error' => 'Unexpected error: ' . $e->getMessage()];
    }
}

// ---------------------------------------------------------------------------
// CreateAccount
// ---------------------------------------------------------------------------

function brandforge_CreateAccount(array $params): string
{
    try {
        ServiceRepository::ensureTable();

        $whmcsProductId   = (int) ($params['pid'] ?? 0);
        $error            = '';
        $godmodePackageId = brandforge_resolvePackageId($whmcsProductId, $error);

        if ($godmodePackageId === null) {
            return $error;
        }

        $client  = brandforge_buildClient($params);
        $payload = Mapper::createAccountPayload($params, $godmodePackageId);

        $response = $client->createAccount($payload);

        // Accept both a flat response and a {"data":{...}} envelope
        $data = $response['data'] ?? $response;

        $subscriptionId = (string) ($data['subscription_id'] ?? '');
        $workspaceId    = (string) ($data['workspace_id']    ?? '');
        $userId         = (string) ($data['user_id']         ?? '');

        if ($subscriptionId === '') {
            return 'Account provisioned but Godmode returned no subscription_id. Check the module log.';
        }

        ServiceRepository::insert(
            clientId:       (int) ($params['clientsdetails']['id'] ?? $params['userid'] ?? 0),
            serviceId:      (int) ($params['serviceid'] ?? 0),
            productId:      $whmcsProductId,
            subscriptionId: $subscriptionId,
            workspaceId:    $workspaceId,
            userId:         $userId
        );

        return 'success';

    } catch (GodmodeApiException $e) {
        return $e->getMessage();
    } catch (\Exception $e) {
        return 'Unexpected error: ' . $e->getMessage();
    }
}

// ---------------------------------------------------------------------------
// SuspendAccount
// ---------------------------------------------------------------------------

function brandforge_SuspendAccount(array $params): string
{
    try {
        $serviceId = (int) ($params['serviceid'] ?? 0);
        $error     = '';
        $service   = brandforge_loadService($serviceId, $error);

        if ($service === null) {
            return $error;
        }

        $client = brandforge_buildClient($params);
        $client->suspendAccount(
            Mapper::subscriptionPayload($service->godmode_subscription_id)
        );

        ServiceRepository::touch($serviceId);
        return 'success';

    } catch (GodmodeApiException $e) {
        return $e->getMessage();
    } catch (\Exception $e) {
        return 'Unexpected error: ' . $e->getMessage();
    }
}

// ---------------------------------------------------------------------------
// UnsuspendAccount
// ---------------------------------------------------------------------------

function brandforge_UnsuspendAccount(array $params): string
{
    try {
        $serviceId = (int) ($params['serviceid'] ?? 0);
        $error     = '';
        $service   = brandforge_loadService($serviceId, $error);

        if ($service === null) {
            return $error;
        }

        $client = brandforge_buildClient($params);
        $client->unsuspendAccount(
            Mapper::subscriptionPayload($service->godmode_subscription_id)
        );

        ServiceRepository::touch($serviceId);
        return 'success';

    } catch (GodmodeApiException $e) {
        return $e->getMessage();
    } catch (\Exception $e) {
        return 'Unexpected error: ' . $e->getMessage();
    }
}

// ---------------------------------------------------------------------------
// TerminateAccount
// ---------------------------------------------------------------------------

function brandforge_TerminateAccount(array $params): string
{
    try {
        $serviceId = (int) ($params['serviceid'] ?? 0);
        $error     = '';
        $service   = brandforge_loadService($serviceId, $error);

        if ($service === null) {
            return $error;
        }

        $client = brandforge_buildClient($params);
        $client->terminateAccount(
            Mapper::subscriptionPayload($service->godmode_subscription_id)
        );

        // Record is kept as an audit trail — only the timestamp is updated.
        ServiceRepository::touch($serviceId);
        return 'success';

    } catch (GodmodeApiException $e) {
        return $e->getMessage();
    } catch (\Exception $e) {
        return 'Unexpected error: ' . $e->getMessage();
    }
}

// ---------------------------------------------------------------------------
// ChangePackage
// ---------------------------------------------------------------------------

function brandforge_ChangePackage(array $params): string
{
    try {
        $serviceId = (int) ($params['serviceid'] ?? 0);
        $error     = '';
        $service   = brandforge_loadService($serviceId, $error);

        if ($service === null) {
            return $error;
        }

        $newProductId     = (int) ($params['pid'] ?? 0);
        $godmodePackageId = brandforge_resolvePackageId($newProductId, $error);

        if ($godmodePackageId === null) {
            return $error;
        }

        $client = brandforge_buildClient($params);
        $client->changePackage(
            Mapper::changePackagePayload($service->godmode_subscription_id, $godmodePackageId)
        );

        ServiceRepository::updateProduct($serviceId, $newProductId);
        return 'success';

    } catch (GodmodeApiException $e) {
        return $e->getMessage();
    } catch (\Exception $e) {
        return 'Unexpected error: ' . $e->getMessage();
    }
}

// ---------------------------------------------------------------------------
// Client Area
// ---------------------------------------------------------------------------

function brandforge_ClientArea(array $params): array
{
    $serviceId = (int) ($params['serviceid'] ?? 0);
    $service   = ServiceRepository::findByServiceId($serviceId);

    // Base vars available to both provisioned and unprovisioned states.
    $vars = [
        'has_service'    => false,
        'service_status' => $params['status'] ?? 'Unknown',
        'service_id'     => $serviceId,
        'brand_name'     => $params['configoption4'] ?? 'BrandForge',
        'brand_color'    => $params['configoption5'] ?? '#6366f1',
    ];

    if ($service === null) {
        return ['templatefile' => 'clientarea', 'vars' => $vars];
    }

    $packageName = PackageLookup::packageName((int) $service->whmcs_product_id)
                  ?? ($params['product']['name'] ?? 'BrandForge Plan');

    // Pre-generate SSO URL so the Launch button is a direct link in the template.
    $ssoUrl   = '';
    $ssoError = '';
    try {
        $handler = new SsoHandler(brandforge_buildClient($params));
        $ssoUrl  = $handler->getLoginUrl((string) $service->godmode_subscription_id);
    } catch (\Exception $e) {
        $ssoError = $e->getMessage();
    }

    $vars = array_merge($vars, [
        'has_service'     => true,
        'package_name'    => $packageName,
        'subscription_id' => (string) ($service->godmode_subscription_id ?? ''),
        'workspace_id'    => (string) ($service->godmode_workspace_id    ?? ''),
        'sso_url'         => $ssoUrl,
        'sso_error'       => $ssoError,
        'created_at'      => (string) ($service->created_at ?? ''),
    ]);

    return ['templatefile' => 'clientarea', 'vars' => $vars];
}

// ---------------------------------------------------------------------------
// Custom button registry
// ---------------------------------------------------------------------------

function brandforge_ClientAreaCustomButtonArray(): array
{
    return [
        'Launch BrandForge' => 'LaunchBrandForge',
        'View Workspace'    => 'ViewWorkspace',
    ];
}

// ---------------------------------------------------------------------------
// SSO button handlers
// ---------------------------------------------------------------------------

/**
 * Shared SSO helper used by both LaunchBrandForge and ViewWorkspace.
 */
function brandforge_doSso(array $params, string $returnPath = ''): array
{
    $serviceId = (int) ($params['serviceid'] ?? 0);
    $error     = '';
    $service   = brandforge_loadService($serviceId, $error);

    $brandName = $params['configoption4'] ?? 'BrandForge';

    if ($service === null) {
        return [
            'templatefile' => 'sso_redirect',
            'vars'         => ['sso_url' => '', 'sso_error' => $error,
                               'brand_name' => $brandName],
        ];
    }

    try {
        $handler = new SsoHandler(brandforge_buildClient($params));
        $url     = $handler->getLoginUrl(
            (string) $service->godmode_subscription_id,
            $returnPath
        );

        return [
            'templatefile' => 'sso_redirect',
            'vars'         => ['sso_url' => $url, 'sso_error' => '',
                               'brand_name' => $brandName],
        ];
    } catch (\Exception $e) {
        return [
            'templatefile' => 'sso_redirect',
            'vars'         => ['sso_url' => '', 'sso_error' => $e->getMessage(),
                               'brand_name' => $brandName],
        ];
    }
}

function brandforge_LaunchBrandForge(array $params): array
{
    return brandforge_doSso($params);
}

function brandforge_ViewWorkspace(array $params): array
{
    return brandforge_doSso($params, '/workspace');
}
