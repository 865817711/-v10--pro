/* 平台币pro - 后台: 活动配置(8种活动类型,梯度编辑器,活动中丝带) */
(function (window, undefined) {
  var old_onload = window.onload;
  window.onload = function () {
    const template = document.getElementsByClassName("template")[0];

    new Vue({
      mixins: [window.oycpMixin],
      components: { comTreeSelect: typeof comTreeSelect !== "undefined" ? comTreeSelect : {} },
      data() {
        return {
          list: [],
          loading: false,
          page: 1,
          limit: 20,
          count: 0,
          grantBadge: 0,
          typeOptions: {},
          cycleOptions: {},
          levelOptions: [],
          filter: { keywords: "", type: "", status: null, range: [] },
          selected: [],
          // 编辑
          editVisible: false,
          submitLoading: false,
          form: this.emptyForm(),
          sceneArr: ["host", "renew", "upgrade", "on_demand_to_recurring"],
          cycleArr: [],
          // 商品树(系统统一组件 com-tree-select)
          productTree: [],
          proList: [],
          typeDesc: {
            standard: "标准送:用户在前台平台币页手动领取,或管理员在发放详情手动发放(不自动触发)",
            recharge: "充值送:充值订单支付到账后自动赠送,支持梯度或比例,受基础配置「单笔充值赠送上限」限制",
            attr: "用户属性送:新注册用户一次性赠送,或指定用户等级按周期(每N天)发放",
            consume_total: "累计消费送:用户累计有效消费(不含余额支付与平台币抵扣)达标触发,每档一次性",
            consume_single: "单笔消费送:单笔订单有效消费达标触发(不含余额支付与平台币抵扣部分)",
            order_buy: "订购送:购买指定商品赠送,可限制新购/续费/升降级场景",
            target: "定向送:管理员定向给指定用户发放,发放的币只能用于指定商品",
            signin: "每日签到送:用户在前台平台币页每日签到领币,支持连续签到递增(平台币pro独有)",
          },
          columns: [
            { colKey: "row-select", type: "multiple", width: 46 },
            { colKey: "id", title: "ID", width: 60 },
            { colKey: "name", title: "活动名称", minWidth: 180, cell: "name" },
            { colKey: "code", title: "活动编码", width: 165, cell: "code" },
            { colKey: "type_text", title: "类型", width: 96 },
            { colKey: "give", title: "赠送", minWidth: 150, cell: "give" },
            { colKey: "scope", title: "适用商品", width: 100, cell: "scope" },
            { colKey: "valid", title: "有效期", width: 120, cell: "valid" },
            { colKey: "time", title: "活动时间", width: 200, cell: "time" },
            { colKey: "grant_info", title: "已发放", width: 110, cell: (h, { row }) => row.grant_count + "条/" + row.grant_total + "币" },
            { colKey: "status", title: "状态", width: 130, cell: "status" },
            { colKey: "op", title: "操作", width: 150, cell: "op" },
          ],
        };
      },
      created() {
        this.loadList();
        this.loadLevels();
        this.loadProductTree();
      },
      methods: {
        emptyForm() {
          return {
            id: 0, code: "", name: "", type: "standard", grant_type: "gradient",
            rules: { give: 10, percent: 5, gradient: [{ full: 100, give: 10 }], tiers: [{ full: 1000, give: 50 }], targets: ["new_register"], cycle_days: 0, base: 1, daily_inc: 0, max: 0 },
            product_scope: 0, product_ids_data: [],
            cycle_limit_switch: 0,
            effective_days: 0, valid_days: 0, status: 1,
            start_time: "", end_time: "", remark: "",
          };
        },
        loadList() {
          this.loading = true;
          const params = { page: this.page, limit: this.limit };
          if (this.filter.keywords) params.keywords = this.filter.keywords;
          if (this.filter.type) params.type = this.filter.type;
          if (this.filter.status !== null && this.filter.status !== "" && this.filter.status !== undefined) params.status = this.filter.status;
          if (this.filter.range && this.filter.range.length == 2) {
            params.start_time = this.filter.range[0];
            params.end_time = this.filter.range[1];
          }
          Axios.get("/ouyun_coin_pro/activity", { params })
            .then((res) => {
              const d = res.data.data || {};
              this.list = d.list || [];
              this.count = d.count || 0;
              this.typeOptions = d.types || {};
              this.cycleOptions = d.cycles || {};
            })
            .catch((e) => this.$message.warning(this.errMsg(e)))
            .finally(() => (this.loading = false));
        },
        loadLevels() {
          Axios.get("/ouyun_coin_pro/client_level_options")
            .then((res) => { this.levelOptions = (res.data.data || {}).list || []; })
            .catch(() => {});
        },
        doSearch() { this.page = 1; this.loadList(); },
        onPage(p) { this.page = p.current; this.loadList(); },
        gradText(grad, pre) {
          if (!grad || !grad.length) return "-";
          return grad.map((g) => (pre || "充") + g.full + "送" + g.give).join(" / ");
        },
        toggleStatus(row, v) {
          Axios.post("/ouyun_coin_pro/activity_status", { id: row.id, status: v })
            .then((res) => {
              this.$message[res.data.status === 200 ? "success" : "warning"](res.data.msg);
              if (res.data.status === 200) row.status = v;
            })
            .catch((e) => this.$message.warning(this.errMsg(e)));
        },
        batchDelete() {
          this.askConfirm("批量删除活动", `确定删除选中的 ${this.selected.length} 个活动?已有发放记录的活动会自动跳过(保护账本)。`, () => {
            Axios.post("/ouyun_coin_pro/activity_batch_delete", { ids: this.selected })
              .then((res) => {
                this.$message[res.data.status === 200 ? "success" : "warning"](res.data.msg);
                this.selected = [];
                this.loadList();
              })
              .catch((e) => this.$message.warning(this.errMsg(e)));
          });
        },
        del(row) {
          this.askConfirm("删除活动", `确定删除活动「${row.name}」?已有发放记录的活动不允许删除。`, () => {
            Axios.post("/ouyun_coin_pro/activity_delete", { id: row.id })
              .then((res) => {
                this.$message[res.data.status === 200 ? "success" : "warning"](res.data.msg);
                this.loadList();
              })
              .catch((e) => this.$message.warning(this.errMsg(e)));
          });
        },
        gotoGrant(row) {
          location.href = this.baseUrl + "plugin/ouyun_coin_pro/grant.htm?activity_id=" + row.id;
        },
        /* ---------- 编辑 ---------- */
        openEdit(row) {
          if (row) {
            Axios.get("/ouyun_coin_pro/activity_detail", { params: { id: row.id } })
              .then((res) => {
                if (res.data.status !== 200) return this.$message.warning(res.data.msg);
                const d = res.data.data;
                const rules = Object.assign({}, this.emptyForm().rules, d.rules_data || {});
                this.form = {
                  id: d.id, code: d.code, name: d.name, type: d.type, grant_type: d.grant_type,
                  rules, product_scope: d.product_scope, product_ids_data: d.product_ids_data || [],
                  cycle_limit_switch: d.cycle_limit_switch,
                  effective_days: d.effective_days, valid_days: d.valid_days, status: d.status,
                  start_time: d.start_time ? this.fmtDate(d.start_time) : "",
                  end_time: d.end_time ? this.fmtDate(d.end_time) : "",
                  remark: d.remark || "",
                };
                const sc = d.scene_data || {};
                this.sceneArr = Object.keys(sc).filter((k) => sc[k]);
                this.cycleArr = d.cycle_limit_data || [];
                this.editVisible = true;
              })
              .catch((e) => this.$message.warning(this.errMsg(e)));
          } else {
            this.form = this.emptyForm();
            this.sceneArr = ["host", "renew", "upgrade", "on_demand_to_recurring"];
            this.cycleArr = [];
            this.editVisible = true;
          }
        },
        onTypeChange() {
          const f = this.form;
          if (f.type == "target") f.product_scope = 1;
          if (f.type == "attr" && !f.rules.targets.length) f.rules.targets = ["new_register"];
        },
        // 商品树选择回调(系统统一组件 com-tree-select)
        choosePro(val) {
          this.form.product_ids_data = (val || []).map(Number);
        },
        // 加载商品分组树(系统接口,与优惠码插件同款)
        loadProductTree() {
          if (this.productTree.length) return;
          Promise.all([
            Axios.get("/product", { params: { limit: 9999, page: 1 } }),
            Axios.get("/product/group/first"),
            Axios.get("/product/group/second"),
          ]).then((res) => {
            const products = (((res[0].data.data || {}).list) || [])
              .map((item) => { item.key = "t-" + item.id; return item; })
              .filter((item) => item.product_group_id_second);
            this.proList = products;
            const first = ((((res[1].data.data || {}).list)) || []).map((item) => { item.key = "f-" + item.id; return item; });
            const second = ((((res[2].data.data || {}).list)) || []).map((item) => { item.key = "s-" + item.id; return item; });
            const tree = first.map((f) => {
              f.children = second.filter((s) => s.parent_id === f.id).map((s) => {
                s.children = products.filter((p) => p.product_group_id_second === s.id);
                return s;
              });
              return f;
            });
            this.productTree = tree.filter((f) => f.children.length > 0)
              .map((f) => {
                f.children = f.children.filter((s) => s.children.length > 0);
                return f;
              });
          }).catch(() => {});
        },
        save() {
          const f = this.form;
          if (!f.name.trim()) return this.$message.warning("请填写活动名称");
          const scene = {};
          ["host", "renew", "upgrade", "on_demand_to_recurring"].forEach((k) => { scene[k] = this.sceneArr.includes(k) ? 1 : 0; });
          const payload = {
            id: f.id, name: f.name, type: f.type, grant_type: f.grant_type,
            rules: f.rules, scene_data: scene,
            product_scope: f.product_scope,
            product_ids_data: f.product_ids_data || [],
            cycle_limit_switch: f.cycle_limit_switch,
            cycle_limit_data: this.cycleArr,
            effective_days: f.effective_days, valid_days: f.valid_days, status: f.status,
            start_time: f.start_time || "", end_time: f.end_time || "", remark: f.remark || "",
          };
          this.submitLoading = true;
          Axios.post("/ouyun_coin_pro/activity_save", payload)
            .then((res) => {
              if (res.data.status === 200) {
                this.$message.success(res.data.msg);
                this.editVisible = false;
                this.loadList();
              } else this.$message.warning(res.data.msg);
            })
            .catch((e) => this.$message.warning(this.errMsg(e)))
            .finally(() => (this.submitLoading = false));
        },
      },
    }).$mount(template);
  };
})(window);
