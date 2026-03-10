<?php

namespace Plugin\StripePayment\Controllers;

use App\Http\Controllers\Controller;
use Plugin\StripePayment\Services\AdminOverviewService;

class AdminController extends Controller
{
    public function __construct(
        protected AdminOverviewService $overviewService
    ) {
    }

    public function overview()
    {
        return $this->success($this->overviewService->getOverview());
    }
}
