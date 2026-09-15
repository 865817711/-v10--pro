/* 平台币pro - 后台: 发放详情(发放记录/手动发放/批量撤销/批量导入/全量流水) */
(function (window, undefined) {
  var old_onload = window.onload;
  window.onload = function () {
    const template = document.getElementsByClassName("template")[0];

    new Vue({
      mixins: [window.oycpMixin],
      components: { comChooseUser: typeof comChooseUser !== "undefined" ? comChooseUser : {} },
      data() {
        return {
          tab: "grant",
          activities: [],
          typeOptions: {},
          // 发放记录
          gf: { activity_id: null, type: "", status: "", source: "", keywords: "" },
          gList: [], gLoading: false, gPage: 1, gLimit: 20, gCount: 0, selected: [],
          gColumns: [
            { colKey: "row-select", type: "multiple", width: 46 },
            { colKey: "id", title: "ID", width: 64 },
            { colKey: "client", title: "用户", width: 140, cell: "client" },
            { colKey: "activity_name", title: "所属活动", minWidth: 130, cell: (h, { row }) => row.activity_name ? (row.activity_name + (row.activity_type_text ? "(" + row.activity_type_text + ")" : "")) : "-" },
            { colKey: "amount", title: "初始/剩余额度", width: 170, cell: "amount" },
            { colKey: "valid", title: "有效期", width: 110, cell: "valid" },
            { colKey: "create_time", title: "发放/领取时间", width: 150, cell: (h, { row }) => this.fmtTime(row.create_time) },
            { colKey: "status", title: "状态", width: 90, cell: "status" },
            { colKey: "source", title: "发放方式", width: 92, cell: "source" },
            { colKey: "remark", title: "备注", minWidth: 120, ellipsis: true },
            { colKey: "op", title: "操作", width: 80, cell: "op" },
          ],
          // 流水
          lf: { type: "", keywords: "" },
          lList: [], lLoading: false, lPage: 1, lLimit: 20, lCount: 0,
          lColumns: [
            { colKey: "id", title: "ID", width: 64 },
            { colKey: "client", title: "用户", width: 140, cell: "client" },
            { colKey: "type_text", title: "类型", width: 92 },
            { colKey: "amount", title: "变动", width: 110, cell: "amount" },
            { colKey: "remark", title: "备注", minWidth: 180, ellipsis: true },
            { colKey: "create_time", title: "时间", width: 150, cell: (h, { row }) => this.fmtTime(row.create_time) },
          ],
          // 手动发放
          grantVisible: false,
          grantKey: 0,
          grantForm: { client_id: null, amount: 10, activity_id: null, valid_days: 0, remark: "" },
          // 批量导入
          importVisible: false,
          importCsv: "",
          importValidDays: 0,
          importResult: null,
        };
      },
      created() {
        // 从活动页"发放"跳转带入活动筛选
        const q = new URLSearchParams(location.search);
        const aid = q.get("activity_id");
        if (aid) this.gf.activity_id = parseInt(aid);
        this.loadActivities();
        this.loadGrants();
      },
      methods: {
        loadActivities() {
          Axios.get("/ouyun_coin_pro/activity", { params: { page: 1, limit: 100 } })
            .then((res) => {
              const d = res.data.data || {};
              this.activities = d.list || [];
              this.typeOptions = d.types || {};
            })
            .catch(() => {});
        },
        doSearch() {
          if (this.tab == "grant") { this.gPage = 1; this.loadGrants(); }
          else { this.lPage = 1; this.loadLogs(); }
        },
        loadGrants() {
          this.gLoading = true;
          const params = { page: this.gPage, limit: this.gLimit };
          Object.keys(this.gf).forEach((k) => {
            if (this.gf[k] !== "" && this.gf[k] !== null) params[k] = this.gf[k];
          });
          Axios.get("/ouyun_coin_pro/grant", { params })
            .then((res) => {
              const d = res.data.data || {};
              this.gList = d.list || [];
              this.gCount = d.count || 0;
            })
            .catch((e) => this.$message.warning(this.errMsg(e)))
            .finally(() => (this.gLoading = false));
        },
        onGPage(p) { this.gPage = p.current; this.loadGrants(); },
        loadLogs() {
          this.lLoading = true;
          const params = { page: this.lPage, limit: this.lLimit };
          Object.keys(this.lf).forEach((k) => {
            if (this.lf[k] !== "" && this.lf[k] !== null) params[k] = this.lf[k];
          });
          Axios.get("/ouyun_coin_pro/log", { params })
            .then((res) => {
              const d = res.data.data || {};
              this.lList = d.list || [];
              this.lCount = d.count || 0;
            })
            .catch((e) => this.$message.warning(this.errMsg(e)))
            .finally(() => (this.lLoading = false));
        },
        onLPage(p) { this.lPage = p.current; this.loadLogs(); },
        /* ---------- 发放 ---------- */
        openGrant() {
          this.grantKey++;
          this.grantForm = { client_id: null, amount: 10, activity_id: null, valid_days: 0, remark: "" };
          this.grantVisible = true;
        },
        changeUser(id) {
          this.grantForm.client_id = id || null;
        },
        doGrant() {
          if (!this.grantForm.client_id) return this.$message.warning("请选择用户");
          this.submitLoading = true;
          Axios.post("/ouyun_coin_pro/grant_manual", this.grantForm)
            .then((res) => {
              if (res.data.status === 200) {
                this.$message.success("发放成功");
                this.grantVisible = false;
                this.grantForm = { client_id: null, amount: 10, activity_id: null, valid_days: 0, remark: "" };
                this.loadGrants();
              } else this.$message.warning(res.data.msg);
            })
            .catch((e) => this.$message.warning(this.errMsg(e)))
            .finally(() => (this.submitLoading = false));
        },
        /* ---------- 撤销 ---------- */
        revokeOne(row) {
          this.askConfirm("撤销发放", `撤销后用户「${row.username || row.client_id}」的剩余 ${row.remaining} 平台币立即失效,已预占(未支付订单在途)的记录不可撤销。确定撤销?`, () => {
            this.doRevoke([row.id], "单条撤销");
          });
        },
        batchRevoke() {
          this.askConfirm("批量撤销", `确定撤销选中的 ${this.selected.length} 条发放记录?剩余额度立即失效。`, () => {
            this.doRevoke(this.selected, "批量撤销");
          });
        },
        doRevoke(ids, reason) {
          Axios.post("/ouyun_coin_pro/grant_batch_revoke", { ids, reason })
            .then((res) => {
              this.$message[res.data.status === 200 ? "success" : "warning"](res.data.msg);
              this.selected = [];
              this.loadGrants();
            })
            .catch((e) => this.$message.warning(this.errMsg(e)));
        },
        /* ---------- 批量导入 ---------- */
        onCsvFile(e) {
          const file = e.target.files[0];
          if (!file) return;
          const reader = new FileReader();
          reader.onload = (ev) => { this.importCsv = ev.target.result; };
          reader.readAsText(file, "UTF-8");
        },
        doImport() {
          if (!this.importCsv.trim()) return this.$message.warning("请粘贴或上传CSV数据");
          this.submitLoading = true;
          this.importResult = null;
          Axios.post("/ouyun_coin_pro/grant_import", {
            csv: this.importCsv,
            valid_days: this.importValidDays,
          })
            .then((res) => {
              if (res.data.status === 200) {
                this.importResult = res.data;
                this.$message.success(res.data.msg);
                this.loadGrants();
              } else this.$message.warning(res.data.msg);
            })
            .catch((e) => this.$message.warning(this.errMsg(e)))
            .finally(() => (this.submitLoading = false));
        },
      },
      watch: {
        tab(v) {
          if (v == "log" && !this.lList.length) this.loadLogs();
        },
      },
    }).$mount(template);
  };
})(window);
