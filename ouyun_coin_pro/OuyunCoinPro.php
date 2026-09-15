<?php
namespace addon\ouyun_coin_pro;

use app\common\lib\Plugin;
use think\facade\Db;

/*
 * 平台币pro
 * 复刻官方平台币并增强: 平台币账本(发放/有效期/抵扣/退款退还/过期) + 8种活动类型
 *   - 标准送/充值送/用户属性送/累计消费送/单笔消费送/订购送/定向送/每日签到送
 *   - 购物车与结算页抵扣勾选、金额联动; 订单抵扣行写入通用 order_item
 *   - 报表统计+CSV导出、批量导入发放、待办事项pro联动、三语言
 *
 * 数据表(前缀 idcsmart_):
 *   ouyun_coin_pro_config   全局配置(KV)
 *   ouyun_coin_pro_activity 活动表(8种类型)
 *   ouyun_coin_pro_grant    发放记录(账本主体,含 frozen 预占冻结)
 *   ouyun_coin_pro_consume  消费流水(pending/confirmed/released)
 *   ouyun_coin_pro_log      全量流水(发放/消费/退款/过期/撤销/调整)
 *   ouyun_coin_pro_signin   每日签到状态
 *
 * 抵扣链路: applyPromoCode 试算(哨兵 __oycoin__) -> afterOrderCreate 落地
 *   (写 order_item 负数折扣行+减订单金额+预占冻结) -> orderPaid 确认扣减
 *   -> beforeOrderCancel/afterOrderDelete/beforeOrderRecycle 释放预占
 *   -> afterRefund 可退基数修正 + afterHostRefund 按比例退币
 *
 * @author 欧云超算
 */
class OuyunCoinPro extends Plugin
{
    # 插件基本信息
    public $info = array(
        'name'        => 'OuyunCoinPro', // 大驼峰,与目录名 ouyun_coin_pro 对应
        'title'       => '平台币pro',
        'description' => '平台币系统:余额/发放/抵扣/退款退还/过期,8种活动(标准送/充值送/属性送/累计消费送/单笔消费送/订购送/定向送/每日签到送),购物车抵扣勾选与金额联动,报表+CSV导出,批量导入发放,待办事项联动,免费开源。',
        'author'      => '欧云超算',
        'version'     => '2.3.0',
    );

