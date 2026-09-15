<?php
namespace addon\ouyun_coin_pro\controller\clientarea;

use addon\ouyun_coin_pro\model\OuyunCoinProModel;
use addon\ouyun_coin_pro\model\ActivityModel;
use app\event\controller\PluginBaseController;
use think\facade\Db;

/**
 * @title 平台币pro - 会员中心
 * @desc 平台币主页(余额/领取/签到/明细) + 购物车抵扣试算
 * @use addon\ouyun_coin_pro\controller\clientarea\ClientController
 */
class ClientController extends PluginBaseController
{
    /** 当前客户ID(CheckHome中间件注入) */
    private function clientId(): int
    {
        return intval($this->request->client_id ?? 0);
    }

    /** 归属账户(代理开关) */
    private function accountId(): int
    {
        $Model = new OuyunCoinProModel();
        return $Model->accountOf($this->clientId());
    }

    /**
     * @title 平台币主页数据(余额+签到状态+可领取活动+说明)
     * @url /console/v1/ouyun_coin_pro/index
     * @method GET
     */
    public function index()
    {
        $Model = new OuyunCoinProModel();
        $cfg = $Model->getConfig();
        if ($cfg['enable'] != '1') {
            return json(['status' => 400, 'msg' => '平台币功能未开启']);
        }
        $clientId = $this->accountId();
        $bal = $Model->balanceOf($clientId);
        # 签到状态
        $signin = ['enabled' => 0, 'signed_today' => 0, 'streak' => 0, 'total' => 0, 'today_amount' => '0.00', 'next_amount' => '0.00'];
        $ActivityModel = new ActivityModel();
        $act = $ActivityModel->activeActivity('signin');
        if (!empty($act) && $clientId > 0) {
            $rules = $act['rules'] ? json_decode($act['rules'], true) : [];
            $signin['enabled'] = 1;
            $signin['base'] = floatval($rules['base'] ?? 0);
            $signin['daily_inc'] = floatval($rules['daily_inc'] ?? 0);
            $signin['max'] = floatval($rules['max'] ?? 0);
            $row = Db::name('ouyun_coin_pro_signin')->where('client_id', $clientId)->find();
            $today = intval(date('Ymd'));
            $signin['signed_today'] = (!empty($row) && intval($row['last_date']) == $today) ? 1 : 0;
            $signin['streak'] = !empty($row) && $signin['signed_today'] ? intval($row['streak_days']) : 0;
            $signin['total'] = intval($row['total_days'] ?? 0);
            $streakNext = $signin['signed_today'] ? intval($row['streak_days']) : (intval($row['streak_days'] ?? 0) + 1);
            $next = floatval($rules['base'] ?? 0) + floatval($rules['daily_inc'] ?? 0) * max(0, $streakNext - 1);
            if (!empty($rules['max']) && $next > floatval($rules['max'])) {
                $next = floatval($rules['max']);
            }
            $signin['next_amount'] = strval($next);
        }
        # 标准送活动(可领取;展示开关)
        $claimable = [];
        if ($cfg['show_sidebar_activity'] == '1' && $clientId > 0) {
            $now = time();
            $acts = Db::name('ouyun_coin_pro_activity')
                ->where('type', 'standard')->where('status', 1)
                ->where('start_time', '<=', $now)
                ->where(function ($q) use ($now) {
                    $q->where('end_time', 0)->whereOr('end_time', '>', $now);
                })
                ->order('create_time DESC')->select()->toArray();
            foreach ($acts as $a) {
                $rules = $a['rules'] ? json_decode($a['rules'], true) : [];
                $claimable[] = [
                    'id' => intval($a['id']), 'name' => $a['name'],
                    'amount' => strval($rules['give'] ?? '0'),
                    'end_time' => intval($a['end_time']),
                    'claimed' => Db::name('ouyun_coin_pro_grant')
                        ->where('client_id', $clientId)->where('activity_id', $a['id'])
                        ->where('source', 'claim')->count() > 0 ? 1 : 0,
                ];
            }
        }
        # 活动预告: 充值送(充值弹窗展示) + 订购送(商品页展示); 不叠加策略下取最新一期
        $promo = ['recharge' => null, 'order_buy' => null];
        $now = time();
        foreach (['recharge', 'order_buy'] as $t) {
            $act = Db::name('ouyun_coin_pro_activity')
                ->where('type', $t)->where('status', 1)
                ->where('start_time', '<=', $now)
                ->where(function ($q) use ($now) {
                    $q->where('end_time', 0)->whereOr('end_time', '>', $now);
                })
                ->order('create_time DESC, id DESC')->find();
            if (!empty($act)) {
                $rules = $act['rules'] ? json_decode($act['rules'], true) : [];
                $promo[$t] = [
                    'name'         => $act['name'],
                    'grant_type'   => $act['grant_type'],
                    'percent'      => isset($rules['percent']) ? floatval($rules['percent']) : 0,
                    'gradient'     => isset($rules['gradient']) && is_array($rules['gradient']) ? $rules['gradient'] : [],
                    'product_scope' => intval($act['product_scope']),
                    'product_ids'  => $act['product_ids'] ? json_decode($act['product_ids'], true) : [],
                    'end_time'     => intval($act['end_time']),
                ];
            }
        }
        return json(['status' => 200, 'msg' => '', 'data' => [
            'balance'    => $bal,
            'signin'     => $signin,
            'claimable'  => $claimable,
            'promo'      => $promo,
            'coin_name'  => $Model->coinName(),
            'rate'       => $Model->rate(),
            'description' => strval($cfg['description']),
            'certified'  => $cfg['cert_first'] == '1' ? (check_certification($clientId) ? 1 : 0) : 1,
            'is_sub'     => get_client_id() != get_client_id(false) ? 1 : 0,
        ]]);
    }

