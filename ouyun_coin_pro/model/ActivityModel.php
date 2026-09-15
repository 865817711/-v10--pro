<?php
namespace addon\ouyun_coin_pro\model;

use think\facade\Db;
use think\Model;

/**
 * @title 平台币pro - 活动管理
 * @desc 8种活动类型 CRUD + 触发逻辑(充值送/属性送/累计消费送/单笔消费送/订购送/签到)
 * @use addon\ouyun_coin_pro\model\ActivityModel
 */
class ActivityModel extends Model
{
    protected $name = 'ouyun_coin_pro_activity';
    protected $pk = 'id';

    /** 活动类型 */
    const TYPES = [
        'standard'        => '标准送',
        'recharge'        => '充值送',
        'attr'            => '用户属性送',
        'consume_total'   => '累计消费送',
        'consume_single'  => '单笔消费送',
        'order_buy'       => '订购送',
        'target'          => '定向送',
        'signin'          => '每日签到送',
    ];

    /** 计费周期 */
    const CYCLES = [
        'monthly' => '月付', 'quarterly' => '季付', 'semiannually' => '半年付',
        'annually' => '年付', 'biennially' => '两年付', 'triennially' => '三年付',
        'onetime' => '一次性', 'free' => '免费', 'on_demand' => '按需',
    ];

    /* ============================ 通用 ============================ */

    /** 当前有效的活动(某类型;叠加关闭时只取最新创建的一期) */
    public function activeActivity(string $type, int $excludeId = 0): array
    {
        $now = time();
        $q = Db::name('ouyun_coin_pro_activity')
            ->where('type', $type)->where('status', 1)
            ->where('start_time', '<=', $now)
            ->where(function ($qr) use ($now) {
                $qr->where('end_time', 0)->whereOr('end_time', '>', $now);
            });
        if ($excludeId > 0) {
            $q->where('id', '<>', $excludeId);
        }
        $list = $q->order('create_time DESC, id DESC')->select()->toArray();
        if (empty($list)) {
            return [];
        }
        $Model = new OuyunCoinProModel();
        if ($Model->getKV('activity_overlay', '0') != '1') {
            return $list[0]; # 不叠加:只取最新创建
        }
        return $list;
    }

    /** 活动是否在有效时间窗内 */
    public function inWindow(array $activity): bool
    {
        $now = time();
        return $activity['status'] == 1
            && $activity['start_time'] <= $now
            && ($activity['end_time'] == 0 || $activity['end_time'] > $now);
    }

    /** 梯度/比例计算:返回赠送额(币);rules: gradient=[{full,give}] ratio={percent} 或固定 give */
    public function calcGive(array $activity, $baseAmount): string
    {
        $rules = $activity['rules'] ? json_decode($activity['rules'], true) : [];
        $rate = (new OuyunCoinProModel())->rate();
        if ($activity['grant_type'] == 'ratio') {
            $percent = isset($rules['percent']) ? floatval($rules['percent']) : 0;
            return bcmul(bcmul(strval($baseAmount), strval($percent), 4), $rate, 2);
        }
        # gradient
        if (!empty($rules['gradient']) && is_array($rules['gradient'])) {
            $give = '0.00';
            $bestFull = -1;
            foreach ($rules['gradient'] as $g) {
                $full = floatval($g['full'] ?? 0);
                $gv = floatval($g['give'] ?? 0);
                if ($baseAmount >= $full && $full >= $bestFull) {
                    $bestFull = $full;
                    $give = bcmul(strval($gv), $rate, 2); # 梯度give直接按币存(后台录入单位=币)
                }
            }
            return $give;
        }
        # 固定额(属性送/签到外的兜底)
        if (isset($rules['give'])) {
            return bcmul(strval($rules['give']), $rate, 2);
        }
        return '0.00';
    }

    /** 商品是否在活动适用范围 */
    public function productMatch(array $activity, int $productId): bool
    {
        if ($activity['product_scope'] != 1) {
            return true;
        }
        $pids = $activity['product_ids'] ? json_decode($activity['product_ids'], true) : [];
        return empty($pids) || in_array($productId, $pids);
    }

    /* ============================ 触发: 注册 ============================ */

    public function onRegister(int $clientId): bool
    {
        if ($clientId <= 0) {
            return true;
        }
        $Model = new OuyunCoinProModel();
        $clientId = $Model->accountOf($clientId);
        $list = $this->activeActivity('attr');
        foreach (is_array($list) && isset($list[0]) ? $list : [$list] as $act) {
            if (empty($act) || !is_array($act)) {
                continue;
            }
            $rules = $act['rules'] ? json_decode($act['rules'], true) : [];
            $targets = isset($rules['targets']) && is_array($rules['targets']) ? $rules['targets'] : [];
            if (!empty($targets) && !in_array('new_register', $targets)) {
                continue;
            }
            $give = isset($rules['give']) ? $rules['give'] : 0;
            if (floatval($give) <= 0) {
                continue;
            }
            $Model->grantCoins($clientId, intval($act['id']), strval($give), 'system',
                '新注册赠送(' . $act['name'] . ')', ['from_key' => 'reg:' . intval($act['id']) . ':' . $clientId]);
        }
        return true;
    }

    /* ============================ 触发: 支付完成 ============================ */

