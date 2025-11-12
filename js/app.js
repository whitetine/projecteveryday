
//   window.API_UPLOAD_URL = 'pages/somefunction/upload.php';
//   window.API_LIST_URL   = 'api.php?do=listActiveFiles';

//   const CONTENT_SEL = '#content';
//   const BASE_PREFIX = 'pages/';
//   let currentApp = null;

//   function filenameToRenderFn(filePath) {
//     const base   = filePath.replace(/^.*\//, '').replace(/\.php.*/, '');
//     const pascal = base.replace(/(^|[_-])(\w)/g, (_, __, c) => c.toUpperCase());
//     return 'render' + pascal + 'Page';
//   }

//   // 共用初始化（原本的那段）
//   window.initCommonPageScript = function () {
//     $(document).off("click", ".toggle-btn").on("click", ".toggle-btn", function() {
//       const acc    = $(this).data("acc");
//       const status = $(this).data("status");
//       const action = $(this).data("action");
//       if (window.Swal) {
//         Swal.fire({
//           title: `是否要${action}此帳號？`,
//           icon: "warning",
//           showCancelButton: true,
//           confirmButtonText: `是，${action}`,
//           cancelButtonText: "取消",
//           reverseButtons: true
//         }).then((r) => {
//           if (r.isConfirmed) location.href = `pages/somefunction/toggle_user.php?acc=${acc}&status=${status}`;
//         });
//       } else if (confirm(`是否要${action}此帳號？`)) {
//         location.href = `pages/somefunction/toggle_user.php?acc=${acc}&status=${status}`;
//       }
//     });
//   };

//   function loadSubpage(path) {
//     if (!path) return;
//     // 卸載上一個 Vue app（如有）
//     if (currentApp && typeof currentApp.unmount === 'function') {
//       try { currentApp.unmount(); } catch(e){}
//       currentApp = null;
//     }

//     $(CONTENT_SEL).html('<div class="p-5 text-center text-secondary">載入中…</div>');

//     $(CONTENT_SEL).load(path, function(response, status, xhr) {
//       if (status === 'error') {
//         $(CONTENT_SEL).html('<div class="alert alert-danger m-3">載入失敗：' + (xhr?.status||'') + ' ' + (xhr?.statusText||'') + '</div>');
//         return;
//       }

//       // 共用初始化
//       if (typeof window.initCommonPageScript === 'function') window.initCommonPageScript();

//       // 呼叫該頁自己的 init（若那頁有定義）
//       if (typeof window.initPageScript === 'function') window.initPageScript();

//       // 若該頁有 Vue 渲染函式（選擇性）
//       const fnName = filenameToRenderFn(path);
//       const fn = window[fnName];
//       if (typeof fn === 'function') {
//         const app = fn(`${CONTENT_SEL} #app`);
//         if (app && typeof app.unmount === 'function') currentApp = app;
//       }

//       // 選單高亮
//       $('.ajax-link').removeClass('active').each(function () {
//         if ($(this).attr('href') === path || ('#'+$(this).attr('href')) === location.hash) $(this).addClass('active');
//       });
//     });
//   }

//   // 攔截 .ajax-link
//   $(document).on("click", "a.ajax-link", function(e) {
//     e.preventDefault();
//     const href = $(this).attr("href") || '';
//     if (href.startsWith('#')) {
//       location.hash = href.slice(1).startsWith('pages/') ? href : href; // 保留你的寫法
//     } else {
//       location.hash = href; // 統一走 hash
//     }
//   });

//   // 路由：把 hash 轉成要載的檔案
//   function routeFromHash() {
//     let h = location.hash.replace(/^#/, '');
//     if (!h) return;
//     // 支援兩種格式：#pages/admin_notify.php 或 #admin_notify
//     if (!h.startsWith('pages/')) {
//       switch (h) {
//         case 'admin_notify': h = 'pages/admin_notify.php'; break;
//         // 其他頁對應... 
//       }
//     }
//     loadSubpage(h);
//   }
//   window.addEventListener('hashchange', routeFromHash);

//   // 初次進站
//   document.addEventListener('DOMContentLoaded', () => {
//     // 側邊欄收合
//     const btn = document.getElementById("sidebarToggle");
//     if (btn) btn.addEventListener("click", (e) => { e.preventDefault(); document.body.classList.toggle("sb-sidenav-toggled"); });

