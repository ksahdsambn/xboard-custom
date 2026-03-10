<?php

namespace Plugin\WalletCenter\Controllers;

use Illuminate\Http\Request;
use Plugin\WalletCenter\Services\CheckinService;
use Plugin\WalletCenter\Services\WalletCenterConfigService;
use Plugin\WalletCenter\Services\WalletCenterManifestService;
use Plugin\WalletCenter\Support\WalletCenterFeature;

class CheckinController extends BaseController
{
    public function __construct(
        WalletCenterConfigService $configService,
        WalletCenterManifestService $manifestService,
        protected CheckinService $checkinService
    ) {
        parent::__construct($configService, $manifestService);
    }

    public function status(Request $request)
    {
        if ($response = $this->requireFeature(WalletCenterFeature::CHECKIN)) {
            return $response;
        }

        return $this->success($this->featurePayload(
            WalletCenterFeature::CHECKIN,
            $this->checkinService->getStatusSnapshot($request->user()),
            'stage-06-checkin'
        ));
    }

    public function claim(Request $request)
    {
        if ($response = $this->requireFeature(WalletCenterFeature::CHECKIN)) {
            return $response;
        }

        try {
            $result = $this->checkinService->claim($request->user(), [
                'request_ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            return $this->fail(
                [500, 'WalletCenter checkin reward credit failed.'],
                $this->featurePayload(
                    WalletCenterFeature::CHECKIN,
                    [
                        'claimed' => false,
                        'server_date' => now()->toDateString(),
                        'reward_range' => $this->checkinService->getRewardRangeSnapshot(),
                    ],
                    'stage-06-checkin'
                )
            );
        }

        $payload = $this->featurePayload(WalletCenterFeature::CHECKIN, $result, 'stage-06-checkin');

        if (!$result['claimed']) {
            return $this->fail([409, 'Already checked in today.'], $payload);
        }

        return $this->success($payload);
    }

    public function history(Request $request)
    {
        if ($response = $this->requireFeature(WalletCenterFeature::CHECKIN)) {
            return $response;
        }

        $records = $this->checkinService->getHistoryForUser(
            $request->user(),
            $this->resolveLimit($request)
        );

        return $this->success($this->featurePayload(WalletCenterFeature::CHECKIN, [
            'records' => $records,
            'count' => $records->count(),
            'reward_range' => $this->checkinService->getRewardRangeSnapshot(),
            'server_date' => now()->toDateString(),
        ], 'stage-06-checkin'));
    }
}
