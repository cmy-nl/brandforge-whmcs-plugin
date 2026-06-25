<?php

namespace BrandForge\Addon;

use WHMCS\Database\Capsule;

class WhmcsProductManager
{
    private const GROUP_NAME = 'BrandForge';

    /** @return \stdClass[] */
    public static function getAllProducts(): array
    {
        $rows = Capsule::table('tblproducts')
            ->select('id', 'name', 'gid')
            ->orderBy('name')
            ->get();

        return ($rows instanceof \Illuminate\Support\Collection)
            ? $rows->all()
            : (array) $rows;
    }

    public static function getProductName(int $productId): ?string
    {
        $row = Capsule::table('tblproducts')
            ->where('id', $productId)
            ->select('name')
            ->first();

        return $row ? $row->name : null;
    }

    /**
     * Create a WHMCS product bound to the BrandForge server module.
     * Returns the new product ID.
     *
     * @throws \RuntimeException
     */
    public static function createProduct(string $name, string $slug): int
    {
        $gid = self::getOrCreateGroup();

        $result = localAPI('AddProduct', [
            'name'        => $name,
            'gid'         => $gid,
            'type'        => 'other',
            'paytype'     => 'recurring',
            'allowqty'    => 0,
            'servertype'  => 'brandforge',
            'autosetup'   => 'payment',
        ]);

        if (($result['result'] ?? '') !== 'success') {
            throw new \RuntimeException(
                'AddProduct failed: ' . ($result['message'] ?? 'unknown error')
            );
        }

        return (int) $result['pid'];
    }

    /**
     * Update the name of an existing WHMCS product.
     *
     * @throws \RuntimeException
     */
    public static function updateProductName(int $productId, string $name): void
    {
        $result = localAPI('UpdateProduct', [
            'pid'  => $productId,
            'name' => $name,
        ]);

        if (($result['result'] ?? '') !== 'success') {
            throw new \RuntimeException(
                "UpdateProduct #{$productId} failed: " . ($result['message'] ?? 'unknown error')
            );
        }
    }

    // -----------------------------------------------------------------------

    private static function getOrCreateGroup(): int
    {
        $group = Capsule::table('tblproductgroups')
            ->where('name', self::GROUP_NAME)
            ->first();

        if ($group) {
            return (int) $group->id;
        }

        return (int) Capsule::table('tblproductgroups')->insertGetId([
            'name'    => self::GROUP_NAME,
            'headline' => 'BrandForge Plans',
            'tagline'  => 'AI-powered brand building platform',
            'hidden'   => 0,
        ]);
    }
}
