<?php
namespace addon\ouyun_coin_pro\model;

use think\facade\Db;
use think\Model;

/**
 * @title 平台币pro - 账本核心
 * @desc 配置/余额/发放/抵扣(试算-落地-确认-释放)/过期/退款退还/通知/签到/待办
 * @use addon\ouyun_coin_pro\model\OuyunCoinProModel
 */
class OuyunCoinProModel extends Model
{
    protected $name = 'ouyun_coin_pro_grant';
    protected $pk = 'id';

    /** apply_promo_code 试算哨兵: 与优惠码互斥模式下,前端把 promo_code 置为该值 */
    const SENTINEL = '__oycoin__';

    /** order_item 折扣行类型(表名除前缀,与官方优惠码 addon_promo_code 同规则) */
    const ORDER_ITEM_TYPE = 'ouyun_coin_pro_consume';

    /** 可参与抵扣的订单子项类型(与官方优惠码插件保持一致) */
    const DISCOUNTABLE_ITEMS = ['host', 'renew', 'upgrade', 'change_billing_cycle', 'addon_idcsmart_flow_packet'];

    /** 订单类型 -> 活动使用场景 */
    const ORDER_SCENE_MAP = [
        'new'                => 'host',
        'artificial'         => 'host',
        'zjmf'               => 'host',
        'renew'              => 'renew',
        'upgrade'            => 'upgrade',
        'change_billing_cycle' => 'on_demand_to_recurring',
    ];

    /** 配置默认值 */
    const DEFAULTS = [
        'enable'               => '1',        // 总开关(关闭后前台不可见不可抵扣)
        'coin_name'            => '平台币',    // 平台币名称(前台全部跟随)
        'rate'                 => '1',        // 汇率:1元=N平台币(赠送与抵扣双向换算)
        'refund_policy'        => 'ratio',    // 退款退还:none不退还,ratio按比例退还
        'show_sidebar_activity'=> '1',        // 会员中心平台币页展示活动区块
        'show_cart_deduct'     => '1',        // 购物车/结算页展示平台币抵扣
        'allow_sub_account'    => '1',        // 代理(子账户)可使用平台币
        'description'          => '',         // 平台币说明(富文本,前台展示)
        'recharge_grant_cap'   => '0',        // 单笔充值赠送上限(0=不限,单位币)
        'balance_cap'          => '0',        // 用户平台币余额上限(0=不限,单位币)
        'cap_overflow'         => 'truncate', // 超出余额上限:truncate截断到上限,skip放弃本次发放
        'activity_overlay'     => '0',        // 同类型时间交叉活动:0不叠加(取最新创建),1叠加
        'cert_first'           => '0',        // 实名认证后才可使用
        'with_promo_code'      => '0',        // 可与优惠码同时使用
        'with_event_promotion' => '1',        // 可与活动折扣同时使用
        'with_client_level'    => '1',        // 可与用户等级折扣同时使用
        'limit_type'           => 'percent',  // 单次抵扣限制方式:fixed固定金额,percent订单比例
        'limit_fixed'          => '0',        // 单次最多抵扣固定金额(元,0=不限)
        'limit_percent'        => '100',      // 单次最多抵扣订单比例(%)
        'max_product_percent'  => '100',      // 单商品最大抵扣比例(%)
        'max_grants_per_product' => '0',      // 单商品最多使用N条发放记录(0=不限)
        'signin_streak_cap'    => '0',        // 兼容字段(签到封顶存活动rules)
        'uninstall_purge'      => '0',        // 卸载时删除全部数据(后台二次确认开关)
    ];

    /* ============================ 配置读写 ============================ */

    public function getKV(string $k, $default = null)
    {
        $v = Db::name('ouyun_coin_pro_config')->where('k', $k)->value('v');
        return $v === null ? $default : $v;
    }

    public function setKV(string $k, $v): void
    {
        $val = is_string($v) ? $v : strval($v);
        $exists = Db::name('ouyun_coin_pro_config')->where('k', $k)->value('k');
        if ($exists) {
            Db::name('ouyun_coin_pro_config')->where('k', $k)->update(['v' => $val]);
        } else {
            Db::name('ouyun_coin_pro_config')->insert(['k' => $k, 'v' => $val]);
        }
    }

    public function getConfig(): array
    {
        $rows = Db::name('ouyun_coin_pro_config')->column('v', 'k');
        $cfg = self::DEFAULTS;
        foreach ($cfg as $k => $v) {
            if (isset($rows[$k]) && $rows[$k] !== '' && $rows[$k] !== null) {
                $cfg[$k] = $rows[$k];
            }
        }
        return $cfg;
    }

    /** 后台保存配置(白名单) */
    public function saveConfig(array $param): array
    {
        $keys = array_keys(self::DEFAULTS);
        unset($keys[array_search('uninstall_purge', $keys)]);
        foreach ($keys as $k) {
            if (array_key_exists($k, $param)) {
                $v = $param[$k];
                if (is_array($v)) {
                    $v = json_encode($v, JSON_UNESCAPED_UNICODE);
                }
                $this->setKV($k, strval($v));
            }
        }
        # uninstall_purge 单独开关(不在常规保存里,防止误触)
        if (isset($param['uninstall_purge']) && in_array($param['uninstall_purge'], ['0', '1', 0, 1], true)) {
            $this->setKV('uninstall_purge', strval(intval($param['uninstall_purge'])));
        }
        return ['status' => 200, 'msg' => lang_plugins('success_message')];
    }

    public function coinName(): string
    {
        $name = strval($this->getKV('coin_name', self::DEFAULTS['coin_name']));
        return $name === '' ? self::DEFAULTS['coin_name'] : $name;
    }

    /** 汇率:1元=N币 */
    public function rate(): string
    {
        $rate = strval($this->getKV('rate', '1'));
        if (!is_numeric($rate) || bccomp($rate, '0.0001', 4) < 0) {
            $rate = '1';
        }
        return $rate;
    }

    /** 元 -> 币 */
    public function yuan2coin($yuan): string
    {
        return bcmul(strval($yuan), $this->rate(), 2);
    }

    /** 币 -> 元 */
    public function coin2yuan($coin): string
    {
        $rate = $this->rate();
        if (bccomp($rate, '0', 4) <= 0) {
            return '0.00';
        }
        return bcdiv(strval($coin), $rate, 2);
    }

    /* ============================ 账户主体 ============================ */

    /**
     * 解析平台币归属账户
     * 代理开关开启时子账户归并到主账户(余额共享);关闭时仅本人
     */
    public function resolveAccount(int $clientId): int
    {
        if ($clientId <= 0) {
            return 0;
        }
        if ($this->getKV('allow_sub_account', '1') == '1') {
            return intval(get_client_id()) ?: $clientId;
        }
        return $clientId;
    }