//     // 使用者下拉 fixed
//     document.querySelectorAll('.user-menu .dropdown-toggle').forEach(btn => {
//       if (!window.bootstrap || !bootstrap.Dropdown) return;
//       bootstrap.Dropdown.getOrCreateInstance(btn, {
//         autoClose: 'outside',
//         popperConfig: (d) => ({ ...d, strategy: 'fixed' })
//       });
//     });

//     // 次選單 hover
//     document.querySelectorAll('.dropdown-submenu').forEach(el => {
//       el.addEventListener('mouseenter', () => {
//         const t = el.querySelector('[data-bs-toggle="dropdown"]');
//         if (!t || !window.bootstrap) return;
//         bootstrap.Dropdown.getOrCreateInstance(t, { autoClose: false, popperConfig: { strategy: 'fixed' }}).show();
//       });
//       el.addEventListener('mouseleave', () => {
//         const t = el.querySelector('[data-bs-toggle="dropdown"]');
//         const dd = (t && window.bootstrap) ? bootstrap.Dropdown.getInstance(t) : null;
//         if (dd) dd.hide();
//       });
//     });

//     // 若有 hash 就載入
//     if (location.hash) routeFromHash();
//   });
// })();
(function () {
  // ========================
  // 全域 API 設定（未合併 storage 前先用 upload.php；日後改下一行即可）
  // ========================
  window.API_UPLOAD_URL = 'pages/somefunction/upload.php';            // ← 之後合併時改成 'api.php?do=upload'
  window.API_LIST_URL = 'api.php?do=listActiveFiles';

  // ========================
  // Router 共用
  // ========================
  const CONTENT_SEL = '#content';      // 主內容容器
  const BASE_PREFIX = 'pages/';        // 受控子頁的前綴
  let currentApp = null;               // 若子頁用 Vue，這裡接住以便換頁時 unmount

  // 檔名 -> render 函式名：apply.php -> renderApplyPage
  function filenameToRenderFn(filePath) {
    const base = filePath.replace(/^.*\//, '').replace(/\.php.*/, '');
    const pascal = base.replace(/(^|[_-])(\w)/g, (_, __, c) => c.toUpperCase());
    return 'render' + pascal + 'Page';
  }

  // ========================
  // 子頁若用到 Vue 的初始化（範例：apply.php）
  // 命名規則：render + 檔名帕斯卡 + Page
  // ========================
  window.renderApplyPage = function (mountSel) {
    const mountEl = document.querySelector(mountSel) || document.querySelector('#app');
    if (!mountEl || !window.Vue) return;
    const { createApp } = Vue;

    const app = createApp({
data() {
  return {
    files: [],
    selectedFileID: '',
    applyOther: '',
    imagePreview: null,
    previewPercent: 60,
    applyUserId: ''   // 後端要的 u_ID（varchar）
  };
},
computed: {
  selectedFileUrl() {
    const f = this.files.find(x => String(x.file_ID) === String(this.selectedFileID));
    return f ? f.file_url : '';
  }
},
methods: {
  previewImage(e) {
    const f = e.target.files?.[0];
    this.imagePreview = (f && f.type.startsWith('image/'))
      ? URL.createObjectURL(f)
      : null;
  },
  async submitForm() {
    const formEl = document.getElementById('applyForm');   // ← 表單一定要有這個 id
    const fd = new FormData(formEl);                       // ✅ 直接把所有欄位（含 hidden）打包

    try {
      const res  = await fetch(window.API_UPLOAD_URL, { method: 'POST', body: fd });
      const text = await res.text();
      const data = JSON.parse(text);
      if (data.status !== 'success') throw new Error(data.message || '上傳失敗');

      Swal.fire('成功', '申請已送出！', 'success');

      // reset UI
      formEl.reset();
      this.selectedFileID = '';
      this.applyOther = '';
      this.imagePreview = null;
      this.previewPercent = 60;
    } catch (err) {
      Swal.fire('錯誤', String(err?.message || err), 'error');
    }
  }
},
mounted() {
  // 從頁面注入的全域變數拿 u_ID（字串）
  this.applyUserId = window.CURRENT_USER?.u_ID || '';

  // 載入表單清單（若你的下拉是用 v-for）
  fetch(window.API_LIST_URL)
    .then(r => r.json())
    .then(arr => { if (Array.isArray(arr)) this.files = arr; });
}

    });

    app.mount(mountEl);
    return app; // 讓 Router 能在換頁時 unmount
  };

  // ========================
  // 通用子頁載入器（hash 控制）
  // ========================
  function loadSubpage(path) {
    if (!path || !path.startsWith(BASE_PREFIX)) return;

    // 卸載上一個 Vue App（若有）
    if (currentApp && typeof currentApp.unmount === 'function') {
      try { currentApp.unmount(); } catch (e) { }
      currentApp = null;
    }

    $(CONTENT_SEL).html('<div class="p-5 text-center text-secondary">載入中…</div>');

    $(CONTENT_SEL).load(path, function (response, status, xhr) {
      if (status === 'error') {
        $(CONTENT_SEL).html('<div class="alert alert-danger m-3">載入失敗：' + (xhr?.status || '') + ' ' + (xhr?.statusText || '') + '</div>');
        return;
      }

      // 子頁 DOM 進來後，跑共用初始化
      initPageScript();

      // 依檔名呼叫對應的 render 函式（若有；建議子頁都用 <div id="app">）
      const fnName = filenameToRenderFn(path);
      const fn = window[fnName];
      if (typeof fn === 'function') {
        const app = fn(`${CONTENT_SEL} #app`);
        if (app && typeof app.unmount === 'function') currentApp = app;
      }

      // 選單高亮
      $('.ajax-link').removeClass('active').each(function () {
        if ($(this).attr('href') === path) $(this).addClass('active');
      });
    });
  }

  // ========================
  // 共用初始化（事件委派、SweetAlert 等）
  // ========================
  function initPageScript() {
    // 啟/停用帳號（委派）
    $(document).off("click", ".toggle-btn").on("click", ".toggle-btn", function () {
      const acc = $(this).data("acc");
      const status = $(this).data("status");
      const action = $(this).data("action");

      if (window.Swal) {
        Swal.fire({
          title: `是否要${action}此帳號？`,
          icon: "warning",
          showCancelButton: true,
          confirmButtonText: `是，${action}`,
          cancelButtonText: "取消",
          reverseButtons: true
        }).then((r) => {
          if (r.isConfirmed) {
            location.href = `pages/somefunction/toggle_user.php?acc=${acc}&status=${status}`;
          }
        });
      } else {
        if (confirm(`是否要${action}此帳號？`)) {
          location.href = `pages/somefunction/toggle_user.php?acc=${acc}&status=${status}`;
        }
      }
    });
  }

  // 攔截 .ajax-link（含 dropdown 裡的）
  $(document).on("click", "a.ajax-link", function (e) {
    e.preventDefault();
    const url = $(this).attr("href");
    window.location.hash = url; // 觸發 hashchange → loadSubpage
  });

  // 監聽 hash 改變
  window.addEventListener('hashchange', function () {
    loadSubpage(location.hash.slice(1));
  });

  // ========================
  // 使用者卡 dropdown 修正（z-index / transform）+ 次選單滑過展開
  // ========================
  document.addEventListener('DOMContentLoaded', () => {
    // 側邊欄收合
    const btn = document.getElementById("sidebarToggle");
    if (btn) btn.addEventListener("click", (e) => { e.preventDefault(); document.body.classList.toggle("sb-sidenav-toggled"); });

    // 對「使用者卡」的 dropdown 啟用 fixed 策略（避免父層 transform 影響疊層）
    document.querySelectorAll('.user-menu .dropdown-toggle').forEach(btn => {
      if (!window.bootstrap || !bootstrap.Dropdown) return;
      bootstrap.Dropdown.getOrCreateInstance(btn, {
        autoClose: 'outside',
        popperConfig: (defaultConfig) => ({ ...defaultConfig, strategy: 'fixed' })
      });
    });

    // 「說明」子選單：滑過展開 / 移出關閉
    document.querySelectorAll('.dropdown-submenu').forEach(el => {
      el.addEventListener('mouseenter', () => {
        const toggle = el.querySelector('[data-bs-toggle="dropdown"]');
        if (!toggle || !window.bootstrap || !bootstrap.Dropdown) return;
        const dd = bootstrap.Dropdown.getOrCreateInstance(toggle, { autoClose: false, popperConfig: { strategy: 'fixed' } });
        dd.show();
      });
      el.addEventListener('mouseleave', () => {
        const toggle = el.querySelector('[data-bs-toggle="dropdown"]');
        const dd = (toggle && window.bootstrap) ? bootstrap.Dropdown.getInstance(toggle) : null;
        if (dd) dd.hide();
      });
    });

    // 首次進站：有 hash 就載入；沒有就保持空白或自行指定預設頁
    const initial = location.hash.slice(1);
    if (initial) {
      loadSubpage(initial);
    } else {
      // 想指定預設頁就解除下面註解：
      // window.location.hash = 'pages/admin_usermanage.php';
    }
  });
})();