    public function onOrderPaid(int $orderId): bool
    {
        $order = Db::name('order')->where('id', $orderId)->find();
        if (empty($order) || !in_array($order['status'], ['Paid', 'Refunded'])) {
            return true;
        }
        $items = Db::name('order_item')->where('order_id', $orderId)->select()->toArray();
        if (empty($items)) {
            return true;
        }
        $isRecharge = isset($items[0]['type']) && $items[0]['type'] == 'recharge';
        $Model = new OuyunCoinProModel();
        $clientId = $Model->accountOf(intval($order['client_id']));

        if ($isRecharge) {
            $this->onRecharge($Model, $clientId, $orderId, strval($items[0]['amount']));
            return true;
        }
        if (in_array($order['type'], ['artificial'])) {
            # 人工订单不计消费送/订购送(管理员手工单)
            return true;
        }
        # 有效消费额 = 订单实付 - 余额支付部分 - 平台币抵扣部分
        $coinDeduct = Db::name('ouyun_coin_pro_consume')->where('order_id', $orderId)
            ->where('status', 'confirmed')->sum('amount');
        $validAmount = bcsub(strval($order['amount']), strval($order['credit'] ?: 0), 2);
        $validAmount = bcsub($validAmount, strval($coinDeduct ?: 0), 2);

        $this->onConsumeSingle($Model, $clientId, $orderId, $validAmount);
        $this->onConsumeTotal($Model, $clientId, $orderId, $validAmount);
        $this->onOrderBuy($Model, $clientId, $orderId, $order, $items, $validAmount);
        return true;
    }

    /** 充值送 */
    protected function onRecharge(OuyunCoinProModel $Model, int $clientId, int $orderId, string $amount): void
    {
        if (bccomp($amount, '0.01', 2) < 0) {
            return;
        }
        $act = $this->activeActivity('recharge');
        if (empty($act) || !$this->inWindow($act)) {
            return;
        }
        $give = $this->calcGive($act, $amount);
        # 单笔充值赠送上限(全局配置,单位币)
        $cap = floatval($Model->getKV('recharge_grant_cap', '0'));
        if ($cap > 0 && bccomp($give, strval($cap), 2) > 0) {
            $give = strval($cap);
        }
        if (bccomp($give, '0.01', 2) < 0) {
            return;
        }
        $Model->grantCoins($clientId, intval($act['id']), $give, 'system',
            '充值赠送(订单#' . $orderId . ',充值' . $amount . '元)', ['from_key' => 'order:' . intval($act['id']) . ':' . $orderId]);
    }

    /** 单笔消费送 */
    protected function onConsumeSingle(OuyunCoinProModel $Model, int $clientId, int $orderId, string $validAmount): void
    {
        if (bccomp($validAmount, '0.01', 2) < 0) {
            return;
        }
        $act = $this->activeActivity('consume_single');
        if (empty($act) || !$this->inWindow($act)) {
            return;
        }
        $rules = $act['rules'] ? json_decode($act['rules'], true) : [];
        $min = isset($rules['min']) ? floatval($rules['min']) : 0;
        if ($validAmount < $min) {
            return;
        }
        $give = $this->calcGive($act, $validAmount);
        if (bccomp($give, '0.01', 2) < 0) {
            return;
        }
        $Model->grantCoins($clientId, intval($act['id']), $give, 'system',
            '单笔消费赠送(订单#' . $orderId . ',消费' . $validAmount . '元)', ['from_key' => 'order:' . intval($act['id']) . ':' . $orderId]);
    }

    /** 累计消费送: 有效累计消费额(已支付订单,不含余额支付/平台币抵扣) 达到梯度档发放,每档一次 */
    protected function onConsumeTotal(OuyunCoinProModel $Model, int $clientId, int $orderId, string $validAmount): void
    {
        $act = $this->activeActivity('consume_total');
        if (empty($act) || !$this->inWindow($act)) {
            return;
        }
        $rules = $act['rules'] ? json_decode($act['rules'], true) : [];
        $tiers = isset($rules['tiers']) && is_array($rules['tiers']) ? $rules['tiers'] : [];
        if (empty($tiers)) {
            return;
        }
        usort($tiers, function ($a, $b) {
            return floatval($a['full']) - floatval($b['full']);
        });
        # 累计有效消费额(实时统计;按账户本人订单)
        $orders = Db::name('order')
            ->whereIn('status', ['Paid', 'Refunded'])
            ->whereNotIn('type', ['recharge', 'artificial'])
            ->where('client_id', $clientId)
            ->column('id,amount,credit');
        $total = '0.00';
        $orderIds = [];
        foreach ($orders as $o) {
            $orderIds[] = $o['id'];
        }
        $coinMap = [];
        if (!empty($orderIds)) {
            $consumes = Db::name('ouyun_coin_pro_consume')->whereIn('order_id', $orderIds)
                ->where('status', 'confirmed')->field('order_id,SUM(amount) AS s')->group('order_id')->select()->toArray();
            foreach ($consumes as $c) {
                $coinMap[$c['order_id']] = $c['s'];
            }
        }
        foreach ($orders as $o) {
            $v = bcsub(strval($o['amount']), strval($o['credit'] ?: 0), 2);
            $v = bcsub($v, strval($coinMap[$o['id']] ?? 0), 2);
            if (bccomp($v, '0', 2) > 0) {
                $total = bcadd($total, $v, 2);
            }
        }
        if (bccomp($total, '0.01', 2) < 0) {
            return;
        }
        # 达标档发放(梯度give为币)
        $rate = $Model->rate();
        foreach ($tiers as $i => $t) {
            $full = floatval($t['full'] ?? 0);
            $give = bcmul(strval($t['give'] ?? 0), $rate, 2);
            if ($full <= 0 || bccomp($give, '0.01', 2) < 0 || $total < $full) {
                continue;
            }
            $Model->grantCoins($clientId, intval($act['id']), $give, 'system',
                '累计消费' . $full . '元赠送', ['from_key' => 'tier:' . intval($act['id']) . ':' . $i . ':' . $clientId]);
        }
    }

