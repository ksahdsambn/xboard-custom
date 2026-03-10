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
            'stage-08-auto-renew'
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
                'stage-08-auto-renew'
            ));
        } catch (\Throwable $exception) {
            report($exception);

            return $this->fail([500, 'WalletCenter auto renew update failed.'], $this->featurePayload(
                WalletCenterFeature::AUTO_RENEW,
                $this->autoRenewService->getConfigSnapshot($request->user()),
                'stage-08-auto-renew'
            ));
        }

        return $this->success($this->featurePayload(
            WalletCenterFeature::AUTO_RENEW,
            $result,
            'stage-08-auto-renew'
        ));
    }

    public function history(Request $request)
    {
        if ($response = $this->requireFeature(WalletCenterFeature::AUTO_RENEW)) {
            return $response;
        }

        $records = $this->autoRenewService->getHistoryForUser(
            $request->user(),
            $this->resolveLimit($request)
        );

        return $this->success($this->featurePayload(WalletCenterFeature::AUTO_RENEW, [
            'records' => $records,
            'count' => $records->count(),
            'config' => $this->autoRenewService->getConfigSnapshot($request->user()),
        ], 'stage-08-auto-renew'));
    }
}
