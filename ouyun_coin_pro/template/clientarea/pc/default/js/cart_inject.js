/*
 * 平台币pro - 购物车/结算页注入(由 cart_view_append 钩子注入)
 * 功能: 结算页平台币抵扣面板(可用余额/勾选/预计抵扣/金额联动)
 * 机制: 勾选后
 *   - 与优惠码互斥时: 猴子补丁 configOption/cart_settle/product_settle,
 *     把 promo_code 置为哨兵 __oycoin__,由后端 apply_promo_code 钩子返回抵扣额,
 *     页面金额走原生优惠码折扣通道自动联动;
 *   - 允许同享时: 保留用户优惠码,面板显示另可抵扣额,结算参数带 use_oycoin=1,
 *     后端 after_order_create 落地抵扣并写 order_item 折扣行。
 */
(function () {
  if (window.__oycoinInjected) return;
  window.__oycoinInjected = true;

  var SENTINEL = "__oycoin__";
  var STORE_KEY = "__oycoin_use";
  var API_BASE = "/console/v1/ouyun_coin_pro";

  function jwt() { return localStorage.getItem("jwt") || ""; }

  function apiGet(path) {
    return fetch(API_BASE + path, {
      credentials: "include",
      headers: { Authorization: "Bearer " + jwt() },
    }).then(function (r) { return r.json(); });
  }

  var state = {
    enabled: false,       // 后端开关+余额>0
    ready: false,
    use: sessionStorage.getItem(STORE_KEY) === "1",
    balance: "0.00",
    trialYuan: "0.00",
    trialCoin: "0.00",
    coinName: "平台币",
    withPromo: false,     // 允许与优惠码同享
    certified: true,
    show: false,          // 配置开关
  };

  /* ---------- 面板渲染 ---------- */
  var panel = null;

  function buildPanel() {
    if (panel) return panel;
    panel = document.createElement("div");
    panel.id = "oycoin-panel";
    panel.style.cssText = "margin:10px 0 4px;padding:12px 16px;border:1px solid #d4e5ff;background:#f0f7ff;border-radius:6px;font-size:13px;color:#333;";
    panel.innerHTML =
      '<label style="display:flex;align-items:center;gap:8px;cursor:pointer;user-select:none;">' +
      '<input type="checkbox" id="oycoin-use" style="width:16px;height:16px;cursor:pointer;"/>' +
      '<b id="oycoin-name">平台币</b>抵扣 <span id="oycoin-balance" style="color:#0052d9;"></span>' +
      '<span id="oycoin-estimate" style="margin-left:10px;color:#00a870;"></span>' +
      "</label>" +
      '<div id="oycoin-tip" style="margin-top:6px;color:#888;font-size:12px;line-height:1.7;"></div>';
    return panel;
  }

  function mountPanel() {
    // 结算页: 挂在合计金额区域(el-footer .totalprice-box 所在卡片)或列表末尾
    var host =
      document.querySelector(".settlement .totalprice-box") ||
      document.querySelector(".totalprice-box") ||
      document.querySelector(".el-footer") ||
      document.querySelector(".settlement table") ||
      document.querySelector(".el-main");
    if (!host) return false;
    var p = buildPanel();
    if (p.parentNode) return true;
    // 挂在 footer 内部最前(金额区上方)
    if (host.classList && host.classList.contains("el-footer")) {
      host.insertBefore(p, host.firstChild);
    } else {
      var footer = document.querySelector(".el-footer");
      if (footer) footer.insertBefore(p, footer.firstChild);
      else host.parentNode.insertBefore(p, host.nextSibling);
    }
    bindEvents();
    return true;
  }

  function refreshPanel() {
    if (!panel) return;
    var cb = document.getElementById("oycoin-use");
    if (cb) {
      cb.checked = state.use;
      cb.disabled = !state.enabled;
    }
    var nameEl = document.getElementById("oycoin-name");
    if (nameEl) nameEl.textContent = state.coinName;
    var balEl = document.getElementById("oycoin-balance");
    if (balEl) balEl.textContent = state.enabled ? "(可用 " + state.balance + " " + state.coinName + ")" : "(可用余额不足)";
    var estEl = document.getElementById("oycoin-estimate");
    if (estEl) estEl.textContent = state.use && state.enabled ? "预计抵扣 " + state.trialYuan + " 元(" + state.trialCoin + state.coinName + ")" : "";
    var tip = document.getElementById("oycoin-tip");
    if (tip) {
      var html = "";
      if (!state.certified) html = "需完成实名认证后才可使用平台币抵扣";
      else if (state.use && state.enabled && !state.withPromo) html = "已启用抵扣:与优惠码互斥,系统将自动清空优惠码并按平台币抵扣";
      else if (state.use && state.enabled && state.withPromo) html = "已启用抵扣:可与优惠码同享,支付页金额以抵扣后为准";
      else html = "勾选后本单可用平台币抵扣,抵扣部分不可退现金(按比例退回平台币)";
      tip.innerHTML = html;
    }
  }

  function bindEvents() {
    var cb = document.getElementById("oycoin-use");
    if (!cb || cb.__oycoinBound) return;
    cb.__oycoinBound = true;
    cb.addEventListener("change", function () {
      state.use = cb.checked && state.enabled;
      sessionStorage.setItem(STORE_KEY, state.use ? "1" : "0");
      refreshPanel();
      applyPatch();
      if (state.use) {
        recalc();
        triggerRecalc(); // 重算页面金额(互斥模式下走哨兵通道原生联动)
      } else {
        triggerRecalc();
      }
    });
  }

  /* ---------- 页面商品数据收集 ---------- */
  function collectItems() {
    // 结算页 Vue 根: .settlement 元素
    var el = document.querySelector(".settlement") || document.querySelector(".template");
    var vm = el && el.__vue__;
    var items = [];
    if (vm) {
      var arr = vm.showGoodsList || vm.showList || vm.initArr || [];
      arr.forEach(function (it) {
        if (!it || !it.product_id) return;
        items.push({
          product_id: it.product_id,
          price: (it.calcItemPrice || (it.price || 0) * (it.qty || 1) || 0),
          qty: it.qty || 1,
          billing_cycle: (it.info && it.info.billing_cycle) || "",
        });
      });
    }
    return items;
  }

  function orderAmount() {
    var el = document.querySelector(".settlement") || document.querySelector(".template");
    var vm = el && el.__vue__;
    if (!vm) return null;
    var v = vm.totalPrice != null ? vm.totalPrice : null;
    return v;
  }

  function recalc() {
    var items = collectItems();
    if (!items.length) return;
    fetch(API_BASE + "/trial", {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json", Authorization: "Bearer " + jwt() },
      body: JSON.stringify({ items: items, order_amount: orderAmount(), scene: "host" }),
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.status !== 200) return;
        var d = res.data || {};
        state.trialYuan = d.total_yuan || "0.00";
        state.trialCoin = d.total_coin || "0.00";
        refreshPanel();
      })
      .catch(function () {});
  }

  /* 让页面重新调 configOption(触发补丁重算金额) */
  function triggerRecalc() {
    var el = document.querySelector(".settlement") || document.querySelector(".template");
    var vm = el && el.__vue__;
    if (vm) {
      var arr = vm.showGoodsList || vm.showList || vm.initArr || [];
      arr.forEach(function (it) {
        if (it && vm.getConfigOption) vm.getConfigOption(it);
      });
    }
  }

  /* ---------- 猴子补丁 ---------- */
  var patched = false;

  function applyPatch() {
    if (patched) return;
    patched = true;
    // configOption(id, params): 试算价格(勾选+互斥时置哨兵)
    if (typeof window.configOption === "function") {
      var _configOption = window.configOption;
      window.configOption = function (id, params) {
        if (state.use && state.enabled && !state.withPromo) {
          params = params || {};
          params.config_options = params.config_options || {};
          params.config_options.promo_code = SENTINEL;
        }
        return _configOption(id, params);
      };
    }
    // cart_settle(params)
    if (typeof window.cart_settle === "function") {
      var _cart = window.cart_settle;
      window.cart_settle = function (params) {
        params = params || {};
        if (state.use && state.enabled) {
          params.customfield = params.customfield || {};
          params.customfield.use_oycoin = 1;
          if (!state.withPromo) params.customfield.promo_code = SENTINEL;
        }
        return _cart(params);
      };
    }
    // product_settle(params): 表单直接购买
    if (typeof window.product_settle === "function") {
      var _prod = window.product_settle;
      window.product_settle = function (params) {
        params = params || {};
        if (state.use && state.enabled) {
          params.customfield = params.customfield || {};
          params.customfield.use_oycoin = 1;
          if (!state.withPromo) {
            params.customfield.promo_code = SENTINEL;
            params.config_options = params.config_options || {};
            params.config_options.promo_code = SENTINEL;
          }
        }
        return _prod(params);
      };
    }
  }

  /* ---------- 初始化 ---------- */
  function initOnSettlement() {
    if (!jwt()) return; // 未登录不注入
    apiGet("/index").then(function (res) {
      if (res.status !== 200) return;
      var d = res.data || {};
      var bal = d.balance || {};
      state.show = true;
      state.balance = bal.available || "0.00";
      state.coinName = d.coin_name || "平台币";
      state.withPromo = String(d.with_promo_code || "0") === "1";
      state.certified = d.certified !== 0;
      state.enabled = parseFloat(state.balance) > 0 && state.certified;
      // 等页面渲染完成再挂面板
      var tries = 0;
      var timer = setInterval(function () {
        tries++;
        if (mountPanel()) {
          clearInterval(timer);
          applyPatch();
          refreshPanel();
          if (state.use && state.enabled) recalc();
        } else if (tries > 40) {
          clearInterval(timer);
        }
      }, 250);
    }).catch(function () {});
  }

  function initOnCart() {
    if (!jwt()) return;
    apiGet("/index").then(function (res) {
      if (res.status !== 200) return;
      var d = res.data || {};
      var bal = d.balance || {};
      if (parseFloat(bal.available || 0) <= 0) return;
      // 购物车页:结算按钮附近插入轻提示条
      var tries = 0;
      var timer = setInterval(function () {
        tries++;
        var host = document.querySelector(".shoppingCar .el-main") || document.querySelector(".el-main");
        if (host && !document.getElementById("oycoin-cart-tip")) {
          var tip = document.createElement("div");
          tip.id = "oycoin-cart-tip";
          tip.style.cssText = "margin:6px 0;padding:8px 14px;background:#f0f7ff;border:1px solid #d4e5ff;border-radius:4px;font-size:12px;color:#555;";
          tip.innerHTML = "💡 您有 <b style='color:#0052d9;'>" + bal.available + " " + (d.coin_name || "平台币") + "</b> 可用,去结算页可勾选抵扣";
          host.insertBefore(tip, host.firstChild);
          clearInterval(timer);
        } else if (tries > 40) clearInterval(timer);
      }, 250);
    }).catch(function () {});
  }

  /* ---------- 商品购买页: "订购送"活动预告条 ---------- */
  function initOnGoods() {
    if (!jwt()) return;
    apiGet("/index").then(function (res) {
      if (res.status !== 200) return;
      var d = res.data || {};
      var promo = (d.promo || {}).order_buy;
      if (!promo) return;
      var pid = 0;
      var m = location.search.match(/[?&]id=(\d+)/);
      if (m) pid = parseInt(m[1]);
      if (!pid) return;
      // 范围校验: 不限商品 或 包含当前商品
      if (promo.product_scope === 1 && promo.product_ids && promo.product_ids.length && promo.product_ids.map(String).indexOf(String(pid)) === -1) return;
      var coinName = d.coin_name || "平台币";
      var txt = "";
      if (promo.grant_type === "ratio" && promo.percent > 0) {
        txt = "下单赠送实付金额" + promo.percent + "%的" + coinName;
      } else if (promo.gradient && promo.gradient.length) {
        txt = promo.gradient.map(function (g) { return "满" + g.full + "元送" + g.give; }).join("、");
      } else {
        return;
      }
      var tries = 0;
      var timer = setInterval(function () {
        tries++;
        if (document.getElementById("oycoin-goods-promo")) { clearInterval(timer); return; }
        var host = document.querySelector(".config-box") || document.querySelector(".el-main");
        if (!host) { if (tries > 40) clearInterval(timer); return; }
        var bar = document.createElement("div");
        bar.id = "oycoin-goods-promo";
        bar.style.cssText = "margin:0 0 12px;padding:10px 14px;border-radius:6px;background:#fff7e8;border:1px solid #ffe3ba;font-size:13px;color:#666;";
        bar.innerHTML = '<span style="color:#e37318;font-weight:600;">🎁 ' + coinName + '活动「' + promo.name + '」</span> ' +
          '<span>' + txt + '</span>' +
          (promo.end_time ? '<span style="color:#999;font-size:12px;margin-left:8px;">至 ' + new Date(promo.end_time * 1000).toLocaleDateString() + '</span>' : "");
        host.insertBefore(bar, host.firstChild);
        clearInterval(timer);
      }, 300);
    }).catch(function () {});
  }

  var path = location.pathname.toLowerCase();
  if (path.indexOf("/cart/settlement") !== -1 || path.indexOf("settlement.htm") !== -1) {
    initOnSettlement();
  } else if (path.indexOf("/cart/goods") !== -1 || path.indexOf("goods.htm") !== -1) {
    initOnGoods();
  } else if (path.indexOf("/cart/shoppingcar") !== -1 || path.indexOf("/cart") !== -1) {
    initOnCart();
  }
})();