    /** 订购送: 购买指定商品赠送(可限新购/续费/升降级场景) */
    protected function onOrderBuy(OuyunCoinProModel $Model, int $clientId, int $orderId, array $order, array $items, string $validAmount): void
    {
        $list = $this->activeActivity('order_buy');
        foreach (is_array($list) && isset($list[0]) ? $list : [$list] as $act) {
            if (empty($act) || !is_array($act) || !$this->inWindow($act)) {
                continue;
            }
            $rules = $act['rules'] ? json_decode($act['rules'], true) : [];
            $scenes = isset($rules['buy_scenes']) && is_array($rules['buy_scenes']) ? $rules['buy_scenes'] : ['new'];
            $orderScene = $order['type'] == 'renew' ? 'renew' : ($order['type'] == 'upgrade' ? 'upgrade' : 'new');
            if (!in_array($orderScene, $scenes)) {
                continue;
            }
            $hit = false;
            $hitAmount = '0.00';
            foreach ($items as $it) {
                if (!in_array($it['type'], ['host', 'renew', 'upgrade', 'change_billing_cycle'])) {
                    continue;
                }
                if (!$this->productMatch($act, intval($it['product_id']))) {
                    continue;
                }
                $hit = true;
                $hitAmount = bcadd($hitAmount, strval($it['amount']), 2);
            }
            if (!$hit || bccomp($hitAmount, '0.01', 2) < 0) {
                continue;
            }
            $give = $this->calcGive($act, $hitAmount);
            if (bccomp($give, '0.01', 2) < 0) {
                continue;
            }
            $Model->grantCoins($clientId, intval($act['id']), $give, 'system',
                '订购赠送(' . $act['name'] . ',订单#' . $orderId . ')', ['from_key' => 'order:' . intval($act['id']) . ':' . $orderId]);
        }
    }

    /* ============================ 触发: 每日 ============================ */

    public function onDailyCron(): bool
    {
        $this->attrCycleSend();
        return true;
    }

    /** 周期性属性送: 每 cycle_days 天给符合等级目标的用户发放 */
    protected function attrCycleSend(): void
    {
        $Model = new OuyunCoinProModel();
        $list = Db::name('ouyun_coin_pro_activity')
            ->where('type', 'attr')->where('status', 1)->select()->toArray();
        foreach ($list as $act) {
            if (!$this->inWindow($act)) {
                continue;
            }
            $rules = $act['rules'] ? json_decode($act['rules'], true) : [];
            $cycleDays = intval($rules['cycle_days'] ?? 0);
            $give = floatval($rules['give'] ?? 0);
            if ($cycleDays <= 0 || $give <= 0) {
                continue; # 一次性注册送走 onRegister
            }
            $targets = isset($rules['targets']) && is_array($rules['targets']) ? $rules['targets'] : [];
            $levelIds = [];
            foreach ($targets as $t) {
                if ($t !== 'new_register' && intval($t) > 0) {
                    $levelIds[] = intval($t);
                }
            }
            if (empty($levelIds)) {
                continue;
            }
            $clientIds = $this->clientIdsByLevels($levelIds);
            foreach ($clientIds as $cid) {
                $cid = $Model->accountOf(intval($cid));
                # 该活动该用户最近一次发放时间
                $last = Db::name('ouyun_coin_pro_grant')
                    ->where('client_id', $cid)->where('activity_id', intval($act['id']))
                    ->order('id DESC')->value('create_time');
                if ($last && time() - intval($last) < $cycleDays * 86400) {
                    continue;
                }
                $Model->grantCoins($cid, intval($act['id']), strval($give), 'system',
                    '周期性发放(' . $act['name'] . ')', []);
            }
        }
    }

    /** 用户等级 -> 用户ID列表(通过 get_client_level_list 钩子反查,分批) */
    public function clientIdsByLevels(array $levelIds): array
    {
        if (empty($levelIds)) {
            return [];
        }
        $result = [];
        $page = 1;
        $limit = 500;
        while ($page < 200) { # 上限10万用户
            $ids = Db::name('client')->where('status', 1)->page($page, $limit)->column('id');
            if (empty($ids)) {
                break;
            }
            $map = hook_one('get_client_level_list', ['client_id' => $ids]);
            if (is_array($map)) {
                foreach ($map as $cid => $info) {
                    if (is_array($info) && in_array($info['id'] ?? 0, $levelIds)) {
                        $result[] = intval($cid);
                    }
                }
            }
            if (count($ids) < $limit) {
                break;
            }
            $page++;
        }
        return array_unique($result);
    }