    /** 通过客户ID反查主账户(子账户归并;后台/异步场景无请求上下文时) */
    public function accountOf(int $clientId): int
    {
        if ($clientId <= 0) {
            return 0;
        }
        if ($this->getKV('allow_sub_account', '1') != '1') {
            return $clientId;
        }
        # 子账户归属由子账户插件通过钩子提供(与 get_client_id 同机制)
        try {
            $results = hook('get_client_parent_id', ['client_id' => $clientId]);
            foreach ((array)$results as $r) {
                if ($r) {
                    return intval($r);
                }
            }
        } catch (\Throwable $e) {
        }
        return $clientId;
    }

    /* ============================ 余额查询 ============================ */

    /** 可用 grant 行(status=normal 且已生效未过期,按剩余有效期升序,0=永不过期排最后) */
    public function usableGrants(int $clientId, int $productId = 0): array
    {
        if ($clientId <= 0) {
            return [];
        }
        $now = time();
        $rows = Db::name('ouyun_coin_pro_grant')
            ->where('client_id', $clientId)
            ->where('status', 'normal')
            ->where('effective_time', '<=', $now)
            ->where(function ($q) use ($now) {
                $q->where('expire_time', 0)->whereOr('expire_time', '>', $now);
            })
            ->whereRaw('remaining > frozen')
            ->orderRaw('IF(expire_time=0, 4294967295, expire_time) ASC, id ASC')
            ->select()->toArray();
        if ($productId > 0) {
            foreach ($rows as $i => $r) {
                $limit = $r['limit_json'] ? json_decode($r['limit_json'], true) : [];
                $pids = isset($limit['product_ids']) && is_array($limit['product_ids']) ? $limit['product_ids'] : [];
                if (!empty($pids) && !in_array($productId, $pids)) {
                    unset($rows[$i]);
                }
            }
            $rows = array_values($rows);
        }
        return $rows;
    }

    /** 余额概览:可用/冻结/各明细 */
    public function balanceOf(int $clientId): array
    {
        $now = time();
        $base = ['client_id' => $clientId];
        if ($clientId <= 0) {
            return $base + ['available' => '0.00', 'frozen' => '0.00', 'expiring_amount' => '0.00', 'expiring' => []];
        }
        $rows = Db::name('ouyun_coin_pro_grant')
            ->where('client_id', $clientId)
            ->where('status', 'normal')
            ->where('effective_time', '<=', $now)
            ->where(function ($q) use ($now) {
                $q->where('expire_time', 0)->whereOr('expire_time', '>', $now);
            })
            ->select()->toArray();
        $available = '0.00';
        $frozenTotal = '0.00';
        $expiring = [];
        foreach ($rows as $r) {
            $free = bcsub($r['remaining'], $r['frozen'], 2);
            if (bccomp($free, '0', 2) > 0) {
                $available = bcadd($available, $free, 2);
            }
            $frozenTotal = bcadd($frozenTotal, min($r['frozen'], $r['remaining']), 2);
            if ($r['expire_time'] > 0 && $r['expire_time'] - $now <= 7 * 86400 && bccomp($free, '0', 2) > 0) {
                $expiring[] = ['id' => $r['id'], 'amount' => $free, 'expire_time' => $r['expire_time']];
            }
        }
        usort($expiring, function ($a, $b) {
            return $a['expire_time'] - $b['expire_time'];
        });
        $expiringAmount = '0.00';
        foreach ($expiring as $e) {
            $expiringAmount = bcadd($expiringAmount, $e['amount'], 2);
        }
        return $base + [
            'available'        => $available,
            'frozen'           => $frozenTotal,
            'expiring_amount'  => $expiringAmount,
            'expiring'         => array_slice($expiring, 0, 10),
            'rate'             => $this->rate(),
            'coin_name'        => $this->coinName(),
            'available_yuan'   => $this->coin2yuan($available),
        ];
    }

    /* ============================ 发放 ============================ */

    /**
     * 统一发放入口(所有活动/手动/退款退回共用)
     * @param int    clientId  归属账户
     * @param int    activityId 活动ID(0=非活动)
     * @param string amount    发放额度(币)
     * @param string source   manual/claim/system
     * @param string remark   备注
     * @param array  extra    ['from_key'=>'幂等键','expire_time'=>0,'admin_id'=>0]
     * @return array ['status'=>200,'data'=>['grant_id'=>0,'amount'=>实发]]
     */
    public function grantCoins(int $clientId, int $activityId, $amount, string $source, string $remark = '', array $extra = []): array
    {
        $amount = bcadd(strval($amount), '0', 2);
        if ($clientId <= 0 || bccomp($amount, '0.01', 2) < 0) {
            return ['status' => 400, 'msg' => '参数错误'];
        }
        # 幂等:同 from_key 已发放过则跳过(grant 表唯一索引兜底并发)
        $fromKey = strval($extra['from_key'] ?? '');
        if ($fromKey !== '') {
            $dup = Db::name('ouyun_coin_pro_grant')->where('from_key', $fromKey)->count();
            if ($dup > 0) {
                return ['status' => 200, 'msg' => '已发放过(幂等跳过)', 'data' => ['grant_id' => 0, 'amount' => '0.00']];
            }
        }

        # 余额上限
        $cap = floatval($this->getKV('balance_cap', '0'));
        if ($cap > 0) {
            $now0 = time();
            $held = Db::name('ouyun_coin_pro_grant')
                ->where('client_id', $clientId)
                ->where('status', 'normal')
                ->where(function ($q) use ($now0) {
                    $q->where('expire_time', 0)->whereOr('expire_time', '>', $now0);
                })
                ->sum('remaining');
            $room = bcsub(strval($cap), strval($held), 2);
            if (bccomp($room, '0', 2) <= 0) {
                return ['status' => 400, 'msg' => '已达平台币余额上限', 'data' => ['grant_id' => 0, 'amount' => '0.00']];
            }
            if (bccomp($amount, $room, 2) > 0) {
                if ($this->getKV('cap_overflow', 'truncate') == 'skip') {
                    return ['status' => 400, 'msg' => '发放额超出余额上限(策略:跳过)', 'data' => ['grant_id' => 0, 'amount' => '0.00']];
                }
                $amount = $room; # truncate
            }
        }
        if (bccomp($amount, '0.01', 2) < 0) {
            return ['status' => 400, 'msg' => '发放额不足0.01', 'data' => ['grant_id' => 0, 'amount' => '0.00']];
        }

        # 活动: 生效时间/有效期/限制固化
        $now = time();
        $effectiveTime = $now;
        $expireTime = intval($extra['expire_time'] ?? 0);
        $limitJson = '';
        $activity = null;
        if ($activityId > 0) {
            $activity = Db::name('ouyun_coin_pro_activity')->where('id', $activityId)->find();
            if (!empty($activity)) {
                if ($activity['effective_days'] > 0) {
                    $effectiveTime = $now + intval($activity['effective_days']) * 86400;
                }
                if ($expireTime == 0 && $activity['valid_days'] > 0) {
                    $expireTime = $now + intval($activity['valid_days']) * 86400;
                }
                $limit = [
                    'product_ids' => ($activity['product_scope'] == 1 && $activity['product_ids']) ? json_decode($activity['product_ids'], true) : [],
                    'scene'       => $activity['scene'] ? json_decode($activity['scene'], true) : [],
                    'cycles'      => ($activity['cycle_limit_switch'] == 1 && $activity['cycle_limit']) ? json_decode($activity['cycle_limit'], true) : [],
                ];
                $limitJson = json_encode($limit, JSON_UNESCAPED_UNICODE);
            }
        }

        try {
            $grantId = Db::name('ouyun_coin_pro_grant')->insertGetId([
                'client_id'      => $clientId,
                'activity_id'    => $activityId,
                'amount'         => $amount,
                'remaining'      => $amount,
                'frozen'         => '0.00',
                'effective_time' => $effectiveTime,
                'expire_time'    => $expireTime,
                'status'         => 'normal',
                'source'         => in_array($source, ['manual', 'claim', 'system']) ? $source : 'system',
                'claim_time'     => $source == 'claim' ? $now : 0,
                'limit_json'     => $limitJson,
                'from_key'       => $fromKey === '' ? null : $fromKey,
                'remark'         => mb_substr($remark, 0, 480),
                'create_time'    => $now,
                'update_time'    => $now,
            ]);
        } catch (\Throwable $e) {
            # 唯一索引冲突=并发重复发放,按幂等跳过
            return ['status' => 200, 'msg' => '已发放过(幂等)', 'data' => ['grant_id' => 0, 'amount' => '0.00']];
        }

        $this->addLog($clientId, 'grant', $amount, $activityId > 0 ? $activityId : $grantId, 'activity', $remark, intval($extra['admin_id'] ?? 0));

        # 发放到账通知(立即生效的才提示)
        if ($effectiveTime <= $now) {
            $this->notice('oycoin_grant', $clientId, [
                'amount'   => $amount,
                'remark'   => $remark,
                'coin_name'=> $this->coinName(),
            ]);
        }
        return ['status' => 200, 'msg' => '发放成功', 'data' => ['grant_id' => $grantId, 'amount' => $amount, 'effective_time' => $effectiveTime, 'expire_time' => $expireTime]];
    }

