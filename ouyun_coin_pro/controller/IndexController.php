<?php
namespace addon\ouyun_coin_pro\controller;

use addon\ouyun_coin_pro\model\OuyunCoinProModel;
use addon\ouyun_coin_pro\model\ActivityModel;
use app\event\controller\PluginAdminBaseController;

/**
 * @title 平台币pro - 后台
 * @desc 活动配置/发放详情/基础配置/报表统计与导出/批量导入
 * @use addon\ouyun_coin_pro\controller\IndexController
 */
class IndexController extends PluginAdminBaseController
{
    private function adminId(): int
    {
        return intval($this->request->admin_id ?? 0);
    }

    /* ---------------- 活动配置 ---------------- */

    /**
     * @title 活动列表
     * @url /<admin>/v1/ouyun_coin_pro/activity
     * @method GET
     * @param int page - 页数
     * @param int limit - 每页条数
     * @param string keywords - 名称/编码/备注
     * @param string type - 活动类型
     * @param int status - 状态
     * @param string start_time - 开始时间筛(活动结束时间>=此值)
     * @param string end_time - 结束时间筛(活动开始时间<=此值)
     */
    public function activityList()
    {
        $param = array_merge($this->request->param(), [
            'page'  => $this->request->page,
            'limit' => $this->request->limit,
        ]);
        return json((new ActivityModel())->activityList($param));
    }

    /**
     * @title 活动详情
     * @url /<admin>/v1/ouyun_coin_pro/activity_detail
     * @method GET
     * @param int id - 活动ID
     */
    public function activityDetail()
    {
        $id = intval($this->request->param('id', 0));
        return json((new ActivityModel())->activityDetail($id));
    }

    /**
     * @title 活动新增/编辑
     * @url /<admin>/v1/ouyun_coin_pro/activity_save
     * @method POST
     */
    public function activitySave()
    {
        return json((new ActivityModel())->activitySave($this->request->param()));
    }

    /**
     * @title 活动删除(无发放记录时)
     * @url /<admin>/v1/ouyun_coin_pro/activity_delete
     * @method POST
     * @param int id - 活动ID
     */
    public function activityDelete()
    {
        $id = intval($this->request->param('id', 0));
        return json((new ActivityModel())->activityDelete($id));
    }

    /**
     * @title 活动批量删除(勾选多条;有发放记录的跳过)
     * @url /<admin>/v1/ouyun_coin_pro/activity_batch_delete
     * @method POST
     * @param array ids - 活动ID数组
     */
    public function activityBatchDelete()
    {
        $ids = $this->request->param('ids', []);
        if (!is_array($ids)) {
            $ids = [$ids];
        }
        return json((new ActivityModel())->activityBatchDelete($ids));
    }

    /**
     * @title 活动启用/结束
     * @url /<admin>/v1/ouyun_coin_pro/activity_status
     * @method POST
     * @param int id - 活动ID
     * @param int status - 1启用 0结束
     */
    public function activityStatus()
    {
        $id = intval($this->request->param('id', 0));
        $status = intval($this->request->param('status', 1));
        return json((new ActivityModel())->activityStatus($id, $status));
    }

    /* ---------------- 发放详情 ---------------- */

    /**
     * @title 发放记录列表
     * @url /<admin>/v1/ouyun_coin_pro/grant
     * @method GET
     */
    public function grantList()
    {
        $param = array_merge($this->request->param(), [
            'page'  => $this->request->page,
            'limit' => $this->request->limit,
        ]);
        return json((new ActivityModel())->grantList($param));
    }

    /**
     * @title 手动发放
     * @url /<admin>/v1/ouyun_coin_pro/grant_manual
     * @method POST
     * @param string client - 用户名/邮箱/手机号
     * @param float amount - 额度(平台币)
     * @param int activity_id - 活动ID(0=无活动)
     * @param int valid_days - 有效期N天(0=不限)
     * @param string remark - 备注
     */
    public function grantManual()
    {
        return json((new ActivityModel())->grantManual($this->request->param(), $this->adminId()));
    }

    /**
     * @title 撤销发放(单条/批量)
     * @url /<admin>/v1/ouyun_coin_pro/grant_revoke
     * @method POST
     * @param array ids - 发放记录ID数组
     * @param string reason - 撤销原因
     */
    public function grantRevoke()
    {
        $ids = $this->request->param('ids', []);
        if (!is_array($ids)) {
            $ids = [$ids];
        }
        $reason = strval($this->request->param('reason', ''));
        return json((new ActivityModel())->grantRevoke($ids, $this->adminId(), $reason));
    }

    /**
     * @title 批量撤销(勾选多条)
     * @url /<admin>/v1/ouyun_coin_pro/grant_batch_revoke
     * @method POST
     */
    public function grantBatchRevoke()
    {
        return $this->grantRevoke();
    }

