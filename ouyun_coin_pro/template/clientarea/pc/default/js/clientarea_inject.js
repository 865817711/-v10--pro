/*
 * 平台币pro - 会员中心页面注入(由 clientarea_view_append 钩子注入)
 * 财务页(finance): 账户余额卡旁插入平台币余额块(点击进入平台币主页)
 * 账号首页(home): 信息卡(邮箱/电话区)插入平台币余额入口
 */
(function () {
  if (window.__oycoinCaInjected) return;
  window.__oycoinCaInjected = true;

  var API = "/console/v1/ouyun_coin_pro/index";

  function jwt() { return localStorage.getItem("jwt") || ""; }

  function coinPageUrl() {
    // 侧栏导航链接形如 /plugin/<id>/index.htm, 从菜单链接反查(避免插件ID硬编码)
    var link = document.querySelector('.asideMenu a[href*="/plugin/"][href*="index.htm"]');
    // 更精确: 找文本为"平台币"的侧栏链接
    var asides = document.querySelectorAll('a[href*="/plugin/"]');
    for (var i = 0; i < asides.length; i++) {
      var t = (asides[i].innerText || "").replace(/\s+/g, "");
      if (t === "平台币" || t === "Coins" || t === "平台幣") {
        return asides[i].getAttribute("href");
      }
    }
    return link ? link.getAttribute("href") : "/plugin/150/index.htm";
  }

  function fmt(n) {
    return (Math.round(parseFloat(n || 0) * 100) / 100).toFixed(2);
  }

  /* 附加信息: 仅预占提示(不显示折算金额) */
  function extraInfo(bal) {
    return parseFloat(bal.frozen) > 0
      ? '<span style="font-size:12px;color:#999;margin-left:4px;">预占' + fmt(bal.frozen) + '</span>'
      : '';
  }

  function loadData() {
    return fetch(API, {
      credentials: "include",
      headers: { Authorization: "Bearer " + jwt() },
    }).then(function (r) { return r.json(); }).catch(function () { return null; });
  }

  /* ---------- 财务页: 余额卡旁的平台币余额块 ---------- */
  function injectFinance(data) {
    var coinName = data.coin_name || "平台币";
    var bal = data.balance || {};
    var host = document.querySelector(".finance-other-money");
    if (!host) {
      // 主题未渲染其他余额区时, 挂在余额卡内部
      var card = document.querySelector(".balance-left-num")
        ? document.querySelector(".balance-left-num").closest("div[class]")
        : null;
      if (!card) return;
      host = document.createElement("div");
      host.className = "finance-other-money";
      host.style.cssText = "display:flex;gap:24px;flex-wrap:wrap;margin-top:10px;";
      card.parentNode.appendChild(host);
    }
    if (document.getElementById("oycoin-finance-item")) return;
    var item = document.createElement("div");
    item.id = "oycoin-finance-item";
    item.className = "other-money-item";
    item.style.cssText = "cursor:pointer;";
    item.title = "进入" + coinName + "主页(领取/签到/明细)";
    item.innerHTML =
      '<div class="other-money-item-title">' + coinName + '</div>' +
      '<div class="other-money-item-value"><b style="font-size:inherit;">' + fmt(bal.available) + '</b>' + extraInfo(bal) + '</div>';
    item.addEventListener("click", function () {
      location.href = coinPageUrl();
    });
    host.appendChild(item);
  }

  /* ---------- 账号首页: 欢迎信息区内插入平台币行(不破坏主题栅格) ---------- */
  function injectHome(data) {
    var coinName = data.coin_name || "平台币";
    var bal = data.balance || {};
    if (document.getElementById("oycoin-home-item")) return;
    var box = document.createElement("div");
    box.id = "oycoin-home-item";
    box.title = "进入" + coinName + "主页(领取/签到/明细)";
    box.style.cssText = "cursor:pointer;display:flex;align-items:center;gap:8px;flex-wrap:wrap;" +
      "padding:8px 4px;margin-top:8px;border-top:1px dashed #e3e8f0;font-size:13px;";
    box.innerHTML =
      '<span style="color:#8f9bb3;">' + coinName + '</span>' +
      '<b style="font-size:18px;font-weight:700;color:#0052d9;font-variant-numeric:tabular-nums;">' + fmt(bal.available) + '</b>' +
      extraInfo(bal) +
      '<span style="margin-left:auto;color:#0052d9;font-size:12px;white-space:nowrap;">领取/签到/明细 &gt;</span>';
    box.addEventListener("click", function () {
      location.href = coinPageUrl();
    });
    // 锚点: 定制主题插在欢迎头部(账户ID块)之后; 默认主题插第三信息块内部; 兜底主内容区独占一行
    var boxle = document.querySelector(".shiwaip2-boxle");
    if (boxle) {
      var head = boxle.querySelector(":scope > .shiwaip2-boxle-b");
      boxle.insertBefore(box, head && head.nextSibling ? head.nextSibling : boxle.firstChild);
      return;
    }
    var info3 = document.querySelector(".info-three") || document.querySelector(".info-second");
    if (info3) {
      info3.appendChild(box);
      return;
    }
    var mc = document.querySelector(".main-content") || document.querySelector(".main-card");
    if (!mc) return;
    box.style.width = "100%";
    mc.appendChild(box);
  }

  /* ---------- 账号信息页: 资料卡顶部入口横条 ---------- */
  function injectAccount(data) {
    var coinName = data.coin_name || "平台币";
    var bal = data.balance || {};
    if (document.getElementById("oycoin-account-item")) return;
    var box = document.querySelector(".main-card .content-box") || document.querySelector(".main-card");
    if (!box) return;
    var bar = document.createElement("div");
    bar.id = "oycoin-account-item";
    bar.title = "进入" + coinName + "主页(领取/签到/明细)";
    bar.style.cssText = "cursor:pointer;display:flex;align-items:center;gap:10px;padding:10px 16px;margin-bottom:12px;" +
      "border-radius:6px;background:#fff;border:1px solid #e3e8f0;color:#333;font-size:13px;";
    bar.innerHTML =
      '<span style="font-weight:600;color:#555;">' + coinName + '余额</span>' +
      '<span style="font-size:18px;font-weight:700;color:#0052d9;font-variant-numeric:tabular-nums;">' + fmt(bal.available) + '</span>' +
      extraInfo(bal) +
      '<span style="margin-left:auto;color:#0052d9;font-size:12px;">领取/签到/明细 &gt;</span>';
    bar.addEventListener("click", function () {
      location.href = coinPageUrl();
    });
    box.insertBefore(bar, box.firstChild);
  }

  /* ---------- 财务页: 充值弹窗内的"充值送"活动预告 ---------- */
  function promoText(promo) {
    if (!promo) return "";
    if (promo.grant_type === "ratio" && promo.percent > 0) {
      return "充值赠送" + promo.percent + "%平台币";
    }
    if (promo.gradient && promo.gradient.length) {
      return promo.gradient.map(function (g) { return "充" + g.full + "送" + g.give; }).join("、");
    }
    return "";
  }

  function injectRechargePromo(data) {
    var promo = (data.promo || {}).recharge;
    var txt = promoText(promo);
    if (!txt) return;
    var coinName = data.coin_name || "平台币";
    if (document.getElementById("oycoin-recharge-promo")) return;
    // 充值弹窗: 含金额输入的可见 el-dialog
    var dlg = Array.from(document.querySelectorAll(".el-dialog")).filter(function (d) {
      if (d.offsetWidth < 50) return false;
      var t = d.innerText || "";
      return t.indexOf("充值") !== -1 && (d.querySelector("input") !== null);
    })[0];
    if (!dlg) return;
    var body = dlg.querySelector(".el-dialog__body") || dlg;
    var bar = document.createElement("div");
    bar.id = "oycoin-recharge-promo";
    bar.style.cssText = "margin:0 0 12px;padding:8px 14px;border-radius:4px;background:#f0f7ff;border:1px solid #d4e5ff;font-size:13px;color:#333;";
    bar.innerHTML =
      '<span style="color:#0052d9;font-weight:600;">🎁 ' + coinName + '活动</span> ' +
      '<span>' + txt + '</span>' +
      (promo.end_time ? '<span style="color:#999;font-size:12px;margin-left:8px;">至 ' + new Date(promo.end_time * 1000).toLocaleDateString() + '</span>' : "");
    body.insertBefore(bar, body.firstChild);
  }

  function run() {
    if (!jwt()) return;
    var path = location.pathname.toLowerCase();
    var isFinance = path.indexOf("/finance.htm") !== -1;
    var isHome = path === "/home.htm" || path === "/" || path === "/index.htm";
    var isAccount = path.indexOf("/account.htm") !== -1;
    if (!isFinance && !isHome && !isAccount) return;
    loadData().then(function (res) {
      if (!res || res.status !== 200) return;
      var data = res.data || {};
      var tries = 0;
      var needLongPoll = isFinance && ((data.promo || {}).recharge); // 财务页有充值活动时需常驻等待弹窗打开
      var timer = setInterval(function () {
        tries++;
        if (isFinance) { injectFinance(data); injectRechargePromo(data); }
        if (isHome) injectHome(data);
        if (isAccount) injectAccount(data);
        var ok = (!isFinance || document.getElementById("oycoin-finance-item"))
          && (!isHome || document.getElementById("oycoin-home-item"))
          && (!isAccount || document.getElementById("oycoin-account-item"));
        if ((ok && !needLongPoll) || tries > 200) { // 轮询上限60s
          clearInterval(timer);
        }
      }, 300);
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", run);
  } else {
    run();
  }
})();