    /** 流水 */
    public function addLog(int $clientId, string $type, $amount, int $relId, string $relType, string $remark = '', int $adminId = 0): void
    {
        Db::name('ouyun_coin_pro_log')->insert([
            'client_id'   => $clientId,
            'type'        => $type,
            'amount'      => bcadd(strval($amount), '0', 2),
            'rel_id'      => $relId,
            'rel_type'    => $relType,
            'remark'      => mb_substr($remark, 0, 480),
            'admin_id'    => $adminId,
            'create_time' => time(),
        ]);
    }

    /* ============================ 抵扣试算 ============================ */

    /**
     * 计算单个商品的最大可抵扣额(元)
     * @param int    clientId 归属账户
     * @param int    productId 商品ID
     * @param string totalPrice 该商品总价(元,单价x数量)
     * @param string sceneKey  场景 host/renew/upgrade/on_demand_to_recurring
     * @param string billingCycle 计费周期名(空=不校验周期)
     * @param string shareBase 订单级剩余可抵扣额度(元,''=不限制,试算分配用)
     * @return array ['yuan'=>抵扣额(元),'coin'=>对应币,'grants'=>[[id,coin],...]]
     */
    public function calcProductDiscount(int $clientId, int $productId, $totalPrice, string $sceneKey = 'host', string $billingCycle = '', $shareBase = null): array
    {
        $cfg = $this->getConfig();
        $empty = ['yuan' => '0.00', 'coin' => '0.00', 'grants' => []];
        if ($cfg['enable'] != '1' || $clientId <= 0 || bccomp(strval($totalPrice), '0', 2) <= 0) {
            return $empty;
        }
        # 实名限制
        if ($cfg['cert_first'] == '1' && !check_certification($clientId)) {
            return $empty;
        }
        # 与优惠码互斥时不与码同享的场景由前端控制(勾平台币清空码),这里不再拦截

        $grants = $this->usableGrants($clientId, $productId);
        if (empty($grants)) {
            return $empty;
        }
        # 可用币余额(逐 grant 校验场景/周期限制)
        $rate = $this->rate();
        $maxGrants = intval($cfg['max_grants_per_product']);
        $picked = [];
        $availCoin = '0.00';
        $count = 0;
        foreach ($grants as $g) {
            $limit = $g['limit_json'] ? json_decode($g['limit_json'], true) : [];
            # 使用场景开关:活动勾选了场景后仅勾选场景可用;未勾选任何场景=全部可用
            $scene = isset($limit['scene']) && is_array($limit['scene']) ? $limit['scene'] : [];
            $sceneOn = array_filter($scene);
            if (!empty($sceneOn) && empty($scene[$sceneKey])) {
                continue;
            }
            # 周期限制
            $cycles = isset($limit['cycles']) && is_array($limit['cycles']) ? $limit['cycles'] : [];
            if (!empty($cycles) && $billingCycle !== '' && !in_array($billingCycle, $cycles)) {
                continue;
            }
            $free = bcsub($g['remaining'], $g['frozen'], 2);
            if (bccomp($free, '0', 2) <= 0) {
                continue;
            }
            $picked[] = ['id' => $g['id'], 'coin' => $free, 'expire_time' => $g['expire_time']];
            $availCoin = bcadd($availCoin, $free, 2);
            $count++;
            if ($maxGrants > 0 && $count >= $maxGrants) {
                break;
            }
        }
        if (empty($picked)) {
            return $empty;
        }

        # 商品级:单商品最大抵扣比例
        $yuan = $this->coin2yuan($availCoin);
        $pct = floatval($cfg['max_product_percent']);
        if ($pct > 0 && $pct < 100) {
            $capYuan = bcdiv(bcmul(strval($totalPrice), strval($pct), 4), '100', 2);
            if (bccomp($yuan, $capYuan, 2) > 0) {
                $yuan = $capYuan;
            }
        }
        if (bccomp($yuan, strval($totalPrice), 2) > 0) {
            $yuan = strval($totalPrice);
        }
        # 订单级剩余额度
        if ($shareBase !== null && bccomp($yuan, strval($shareBase), 2) > 0) {
            $yuan = strval($shareBase);
        }
        if (bccomp($yuan, '0.00', 2) <= 0) {
            return $empty;
        }
        # 按额度裁剪可用 grant(逐张扣)
        $coin = $this->yuan2coin($yuan);
        $use = [];
        $left = $coin;
        foreach ($picked as $p) {
            if (bccomp($left, '0', 2) <= 0) {
                break;
            }
            $take = bccomp($p['coin'], $left, 2) > 0 ? $left : $p['coin'];
            $use[] = ['id' => $p['id'], 'coin' => $take];
            $left = bcsub($left, $take, 2);
        }
        $realCoin = bcsub($coin, $left, 2);
        $realYuan = $this->coin2yuan($realCoin);
        return ['yuan' => $realYuan, 'coin' => $realCoin, 'grants' => $use];
    }