    /**
     * @title 批量导入发放(CSV: 手机号/邮箱+额度+备注)
     * @url /<admin>/v1/ouyun_coin_pro/grant_import
     * @method POST
     * @param string csv - CSV文本(每行: 账号,额度,备注)
     * @param int activity_id - 关联活动(0=无)
     * @param int valid_days - 有效期N天
     */
    public function grantImport()
    {
        $csv = strval($this->request->param('csv', ''));
        $rows = [];
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || stripos($line, '手机') !== false || stripos($line, '邮箱') !== false || stripos($line, '账号') !== false) {
                continue;
            }
            $cols = str_getcsv($line);
            if (count($cols) < 2) {
                $cols = explode(',', $line);
            }
            $rows[] = [strval($cols[0] ?? ''), strval($cols[1] ?? '0'), strval($cols[2] ?? '')];
        }
        if (empty($rows)) {
            return json(['status' => 400, 'msg' => '未解析到有效数据行(格式: 账号,额度,备注)']);
        }
        return json((new ActivityModel())->grantImport(
            $rows,
            $this->adminId(),
            intval($this->request->param('activity_id', 0)),
            intval($this->request->param('valid_days', 0))
        ));
    }

    /**
     * @title 全量流水列表
     * @url /<admin>/v1/ouyun_coin_pro/log
     * @method GET
     */
    public function logList()
    {
        $param = array_merge($this->request->param(), [
            'page'  => $this->request->page,
            'limit' => $this->request->limit,
        ]);
        return json((new ActivityModel())->logList($param));
    }

    /**
     * @title 用户余额汇总列表
     * @url /<admin>/v1/ouyun_coin_pro/client_balance
     * @method GET
     * @param int page - 页数
     * @param int limit - 每页条数
     * @param string keywords - 用户名/邮箱/手机号
     */
    public function clientBalance()
    {
        $param = array_merge($this->request->param(), [
            'page'  => $this->request->page,
            'limit' => $this->request->limit,
        ]);
        return json((new ActivityModel())->clientBalance($param));
    }

    /* ---------------- 基础配置 ---------------- */

    /**
     * @title 读取配置
     * @url /<admin>/v1/ouyun_coin_pro/config
     * @method GET
     */
    public function config()
    {
        $Model = new OuyunCoinProModel();
        return json(['status' => 200, 'msg' => '', 'data' => $Model->getConfig()]);
    }

    /**
     * @title 保存配置
     * @url /<admin>/v1/ouyun_coin_pro/config_save
     * @method POST
     */
    public function configSave()
    {
        return json((new OuyunCoinProModel())->saveConfig($this->request->param()));
    }

    /* ---------------- 报表 ---------------- */

    /**
     * @title 报表统计(按日发放/消费/过期+总览)
     * @url /<admin>/v1/ouyun_coin_pro/report
     * @method GET
     * @param string start_time - 开始日期 Y-m-d
     * @param string end_time - 结束日期 Y-m-d
     */
    public function report()
    {
        return json((new ActivityModel())->report($this->request->param()));
    }

    /**
     * @title 报表明细导出CSV
     * @url /<admin>/v1/ouyun_coin_pro/report_export
     * @method GET
     * @param string start_time - 开始日期
     * @param string end_time - 结束日期
     */
    public function reportExport()
    {
        $param = $this->request->param();
        $data = (new ActivityModel())->report($param);
        $days = $data['data']['days'];
        $Model = new OuyunCoinProModel();
        $coinName = $Model->coinName();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="ouyun_coin_pro_report_' . date('YmdHis') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); # BOM for Excel
        fputcsv($out, ['日期', '发放(' . $coinName . ')', '发放笔数', '消费(' . $coinName . ')', '消费笔数', '过期(' . $coinName . ')', '过期笔数', '退款退回(' . $coinName . ')']);
        foreach ($days as $d) {
            fputcsv($out, [$d['date'], $d['grant'], $d['grant_cnt'], $d['consume'], $d['consume_cnt'], $d['expire'], $d['expire_cnt'], $d['refund']]);
        }
        fclose($out);
        exit;
    }

    /* ---------------- 辅助 ---------------- */

    /**
     * @title 用户等级选项(活动配置页联动用户等级插件)
     * @url /<admin>/v1/ouyun_coin_pro/client_level_options
     * @method GET
     */
    public function clientLevelOptions()
    {
        return json(['status' => 200, 'msg' => '', 'data' => ['list' => (new ActivityModel())->levelOptions()]]);
    }

    /**
     * @title 商品搜索(活动配置页选商品)
     * @url /<admin>/v1/ouyun_coin_pro/product_search
     * @method GET
     * @param string keywords - 商品名关键词
     */
    public function productSearch()
    {
        $kw = strval($this->request->param('keywords', ''));
        $q = new \app\common\model\ProductModel();
        $list = $q->where('hidden', 0)
            ->where(function ($qr) use ($kw) {
                if ($kw !== '') {
                    $qr->whereLike('name', '%' . $kw . '%');
                }
            })
            ->field('id,name,product_group_id')
            ->order('id DESC')
            ->limit(30)
            ->select()->toArray();
        return json(['status' => 200, 'msg' => '', 'data' => ['list' => $list]]);
    }
}