    /** 等级列表选项(活动配置页;来自用户等级插件) */
    public function levelOptions(): array
    {
        $options = [];
        # 尝试直接实例化等级插件模型(只读)
        $class = 'addon\idcsmart_client_level\model\IdcsmartClientLevelModel';
        if (class_exists($class)) {
            try {
                $rows = (new $class())->order('id ASC')->select()->toArray();
                foreach ($rows as $r) {
                    $options[] = ['id' => intval($r['id']), 'name' => strval($r['name'] ?? ('等级' . $r['id']))];
                }
            } catch (\Throwable $e) {
            }
        }
        return $options;
    }

    /* ============================ CRUD(后台) ============================ */

    /** 活动列表 */
    public function activityList(array $param): array
    {
        $page = max(1, intval($param['page'] ?? 1));
        $limit = min(100, max(1, intval($param['limit'] ?? 20)));
        $q = Db::name('ouyun_coin_pro_activity');
        if (!empty($param['keywords'])) {
            $kw = strval($param['keywords']);
            $q->where(function ($qr) use ($kw) {
                $qr->whereLike('name', '%' . $kw . '%')->whereOr('code', $kw)->whereOr('remark', 'like', '%' . $kw . '%');
            });
        }
        if (!empty($param['type'])) {
            $q->where('type', strval($param['type']));
        }
        if (isset($param['status']) && $param['status'] !== '') {
            $q->where('status', intval($param['status']));
        }
        if (!empty($param['start_time'])) {
            $q->where('end_time', '>=', strtotime(strval($param['start_time'])));
        }
        if (!empty($param['end_time'])) {
            $q->where('start_time', '<=', strtotime(strval($param['end_time'])) + 86399);
        }
        $count = $q->count();
        $rows = $q->order('id DESC')->page($page, $limit)->select()->toArray();
        $grantStats = Db::name('ouyun_coin_pro_grant')->field('activity_id,COUNT(*) cnt,SUM(amount) total')
            ->group('activity_id')->select()->toArray();
        $statMap = [];
        foreach ($grantStats as $g) {
            $statMap[$g['activity_id']] = $g;
        }
        foreach ($rows as &$r) {
            $r['type_text'] = self::TYPES[$r['type']] ?? $r['type'];
            $r['grant_count'] = intval($statMap[$r['id']]['cnt'] ?? 0);
            $r['grant_total'] = strval($statMap[$r['id']]['total'] ?? '0.00');
            $r['rules_data'] = $r['rules'] ? json_decode($r['rules'], true) : [];
            $r['scene_data'] = $r['scene'] ? json_decode($r['scene'], true) : [];
            $r['product_ids_data'] = $r['product_ids'] ? json_decode($r['product_ids'], true) : [];
            $r['cycle_limit_data'] = $r['cycle_limit'] ? json_decode($r['cycle_limit'], true) : [];
            $now = time();
            $r['running'] = $r['status'] == 1 && $r['start_time'] <= $now && ($r['end_time'] == 0 || $r['end_time'] > $now) ? 1 : 0;
        }
        return ['status' => 200, 'msg' => '', 'data' => ['list' => $rows, 'count' => $count, 'types' => self::TYPES, 'cycles' => self::CYCLES]];
    }

    /** 活动详情 */
    public function activityDetail(int $id): array
    {
        $row = Db::name('ouyun_coin_pro_activity')->where('id', $id)->find();
        if (empty($row)) {
            return ['status' => 400, 'msg' => '活动不存在'];
        }
        $row['rules_data'] = $row['rules'] ? json_decode($row['rules'], true) : [];
        $row['scene_data'] = $row['scene'] ? json_decode($row['scene'], true) : [];
        $row['product_ids_data'] = $row['product_ids'] ? json_decode($row['product_ids'], true) : [];
        $row['cycle_limit_data'] = $row['cycle_limit'] ? json_decode($row['cycle_limit'], true) : [];
        $row['type_text'] = self::TYPES[$row['type']] ?? $row['type'];
        return ['status' => 200, 'msg' => '', 'data' => $row];
    }