    # 插件安装: 建 6 张表,写默认配置,注册通知动作(notice_setting 默认禁用)
    public function install()
    {
        $prefix = config('database.connections.mysql.prefix');

        # 1) 全局配置表(KV)
        $table = $prefix . 'ouyun_coin_pro_config';
        if (empty(Db::query("SHOW TABLES LIKE '{$table}'"))) {
            Db::execute("CREATE TABLE `{$table}` (
                `k` varchar(50) NOT NULL COMMENT '键',
                `v` text COMMENT '值',
                PRIMARY KEY (`k`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='平台币pro 配置'");
        }

        # 2) 活动表
        $table = $prefix . 'ouyun_coin_pro_activity';
        if (empty(Db::query("SHOW TABLES LIKE '{$table}'"))) {
            Db::execute("CREATE TABLE `{$table}` (
                `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                `name` varchar(100) NOT NULL DEFAULT '' COMMENT '活动名称',
                `code` varchar(20) NOT NULL DEFAULT '' COMMENT '活动编码(12-16位随机,可复制)',
                `type` varchar(20) NOT NULL DEFAULT 'standard' COMMENT '类型:standard标准送,recharge充值送,attr用户属性送,consume_total累计消费送,consume_single单笔消费送,order_buy订购送,target定向送,signin每日签到送',
                `grant_type` varchar(10) NOT NULL DEFAULT 'gradient' COMMENT '赠送类型:gradient梯度,ratio比例',
                `rules` text COMMENT '规则JSON(梯度数组/比例/属性/签到参数)',
                `scene` varchar(100) NOT NULL DEFAULT '' COMMENT '使用场景开关JSON:{host,renew,upgrade,on_demand_to_recurring}',
                `product_scope` tinyint(1) unsigned NOT NULL DEFAULT 0 COMMENT '适用商品:0不限制,1指定商品',
                `product_ids` text COMMENT '指定商品ID JSON数组',
                `cycle_limit_switch` tinyint(1) unsigned NOT NULL DEFAULT 0 COMMENT '周期限制开关',
                `cycle_limit` varchar(255) NOT NULL DEFAULT '' COMMENT '限定计费周期JSON数组(monthly,quarterly...)',
                `effective_days` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '发放后N天生效(0=立即生效)',
                `valid_days` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '平台币有效期N天(0=不限制)',
                `status` tinyint(1) unsigned NOT NULL DEFAULT 1 COMMENT '状态:0已结束,1启用中',
                `start_time` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '活动开始时间',
                `end_time` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '活动结束时间(0=不限制)',
                `remark` varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
                `create_time` int(10) unsigned NOT NULL DEFAULT 0,
                `update_time` int(10) unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_code` (`code`),
                KEY `idx_type_status` (`type`,`status`,`start_time`,`end_time`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='平台币pro 活动'");
        }

        # 3) 发放记录表(账本主体)
        #    可用余额 = SUM(remaining-frozen WHERE status=normal AND effective_time<=now AND (expire_time=0 OR expire_time>now))
        $table = $prefix . 'ouyun_coin_pro_grant';
        if (empty(Db::query("SHOW TABLES LIKE '{$table}'"))) {
            Db::execute("CREATE TABLE `{$table}` (
                `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                `client_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '用户ID(主账户)',
                `activity_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '活动ID(0=手动/退款等非活动发放)',
                `amount` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '初始额度(平台币)',
                `remaining` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '剩余额度(平台币)',
                `frozen` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '预占冻结(未支付订单预占)',
                `effective_time` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '生效时间(0=已生效)',
                `expire_time` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '过期时间(0=永不过期)',
                `status` varchar(10) NOT NULL DEFAULT 'normal' COMMENT '状态:normal正常,expired已过期,revoked已撤销',
                `source` varchar(10) NOT NULL DEFAULT 'system' COMMENT '发放方式:manual手动发放,claim自主领取,system系统发放',
                `claim_time` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '领取时间(自主领取时)',
                `limit_json` text COMMENT '使用限制JSON(发放时从活动固化:{product_ids,scene,cycles})',
                `from_key` varchar(100) NULL DEFAULT NULL COMMENT '幂等键(order:活动:订单/reg:活动:用户/tier:档位:用户,NULL=不查重)',
                `remark` varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
                `create_time` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '发放/领取时间',
                `update_time` int(10) unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_from` (`from_key`),
                KEY `idx_client` (`client_id`,`status`,`effective_time`,`expire_time`),
                KEY `idx_activity` (`activity_id`),
                KEY `idx_expire` (`status`,`expire_time`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='平台币pro 发放记录(账本)'");
        }

        # 4) 消费流水表(一次抵扣可能按发放记录拆多条)
        $table = $prefix . 'ouyun_coin_pro_consume';
        if (empty(Db::query("SHOW TABLES LIKE '{$table}'"))) {
            Db::execute("CREATE TABLE `{$table}` (
                `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                `grant_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '发放记录ID',
                `client_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '用户ID',
                `order_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '订单ID',
                `host_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '产品ID',
                `product_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '商品ID',
                `amount_coin` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '消耗平台币数量',
                `amount` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '抵扣金额(元)',
                `status` varchar(10) NOT NULL DEFAULT 'pending' COMMENT '状态:pending预占,confirmed已确认,released已释放',
                `create_time` int(10) unsigned NOT NULL DEFAULT 0,
                `update_time` int(10) unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `idx_order` (`order_id`,`status`),
                KEY `idx_grant` (`grant_id`),
                KEY `idx_host` (`host_id`),
                KEY `idx_client` (`client_id`,`create_time`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='平台币pro 消费流水'");
        }

        # 5) 全量流水表(前台明细直接查它)
        $table = $prefix . 'ouyun_coin_pro_log';
        if (empty(Db::query("SHOW TABLES LIKE '{$table}'"))) {
            Db::execute("CREATE TABLE `{$table}` (
                `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                `client_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '用户ID',
                `type` varchar(12) NOT NULL DEFAULT '' COMMENT '类型:grant发放,consume消费,refund退款退还,expire过期,revoke撤销,adjust调整,expire_soon过期提醒',
                `amount` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '变动值(正增负减)',
                `rel_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '关联ID(grant_id/order_id)',
                `rel_type` varchar(20) NOT NULL DEFAULT '' COMMENT '关联类型:grant/order/activity/refund',
                `remark` varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
                `admin_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '操作管理员(0=系统/用户)',
                `create_time` int(10) unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `idx_client` (`client_id`,`create_time`),
                KEY `idx_rel` (`type`,`rel_id`),
                KEY `idx_day` (`create_time`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='平台币pro 全量流水'");
        }

        # 6) 每日签到表
        $table = $prefix . 'ouyun_coin_pro_signin';
        if (empty(Db::query("SHOW TABLES LIKE '{$table}'"))) {
            Db::execute("CREATE TABLE `{$table}` (
                `client_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '用户ID(主账户)',
                `last_date` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '最后签到日(Ymd)',
                `streak_days` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '连续签到天数',
                `total_days` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '累计签到天数',
                `create_time` int(10) unsigned NOT NULL DEFAULT 0,
                `update_time` int(10) unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY (`client_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='平台币pro 每日签到'");
        }

        # 默认配置
        $Model = new \addon\ouyun_coin_pro\model\OuyunCoinProModel();
        foreach (\addon\ouyun_coin_pro\model\OuyunCoinProModel::DEFAULTS as $k => $v) {
            if ($Model->getKV($k) === null) {
                $Model->setKV($k, $v);
            }
        }

        # 注册通知动作(默认禁用,后台-通知设置里可开启并配模板)
        $notices = [
            ['oycoin_grant', '平台币发放到账'],
            ['oycoin_expire_soon', '平台币即将过期'],
            ['oycoin_consume', '平台币消费成功'],
        ];
        foreach ($notices as $n) {
            $exists = Db::name('notice_setting')->where('name', $n[0])->value('id');
            if (empty($exists)) {
                Db::name('notice_setting')->insert([
                    'name'       => $n[0],
                    'name_lang'  => $n[1],
                    'sms_enable' => 0,
                    'email_enable' => 0,
                    'type'       => 'plugin',
                ]);
            }
        }

        return true;
    }

    # 插件卸载: 默认保留全部数据(配置/活动/账本),便于重装恢复;
    # 如需彻底清除,先在「基础配置」页打开「卸载时删除全部数据」开关(二次确认)再卸载。
    public function uninstall()
    {
        $purge = Db::name('ouyun_coin_pro_config')->where('k', 'uninstall_purge')->value('v');
        if ($purge == 1) {
            $prefix = config('database.connections.mysql.prefix');
            foreach (['config', 'activity', 'grant', 'consume', 'log', 'signin'] as $t) {
                Db::execute("DROP TABLE IF EXISTS `{$prefix}ouyun_coin_pro_{$t}`");
            }
            Db::name('notice_setting')->whereIn('name', ['oycoin_grant', 'oycoin_expire_soon', 'oycoin_consume'])->delete();
        }
        return true;
    }

    /* ================================================================== */
    /* ================  以下 public 方法由系统自动注册为钩子  ============ */
    /* ================================================================== */

    # 优惠码管线试算(哨兵 __oycoin__): 勾选平台币且与优惠码互斥时,
    # 前端把 config_options.promo_code 置为哨兵,由本钩子返回抵扣额实现原生金额联动
    public function applyPromoCode($param)
    {
        return (new \addon\ouyun_coin_pro\model\OuyunCoinProModel())->hookApplyPromoCode($param);
    }

    # 订单创建后: 检测 customfield.use_oycoin -> 逐商品计算抵扣 -> 写 order_item 负数折扣行
    # -> 减订单金额(amount/amount_unpaid) -> 预占冻结(原子 frozen+=X)
    public function afterOrderCreate($param)
    {
        return (new \addon\ouyun_coin_pro\model\OuyunCoinProModel())->hookAfterOrderCreate($param);
    }

    # 支付成功: 1)确认扣减本订单预占(pending->confirmed, remaining-=X, frozen-=X)
    #          2)触发活动: 充值送/单笔消费送/累计消费送/订购送
    public function orderPaid($param)
    {
        return (new \addon\ouyun_coin_pro\model\OuyunCoinProModel())->hookOrderPaid($param);
    }

    # 订单取消前: 释放预占(frozen-=X, pending->released)
    public function beforeOrderCancel($param)
    {
        return (new \addon\ouyun_coin_pro\model\OuyunCoinProModel())->releaseOrder(intval($param['id'] ?? 0));
    }

    # 订单删除后(超时未支付自动删除走这里): 释放预占
    public function afterOrderDelete($param)
    {
        return (new \addon\ouyun_coin_pro\model\OuyunCoinProModel())->releaseOrder(intval($param['id'] ?? 0));
    }

    # 订单进回收站前(开了订单回收站时超时订单走这里): 释放预占
    public function beforeOrderRecycle($param)
    {
        return (new \addon\ouyun_coin_pro\model\OuyunCoinProModel())->releaseOrder(intval($param['id'] ?? 0));
    }

    # 注册完成: 触发"新注册用户"属性送
    public function afterClientRegister($param)
    {
        return (new \addon\ouyun_coin_pro\model\OuyunCoinProModel())->hookAfterRegister($param);
    }

    # 每日任务: 过期扫描置 expired + 即将过期提醒(前一天) + 周期性属性送
    public function dailyCron($param)
    {
        return (new \addon\ouyun_coin_pro\model\OuyunCoinProModel())->hookDailyCron();
    }

    # 5分钟任务: 过期扫描(更及时地把到期记录置 expired)
    public function fiveMinuteCron($param)
    {
        return (new \addon\ouyun_coin_pro\model\OuyunCoinProModel())->expireScan();
    }

    # 退款计算: 返回该产品平台币抵扣总额(负数),从可退基数中扣除
    public function afterRefund($param)
    {
        return (new \addon\ouyun_coin_pro\model\OuyunCoinProModel())->hookAfterRefund($param);
    }

    # 退款完成: 按退款比例把消耗的平台币退还给用户(新发放记录)
    public function afterHostRefund($param)
    {
        return (new \addon\ouyun_coin_pro\model\OuyunCoinProModel())->hookAfterHostRefund($param);
    }

    # 待办事项pro联动: 返回第三方待办条目(待领取标准送/即将过期平台币)
    public function ouyunTodoProItems($param)
    {
        return (new \addon\ouyun_coin_pro\model\OuyunCoinProModel())->todoItems();
    }

    # 后台用户详情: 追加平台币余额/明细入口字段
    public function adminClientIndex($param)
    {
        return (new \addon\ouyun_coin_pro\model\OuyunCoinProModel())->hookAdminClientIndex($param);
    }

    # 购物车页注入: 结算页平台币抵扣面板(勾选/余额/预计抵扣/金额联动)
    public function cartViewAppend($param)
    {
        $cfg = (new \addon\ouyun_coin_pro\model\OuyunCoinProModel())->getConfig();
        if ($cfg['enable'] != '1' || $cfg['show_cart_deduct'] != '1') {
            return [];
        }
        $view = strval($param['view_html'] ?? '');
        if (!in_array($view, ['settlement', 'shoppingCar', 'goods'])) {
            return [];
        }
        $ver = 'b3';
        return [
            'body' => '<script src="/plugins/addon/ouyun_coin_pro/template/clientarea/pc/default/js/cart_inject.js?' . $ver . '"></script>',
        ];
    }

    # 会员中心页面注入: 财务页余额旁/账号首页信息卡 显示平台币余额与入口
    public function clientareaViewAppend($param)
    {
        $cfg = (new \addon\ouyun_coin_pro\model\OuyunCoinProModel())->getConfig();
        if ($cfg['enable'] != '1') {
            return [];
        }
        $view = strval($param['view_html'] ?? '');
        if (!in_array($view, ['finance', 'home', 'account'])) {
            return [];
        }
        return [
            'body' => '<script src="/plugins/addon/ouyun_coin_pro/template/clientarea/pc/default/js/clientarea_inject.js?v=i"></script>',
        ];
    }
}
