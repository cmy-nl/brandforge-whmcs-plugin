<?php

namespace BrandForge;

use WHMCS\Database\Capsule;

class ServiceRepository
{
    const TABLE = 'mod_brandforge_services';

    /**
     * Create the table if it does not exist yet.
     * Safe to call on every request — schema builder checks first.
     */
    public static function ensureTable(): void
    {
        if (Capsule::schema()->hasTable(self::TABLE)) {
            return;
        }

        Capsule::schema()->create(self::TABLE, function ($table) {
            $table->increments('id');
            $table->unsignedInteger('whmcs_client_id');
            $table->unsignedInteger('whmcs_service_id')->unique();
            $table->unsignedInteger('whmcs_product_id');
            $table->string('godmode_subscription_id', 255)->nullable();
            $table->string('godmode_workspace_id', 255)->nullable();
            $table->string('godmode_user_id', 255)->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
        });
    }

    public static function insert(
        int    $clientId,
        int    $serviceId,
        int    $productId,
        string $subscriptionId,
        string $workspaceId,
        string $userId
    ): void {
        $now = date('Y-m-d H:i:s');

        Capsule::table(self::TABLE)->insert([
            'whmcs_client_id'         => $clientId,
            'whmcs_service_id'        => $serviceId,
            'whmcs_product_id'        => $productId,
            'godmode_subscription_id' => $subscriptionId ?: null,
            'godmode_workspace_id'    => $workspaceId    ?: null,
            'godmode_user_id'         => $userId         ?: null,
            'created_at'              => $now,
            'updated_at'              => $now,
        ]);
    }

    public static function findByServiceId(int $serviceId): ?\stdClass
    {
        $row = Capsule::table(self::TABLE)
            ->where('whmcs_service_id', $serviceId)
            ->first();

        return $row ?: null;
    }

    /**
     * Update the linked product ID after a package change.
     */
    public static function updateProduct(int $serviceId, int $productId): void
    {
        Capsule::table(self::TABLE)
            ->where('whmcs_service_id', $serviceId)
            ->update([
                'whmcs_product_id' => $productId,
                'updated_at'       => date('Y-m-d H:i:s'),
            ]);
    }

    public static function touch(int $serviceId): void
    {
        Capsule::table(self::TABLE)
            ->where('whmcs_service_id', $serviceId)
            ->update(['updated_at' => date('Y-m-d H:i:s')]);
    }
}