    /** 新增/编辑活动 */
    public function activitySave(array $param): array
    {
        $id = intval($param['id'] ?? 0);
        $type = strval($param['type'] ?? '');
        if (!isset(self::TYPES[$type])) {
            return ['status' => 400, 'msg' => '活动类型错误'];
        }
        $name = trim(strval($param['name'] ?? ''));
        if ($name === '') {
            return ['status' => 400, 'msg' => '请填写活动名称'];
        }
        $grantType = in_array($param['grant_type'] ?? '', ['gradient', 'ratio']) ? strval($param['grant_type']) : 'gradient';
        # rules 由前端传 JSON 字符串或数组
        $rules = $param['rules'] ?? [];
        if (is_string($rules)) {
            $rules = json_decode($rules, true);
            if (!is_array($rules)) {
                $rules = [];
            }
        }
        if (in_array($type, ['recharge', 'consume_single', 'order_buy'])) {
            # 梯度/比例型:校验
            if ($grantType == 'gradient') {
                $grad = isset($rules['gradient']) && is_array($rules['gradient']) ? $rules['gradient'] : [];
                $valid = [];
                foreach ($grad as $g) {
                    if (floatval($g['full'] ?? 0) > 0 && floatval($g['give'] ?? 0) > 0) {
                        $valid[] = ['full' => floatval($g['full']), 'give' => floatval($g['give'])];
                    }
                }
                if (empty($valid)) {
                    return ['status' => 400, 'msg' => '请至少配置一档有效梯度(门槛与赠送均需大于0)'];
                }
                usort($valid, function ($a, $b) {
                    return $a['full'] - $b['full'];
                });
                $rules['gradient'] = $valid;
            } elseif (floatval($rules['percent'] ?? 0) <= 0) {
                return ['status' => 400, 'msg' => '请填写赠送比例'];
            }
        }
        if ($type == 'consume_total') {
            $tiers = isset($rules['tiers']) && is_array($rules['tiers']) ? $rules['tiers'] : [];
            $valid = [];
            foreach ($tiers as $t) {
                if (floatval($t['full'] ?? 0) > 0 && floatval($t['give'] ?? 0) > 0) {
                    $valid[] = ['full' => floatval($t['full']), 'give' => floatval($t['give'])];
                }
            }
            if (empty($valid)) {
                return ['status' => 400, 'msg' => '请至少配置一档累计消费梯度'];
            }
            usort($valid, function ($a, $b) {
                return $a['full'] - $b['full'];
            });
            $rules['tiers'] = $valid;
        }
        if ($type == 'attr') {
            $give = floatval($rules['give'] ?? 0);
            if ($give <= 0) {
                return ['status' => 400, 'msg' => '请填写赠送额度'];
            }
            $targets = isset($rules['targets']) && is_array($rules['targets']) ? $rules['targets'] : [];
            if (empty($targets)) {
                return ['status' => 400, 'msg' => '请选择赠送目标(新注册用户或用户等级)'];
            }
        }
        if ($type == 'signin') {
            if (floatval($rules['base'] ?? 0) <= 0) {
                return ['status' => 400, 'msg' => '请填写签到基础奖励'];
            }
        }
        if ($type == 'target' && intval($param['product_scope'] ?? 0) != 1 && empty($param['product_ids_data'] ?? null)) {
            $param['product_scope'] = 1; # 定向送必须限定商品
        }
        $scene = $param['scene_data'] ?? [];
        if (is_string($scene)) {
            $scene = json_decode($scene, true) ?: [];
        }
        $sceneFull = [
            'host' => empty($scene['host']) ? 0 : 1,
            'renew' => empty($scene['renew']) ? 0 : 1,
            'upgrade' => empty($scene['upgrade']) ? 0 : 1,
            'on_demand_to_recurring' => empty($scene['on_demand_to_recurring']) ? 0 : 1,
        ];
        $pids = $param['product_ids_data'] ?? [];
        if (is_string($pids)) {
            $pids = json_decode($pids, true) ?: [];
        }
        $pids = array_values(array_unique(array_map('intval', array_filter($pids, function ($v) {
            return intval($v) > 0;
        }))));
        $cycles = $param['cycle_limit_data'] ?? [];
        if (is_string($cycles)) {
            $cycles = json_decode($cycles, true) ?: [];
        }
        $cycleSwitch = intval($param['cycle_limit_switch'] ?? 0) == 1 && !empty($cycles) ? 1 : 0;

        $now = time();
        $data = [
            'name' => mb_substr($name, 0, 90),
            'type' => $type,
            'grant_type' => $grantType,
            'rules' => json_encode($rules, JSON_UNESCAPED_UNICODE),
            'scene' => json_encode($sceneFull, JSON_UNESCAPED_UNICODE),
            'product_scope' => intval($param['product_scope'] ?? 0) == 1 && !empty($pids) ? 1 : 0,
            'product_ids' => !empty($pids) ? json_encode($pids) : '',
            'cycle_limit_switch' => $cycleSwitch,
            'cycle_limit' => $cycleSwitch ? json_encode(array_values($cycles)) : '',
            'effective_days' => max(0, intval($param['effective_days'] ?? 0)),
            'valid_days' => max(0, intval($param['valid_days'] ?? 0)),
            'status' => intval($param['status'] ?? 1) == 1 ? 1 : 0,
            'start_time' => !empty($param['start_time']) ? strtotime(strval($param['start_time'])) : $now,
            'end_time' => !empty($param['end_time']) ? strtotime(strval($param['end_time'])) : 0,
            'remark' => mb_substr(strval($param['remark'] ?? ''), 0, 480),
            'update_time' => $now,
        ];
        if (empty($data['start_time'])) {
            $data['start_time'] = $now;
        }
        if ($id > 0) {
            $exist = Db::name('ouyun_coin_pro_activity')->where('id', $id)->find();
            if (empty($exist)) {
                return ['status' => 400, 'msg' => '活动不存在'];
            }
            Db::name('ouyun_coin_pro_activity')->where('id', $id)->update($data);
        } else {
            # 活动编码:12-16位随机,唯一
            $code = '';
            for ($i = 0; $i < 10; $i++) {
                $code = strtoupper(substr(md5(uniqid('oyc', true) . mt_rand()), 0, 14));
                if (!Db::name('ouyun_coin_pro_activity')->where('code', $code)->count()) {
                    break;
                }
            }
            $data['code'] = $code;
            $data['create_time'] = $now;
            $id = Db::name('ouyun_coin_pro_activity')->insertGetId($data);
        }
        return ['status' => 200, 'msg' => '保存成功', 'data' => ['id' => $id]];
    }

