<?php

namespace Plugin\WalletCenter\Controllers;

use App\Exceptions\ApiException;
use Illuminate\Http\Request;
use Plugin\WalletCenter\Services\AutoRenewService;
use Plugin\WalletCenter\Services\WalletCenterConfigService;
use Plugin\WalletCenter\Services\WalletCenterManifestService;
use Plugin\WalletCenter\Support\WalletCenterFeature;

class AutoRenewController extends BaseController
{
    public function __construct(
        WalletCenterConfigService $configService,
        WalletCenterManifestService $manifestService,
        protected AutoRenewService $autoRenewService
    ) {
        parent::__construct($configService, $manifestService);
    }

    public function config(Request $request)
    {
        if ($response = $this->requireFeature(WalletCenterFeature::AUTO_RENEW)) {
            return $response;
        }

        return $this->success($this->featurePayload(
            WalletCenterFeature::AUTO_RENEW,
            $this->autoRenewService->getConfigSnapshot($request->user()),
            'wallet-center'
        ));
    }

    public function update(Request $request)
    {
        if ($response = $this->requireFeature(WalletCenterFeature::AUTO_RENEW)) {
            return $response;
        }

        $request->validate([
            'enabled' => 'required|boolean',
        ]);

        try {
            $result = $this->autoRenewService->updateSetting(
                $request->user(),
                $request->boolean('enabled')
            );
        } catch (ApiException $exception) {
            return $this->fail([400, $exception->getMessage()], $this->featurePayload(
                WalletCenterFeature::AUTO_RENEW,
                $this->autoRenewService->getConfigSnapshot($request->user()),
                'wallet-center'
            ));
        } catch (\Throwable $exception) {
            report($exception);

            return $this->fail([500, 'WalletCenter auto renew update failed.'], $this->featurePayload(
                WalletCenterFeature::AUTO_RENEW,
                $this->autoRenewService->getConfigSnapshot($request->user()),
                'wallet-center'
            ));
        }

        return $this->success($this->featurePayload(
            WalletCenterFeature::AUTO_RENEW,
            $result,
            'wallet-center'
        ));
    }

    public function history(Request $request)
    {
        if ($response = $this->requireFeature(WalletCenterFeature::AUTO_RENEW)) {
            return $response;
        }

        $page = $this->resolvePage($request);
        $perPage = $this->resolveLimit($request, 10, 50);
        $history = $this->autoRenewService->paginateHistoryForUser(
            $request->user(),
            $page,
            $perPage,
            $request->query('status')
        );

        return $this->success($this->featurePayload(WalletCenterFeature::AUTO_RENEW, array_merge($history, [
            'count' => $history['total'],
            'config' => $this->autoRenewService->getConfigSnapshot($request->user()),
        ]), 'wallet-center'));
    }
}