    /**
     * 多商品订单试算分配
     * @param array items [[product_id, price(总价元), billing_cycle],...]
     * @param string sceneKey
     * @param string orderAmount 订单应付(元,订单级比例基数)
     */
    public function trialCart(int $clientId, array $items, string $sceneKey = 'host', $orderAmount = null): array
    {
        $cfg = $this->getConfig();
        $result = ['items' => [], 'total_yuan' => '0.00', 'total_coin' => '0.00', 'balance' => '0.00', 'coin_name' => $this->coinName(), 'enabled' => false];
        if ($cfg['enable'] != '1' || $clientId <= 0) {
            return $result;
        }
        if ($cfg['cert_first'] == '1' && !check_certification($clientId)) {
            return $result;
        }
        $bal = $this->balanceOf($clientId);
        $result['balance'] = $bal['available'];
        if (bccomp($bal['available'], '0', 2) <= 0) {
            return $result;
        }
        # 订单级总额度
        $totalBase = null;
        if ($orderAmount !== null) {
            $totalBase = strval($orderAmount);
            if ($cfg['limit_type'] == 'fixed') {
                $fixed = floatval($cfg['limit_fixed']);
                if ($fixed > 0 && bccomp($totalBase, strval($fixed), 2) > 0) {
                    $totalBase = strval($fixed);
                }
            } else {
                $pct = floatval($cfg['limit_percent']);
                if ($pct > 0 && $pct < 100) {
                    $totalBase = bcdiv(bcmul(strval($orderAmount), strval($pct), 4), '100', 2);
                }
            }
        }
        $left = $totalBase;
        foreach ($items as $it) {
            $share = $left;
            $d = $this->calcProductDiscount($clientId, intval($it['product_id']), strval($it['price']), $sceneKey, strval($it['billing_cycle'] ?? ''), $share);
            if (bccomp($d['yuan'], '0', 2) > 0) {
                $result['items'][] = ['product_id' => intval($it['product_id']), 'yuan' => $d['yuan'], 'coin' => $d['coin']];
                $result['total_yuan'] = bcadd($result['total_yuan'], $d['yuan'], 2);
                $result['total_coin'] = bcadd($result['total_coin'], $d['coin'], 2);
                if ($left !== null) {
                    $left = bcsub($left, $d['yuan'], 2);
                    if (bccomp($left, '0', 2) <= 0) {
                        break;
                    }
                }
            }
        }
        $result['enabled'] = bccomp($result['total_yuan'], '0', 2) > 0;
        return $result;
    }

    /* ============================ 钩子: 试算 ============================ */

    /**
     * apply_promo_code 钩子: 哨兵模式下返回抵扣额实现原生金额联动
     * 参数: host_id/price(单价)/scene(new,renew,upgrade,change_billing_cycle)/qty/duration/product_id/promo_code
     */
    public function hookApplyPromoCode($param)
    {
        $code = $param['promo_code'] ?? '';
        if (is_array($code)) {
            $code = reset($code);
        }
        if (strval($code) !== self::SENTINEL) {
            return ['status' => 400];
        }
        $clientId = intval(request()->client_id ?? 0);
        if ($clientId <= 0) {
            return ['status' => 400];
        }
        $clientId = $this->resolveAccount($clientId);
        $sceneMap = ['new' => 'host', 'renew' => 'renew', 'upgrade' => 'upgrade', 'change_billing_cycle' => 'on_demand_to_recurring'];
        $sceneKey = $sceneMap[strval($param['scene'] ?? 'new')] ?? 'host';
        $qty = max(1, intval($param['qty'] ?? 1));
        $price = bcmul(strval($param['price'] ?? 0), strval($qty), 2);
        # 订单级单次抵扣限制(试算时以本商品金额近似基数;落地时按真实订单额)
        $cfg = $this->getConfig();
        $shareBase = null;
        if ($cfg['limit_type'] == 'fixed') {
            $fixed = floatval($cfg['limit_fixed']);
            if ($fixed > 0) {
                $shareBase = bccomp($price, strval($fixed), 2) > 0 ? strval($fixed) : $price;
            }
        } else {
            $pct = floatval($cfg['limit_percent']);
            if ($pct > 0 && $pct < 100) {
                $shareBase = bcdiv(bcmul($price, strval($pct), 4), '100', 2);
            }
        }
        $d = $this->calcProductDiscount($clientId, intval($param['product_id'] ?? 0), $price, $sceneKey, '', $shareBase);
        return ['status' => 200, 'msg' => 'ok', 'data' => [
            'discount'               => $d['yuan'],
            'id'                     => 0,
            'loop'                   => 0,
            'renew'                  => 0,
            'single_user_once'       => 0,
            'exclude_with_client_level' => $cfg['with_client_level'] == '1' ? 0 : 1,
        ]];
    }

    /* ============================ 钩子: 落地 ============================ */

