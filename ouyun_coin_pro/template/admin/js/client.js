/* 平台币pro - 后台: 用户余额(汇总列表+持币明细弹窗) */
(function (window, undefined) {
  var old_onload = window.onload;
  window.onload = function () {
    const template = document.getElementsByClassName("template")[0];

    new Vue({
      mixins: [window.oycpMixin],
      components: { comChooseUser: typeof comChooseUser !== "undefined" ? comChooseUser : {} },
      data() {
        return {
          keywords: "",
          list: [],
          loading: false,
          page: 1,
          limit: 20,
          count: 0,
          coinName: "平台币",
          nowTs: Math.floor(Date.now() / 1000),
          columns: [
            { colKey: "id", title: "ID", width: 70 },
            { colKey: "user", title: "用户", minWidth: 180, cell: "user" },
            { colKey: "available", title: "可用余额", width: 140, cell: "available",
              sortType: "all", sorter: (a, b) => parseFloat(a.available) - parseFloat(b.available) },
            { colKey: "frozen", title: "预占中", width: 110, cell: "frozen",
              sortType: "all", sorter: (a, b) => parseFloat(a.frozen_total) - parseFloat(b.frozen_total) },
            { colKey: "grant_count", title: "持有笔数", width: 100, sortType: "all",
              sorter: (a, b) => a.grant_count - b.grant_count },
            { colKey: "expiring", title: "7天内到期", width: 120, cell: "expiring" },
            { colKey: "op", title: "操作", width: 90, cell: "op" },
          ],
          // 明细弹窗
          detailVisible: false,
          detailLoading: false,
          detailUser: {},
          detailList: [],
          dPage: 1,
          dLimit: 10,
          dCount: 0,
          // 手动发放
          grantVisible: false,
          grantSubmitting: false,
          grantKey: 0,
          grantForm: { client_id: null, amount: 10, valid_days: 0, remark: "" },
          detailColumns: [
            { colKey: "id", title: "记录ID", width: 76 },
            { colKey: "activity_name", title: "来源活动", minWidth: 130, cell: (h, { row }) => row.activity_name ? (row.activity_name + (row.activity_type_text ? "(" + row.activity_type_text + ")" : "")) : (row.source_text || "-") },
            { colKey: "grant_amount", title: "这笔获得", width: 120, cell: "grant_amount" },
            { colKey: "remaining", title: "剩余", width: 130, cell: "remaining" },
            { colKey: "create_time", title: "领取/发放时间", width: 160, cell: (h, { row }) => this.fmtTime(row.create_time) },
            { colKey: "effective", title: "生效", width: 110, cell: "effective" },
            { colKey: "valid", title: "到期时间", width: 150, cell: "valid" },
            { colKey: "status", title: "状态", width: 90, cell: "status" },
            { colKey: "remark", title: "备注", minWidth: 130, ellipsis: true },
            { colKey: "op", title: "操作", width: 80, cell: "op" },
          ],
        };
      },
      created() {
        this.load();
        this.loadCoinName();
      },
      methods: {
        loadCoinName() {
          Axios.get("/ouyun_coin_pro/config").then((res) => {
            this.coinName = (res.data.data || {}).coin_name || "平台币";
          }).catch(() => {});
        },
        load() {
          this.loading = true;
          const params = { page: this.page, limit: this.limit };
          if (this.keywords) params.keywords = this.keywords;
          Axios.get("/ouyun_coin_pro/client_balance", { params })
            .then((res) => {
              const d = res.data.data || {};
              this.list = d.list || [];
              this.count = d.count || 0;
            })
            .catch((e) => this.$message.warning(this.errMsg(e)))
            .finally(() => (this.loading = false));
        },
        doSearch() { this.page = 1; this.load(); },
        onPage(p) { this.page = p.current; this.load(); },
        /* ---------- 持币明细弹窗 ---------- */
        openDetail(row) {
          this.detailUser = row;
          this.detailVisible = true;
          this.dPage = 1;
          this.loadDetail();
        },
        loadDetail() {
          this.detailLoading = true;
          Axios.get("/ouyun_coin_pro/grant", {
            params: { page: this.dPage, limit: this.dLimit, client_id: this.detailUser.id },
          })
            .then((res) => {
              const d = res.data.data || {};
              this.detailList = d.list || [];
              this.dCount = d.count || 0;
            })
            .catch((e) => this.$message.warning(this.errMsg(e)))
            .finally(() => (this.detailLoading = false));
        },
        onDPage(p) { this.dPage = p.current; this.loadDetail(); },
        /* ---------- 手动发放 ---------- */
        openGrant(row) {
          this.grantKey++;
          this.grantForm = {
            client_id: row ? row.id : null,
            amount: 10, valid_days: 0, remark: "",
          };
          this.grantVisible = true;
        },
        changeUser(id) {
          this.grantForm.client_id = id || null;
        },
        doGrant() {
          if (!this.grantForm.client_id) return this.$message.warning("请选择用户");
          if (!(this.grantForm.amount > 0)) return this.$message.warning("发放额度需大于0");
          this.grantSubmitting = true;
          Axios.post("/ouyun_coin_pro/grant_manual", this.grantForm)
            .then((res) => {
              if (res.data.status === 200) {
                this.$message.success("发放成功");
                this.grantVisible = false;
                this.load();
              } else this.$message.warning(res.data.msg);
            })
            .catch((e) => this.$message.warning(this.errMsg(e)))
            .finally(() => (this.grantSubmitting = false));
        },
        /* ---------- 单笔撤回 ---------- */
        revokeGrant(row) {
          const frozen = parseFloat(row.frozen) > 0;
          if (frozen) {
            return this.$message.warning("该笔有 " + row.frozen + " 预占中(未支付订单在途),不可撤销");
          }
          if (row.status != "normal") {
            return this.$message.warning("仅状态为正常的记录可撤销");
          }
          this.askConfirm("撤回这笔平台币",
            `确定撤回记录 #${row.id}?用户「${this.detailUser.username}」这笔的剩余 ${row.remaining} ${this.coinName}将立即失效。`,
            () => {
              Axios.post("/ouyun_coin_pro/grant_revoke", { ids: [row.id], reason: "用户余额页单笔撤回" })
                .then((res) => {
                  this.$message[res.data.status === 200 ? "success" : "warning"](res.data.msg);
                  this.loadDetail();
                  this.load(); // 刷新列表余额
                })
                .catch((e) => this.$message.warning(this.errMsg(e)));
            });
        },
      },
    }).$mount(template);
  };
})(window);
