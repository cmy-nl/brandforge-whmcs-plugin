<?php

namespace BrandForge;

/**
 * Builds Godmode API request payloads from WHMCS module params.
 */
class Mapper
{
    /**
     * POST /api/godmode/v1/provision/create
     *
     * @param array  $params           WHMCS module params
     * @param string $godmodePackageId Resolved from mod_brandforge_packages
     */
    public static function createAccountPayload(array $params, string $godmodePackageId): array
    {
        $client = $params['clientsdetails'] ?? [];

        return [
            'client_id'  => (string) ($client['id'] ?? $params['userid'] ?? ''),
            'service_id' => (string) ($params['serviceid'] ?? ''),
            'product_id' => $godmodePackageId,
            'customer'   => [
                'email'      => $client['email']       ?? '',
                'first_name' => $client['firstname']   ?? '',
                'last_name'  => $client['lastname']    ?? '',
                'phone'      => $client['phonenumber'] ?? '',
                'company'    => $client['companyname'] ?? '',
            ],
        ];
    }

    /**
     * POST /api/godmode/v1/provision/suspend
     * POST /api/godmode/v1/provision/unsuspend
     * POST /api/godmode/v1/provision/terminate
     */
    public static function subscriptionPayload(string $subscriptionId): array
    {
        return ['subscription_id' => $subscriptionId];
    }

    /**
     * POST /api/godmode/v1/provision/change_package
     */
    public static function changePackagePayload(string $subscriptionId, string $godmodePackageId): array
    {
        return [
            'subscription_id' => $subscriptionId,
            'product_id'      => $godmodePackageId,
        ];
    }
}