    /**
     * after_order_create 钩子: 检测 use_oycoin -> 逐商品抵扣 -> 写 order_item -> 减订单金额 -> 预占冻结
     */
    public function hookAfterOrderCreate($param)
    {
        $orderId = intval($param['id'] ?? 0);
        if ($orderId <= 0) {
            return true;
        }
        $order = Db::name('order')->where('id', $orderId)->find();
        if (empty($order) || in_array($order['status'], ['Paid', 'Cancelled', 'Refunded'])) {
            return true;
        }
        $customfield = $param['customfield'] ?? [];
        if (!is_array($customfield)) {
            return true;
        }
        # 使用标记: customfield.use_oycoin=1 或 promo_code 为哨兵
        $use = false;
        if (isset($customfield['use_oycoin']) && intval($customfield['use_oycoin']) == 1) {
            $use = true;
        }
        if (!$use && isset($customfield['promo_code'])) {
            $pc = $customfield['promo_code'];
            $pc = is_array($pc) ? implode('', $pc) : strval($pc);
            if ($pc === self::SENTINEL) {
                $use = true;
            }
        }
        # 逐产品优惠码哨兵(host_customfield 里)
        if (!$use && !empty($customfield['host_customfield']) && is_array($customfield['host_customfield'])) {
            foreach ($customfield['host_customfield'] as $hc) {
                $pc = $hc['customfield']['promo_code'] ?? '';
                $pc = is_array($pc) ? implode('', $pc) : strval($pc);
                if ($pc === self::SENTINEL) {
                    $use = true;
                    break;
                }
            }
        }
        if (!$use) {
            return true;
        }

        $clientId = $this->accountOf(intval($order['client_id']));
        if ($clientId <= 0) {
            return true;
        }
        $cfg = $this->getConfig();
        if ($cfg['enable'] != '1') {
            return true;
        }
        $sceneKey = self::ORDER_SCENE_MAP[$order['type']] ?? 'host';
        if ($cfg['cert_first'] == '1' && !check_certification($clientId)) {
            return true;
        }

        # 订单可抵扣子项
        $items = Db::name('order_item')->alias('oi')
            ->field('oi.id,oi.host_id,oi.product_id,oi.amount,oi.type,h.billing_cycle')
            ->leftJoin('host h', 'h.id=oi.host_id AND h.is_delete=0')
            ->where('oi.order_id', $orderId)
            ->whereIn('oi.type', self::DISCOUNTABLE_ITEMS)
            ->select()->toArray();
        if (empty($items)) {
            return true;
        }

        # 订单级总额度
        $orderAmount = strval($order['amount']);
        $totalBase = $orderAmount;
        if ($cfg['limit_type'] == 'fixed') {
            $fixed = floatval($cfg['limit_fixed']);
            if ($fixed > 0 && bccomp($totalBase, strval($fixed), 2) > 0) {
                $totalBase = strval($fixed);
            }
        } else {
            $pct = floatval($cfg['limit_percent']);
            if ($pct > 0 && $pct < 100) {
                $totalBase = bcdiv(bcmul($orderAmount, strval($pct), 4), '100', 2);
            }
        }
        if (bccomp($totalBase, '0.01', 2) < 0) {
            return true;
        }

        $left = $totalBase;
        $orderItems = [];   # 待插入 order_item
        $alloc = [];        # 待预占 [grant_id => coin]
        $totalYuan = '0.00';
        foreach ($items as $it) {
            if (bccomp($left, '0.00', 2) <= 0) {
                break;
            }
            $price = strval($it['amount']);
            if (bccomp($price, '0', 2) <= 0) {
                continue;
            }
            $d = $this->calcProductDiscount($clientId, intval($it['product_id']), $price, $sceneKey, strval($it['billing_cycle'] ?? ''), $left);
            if (bccomp($d['yuan'], '0.00', 2) <= 0) {
                continue;
            }
            # 裁剪到订单剩余额度
            $take = bccomp($d['yuan'], $left, 2) > 0 ? $left : $d['yuan'];
            $coin = $this->yuan2coin($take);
            # 逐 grant 裁剪
            $useGrants = [];
            $cl = $coin;
            foreach ($d['grants'] as $g) {
                if (bccomp($cl, '0', 2) <= 0) {
                    break;
                }
                $t = bccomp($g['coin'], $cl, 2) > 0 ? $cl : $g['coin'];
                $useGrants[] = ['id' => $g['id'], 'coin' => $t];
                $cl = bcsub($cl, $t, 2);
            }
            $realCoin = bcsub($coin, $cl, 2);
            if (bccomp($realCoin, '0.01', 2) < 0) {
                continue;
            }
            $realYuan = $this->coin2yuan($realCoin);
            $orderItems[] = [
                'order_id' => $orderId, 'client_id' => $clientId, 'host_id' => $it['host_id'],
                'product_id' => $it['product_id'], 'type' => self::ORDER_ITEM_TYPE, 'rel_id' => 0,
                'amount' => bcsub('0', $realYuan, 2),
                'description' => $this->coinName() . '抵扣' . $realYuan . '元(' . $realCoin . $this->coinName() . ')',
                'create_time' => time(),
            ];
            foreach ($useGrants as $ug) {
                $alloc[$ug['id']] = bcadd(isset($alloc[$ug['id']]) ? $alloc[$ug['id']] : '0', $ug['coin'], 2);
            }
            $totalYuan = bcadd($totalYuan, $realYuan, 2);
            $left = bcsub($left, $realYuan, 2);
        }
        if (empty($orderItems) || bccomp($totalYuan, '0.01', 2) < 0) {
            return true;
        }
        # 总抵扣不能超过订单金额
        if (bccomp($totalYuan, $orderAmount, 2) > 0) {
            return true;
        }

        Db::startTrans();
        try {
            # 原子预占冻结(失败时回退该 grant 的额度)
            $now = time();
            $frozen = [];
            foreach ($alloc as $grantId => $coin) {
                $affected = Db::name('ouyun_coin_pro_grant')
                    ->where('id', $grantId)
                    ->where('client_id', $clientId)
                    ->where('status', 'normal')
                    ->where('effective_time', '<=', $now)
                    ->where(function ($q) use ($now) {
                        $q->where('expire_time', 0)->whereOr('expire_time', '>', $now);
                    })
                    ->whereRaw('remaining - frozen >= ' . floatval($coin))
                    ->update(['frozen' => Db::raw('frozen + ' . floatval($coin)), 'update_time' => $now]);
                if ($affected > 0) {
                    $frozen[$grantId] = $coin;
                } else {
                    # 竞争失败:检查是否刚好冻结相同额度(并发同订单重放)
                    $g = Db::name('ouyun_coin_pro_grant')->where('id', $grantId)->find();
                    if (!empty($g) && Db::name('ouyun_coin_pro_consume')->where('grant_id', $grantId)->where('order_id', $orderId)->where('status', 'pending')->count() > 0) {
                        $frozen[$grantId] = $coin; # 该订单已预占过(幂等)
                    }
                }
            }
            $frozenCoin = '0.00';
            foreach ($frozen as $c) {
                $frozenCoin = bcadd($frozenCoin, $c, 2);
            }
            if (bccomp($frozenCoin, '0.01', 2) < 0) {
                Db::rollback();
                return true; # 预占全部失败,放弃抵扣(订单保持原价)
            }
            # 预占额度不足时的裁剪:按冻结结果收缩抵扣额
            $planCoin = $this->yuan2coin($totalYuan);
            if (bccomp($frozenCoin, $planCoin, 2) < 0) {
                $totalYuan = $this->coin2yuan($frozenCoin);
                $keep = $frozenCoin;
                foreach ($orderItems as $i => $oi) {
                    $coin = $this->yuan2coin(bcsub('0', $oi['amount'], 2));
                    if (bccomp($keep, $coin, 2) >= 0) {
                        $keep = bcsub($keep, $coin, 2);
                        continue;
                    }
                    if (bccomp($keep, '0.01', 2) >= 0) {
                        $y = $this->coin2yuan($keep);
                        $orderItems[$i]['amount'] = bcsub('0', $y, 2);
                        $orderItems[$i]['description'] = $this->coinName() . '抵扣' . $y . '元(' . $keep . $this->coinName() . ')';
                        $keep = '0.00';
                    } else {
                        unset($orderItems[$i]);
                    }
                }
                $orderItems = array_values($orderItems);
                # 收缩冻结记录
                $over = bcsub($planCoin, $frozenCoin, 2);
                foreach ($frozen as $grantId => $c) {
                    if (bccomp($over, '0', 2) <= 0) {
                        break;
                    }
                    $back = bccomp($c, $over, 2) > 0 ? $over : $c;
                    Db::name('ouyun_coin_pro_grant')->where('id', $grantId)->update([
                        'frozen' => Db::raw('GREATEST(frozen - ' . floatval($back) . ', 0)'), 'update_time' => $now,
                    ]);
                    $frozen[$grantId] = bcsub($c, $back, 2);
                    $over = bcsub($over, $back, 2);
                }
                if (empty($orderItems) || bccomp($totalYuan, '0.01', 2) < 0) {
                    Db::rollback();
                    return true;
                }
            }

            # 消费流水(pending)
            $consumes = [];
            foreach ($orderItems as $oi) {
                $coin = $this->yuan2coin(bcsub('0', $oi['amount'], 2));
                $cl = $coin;
                foreach ($frozen as $grantId => $cap) {
                    if (bccomp($cl, '0', 2) <= 0) {
                        break;
                    }
                    $pool = isset($consumes[$grantId]) ? bcsub($cap, $consumes[$grantId]['coin'], 2) : $cap;
                    if (bccomp($pool, '0', 2) <= 0) {
                        continue;
                    }
                    $t = bccomp($pool, $cl, 2) > 0 ? $cl : $pool;
                    if (!isset($consumes[$grantId])) {
                        $consumes[$grantId] = ['coin' => '0.00', 'yuan' => '0.00'];
                    }
                    $consumes[$grantId]['coin'] = bcadd($consumes[$grantId]['coin'], $t, 2);
                    $consumes[$grantId]['yuan'] = bcadd($consumes[$grantId]['yuan'], $this->coin2yuan($t), 2);
                    $consumes[$grantId]['host_id'] = $oi['host_id'];
                    $consumes[$grantId]['product_id'] = $oi['product_id'];
                    $cl = bcsub($cl, $t, 2);
                }
            }
            $rows = [];
            foreach ($consumes as $grantId => $c) {
                if (bccomp($c['coin'], '0.00', 2) <= 0) {
                    continue;
                }
                $rows[] = [
                    'grant_id' => $grantId, 'client_id' => $clientId, 'order_id' => $orderId,
                    'host_id' => intval($c['host_id'] ?? 0), 'product_id' => intval($c['product_id'] ?? 0),
                    'amount_coin' => $c['coin'], 'amount' => $c['yuan'], 'status' => 'pending',
                    'create_time' => $now, 'update_time' => $now,
                ];
            }
            if (!empty($rows)) {
                Db::name('ouyun_coin_pro_consume')->insertAll($rows);
            }

            # 写 order_item 折扣行
            Db::name('order_item')->insertAll($orderItems);

            # 减订单金额
            $newAmount = bcsub($orderAmount, $totalYuan, 2);
            $update = ['amount' => $newAmount, 'amount_unpaid' => $newAmount, 'update_time' => $now];
            if (bccomp($newAmount, '0', 2) <= 0) {
                # 抵扣至0元:照官方优惠码做法直接置已支付(外部 processPaidOrder 会完成开通流程)
                $update['status'] = 'Paid';
                $update['pay_time'] = $now;
            }
            Db::name('order')->where('id', $orderId)->where('status', 'Unpaid')->update($update);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return true;
        }
        return true;
    }

