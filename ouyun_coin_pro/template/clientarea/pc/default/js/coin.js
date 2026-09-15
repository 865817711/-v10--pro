/* 平台币pro - 会员中心主页(余额/签到/领取/明细) */
window.onload = function () {
  const template = document.getElementsByClassName("oycoin")[0];
  const api = {
    index: () => Axios.get("/ouyun_coin_pro/index"),
    claim: (id) => Axios.post("/ouyun_coin_pro/claim", { id }),
    signin: () => Axios.post("/ouyun_coin_pro/signin", {}),
    logs: (params) => Axios.get("/ouyun_coin_pro/logs", { params }),
  };

  new Vue({
    components: { asideMenu, topMenu },
    data() {
      return {
        data: { balance: {}, signin: {}, claimable: [] },
        balance: {},
        signin: { enabled: 0 },
        claimable: [],
        description: "",
        rate: 1,
        currency: (window.commonData && commonData.currency_prefix) || "¥",
        loading: true,
        signinLoading: false,
        logType: "",
        logs: [],
        logLoading: false,
        logPage: 1,
        logLimit: 15,
        logCount: 0,
      };
    },
    computed: {
      coinName() { return this.data.coin_name || "平台币"; },
    },
    created() {
      this.loadIndex();
      this.loadLogs();
    },
    methods: {
      loadIndex() {
        this.loading = true;
        api.index()
          .then((res) => {
            if (res.data.status !== 200) return;
            this.data = res.data.data || {};
            this.balance = this.data.balance || {};
            this.signin = this.data.signin || {};
            this.claimable = this.data.claimable || [];
            this.description = this.data.description || "";
            this.rate = this.data.rate || 1;
          })
          .catch(() => {})
          .finally(() => (this.loading = false));
      },
      loadLogs() {
        this.logLoading = true;
        const params = { page: this.logPage, limit: this.logLimit };
        if (this.logType) params.type = this.logType;
        api.logs(params)
          .then((res) => {
            const d = res.data.data || {};
            this.logs = d.list || [];
            this.logCount = d.count || 0;
          })
          .catch(() => {})
          .finally(() => (this.logLoading = false));
      },
      doClaim(a) {
        api.claim(a.id)
          .then((res) => {
            if (res.data.status === 200) {
              this.$message.success("领取成功,+" + ((res.data.data || {}).amount || 0) + " " + this.coinName);
              this.loadIndex();
            } else this.$message.warning(res.data.msg);
          })
          .catch((e) => this.$message.warning((e.data && e.data.msg) || "领取失败"));
      },
      doSignin() {
        this.signinLoading = true;
        api.signin()
          .then((res) => {
            if (res.data.status === 200) {
              this.$message.success("签到成功,+" + ((res.data.data || {}).amount || 0) + " " + this.coinName);
              this.loadIndex();
            } else this.$message.warning(res.data.msg);
          })
          .catch((e) => this.$message.warning((e.data && e.data.msg) || "签到失败"))
          .finally(() => (this.signinLoading = false));
      },
      fmtTime(ts) {
        if (!ts) return "-";
        const d = new Date(ts * 1000);
        const p = (n) => (n < 10 ? "0" + n : n);
        return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`;
      },
      fmtDate(ts) {
        if (!ts) return "-";
        const d = new Date(ts * 1000);
        const p = (n) => (n < 10 ? "0" + n : n);
        return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
      },
    },
  }).$mount(template);
  // 挂载成功后隐藏"加载中"占位(主题CSS约定 .template:first-child 隐藏,故只隐藏不移除mainLoading)
  var ml = document.getElementById("mainLoading");
  if (ml) ml.style.display = "none";
};
