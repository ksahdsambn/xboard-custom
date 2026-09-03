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
            'wallet-center'
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
        } catch (\App\Exceptions\ApiException $exception) {
            return $this->fail(
                [400, $exception->getMessage()],
                $this->featurePayload(
                    WalletCenterFeature::CHECKIN,
                    [
                        'claimed' => false,
                        'server_date' => now()->toDateString(),
                        'reward_range' => $this->checkinService->getRewardRangeSnapshot(),
                    ],
                    'wallet-center'
                )
            );
        } catch (\Throwable $exception) {
            report($exception);

            return $this->fail(
                [500, '签到奖励入账失败。'],
                $this->featurePayload(
                    WalletCenterFeature::CHECKIN,
                    [
                        'claimed' => false,
                        'server_date' => now()->toDateString(),
                        'reward_range' => $this->checkinService->getRewardRangeSnapshot(),
                    ],
                    'wallet-center'
                )
            );
        }

        $payload = $this->featurePayload(WalletCenterFeature::CHECKIN, $result, 'wallet-center');

        if (!$result['claimed']) {
            return $this->fail([409, '今日已签到。'], $payload);
        }

        return $this->success($payload);
    }

    public function history(Request $request)
    {
        if ($response = $this->requireFeature(WalletCenterFeature::CHECKIN)) {
            return $response;
        }

        $page = $this->resolvePage($request);
        $perPage = $this->resolveLimit($request, 10, 50);
        $history = $this->checkinService->paginateHistoryForUser($request->user(), $page, $perPage);

        return $this->success($this->featurePayload(WalletCenterFeature::CHECKIN, array_merge($history, [
            'count' => $history['total'],
            'reward_range' => $this->checkinService->getRewardRangeSnapshot(),
            'server_date' => now()->toDateString(),
        ]), 'wallet-center'));
    }
}