    /** 删除活动(有发放记录时禁止) */
    public function activityDelete(int $id): array
    {
        $used = Db::name('ouyun_coin_pro_grant')->where('activity_id', $id)->count();
        if ($used > 0) {
            return ['status' => 400, 'msg' => '该活动已有发放记录,请改用「结束」停用(保护账本数据)'];
        }
        Db::name('ouyun_coin_pro_activity')->where('id', $id)->delete();
        return ['status' => 200, 'msg' => '删除成功'];
    }

    /** 批量删除(有发放记录的跳过并逐条反馈) */
    public function activityBatchDelete(array $ids): array
    {
        if (empty($ids)) {
            return ['status' => 400, 'msg' => '请选择要删除的活动'];
        }
        $ok = 0;
        $fail = [];
        foreach ($ids as $id) {
            $id = intval($id);
            $act = Db::name('ouyun_coin_pro_activity')->where('id', $id)->find();
            if (empty($act)) {
                continue;
            }
            $r = $this->activityDelete($id);
            if ($r['status'] == 200) {
                $ok++;
            } else {
                $fail[] = '「' . $act['name'] . '」' . $r['msg'];
            }
        }
        $msg = '删除成功' . $ok . '条';
        if ($fail) {
            $msg .= ';' . count($fail) . '条跳过:' . implode(';', $fail);
        }
        return ['status' => 200, 'msg' => $msg, 'data' => ['ok' => $ok, 'fail' => $fail]];
    }

    /** 启停 */
    public function activityStatus(int $id, int $status): array
    {
        Db::name('ouyun_coin_pro_activity')->where('id', $id)->update([
            'status' => $status == 1 ? 1 : 0, 'update_time' => time(),
        ]);
        return ['status' => 200, 'msg' => $status == 1 ? '已启用' : '已结束'];
    }

    /* ============================ 发放管理(后台) ============================ */

    /** 发放记录列表 */
    public function grantList(array $param): array
    {
        $page = max(1, intval($param['page'] ?? 1));
        $limit = min(100, max(1, intval($param['limit'] ?? 20)));
        $q = Db::name('ouyun_coin_pro_grant')->alias('g')
            ->leftJoin('client c', 'c.id=g.client_id')
            ->leftJoin('ouyun_coin_pro_activity a', 'a.id=g.activity_id');
        if (!empty($param['keywords'])) {
            $kw = strval($param['keywords']);
            $q->where(function ($qr) use ($kw) {
                $qr->whereLike('c.username', '%' . $kw . '%')
                    ->whereOr('c.phone', 'like', '%' . $kw . '%')
                    ->whereOr('c.email', 'like', '%' . $kw . '%')
                    ->whereOr('g.remark', 'like', '%' . $kw . '%');
            });
        }
        if (!empty($param['activity_id'])) {
            $q->where('g.activity_id', intval($param['activity_id']));
        }
        if (!empty($param['client_id'])) {
            $q->where('g.client_id', intval($param['client_id']));
        }
        if (!empty($param['type'])) {
            $q->where('a.type', strval($param['type']));
        }
        if (!empty($param['status'])) {
            $q->where('g.status', strval($param['status']));
        }
        if (!empty($param['source'])) {
            $q->where('g.source', strval($param['source']));
        }
        $count = $q->count();
        $rows = $q->field('g.*,c.username,c.email,c.phone,a.name activity_name,a.type activity_type')
            ->order('g.id DESC')->page($page, $limit)->select()->toArray();
        foreach ($rows as &$r) {
            $r['activity_type_text'] = self::TYPES[$r['activity_type']] ?? '';
            $r['status_text'] = ['normal' => '正常', 'expired' => '已过期', 'revoked' => '已撤销'][$r['status']] ?? $r['status'];
            $r['source_text'] = ['manual' => '手动发放', 'claim' => '自主领取', 'system' => '系统发放'][$r['source']] ?? $r['source'];
        }
        return ['status' => 200, 'msg' => '', 'data' => ['list' => $rows, 'count' => $count]];
    }

    /** 手动发放(管理员) */
    public function grantManual(array $param, int $adminId): array
    {
        $Model = new OuyunCoinProModel();
        # 统一用户选择组件传数字ID; 兼容旧的账号字符串
        $clientId = 0;
        if (!empty($param['client_id'])) {
            $clientId = intval(Db::name('client')->where('id', intval($param['client_id']))->value('id'));
        }
        if ($clientId <= 0) {
            $clientId = $this->findClient(strval($param['client'] ?? ''));
        }
        if ($clientId <= 0) {
            return ['status' => 400, 'msg' => '未找到用户(支持用户ID/用户名/邮箱/手机号)'];
        }
        $clientId = $Model->accountOf($clientId);
        $amount = bcadd(strval($param['amount'] ?? 0), '0', 2);
        if (bccomp($amount, '0.01', 2) < 0) {
            return ['status' => 400, 'msg' => '发放额度需大于0'];
        }
        $activityId = intval($param['activity_id'] ?? 0);
        $validDays = intval($param['valid_days'] ?? 0);
        $res = $Model->grantCoins($clientId, $activityId, $amount, 'manual',
            mb_substr(strval($param['remark'] ?? '手动发放'), 0, 480),
            ['admin_id' => $adminId, 'expire_time' => $validDays > 0 ? time() + $validDays * 86400 : 0]);
        if ($res['status'] == 200) {
            $this->adminLog($adminId, '手动发放' . $amount . $Model->coinName() . '给用户#' . $clientId, $res['data']['grant_id']);
        }
        return $res;
    }

