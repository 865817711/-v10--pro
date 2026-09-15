/* 平台币pro - 后台公共 mixin(四页共用:受控确认弹窗/错误处理/复制) */
(function (window) {
  const baseUrl = `${location.origin}/${location.pathname.split("/")[1]}/`;

  window.oycpMixin = {
    data() {
      return {
        baseUrl,
        confirmVisible: false,
        confirmTitle: "",
        confirmBody: "",
        confirmFn: null,
        submitLoading: false,
      };
    },
    methods: {
      errMsg(e) {
        if (e && e.data && e.data.msg) return e.data.msg;
        return (e && (e.msg || e.message)) || "error";
      },
      askConfirm(title, body, fn) {
        this.confirmTitle = title;
        this.confirmBody = body;
        this.confirmFn = fn;
        this.confirmVisible = true;
      },
      doConfirm() {
        this.confirmVisible = false;
        if (typeof this.confirmFn === "function") this.confirmFn();
      },
      copyText(text, silent) {
        const val = String(text == null ? "" : text).trim();
        if (!val) return this.$message.warning("内容为空");
        const done = () => this.$message.success(silent || "已复制: " + val);
        const fallback = () => {
          const ta = document.createElement("textarea");
          ta.value = val;
          ta.style.cssText = "position:fixed;top:-9999px;opacity:0;";
          document.body.appendChild(ta);
          ta.select();
          try { document.execCommand("copy"); done(); } catch (e) { this.$message.error("复制失败"); }
          document.body.removeChild(ta);
        };
        if (navigator.clipboard && window.isSecureContext) {
          navigator.clipboard.writeText(val).then(done).catch(fallback);
        } else fallback();
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
  };
})(window);
