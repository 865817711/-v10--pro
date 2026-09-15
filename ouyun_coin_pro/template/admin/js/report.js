/* 平台币pro - 后台: 数据报表(echarts按日统计+CSV导出) */
(function (window, undefined) {
  var old_onload = window.onload;
  window.onload = function () {
    const template = document.getElementsByClassName("template")[0];

    new Vue({
      mixins: [window.oycpMixin],
      data() {
        return {
          loading: false,
          exporting: false,
          range: [],
          days: [],
          summary: {},
          coinName: "平台币",
          chart: null,
          columns: [
            { colKey: "date", title: "日期", width: 120 },
            { colKey: "grant", title: "发放", width: 110 },
            { colKey: "grant_cnt", title: "发放笔数", width: 96 },
            { colKey: "consume", title: "消费", width: 110 },
            { colKey: "consume_cnt", title: "消费笔数", width: 96 },
            { colKey: "expire", title: "过期", width: 110 },
            { colKey: "expire_cnt", title: "过期笔数", width: 96 },
            { colKey: "refund", title: "退款退回", width: 110 },
          ],
        };
      },
      created() {
        this.load();
      },
      methods: {
        load() {
          this.loading = true;
          const params = {};
          if (this.range && this.range.length == 2) {
            params.start_time = this.range[0];
            params.end_time = this.range[1];
          }
          Axios.get("/ouyun_coin_pro/report", { params })
            .then((res) => {
              const d = res.data.data || {};
              this.days = d.days || [];
              this.summary = d.summary || {};
              this.coinName = this.summary.coin_name || "平台币";
              this.renderChart();
            })
            .catch((e) => this.$message.warning(this.errMsg(e)))
            .finally(() => (this.loading = false));
        },
        renderChart() {
          if (!window.echarts) return;
          const el = document.getElementById("chart");
          if (!el) return;
          if (!this.chart) this.chart = echarts.init(el);
          const dates = this.days.map((d) => d.date);
          this.chart.setOption({
            tooltip: { trigger: "axis" },
            legend: { data: ["发放", "消费", "过期", "退款退回"] },
            grid: { left: 50, right: 20, top: 40, bottom: 30 },
            xAxis: { type: "category", data: dates },
            yAxis: { type: "value", name: this.coinName },
            series: [
              { name: "发放", type: "bar", stack: "in", barMaxWidth: 22, itemStyle: { color: "#00a870" }, data: this.days.map((d) => d.grant) },
              { name: "退款退回", type: "bar", stack: "in", barMaxWidth: 22, itemStyle: { color: "#8a2be2" }, data: this.days.map((d) => d.refund) },
              { name: "消费", type: "line", smooth: true, itemStyle: { color: "#d54941" }, data: this.days.map((d) => d.consume) },
              { name: "过期", type: "line", smooth: true, itemStyle: { color: "#e37318" }, data: this.days.map((d) => d.expire) },
            ],
          }, true);
        },
        exportCsv() {
          this.exporting = true;
          const params = {};
          if (this.range && this.range.length == 2) {
            params.start_time = this.range[0];
            params.end_time = this.range[1];
          }
          const qs = Object.keys(params).map((k) => k + "=" + encodeURIComponent(params[k])).join("&");
          // 走原生请求保留 cookie(下载)
          const url = this.baseUrl + "v1/ouyun_coin_pro/report_export" + (qs ? "?" + qs : "");
          fetch(url, { credentials: "include", headers: { Authorization: "Bearer " + localStorage.getItem("backJwt") } })
            .then((r) => {
              if (!r.ok) throw new Error("HTTP " + r.status);
              return r.blob();
            })
            .then((blob) => {
              const a = document.createElement("a");
              a.href = URL.createObjectURL(blob);
              a.download = "ouyun_coin_pro_report.csv";
              document.body.appendChild(a);
              a.click();
              a.remove();
              this.$message.success("导出成功");
            })
            .catch((e) => this.$message.warning("导出失败: " + e.message))
            .finally(() => (this.exporting = false));
        },
      },
    }).$mount(template);
    window.addEventListener("resize", () => {
      const vm = template.__vue__;
      if (vm && vm.chart) vm.chart.resize();
    });
  };
})(window);
