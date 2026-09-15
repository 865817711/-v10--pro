/* 平台币pro - 后台: 基础配置 */
(function (window, undefined) {
  var old_onload = window.onload;
  window.onload = function () {
    const template = document.getElementsByClassName("template")[0];

    new Vue({
      mixins: [window.oycpMixin],
      data() {
        return {
          loading: false,
          saving: false,
          form: {},
        };
      },
      created() {
        this.load();
      },
      methods: {
        load() {
          this.loading = true;
          Axios.get("/ouyun_coin_pro/config")
            .then((res) => { this.form = res.data.data || {}; })
            .catch((e) => this.$message.warning(this.errMsg(e)))
            .finally(() => (this.loading = false));
        },
        onPurgeChange(v) {
          if (v == "1") {
            this.askConfirm("危险操作确认", "开启后,卸载插件将删除全部平台币数据表(配置/活动/发放记录/流水),不可恢复!确定开启?", () => {
              Axios.post("/ouyun_coin_pro/config_save", { uninstall_purge: 1 })
                .then(() => this.$message.success("已开启:卸载时删除全部数据"))
                .catch((e) => this.$message.warning(this.errMsg(e)));
            });
            // 未确认则本次不落库,保存时统一提交
          } else {
            Axios.post("/ouyun_coin_pro/config_save", { uninstall_purge: 0 })
              .then(() => this.$message.success("已关闭:卸载保留数据"))
              .catch((e) => this.$message.warning(this.errMsg(e)));
          }
        },
        save() {
          this.saving = true;
          const payload = Object.assign({}, this.form);
          delete payload.uninstall_purge; // 危险开关单独处理
          Axios.post("/ouyun_coin_pro/config_save", payload)
            .then((res) => {
              this.$message[res.data.status === 200 ? "success" : "warning"](res.data.msg);
            })
            .catch((e) => this.$message.warning(this.errMsg(e)))
            .finally(() => (this.saving = false));
        },
      },
    }).$mount(template);
  };
})(window);