    /* ============================ 支付确认/释放 ============================ */

    /** order_paid: 确认扣减 + 活动触发 */
    public function hookOrderPaid($param)
    {
        $orderId = intval($param['id'] ?? 0);
        if ($orderId <= 0) {
            return true;
        }
        $this->confirmOrder($orderId);
        try {
            (new ActivityModel())->onOrderPaid($orderId);
        } catch (\Throwable $e) {
        }
        return true;
    }

    /** 确认: pending -> confirmed, remaining-=X frozen-=X (原子,天然幂等) */
    public function confirmOrder(int $orderId): bool
    {
        $pendings = Db::name('ouyun_coin_pro_consume')->where('order_id', $orderId)->where('status', 'pending')->select()->toArray();
        if (empty($pendings)) {
            return true;
        }
        $now = time();
        $totalCoin = '0.00';
        $totalYuan = '0.00';
        $clientId = 0;
        Db::startTrans();
        try {
            foreach ($pendings as $c) {
                # 原子扣减: frozen 条件保证重复确认时影响行数为0
                $affected = Db::name('ouyun_coin_pro_grant')
                    ->where('id', $c['grant_id'])
                    ->whereRaw('frozen >= ' . floatval($c['amount_coin']))
                    ->whereRaw('remaining >= ' . floatval($c['amount_coin']))
                    ->update([
                        'remaining' => Db::raw('remaining - ' . floatval($c['amount_coin'])),
                        'frozen' => Db::raw('frozen - ' . floatval($c['amount_coin'])),
                        'update_time' => $now,
                    ]);
                if ($affected > 0) {
                    Db::name('ouyun_coin_pro_consume')->where('id', $c['id'])->update(['status' => 'confirmed', 'update_time' => $now]);
                    $totalCoin = bcadd($totalCoin, $c['amount_coin'], 2);
                    $totalYuan = bcadd($totalYuan, $c['amount'], 2);
                    $clientId = intval($c['client_id']);
                } else {
                    # 已确认过(重放)或数据异常:核对已确认流水
                    $ok = Db::name('ouyun_coin_pro_consume')->where('id', $c['id'])->where('status', 'confirmed')->count();
                    if (empty($ok)) {
                        Db::name('ouyun_coin_pro_consume')->where('id', $c['id'])->update(['status' => 'released', 'update_time' => $now]);
                    }
                }
            }
            if (bccomp($totalCoin, '0.01', 2) >= 0 && $clientId > 0) {
                $this->addLog($clientId, 'consume', bcsub('0', $totalCoin, 2), $orderId, 'order', '订单#' . $orderId . '消费' . $totalCoin . $this->coinName());
                Db::commit();
                $this->notice('oycoin_consume', $clientId, [
                    'order_id' => $orderId, 'amount' => $totalCoin, 'yuan' => $totalYuan, 'coin_name' => $this->coinName(),
                ]);
            } else {
                Db::commit();
            }
        } catch (\Throwable $e) {
            Db::rollback();
        }
        return true;
    }

