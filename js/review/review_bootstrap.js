//review/review_bootstrap.js
(function () {
  // 讀 base 路徑（你如果 main.php 有 <head name="app-base" content="/original/projecteveryday/"> 就能吃到）
  function getBase() {
    const meta = document.querySelector('head[name="app-base"]');
    const v = meta?.getAttribute('content') || '/';
    return v.endsWith('/') ? v : (v + '/');
  }

  window.APP_BASE = window.APP_BASE || getBase();

  // ✅ 關鍵：credentials: 'same-origin' 讓 cookie/session 會送到 PHP
  window.apiFetch = async function apiFetch(doName, options = {}) {
    const url = `${window.APP_BASE}api.php?do=${encodeURIComponent(doName)}` +
      (options.query ? `&${new URLSearchParams(options.query).toString()}` : '');

    const fetchOpt = {
      method: options.method || 'GET',
      credentials: 'same-origin',
      headers: options.headers || {},
      body: options.body || undefined,
    };

    // JSON body
    if (options.json) {
      fetchOpt.method = options.method || 'POST';
      fetchOpt.headers = { ...fetchOpt.headers, 'Content-Type': 'application/json' };
      fetchOpt.body = JSON.stringify(options.json);
    }

    // FormData body
    if (options.formData) {
      fetchOpt.method = options.method || 'POST';
      fetchOpt.body = options.formData;
    }

    const res = await fetch(url, fetchOpt);

    // 這裡不要直接 res.json()，因為 PHP 500 或空回應會爆
    const text = await res.text();
    let data;
    try { data = JSON.parse(text); }
    catch { throw new Error(`API 回傳不是 JSON：${text.slice(0, 180)}`); }

    if (!res.ok || data?.ok === false) {
      const msg = data?.msg || data?.message || `HTTP ${res.status}`;
      const code = data?.code || 'UNKNOWN';
      const err = new Error(`${msg} (${code})`);
      err.code = code;
      err.data = data;
      throw err;
    }
    return data;
  };

  // SPA：頁面載入後掛載
  window.mountTeamApplyReview = function mountTeamApplyReview() {
    if (!window.Vue) {
      console.error('Vue 未載入：請確認 head.php 有引入 vue.global.js');
      return;
    }
    if (!document.querySelector('#reviewApp')) return;

    // 讓 app.js 來真正 mount（避免重複 mount）
    if (window.__TEAM_REVIEW_MOUNTED__) return;
    window.__TEAM_REVIEW_MOUNTED__ = true;

    // 交給 app.js 的 global 方法
    if (typeof window.createTeamApplyReviewApp === 'function') {
      window.createTeamApplyReviewApp();
    } else {
      console.error('createTeamApplyReviewApp 不存在：請確認 team_apply_review.app.js 已載入');
    }
  };
})();