    /** 批量撤销 */
    public function grantRevoke(array $ids, int $adminId, string $reason = ''): array
    {
        if (empty($ids)) {
            return ['status' => 400, 'msg' => '请选择要撤销的记录'];
        }
        $now = time();
        $ok = 0;
        $fail = 0;
        $Model = new OuyunCoinProModel();
        foreach ($ids as $id) {
            $g = Db::name('ouyun_coin_pro_grant')->where('id', intval($id))->where('status', 'normal')->find();
            if (empty($g)) {
                $fail++;
                continue;
            }
            if (bccomp($g['frozen'], '0', 2) > 0) {
                $fail++; # 有预占(未支付订单在途)不可撤销
                continue;
            }
            Db::name('ouyun_coin_pro_grant')->where('id', $g['id'])->update([
                'status' => 'revoked', 'update_time' => $now,
            ]);
            $Model->addLog(intval($g['client_id']), 'revoke', bcsub('0', $g['remaining'], 2), intval($g['id']), 'grant',
                '撤销(' . ($reason ?: '管理员撤销') . '),扣除剩余' . $g['remaining'], $adminId);
            $ok++;
        }
        $msg = '撤销成功' . $ok . '条';
        if ($fail > 0) {
            $msg .= ',' . $fail . '条失败(不存在/已撤销/有订单预占中)';
        }
        if ($ok > 0) {
            $this->adminLog($adminId, '批量撤销平台币' . $ok . '条:' . $reason, 0);
        }
        return ['status' => 200, 'msg' => $msg, 'data' => ['ok' => $ok, 'fail' => $fail]];
    }

    /** 批量导入发放: [[用户名/邮箱/手机号, 额度, 备注],...] */
    public function grantImport(array $rows, int $adminId, int $activityId = 0, int $validDays = 0): array
    {
        $Model = new OuyunCoinProModel();
        $ok = 0;
        $fail = [];
        foreach ($rows as $i => $r) {
            $account = trim(strval($r[0] ?? ''));
            $amount = bcadd(trim(strval($r[1] ?? '0')), '0', 2);
            $remark = mb_substr(trim(strval($r[2] ?? '')), 0, 200);
            if ($account === '' || bccomp($amount, '0.01', 2) < 0) {
                $fail[] = '第' . ($i + 1) . '行:用户或额度无效';
                continue;
            }
            $clientId = $this->findClient($account);
            if ($clientId <= 0) {
                $fail[] = '第' . ($i + 1) . '行:未找到用户' . $account;
                continue;
            }
            $clientId = $Model->accountOf($clientId);
            $res = $Model->grantCoins($clientId, $activityId, $amount, 'manual',
                $remark ?: '批量导入发放', ['admin_id' => $adminId, 'expire_time' => $validDays > 0 ? time() + $validDays * 86400 : 0]);
            if ($res['status'] == 200) {
                $ok++;
            } else {
                $fail[] = '第' . ($i + 1) . '行:' . $account . ' ' . ($res['msg'] ?? '失败');
            }
        }
        $this->adminLog($adminId, '批量导入发放平台币,成功' . $ok . '条,失败' . count($fail) . '条', 0);
        return ['status' => 200, 'msg' => '导入完成:成功' . $ok . '条,失败' . count($fail) . '条', 'data' => ['ok' => $ok, 'fail' => $fail]];
    }

    /** 按用户名/邮箱/手机号找用户 */
    public function findClient(string $account): int
    {
        $account = trim($account);
        if ($account === '') {
            return 0;
        }
        $id = Db::name('client')->where('username', $account)->whereOr('email', $account)->whereOr('phone', $account)->value('id');
        return intval($id);
    }

    protected function adminLog(int $adminId, string $action, int $relId): void
    {
        try {
            active_log('平台币pro:' . $action, 'ouyun_coin_pro', $relId);
        } catch (\Throwable $e) {
        }
    }

    /* ============================ 用户余额(后台) ============================ */

