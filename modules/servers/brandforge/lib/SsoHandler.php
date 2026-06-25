<?php

namespace BrandForge;

use BrandForge\Exceptions\GodmodeApiException;

class SsoHandler
{
    private GodmodeClient $client;

    public function __construct(GodmodeClient $client)
    {
        $this->client = $client;
    }

    /**
     * Obtain a one-time SSO login URL for the given subscription.
     *
     * @param string $subscriptionId  Godmode subscription ID
     * @param string $returnPath      Optional BrandForge-side path to land on after login
     * @return string                 The login URL
     * @throws \RuntimeException      When the API returns no URL
     * @throws GodmodeApiException    On API-level failures
     */
    public function getLoginUrl(string $subscriptionId, string $returnPath = ''): string
    {
        $response = $this->client->sso($subscriptionId, $returnPath);

        // Accept multiple possible envelope shapes from the API.
        $data = $response['data'] ?? $response;
        $url  = $data['url'] ?? $data['login_url'] ?? $data['sso_url'] ?? '';

        if ($url === '') {
            throw new \RuntimeException(
                'Godmode SSO endpoint returned a successful response but no login URL was found.'
            );
        }

        return $url;
    }
}
