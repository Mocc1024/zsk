<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\service\FitnessService;

/**
 * 全民健身 - 统计管理
 *
 * 按门店维度展示业绩（总/今年/本月/今日）与课时、人数指标。
 * 口径统一由 FitnessService::storeStats() 计算，避免与其他页面不一致。
 */
class FitnessStatController extends BaseController
{
    public function index()
    {
        $this->checkPermission('fitness.stat.view');

        $storeId = (int) $this->input('store_id', 0);
        $list    = FitnessService::storeStats($storeId > 0 ? $storeId : null);

        return $this->success(['list' => $list]);
    }
}
