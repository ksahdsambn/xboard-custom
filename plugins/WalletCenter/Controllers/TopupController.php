<?php

namespace Plugin\WalletCenter\Controllers;

use App\Exceptions\ApiException;
use Illuminate\Http\Request;
use Plugin\WalletCenter\Services\TopupService;
use Plugin\WalletCenter\Services\WalletCenterConfigService;
use Plugin\WalletCenter\Services\WalletCenterManifestService;
use Plugin\WalletCenter\Support\WalletCenterFeature;

class TopupController extends BaseController
{
    public function __construct(
        WalletCenterConfigService $configService,
        WalletCenterManifestService $manifestService,
        protected TopupService $topupService
    ) {
        parent::__construct($configService, $manifestService);
    }

    public function methods()
    {
        if ($response = $this->requireFeature(WalletCenterFeature::TOPUP)) {
            return $response;
        }

        return $this->success($this->featurePayload(WalletCenterFeature::TOPUP, [
            'payment_channels' => $this->topupService->listAvailableChannels(),
            'amount_range' => $this->topupService->getAmountRangeSnapshot(),
        ], 'stage-07-topup'));
    }

    public function create(Request $request)
    {
        if ($response = $this->requireFeature(WalletCenterFeature::TOPUP)) {
            return $response;
        }

        $request->validate([
            'payment_id' => 'required|integer',
            'amount' => 'required',
        ]);

        try {
            $result = $this->topupService->create(
                $request->user(),
                $request->only(['payment_id', 'amount']),
                [
                    'request_ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]
            );
        } catch (ApiException $exception) {
            return $this->fail([400, $exception->getMessage()], $this->featurePayload(WalletCenterFeature::TOPUP, [
                'payment_channels' => $this->topupService->listAvailableChannels(),
                'amount_range' => $this->topupService->getAmountRangeSnapshot(),
            ], 'stage-07-topup'));
        } catch (\Throwable $exception) {
            report($exception);

            return $this->fail([500, 'WalletCenter topup create failed.'], $this->featurePayload(WalletCenterFeature::TOPUP, [
                'payment_channels' => $this->topupService->listAvailableChannels(),
                'amount_range' => $this->topupService->getAmountRangeSnapshot(),
            ], 'stage-07-topup'));
        }

        return $this->success($this->featurePayload(WalletCenterFeature::TOPUP, $result, 'stage-07-topup'));
    }

    public function detail(Request $request)
    {
        if ($response = $this->requireFeature(WalletCenterFeature::TOPUP)) {
            return $response;
        }

        $request->validate([
            'trade_no' => 'required|string',
        ]);

        $order = $this->topupService->getOrderForUser($request->user(), $request->input('trade_no'));
        if (!$order) {
            return $this->fail([404, 'WalletCenter topup order does not exist.']);
        }

        return $this->success($this->featurePayload(WalletCenterFeature::TOPUP, [
            'order' => $order,
            'amount_range' => $this->topupService->getAmountRangeSnapshot(),
        ], 'stage-07-topup'));
    }

    public function history(Request $request)
    {
        if ($response = $this->requireFeature(WalletCenterFeature::TOPUP)) {
            return $response;
        }

        $records = $this->topupService->getHistoryForUser(
            $request->user(),
            $this->resolveLimit($request)
        );

        return $this->success($this->featurePayload(WalletCenterFeature::TOPUP, [
            'records' => $records,
            'count' => $records->count(),
            'amount_range' => $this->topupService->getAmountRangeSnapshot(),
        ], 'stage-07-topup'));
    }

    public function notify(Request $request, string $method, string $uuid)
    {
        if ($response = $this->requireFeature(WalletCenterFeature::TOPUP)) {
            return $response;
        }

        try {
            $result = $this->topupService->processNotification($method, $uuid, $request);

            return $result['response'];
        } catch (\Throwable $exception) {
            report($exception);

            return response('fail', 500);
        }
    }
}