    /** 释放预占: pending -> released, frozen-=X (取消/删除/回收) */
    public function releaseOrder(int $orderId)
    {
        if ($orderId <= 0) {
            return ['status' => 200];
        }
        $pendings = Db::name('ouyun_coin_pro_consume')->where('order_id', $orderId)->where('status', 'pending')->select()->toArray();
        if (empty($pendings)) {
            return ['status' => 200];
        }
        $now = time();
        Db::startTrans();
        try {
            foreach ($pendings as $c) {
                Db::name('ouyun_coin_pro_grant')->where('id', $c['grant_id'])->update([
                    'frozen' => Db::raw('GREATEST(frozen - ' . floatval($c['amount_coin']) . ', 0)'),
                    'update_time' => $now,
                ]);
                Db::name('ouyun_coin_pro_consume')->where('id', $c['id'])->update(['status' => 'released', 'update_time' => $now]);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
        }
        return ['status' => 200];
    }

    /* ============================ 注册/cron ============================ */

    public function hookAfterRegister($param)
    {
        try {
            (new ActivityModel())->onRegister(intval($param['id'] ?? 0));
        } catch (\Throwable $e) {
        }
        return true;
    }

    public function hookDailyCron()
    {
        try {
            $this->expireScan();
            $this->expireSoonNotice();
            (new ActivityModel())->onDailyCron();
        } catch (\Throwable $e) {
        }
        return true;
    }

    /** 过期扫描: normal 且 expire_time<=now -> expired, 写 expire 流水 */
    public function expireScan(): bool
    {
        $now = time();
        $rows = Db::name('ouyun_coin_pro_grant')
            ->where('status', 'normal')
            ->where('expire_time', '>', 0)
            ->where('expire_time', '<=', $now)
            ->where('remaining', '>', 0)
            ->limit(500)
            ->select()->toArray();
        foreach ($rows as $r) {
            Db::name('ouyun_coin_pro_grant')->where('id', $r['id'])->where('status', 'normal')->update([
                'status' => 'expired', 'update_time' => $now,
            ]);
            $this->addLog(intval($r['client_id']), 'expire', bcsub('0', $r['remaining'], 2), intval($r['id']), 'grant', '过期失效(' . $r['remaining'] . $this->coinName() . ')');
        }
        return true;
    }

    /** 即将过期提醒(前一天,每天一次) */
    public function expireSoonNotice(): bool
    {
        $now = time();
        $dayStart = strtotime(date('Y-m-d'));
        $rows = Db::name('ouyun_coin_pro_grant')
            ->where('status', 'normal')
            ->where('expire_time', '>', $now)
            ->where('expire_time', '<=', $now + 86400)
            ->whereRaw('remaining > frozen')
            ->limit(500)
            ->select()->toArray();
        foreach ($rows as $r) {
            $free = bcsub($r['remaining'], $r['frozen'], 2);
            if (bccomp($free, '0.01', 2) < 0) {
                continue;
            }
            $sent = Db::name('ouyun_coin_pro_log')
                ->where('client_id', $r['client_id'])->where('type', 'expire_soon')
                ->where('rel_id', $r['id'])->where('rel_type', 'grant')
                ->where('create_time', '>=', $dayStart)->count();
            if ($sent > 0) {
                continue;
            }
            $this->addLog(intval($r['client_id']), 'expire_soon', $free, intval($r['id']), 'grant', '即将过期提醒');
            $this->notice('oycoin_expire_soon', intval($r['client_id']), [
                'amount' => $free, 'expire_time' => $r['expire_time'], 'coin_name' => $this->coinName(),
            ]);
        }
        return true;
    }

    /* ============================ 退款 ============================ */

    /** after_refund: 返回该产品平台币已抵扣总额(负数元),从可退基数中扣除 */
    public function hookAfterRefund($param)
    {
        $hostId = intval($param['host_id'] ?? 0);
        if ($hostId <= 0) {
            return 0;
        }
        $sum = Db::name('ouyun_coin_pro_consume')->alias('c')
            ->leftJoin('order o', 'o.id=c.order_id')
            ->where('c.host_id', $hostId)
            ->where('c.status', 'confirmed')
            ->whereIn('o.status', ['Paid', 'Refunded'])
            ->sum('c.amount');
        return 0 - floatval($sum ?: 0); # consume.amount 为正数(抵扣额),返回负数
    }

    /** after_host_refund: 按退款比例退还消耗的平台币 */
    public function hookAfterHostRefund($param)
    {
        $hostId = intval($param['host_id'] ?? 0);
        $refundAmount = strval($param['amount'] ?? 0); # 本次实际退款金额(元)
        if ($hostId <= 0 || bccomp($refundAmount, '0', 2) <= 0) {
            return true;
        }
        if ($this->getKV('refund_policy', 'ratio') != 'ratio') {
            return true; # 配置为不退还
        }
        # 该产品的平台币消费(确认)
        $consumes = Db::name('ouyun_coin_pro_consume')->alias('c')
            ->field('c.*,o.status order_status')
            ->leftJoin('order o', 'o.id=c.order_id')
            ->where('c.host_id', $hostId)
            ->where('c.status', 'confirmed')
            ->whereIn('o.status', ['Paid', 'Refunded'])
            ->select()->toArray();
        if (empty($consumes)) {
            return true;
        }
        # 已退过部分:按剩余未退的币计算(总消耗-已退)
        $totalCoin = '0.00';
        foreach ($consumes as $c) {
            $totalCoin = bcadd($totalCoin, $c['amount_coin'], 2);
        }
        $refunded = Db::name('ouyun_coin_pro_log')
            ->where('type', 'refund')->where('rel_type', 'refund_host')->where('rel_id', $hostId)
            ->sum('amount');
        $leftCoin = bcsub($totalCoin, strval($refunded ?: 0), 2);
        if (bccomp($leftCoin, '0.01', 2) < 0) {
            return true;
        }
        # 退款比例 = 本次退款金额 / 该产品实付基数(host子项金额+平台币抵扣(负)+优惠码折扣(负))
        $hostItem = Db::name('order_item')->where('host_id', $hostId)->where('type', 'host')->find();
        $paid = $hostItem ? strval($hostItem['amount']) : '0';
        foreach ($consumes as $c) {
            $paid = bcsub($paid, $c['amount'], 2);
        }
        # 优惠码折扣部分
        $promoSum = Db::name('order_item')->where('host_id', $hostId)->where('type', 'addon_promo_code')->sum('amount');
        $paid = bcadd($paid, strval($promoSum ?: 0), 2); # addon_promo_code amount 为负
        if (bccomp($paid, '0.01', 2) < 0) {
            $paid = '0.01';
        }
        $ratio = bcdiv($refundAmount, $paid, 4);
        if (bccomp($ratio, '1', 4) > 0) {
            $ratio = '1';
        }
        $backCoin = bcmul($leftCoin, $ratio, 2);
        if (bccomp($backCoin, '0.01', 2) < 0) {
            return true;
        }
        # 退还:新发放记录(继承原消费最近一条的有效期剩余)
        $lastConsume = $consumes[count($consumes) - 1];
        $grant = Db::name('ouyun_coin_pro_grant')->where('id', $lastConsume['grant_id'])->find();
        $expireTime = 0;
        if (!empty($grant) && $grant['expire_time'] > time()) {
            $expireTime = intval($grant['expire_time']);
        }
        $clientId = $this->accountOf(intval($lastConsume['client_id']));
        $res = $this->grantCoins($clientId, intval($grant['activity_id'] ?? 0), $backCoin, 'system',
            '退款退回(产品#' . $hostId . ',退款' . $refundAmount . '元)', [
                'expire_time' => $expireTime,
            ]);
        if ($res['status'] == 200) {
            $this->addLog($clientId, 'refund', $backCoin, $hostId, 'refund_host', '退款退还' . $backCoin . $this->coinName());
        }
        return true;
    }

    /* ============================ 待办/后台联动 ============================ */

    /** 待办事项pro 条目 */
    public function todoItems(): array
    {
        $items = [];
        try {
            $langSet = \think\facade\Lang::getLangSet();
            $isEn = $langSet == 'en-us';
            # 全站平台币总余额(与报表「账户剩余」同口径:normal记录remaining合计;整数显示)
            $total = Db::name('ouyun_coin_pro_grant')->where('status', 'normal')->sum('remaining');
            $items[] = [
                'key' => 'oycoin_total_balance',
                'label' => $isEn ? 'Total coins' : '总平台币',
                'count' => number_format(floor((float)$total)),
                'url' => 'plugin/ouyun_coin_pro/client.htm', 'icon' => 4,
            ];
            # 可领取的标准送活动数
            $now = time();
            $claimable = Db::name('ouyun_coin_pro_activity')
                ->where('type', 'standard')->where('status', 1)
                ->where('start_time', '<=', $now)
                ->where(function ($q) use ($now) {
                    $q->where('end_time', 0)->whereOr('end_time', '>', $now);
                })
                ->count();
            if ($claimable > 0) {
                $items[] = [
                    'key' => 'oycoin_claimable', 'label' => $isEn ? 'Coins activities claimable' : '平台币活动进行中',
                    'count' => $claimable, 'url' => 'plugin/ouyun_coin_pro/index.htm', 'icon' => 6,
                ];
            }
            # 7天内即将过期的发放记录数
            $expiring = Db::name('ouyun_coin_pro_grant')
                ->where('status', 'normal')->where('expire_time', '>', $now)
                ->where('expire_time', '<=', $now + 7 * 86400)
                ->whereRaw('remaining > frozen')
                ->count();
            if ($expiring > 0) {
                $items[] = [
                    'key' => 'oycoin_expiring', 'label' => $isEn ? 'Coins expiring in 7 days' : '即将过期平台币',
                    'count' => $expiring, 'url' => 'plugin/ouyun_coin_pro/grant.htm', 'icon' => 10,
                ];
            }
        } catch (\Throwable $e) {
            return [];
        }
        return $items;
    }

    /** 后台用户详情追加平台币信息 */
    public function hookAdminClientIndex($param)
    {
        $clientId = intval($param['id'] ?? 0);
        if ($clientId <= 0) {
            return ['status' => 400];
        }
        $clientId = $this->accountOf($clientId);
        $bal = $this->balanceOf($clientId);
        return ['status' => 200, 'data' => [
            'ouyun_coin_pro' => $this->coinName() . '可用余额: ' . $bal['available'] . ($bal['frozen'] > 0 ? '(预占' . $bal['frozen'] . ')' : ''),
        ]];
    }

    /* ============================ 签到 ============================ */

    /** 每日签到: 返回发放结果 */
    public function signin(int $clientId): array
    {
        $ActivityModel = new ActivityModel();
        $activity = $ActivityModel->activeActivity('signin');
        if (empty($activity)) {
            return ['status' => 400, 'msg' => '签到活动未开启'];
        }
        $clientId = $this->accountOf($clientId);
        if ($clientId <= 0) {
            return ['status' => 400, 'msg' => lang_plugins('login_first')];
        }
        $today = intval(date('Ymd'));
        $row = Db::name('ouyun_coin_pro_signin')->where('client_id', $clientId)->find();
        if (!empty($row) && intval($row['last_date']) == $today) {
            return ['status' => 400, 'msg' => '今日已签到'];
        }
        $yesterday = intval(date('Ymd', strtotime('-1 day')));
        $streak = (!empty($row) && intval($row['last_date']) == $yesterday) ? intval($row['streak_days']) + 1 : 1;
        $rules = $activity['rules'] ? json_decode($activity['rules'], true) : [];
        $base = isset($rules['base']) ? floatval($rules['base']) : 0;
        $inc = isset($rules['daily_inc']) ? floatval($rules['daily_inc']) : 0;
        $max = isset($rules['max']) && $rules['max'] > 0 ? floatval($rules['max']) : 0;
        $coin = $base + $inc * max(0, $streak - 1);
        if ($max > 0 && $coin > $max) {
            $coin = $max;
        }
        if ($coin < 0.01) {
            return ['status' => 400, 'msg' => '签到奖励未配置'];
        }
        $res = $this->grantCoins($clientId, intval($activity['id']), strval($coin), 'claim',
            '每日签到(连续' . $streak . '天)', []);
        if ($res['status'] != 200) {
            return ['status' => 400, 'msg' => $res['msg'] ?? '签到失败'];
        }
        $now = time();
        if (empty($row)) {
            Db::name('ouyun_coin_pro_signin')->insert([
                'client_id' => $clientId, 'last_date' => $today, 'streak_days' => $streak,
                'total_days' => 1, 'create_time' => $now, 'update_time' => $now,
            ]);
        } else {
            Db::name('ouyun_coin_pro_signin')->where('client_id', $clientId)->update([
                'last_date' => $today,
                'streak_days' => $streak,
                'total_days' => Db::raw('total_days + 1'),
                'update_time' => $now,
            ]);
        }
        return ['status' => 200, 'msg' => '签到成功', 'data' => [
            'amount' => $res['data']['amount'], 'streak' => $streak,
        ]];
    }

    /* ============================ 通知 ============================ */

    public function notice(string $name, int $clientId, array $vars = []): void
    {
        try {
            $coinName = $this->coinName();
            $tpl = [
                'amount' => $coinName,
                'coin_name' => $coinName,
                'order_id' => '订单', 'yuan' => '金额', 'expire_time' => '过期时间', 'remark' => '备注',
            ];
            foreach ($vars as $k => $v) {
                if ($k == 'expire_time' && is_numeric($v) && $v > 0) {
                    $v = date('Y-m-d H:i', intval($v));
                }
                $tpl[$k] = strval($v);
            }
            $descMap = [
                'oycoin_grant'       => '平台币发放到账',
                'oycoin_expire_soon' => '平台币即将过期',
                'oycoin_consume'     => '平台币消费成功',
            ];
            system_notice([
                'name'              => $name,
                'email_description' => '#client#' . $clientId . '#' . ($descMap[$name] ?? '平台币通知'),
                'sms_description'   => '#client#' . $clientId . '#' . ($descMap[$name] ?? '平台币通知'),
                'task_data'         => array_merge(['client_id' => $clientId], ['template_param' => $tpl]),
            ]);
        } catch (\Throwable $e) {
        }
    }
}
