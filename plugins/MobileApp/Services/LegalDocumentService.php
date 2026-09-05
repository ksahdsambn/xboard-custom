<?php

namespace Plugin\MobileApp\Services;

final class LegalDocumentService
{
    public function all(): array
    {
        $path = dirname(__DIR__) . '/docs/legal.json';
        $payload = json_decode((string) file_get_contents($path), true);
        return is_array($payload) ? $payload : [];
    }

    public function privacy(): array
    {
        return $this->all()['privacy'] ?? ['version' => 'privacy-v1', 'url' => '', 'content' => ''];
    }

    public function terms(): array
    {
        return $this->all()['terms'] ?? ['version' => 'terms-v1', 'url' => '', 'content' => ''];
    }

    public function accountDeletion(): array
    {
        return $this->all()['accountDeletion'] ?? [
            'version' => 'deletion-v1',
            'url' => '',
            'playSubscriptionWarning' => 'Deleting the Xboard account does not cancel Play subscriptions. Manage or cancel them in Google Play.',
            'playSubscriptionManagementUrl' => 'https://play.google.com/store/account/subscriptions',
        ];
    }

    public function support(): array
    {
        return $this->all()['support'] ?? ['supportUrl' => '', 'email' => ''];
    }
}
