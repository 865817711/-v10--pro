<?php
/**
 * 平台币pro - 自定义路由(后台 + 前台)
 * 后台路由走后台鉴权组 DIR_ADMIN.'/v1',继承 PluginAdminBaseController;
 * 前台路由走 console/v1 客户登录鉴权(CheckHome 注入 request->client_id)。
 */
use think\facade\Route;

# ============ 后台 ============
Route::group(DIR_ADMIN . '/v1', function () {
    # 活动配置
    Route::get('ouyun_coin_pro/activity', "\\addon\\ouyun_coin_pro\\controller\\IndexController::activityList")
        ->append(['_plugin' => 'ouyun_coin_pro', '_controller' => 'Index', '_action' => 'activityList']);
    Route::get('ouyun_coin_pro/activity_detail', "\\addon\\ouyun_coin_pro\\controller\\IndexController::activityDetail")
        ->append(['_plugin' => 'ouyun_coin_pro', '_controller' => 'Index', '_action' => 'activityDetail']);
    Route::post('ouyun_coin_pro/activity_save', "\\addon\\ouyun_coin_pro\\controller\\IndexController::activitySave")
        ->append(['_plugin' => 'ouyun_coin_pro', '_controller' => 'Index', '_action' => 'activitySave']);
    Route::post('ouyun_coin_pro/activity_delete', "\\addon\\ouyun_coin_pro\\controller\\IndexController::activityDelete")
        ->append(['_plugin' => 'ouyun_coin_pro', '_controller' => 'Index', '_action' => 'activityDelete']);
    Route::post('ouyun_coin_pro/activity_batch_delete', "\\addon\\ouyun_coin_pro\\controller\\IndexController::activityBatchDelete")
        ->append(['_plugin' => 'ouyun_coin_pro', '_controller' => 'Index', '_action' => 'activityBatchDelete']);
    Route::post('ouyun_coin_pro/activity_status', "\\addon\\ouyun_coin_pro\\controller\\IndexController::activityStatus")
        ->append(['_plugin' => 'ouyun_coin_pro', '_controller' => 'Index', '_action' => 'activityStatus']);

    # 发放详情
    Route::get('ouyun_coin_pro/grant', "\\addon\\ouyun_coin_pro\\controller\\IndexController::grantList")
        ->append(['_plugin' => 'ouyun_coin_pro', '_controller' => 'Index', '_action' => 'grantList']);
    Route::post('ouyun_coin_pro/grant_manual', "\\addon\\ouyun_coin_pro\\controller\\IndexController::grantManual")
        ->append(['_plugin' => 'ouyun_coin_pro', '_controller' => 'Index', '_action' => 'grantManual']);
    Route::post('ouyun_coin_pro/grant_revoke', "\\addon\\ouyun_coin_pro\\controller\\IndexController::grantRevoke")
        ->append(['_plugin' => 'ouyun_coin_pro', '_controller' => 'Index', '_action' => 'grantRevoke']);
    Route::post('ouyun_coin_pro/grant_batch_revoke', "\\addon\\ouyun_coin_pro\\controller\\IndexController::grantBatchRevoke")
        ->append(['_plugin' => 'ouyun_coin_pro', '_controller' => 'Index', '_action' => 'grantBatchRevoke']);
    Route::post('ouyun_coin_pro/grant_import', "\\addon\\ouyun_coin_pro\\controller\\IndexController::grantImport")
        ->append(['_plugin' => 'ouyun_coin_pro', '_controller' => 'Index', '_action' => 'grantImport']);
    Route::get('ouyun_coin_pro/log', "\\addon\\ouyun_coin_pro\\controller\\IndexController::logList")
        ->append(['_plugin' => 'ouyun_coin_pro', '_controller' => 'Index', '_action' => 'logList']);
    # 用户余额汇总(每用户可用/冻结/笔数)
    Route::get('ouyun_coin_pro/client_balance', "\\addon\\ouyun_coin_pro\\controller\\IndexController::clientBalance")
        ->append(['_plugin' => 'ouyun_coin_pro', '_controller' => 'Index', '_action' => 'clientBalance']);

    # 基础配置
    Route::get('ouyun_coin_pro/config', "\\addon\\ouyun_coin_pro\\controller\\IndexController::config")
        ->append(['_plugin' => 'ouyun_coin_pro', '_controller' => 'Index', '_action' => 'config']);
    Route::post('ouyun_coin_pro/config_save', "\\addon\\ouyun_coin_pro\\controller\\IndexController::configSave")
        ->append(['_plugin' => 'ouyun_coin_pro', '_controller' => 'Index', '_action' => 'configSave']);

    # 报表
    Route::get('ouyun_coin_pro/report', "\\addon\\ouyun_coin_pro\\controller\\IndexController::report")
        ->append(['_plugin' => 'ouyun_coin_pro', '_controller' => 'Index', '_action' => 'report']);
    Route::get('ouyun_coin_pro/report_export', "\\addon\\ouyun_coin_pro\\controller\\IndexController::reportExport")
        ->append(['_plugin' => 'ouyun_coin_pro', '_controller' => 'Index', '_action' => 'reportExport']);

    # 辅助
    Route::get('ouyun_coin_pro/client_level_options', "\\addon\\ouyun_coin_pro\\controller\\IndexController::clientLevelOptions")
        ->append(['_plugin' => 'ouyun_coin_pro', '_controller' => 'Index', '_action' => 'clientLevelOptions']);
    Route::get('ouyun_coin_pro/product_search', "\\addon\\ouyun_coin_pro\\controller\\IndexController::productSearch")
        ->append(['_plugin' => 'ouyun_coin_pro', '_controller' => 'Index', '_action' => 'productSearch']);
})
    ->middleware(\app\http\middleware\ParamFilter::class)
    ->middleware(\app\http\middleware\CheckAdmin::class)
    ->middleware(\app\http\middleware\RejectRepeatRequest::class);

# ============ 客户前台(clientarea) ============
Route::group('console/v1', function () {
    # 平台币主页(余额/签到/可领取/说明)
    Route::get('ouyun_coin_pro/index', "\\addon\\ouyun_coin_pro\\controller\\clientarea\\ClientController::index")
        ->append(['_plugin' => 'ouyun_coin_pro', '_controller' => 'Client', '_action' => 'index']);
    # 领取标准送
    Route::post('ouyun_coin_pro/claim', "\\addon\\ouyun_coin_pro\\controller\\clientarea\\ClientController::claim")
        ->append(['_plugin' => 'ouyun_coin_pro', '_controller' => 'Client', '_action' => 'claim']);
    # 每日签到
    Route::post('ouyun_coin_pro/signin', "\\addon\\ouyun_coin_pro\\controller\\clientarea\\ClientController::signin")
        ->append(['_plugin' => 'ouyun_coin_pro', '_controller' => 'Client', '_action' => 'signin']);
    # 明细流水
    Route::get('ouyun_coin_pro/logs', "\\addon\\ouyun_coin_pro\\controller\\clientarea\\ClientController::logs")
        ->append(['_plugin' => 'ouyun_coin_pro', '_controller' => 'Client', '_action' => 'logs']);
    # 购物车抵扣试算
    Route::post('ouyun_coin_pro/trial', "\\addon\\ouyun_coin_pro\\controller\\clientarea\\ClientController::trial")
        ->append(['_plugin' => 'ouyun_coin_pro', '_controller' => 'Client', '_action' => 'trial']);
})
    ->middleware(\app\http\middleware\ParamFilter::class)
    ->middleware(\app\http\middleware\CheckHome::class)
    ->middleware(\app\http\middleware\RejectRepeatRequest::class);