    /**
     * @title 领取标准送活动
     * @url /console/v1/ouyun_coin_pro/claim
     * @method POST
     * @param int id - 活动ID
     */
    public function claim()
    {
        $Model = new OuyunCoinProModel();
        $cfg = $Model->getConfig();
        if ($cfg['enable'] != '1') {
            return json(['status' => 400, 'msg' => '平台币功能未开启']);
        }
        $id = intval($this->request->param('id', 0));
        $clientId = $this->clientId();
        if ($clientId <= 0) {
            return json(['status' => 400, 'msg' => '请先登录']);
        }
        $clientId = $this->accountId();
        $act = Db::name('ouyun_coin_pro_activity')->where('id', $id)->where('type', 'standard')->find();
        if (empty($act)) {
            return json(['status' => 400, 'msg' => '活动不存在']);
        }
        $now = time();
        if ($act['status'] != 1 || $act['start_time'] > $now || ($act['end_time'] > 0 && $act['end_time'] < $now)) {
            return json(['status' => 400, 'msg' => '活动已结束']);
        }
        $rules = $act['rules'] ? json_decode($act['rules'], true) : [];
        $give = strval($rules['give'] ?? '0');
        if (bccomp($give, '0.01', 2) < 0) {
            return json(['status' => 400, 'msg' => '活动奖励未配置']);
        }
        # 每活动限领一次
        $dup = Db::name('ouyun_coin_pro_grant')
            ->where('client_id', $clientId)->where('activity_id', $id)->where('source', 'claim')->count();
        if ($dup > 0) {
            return json(['status' => 400, 'msg' => '已领取过该活动']);
        }
        $res = $Model->grantCoins($clientId, $id, $give, 'claim', '领取「' . $act['name'] . '」',
            ['from_key' => 'claim:' . $id . ':' . $clientId]);
        return json($res);
    }

    /**
     * @title 每日签到
     * @url /console/v1/ouyun_coin_pro/signin
     * @method POST
     */
    public function signin()
    {
        $Model = new OuyunCoinProModel();
        if ($Model->getConfig()['enable'] != '1') {
            return json(['status' => 400, 'msg' => '平台币功能未开启']);
        }
        return json($Model->signin($this->clientId()));
    }

    /**
     * @title 明细流水
     * @url /console/v1/ouyun_coin_pro/logs
     * @method GET
     * @param int page - 页数
     * @param int limit - 每页条数
     * @param string type - 类型筛选
     */
    public function logs()
    {
        $page = max(1, intval($this->request->page));
        $limit = min(50, max(1, intval($this->request->limit)));
        $clientId = $this->accountId();
        $q = Db::name('ouyun_coin_pro_log')->where('client_id', $clientId);
        $type = strval($this->request->param('type', ''));
        if ($type !== '') {
            $q->where('type', $type);
        }
        $count = $q->count();
        $rows = $q->order('id DESC')->page($page, $limit)->select()->toArray();
        $map = ['grant' => '发放', 'consume' => '消费', 'refund' => '退款退还', 'expire' => '过期', 'revoke' => '撤销', 'adjust' => '调整'];
        foreach ($rows as &$r) {
            $r['type_text'] = $map[$r['type']] ?? $r['type'];
        }
        return json(['status' => 200, 'msg' => '', 'data' => ['list' => $rows, 'count' => $count]]);
    }

    /**
     * @title 抵扣试算(购物车注入JS调用)
     * @url /console/v1/ouyun_coin_pro/trial
     * @method POST
     * @param array items - [{product_id, price(总价元), billing_cycle}]
     * @param string scene - 场景 host/renew/upgrade
     * @param float order_amount - 订单应付金额(元)
     */
    public function trial()
    {
        $Model = new OuyunCoinProModel();
        $cfg = $Model->getConfig();
        if ($cfg['enable'] != '1' || $cfg['show_cart_deduct'] != '1') {
            return json(['status' => 200, 'msg' => '', 'data' => ['enabled' => false, 'total_yuan' => '0.00', 'total_coin' => '0.00', 'items' => []]]);
        }
        $clientId = $this->clientId();
        if ($clientId <= 0) {
            return json(['status' => 200, 'msg' => '', 'data' => ['enabled' => false, 'total_yuan' => '0.00', 'total_coin' => '0.00', 'items' => []]]);
        }
        $clientId = $Model->resolveAccount($clientId);
        $items = $this->request->param('items', []);
        $clean = [];
        foreach ((array)$items as $it) {
            if (!is_array($it) || empty($it['product_id'])) {
                continue;
            }
            $clean[] = [
                'product_id' => intval($it['product_id']),
                'price' => strval($it['price'] ?? 0),
                'billing_cycle' => strval($it['billing_cycle'] ?? ''),
            ];
        }
        $sceneMap = ['new' => 'host', 'host' => 'host', 'renew' => 'renew', 'upgrade' => 'upgrade'];
        $scene = $sceneMap[strval($this->request->param('scene', 'host'))] ?? 'host';
        $orderAmount = $this->request->param('order_amount', null);
        $data = $Model->trialCart($clientId, $clean, $scene, $orderAmount !== null ? strval($orderAmount) : null);
        $data['certified'] = $cfg['cert_first'] == '1' ? (check_certification($clientId) ? 1 : 0) : 1;
        $data['with_promo_code'] = strval($cfg['with_promo_code']);
        return json(['status' => 200, 'msg' => '', 'data' => $data]);
    }
}
