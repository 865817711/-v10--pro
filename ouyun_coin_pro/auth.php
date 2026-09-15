<?php
/*
 * 权限定义。
 * 超级管理员(admin_id=1)默认拥有全部权限,以下定义用于把功能分配给其他管理员角色。
 */
return [
    [
        'title' => 'auth_ouyun_coin_pro',
        'url' => '',
        'description' => '平台币pro',
        'parent' => 'auth_user',
        'child' => [
            [
                'title' => 'auth_ouyun_coin_pro_all',
                'url' => 'index',
                'auth_rule' => [
                    'addon\ouyun_coin_pro\controller\IndexController::activityList',
                    'addon\ouyun_coin_pro\controller\IndexController::activityDetail',
                    'addon\ouyun_coin_pro\controller\IndexController::activitySave',
                    'addon\ouyun_coin_pro\controller\IndexController::activityDelete',
                    'addon\ouyun_coin_pro\controller\IndexController::activityBatchDelete',
                    'addon\ouyun_coin_pro\controller\IndexController::activityStatus',
                    'addon\ouyun_coin_pro\controller\IndexController::grantList',
                    'addon\ouyun_coin_pro\controller\IndexController::grantManual',
                    'addon\ouyun_coin_pro\controller\IndexController::grantRevoke',
                    'addon\ouyun_coin_pro\controller\IndexController::grantBatchRevoke',
                    'addon\ouyun_coin_pro\controller\IndexController::grantImport',
                    'addon\ouyun_coin_pro\controller\IndexController::logList',
                    'addon\ouyun_coin_pro\controller\IndexController::clientBalance',
                    'addon\ouyun_coin_pro\controller\IndexController::config',
                    'addon\ouyun_coin_pro\controller\IndexController::configSave',
                    'addon\ouyun_coin_pro\controller\IndexController::report',
                    'addon\ouyun_coin_pro\controller\IndexController::reportExport',
                    'addon\ouyun_coin_pro\controller\IndexController::clientLevelOptions',
                ],
                'description' => '平台币活动管理/发放详情/基础配置/报表导出',
            ],
        ],
    ],
];