    /**
     * 用户余额汇总列表(按账户聚合可用/冻结/笔数/即将过期)
     * 点击行弹窗查看该用户每一笔平台币明细(复用 grantList 的 client_id 筛选)
     */
    public function clientBalance(array $param): array
    {
        $page = max(1, intval($param['page'] ?? 1));
        $limit = min(100, max(1, intval($param['limit'] ?? 20)));
        $now = time();
        # 子查询:每个账户的可用余额(仅 normal+已生效+未过期,逐笔 remaining-frozen 求和)
        $sumSql = "SELECT client_id,
                SUM(GREATEST(remaining-frozen,0)) available,
                SUM(LEAST(frozen,remaining)) frozen_total,
                SUM(CASE WHEN expire_time>0 AND expire_time<= {$now}+7*86400 AND remaining>frozen THEN GREATEST(remaining-frozen,0) ELSE 0 END) expiring
            FROM idcsmart_ouyun_coin_pro_grant
            WHERE status='normal' AND effective_time<={$now} AND (expire_time=0 OR expire_time>{$now})
            GROUP BY client_id";
        $q = Db::name('client')->alias('c')
            ->leftJoin('(' . $sumSql . ') b', 'b.client_id=c.id')
            ->where(function ($qr) use ($param) {
                if (!empty($param['keywords'])) {
                    $kw = strval($param['keywords']);
                    $qr->whereLike('c.username', '%' . $kw . '%')
                        ->whereOr('c.email', 'like', '%' . $kw . '%')
                        ->whereOr('c.phone', 'like', '%' . $kw . '%');
                }
            })
            ->whereNotNull('b.client_id');
        $count = $q->count();
        $rows = $q->field('c.id,c.username,c.email,c.phone,
                IFNULL(b.available,0) available,
                IFNULL(b.frozen_total,0) frozen_total,
                IFNULL(b.expiring,0) expiring')
            ->order('available DESC, c.id DESC')
            ->page($page, $limit)
            ->select()->toArray();
        foreach ($rows as &$r) {
            $r['grant_count'] = Db::name('ouyun_coin_pro_grant')
                ->where('client_id', $r['id'])->where('status', 'normal')->count();
        }
        return ['status' => 200, 'msg' => '', 'data' => ['list' => $rows, 'count' => $count]];
    }

    /* ============================ 流水/报表 ============================ */

    /** 全量流水列表 */
    public function logList(array $param): array
    {
        $page = max(1, intval($param['page'] ?? 1));
        $limit = min(100, max(1, intval($param['limit'] ?? 20)));
        $q = Db::name('ouyun_coin_pro_log')->alias('l')
            ->leftJoin('client c', 'c.id=l.client_id');
        if (!empty($param['keywords'])) {
            $kw = strval($param['keywords']);
            $q->where(function ($qr) use ($kw) {
                $qr->whereLike('c.username', '%' . $kw . '%')->whereOr('l.remark', 'like', '%' . $kw . '%');
            });
        }
        if (!empty($param['client_id'])) {
            $q->where('l.client_id', intval($param['client_id']));
        }
        if (!empty($param['type'])) {
            $q->where('l.type', strval($param['type']));
        }
        if (!empty($param['start_time'])) {
            $q->where('l.create_time', '>=', strtotime(strval($param['start_time'])));
        }
        if (!empty($param['end_time'])) {
            $q->where('l.create_time', '<', strtotime(strval($param['end_time'])) + 86400);
        }
        $count = $q->count();
        $rows = $q->field('l.*,c.username')->order('l.id DESC')->page($page, $limit)->select()->toArray();
        foreach ($rows as &$r) {
            $r['type_text'] = [
                'grant' => '发放', 'consume' => '消费', 'refund' => '退款退还', 'expire' => '过期',
                'revoke' => '撤销', 'adjust' => '调整', 'expire_soon' => '过期提醒',
            ][$r['type']] ?? $r['type'];
        }
        return ['status' => 200, 'msg' => '', 'data' => ['list' => $rows, 'count' => $count]];
    }

    /** 报表: 按日统计发放/消费/过期 */
    public function report(array $param): array
    {
        $start = !empty($param['start_time']) ? strtotime(strval($param['start_time'])) : strtotime('-29 day');
        $end = (!empty($param['end_time']) ? strtotime(strval($param['end_time'])) : time()) + 86399;
        $days = [];
        $cur = $start;
        while ($cur <= $end) {
            $days[date('Y-m-d', $cur)] = ['date' => date('Y-m-d', $cur), 'grant' => 0, 'grant_cnt' => 0, 'consume' => 0, 'consume_cnt' => 0, 'expire' => 0, 'expire_cnt' => 0, 'refund' => 0];
            $cur += 86400;
        }
        $rows = Db::name('ouyun_coin_pro_log')
            ->where('create_time', '>=', $start)->where('create_time', '<=', $end)
            ->whereIn('type', ['grant', 'consume', 'expire', 'refund'])
            ->field('FROM_UNIXTIME(create_time, \'%Y-%m-%d\') d,type,COUNT(*) cnt,SUM(amount) s')
            ->group('d,type')->select()->toArray();
        foreach ($rows as $r) {
            if (!isset($days[$r['d']])) {
                continue;
            }
            $amt = abs(floatval($r['s'] ?? 0));
            if ($r['type'] == 'grant') {
                $days[$r['d']]['grant'] = $amt;
                $days[$r['d']]['grant_cnt'] = intval($r['cnt']);
            } elseif ($r['type'] == 'consume') {
                $days[$r['d']]['consume'] = $amt;
                $days[$r['d']]['consume_cnt'] = intval($r['cnt']);
            } elseif ($r['type'] == 'expire') {
                $days[$r['d']]['expire'] = $amt;
                $days[$r['d']]['expire_cnt'] = intval($r['cnt']);
            } elseif ($r['type'] == 'refund') {
                $days[$r['d']]['refund'] = $amt;
            }
        }
        # 总览
        $Model = new OuyunCoinProModel();
        $summary = [
            'total_grant' => Db::name('ouyun_coin_pro_grant')->sum('amount'),
            'total_remaining' => Db::name('ouyun_coin_pro_grant')->where('status', 'normal')->sum('remaining'),
            'total_consumed' => Db::name('ouyun_coin_pro_consume')->where('status', 'confirmed')->sum('amount_coin'),
            'total_expired' => Db::name('ouyun_coin_pro_log')->where('type', 'expire')->sum('amount'),
            'coin_name' => $Model->coinName(),
        ];
        return ['status' => 200, 'msg' => '', 'data' => [
            'days' => array_values($days), 'summary' => $summary,
        ]];
    }
}
