
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
  const loadedScriptSrcs = new Set();  // 已載入的腳本清單
  const loadedStyleHrefs = new Set();  // 已載入的樣式表清單

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
  window.loadSubpage = function loadSubpage(path) {
    if (!path || !path.startsWith(BASE_PREFIX)) return;

    // 卸載上一個 Vue App（若有）
    if (currentApp && typeof currentApp.unmount === 'function') {
      try { currentApp.unmount(); } catch (e) { }
      currentApp = null;
    }
    
    // 重置頁面初始化標記，允許重新初始化
    window._workDraftInitialized = false;
    window._workFormInitialized = false;
    window.__workDraftApiUrl = undefined;
    window.__workFormApiUrl = undefined;

    // 显示加载提示，保持容器高度避免跳动
    $(CONTENT_SEL).html('<div class="p-5 text-center text-secondary" style="min-height: 400px; display: flex; align-items: center; justify-content: center;">載入中…</div>');

    const callPageSpecificInit = (delay = 200) => {
      if (path && path.includes('work_draft') && typeof window.initWorkDraft === 'function') {
        setTimeout(() => {
          const filterForm = document.getElementById('filter-form');
          if (filterForm) {
            try {
              window.initWorkDraft();
            } catch (e) {
              console.error('Failed to init work-draft:', e);
            }
          }
        }, delay);
      }
      if (path && path.includes('work_form') && typeof window.initWorkForm === 'function') {
        setTimeout(() => {
          const formEl = document.getElementById('work-main-form');
          if (formEl) {
            try {
              window.initWorkForm();
            } catch (e) {
              console.error('Failed to init work-form:', e);
            }
          }
        }, delay);
      }
    };

    // 使用 fetch 載入頁面內容，然後手動處理
    fetch(path, { credentials: 'same-origin' })
      .then(res => {
        if (!res.ok) throw new Error(`HTTP ${res.status}: ${res.statusText}`);
        return res.text();
      })
      .then(html => {
        // 創建臨時容器來解析 HTML
        const tempDiv = document.createElement('html');
        tempDiv.innerHTML = html;
        const baseUrl = new URL(path, window.location.origin);
        
        // 提取 body 內容（如果有的話），否則使用整個 HTML
        let content = '';
        const bodyEl = tempDiv.querySelector('body');
        if (bodyEl) {
          // 如果有 body，提取其所有子元素
          content = bodyEl.innerHTML;
        } else {
          // 如果沒有 body，檢查是否有特定容器
          const partial = tempDiv.querySelector('#content-partial');
          if (partial) {
            content = partial.outerHTML;
          } else {
            // 否則使用整個內容（可能是片段）
            content = tempDiv.innerHTML;
          }
        }
        
        // 在插入前先提取所有 script 標籤與樣式
        const tempScriptContainer = document.createElement('div');
        tempScriptContainer.innerHTML = content;
        const styleLinks = Array.from(tempScriptContainer.querySelectorAll('link[rel~="stylesheet"]'));
        const scripts = Array.from(tempScriptContainer.querySelectorAll('script'));
        
        // 處理樣式：追加至 <head>，避免重複載入
        styleLinks.forEach(link => {
          const hrefAttr = link.getAttribute('href');
          if (!hrefAttr) return;
          try {
            const absHref = new URL(hrefAttr, baseUrl).href;
            if (!loadedStyleHrefs.has(absHref)) {
              loadedStyleHrefs.add(absHref);
              const newLink = document.createElement('link');
              newLink.rel = link.rel || 'stylesheet';
              newLink.href = absHref;
              if (link.media) newLink.media = link.media;
              if (link.crossOrigin) newLink.crossOrigin = link.crossOrigin;
              document.head.appendChild(newLink);
            }
          } catch (e) {
            console.error('Failed to process stylesheet:', hrefAttr, e);
          }
          link.remove();
        });

        // 移除 script 標籤後再插入內容
        scripts.forEach(script => script.remove());
        content = tempScriptContainer.innerHTML;
        
        // 插入內容（不包含 script 標籤）
        $(CONTENT_SEL).html(content);
        let loadedCount = 0;
        const totalScripts = scripts.length;
        
        if (totalScripts === 0) {
          // 如果沒有 script，直接初始化
          callPageSpecificInit(120);
          setTimeout(initAfterLoad, 100);
        } else {
          scripts.forEach((script) => {
            if (script.src) {
              // 外部腳本：動態載入
              const newScript = document.createElement('script');
              const srcAttr = script.getAttribute('src');
              let absSrc = srcAttr;
              try {
                absSrc = srcAttr ? new URL(srcAttr, baseUrl).href : script.src;
              } catch (e) {
                console.error('Failed to resolve script URL:', srcAttr, e);
              }

              if (absSrc) {
                if (loadedScriptSrcs.has(absSrc)) {
                  // 已載入，直接觸發初始化
                  loadedCount++;
                  callPageSpecificInit(120);
                  setTimeout(() => {
                    $(document).trigger('scriptExecuted', [path]);
                  }, 60);
                  if (loadedCount === totalScripts) {
                    setTimeout(initAfterLoad, 100);
                  }
                  script.remove();
                  return;
                }
                loadedScriptSrcs.add(absSrc);
                newScript.src = absSrc;
              } else {
                newScript.src = script.src;
              }
              newScript.async = false;
              
              // 等待腳本載入完成
              newScript.onload = function() {
                loadedCount++;
                callPageSpecificInit(150);
                // 腳本載入完成後，給一點時間執行，然後觸發事件
                setTimeout(() => {
                  $(document).trigger('scriptExecuted', [path]);
                }, 100);
                if (loadedCount === totalScripts) {
                  setTimeout(initAfterLoad, 100);
                }
              };
              newScript.onerror = function() {
                console.error('Failed to load script:', script.src);
                loadedCount++;
                callPageSpecificInit(200);
                if (loadedCount === totalScripts) {
                  setTimeout(initAfterLoad, 100);
                }
              };
              
              document.head.appendChild(newScript);
              script.remove();
            } else {
              // 內聯腳本：直接執行
              try {
                const scriptContent = script.textContent || script.innerHTML;
                if (scriptContent.trim()) {
                  // 使用 Function 構造函數更安全
                  (new Function(scriptContent))();
                  callPageSpecificInit(120);
                  // 執行後立即觸發事件
                  setTimeout(() => {
                    $(document).trigger('scriptExecuted', [path]);
                  }, 50);
                }
              } catch (e) {
                console.error('Script execution error:', e, script);
              }
              
              loadedCount++;
              script.remove();
              
              if (loadedCount === totalScripts) {
                setTimeout(initAfterLoad, 100);
              }
            }
          });
        }
      })
      .catch(err => {
        console.error('Load error:', err);
        $(CONTENT_SEL).html('<div class="alert alert-danger m-3">載入失敗：' + err.message + '</div>');
      });
    
    // 初始化函數
    function initAfterLoad() {
      callPageSpecificInit(150);

      // 确保 CSS 已加载后再显示内容（防止跑版）
      const userManagementContent = $(CONTENT_SEL).find('#userManagementContent');
      if (userManagementContent.length > 0) {
        // 等待 CSS 和布局稳定，使用双重 requestAnimationFrame 确保渲染完成
        requestAnimationFrame(function() {
          requestAnimationFrame(function() {
            // 再次等待，确保所有样式都已应用
            setTimeout(function() {
              userManagementContent.css('visibility', 'visible');
            }, 50);
          });
        });
      }

      // 子頁 DOM 進來後，跑共用初始化
      // 延迟初始化，确保布局稳定后再执行脚本
      setTimeout(function() {
        initPageScript();
        
        // 重新初始化 Bootstrap 下拉菜单（只初始化侧边栏外的，避免重复）
        // 注意：不要重复初始化已经在 DOMContentLoaded 中初始化的菜单
        if (window.bootstrap) {
          // 只初始化不在 .user-menu 中的下拉菜单（避免重复）
          $('.dropdown-toggle').not('.user-menu .dropdown-toggle').each(function() {
            // 先检查是否已经初始化
            const existing = bootstrap.Dropdown.getInstance(this);
            if (!existing) {
              bootstrap.Dropdown.getOrCreateInstance(this, {
                popperConfig: {
                  strategy: 'fixed'
                }
              });
            }
          });
        }
        
        // 手動觸發自定義事件，讓動態載入的頁面知道已經載入完成
        // 在 CONTENT_SEL 和 document 上都觸發，確保所有監聽器都能收到
        $(CONTENT_SEL).trigger('pageLoaded', [path]);
        $(document).trigger('pageLoaded', [path]);
        
        // 直接檢測並初始化特定頁面（不依賴事件系統）
        if (path && path.includes('work_draft')) {
          // 檢測 work-draft 頁面
          setTimeout(function() {
            const filterForm = document.getElementById('filter-form');
            if (filterForm && typeof window.initWorkDraft === 'function') {
              try {
                window.initWorkDraft();
              } catch (e) {
                console.error('Failed to init work-draft:', e);
              }
            } else if (filterForm && typeof initWorkDraft === 'function') {
              try {
                initWorkDraft();
              } catch (e) {
                console.error('Failed to init work-draft:', e);
              }
            }
          }, 300);
        }
        
        if (path && path.includes('work_form')) {
          // 檢測 work-form 頁面
          setTimeout(function() {
            const formEl = document.getElementById('work-main-form');
            if (formEl && typeof window.initWorkForm === 'function') {
              try {
                window.initWorkForm();
              } catch (e) {
                console.error('Failed to init work-form:', e);
              }
            } else if (formEl && typeof initWorkForm === 'function') {
              try {
                initWorkForm();
              } catch (e) {
                console.error('Failed to init work-form:', e);
              }
            }
          }, 300);
        }
      }, 150);

      // 依檔名呼叫對應的 render 函式（若有；建議子頁都用 <div id="app">）
      const fnName = filenameToRenderFn(path);
      const fn = window[fnName];
      if (typeof fn === 'function') {
        setTimeout(function() {
          const app = fn(`${CONTENT_SEL} #app`);
          if (app && typeof app.unmount === 'function') currentApp = app;
        }, 200);
      }

      // 選單高亮
      $('.ajax-link').removeClass('active').each(function () {
        if ($(this).attr('href') === path) $(this).addClass('active');
      });
    }
  };

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

    // 初始化导航栏中的用户菜单下拉（使用 Bootstrap 的标准方式）
    if (window.bootstrap && bootstrap.Dropdown) {
      const userMenuBtn = document.getElementById('userMenuBtn');
      if (userMenuBtn) {
        // 使用 Bootstrap 的标准初始化，确保下拉菜单正常工作
        bootstrap.Dropdown.getOrCreateInstance(userMenuBtn, {
          popperConfig: {
            strategy: 'fixed'
          }
        });
      }
    }

    // 「說明」子選單：滑過展開 / 移出關閉
    // 使用事件委托，避免重复绑定
    let submenuHandlersBound = false;
    function initSubmenuHandlers() {
      if (submenuHandlersBound) return;
      
      document.querySelectorAll('.dropdown-submenu').forEach(el => {
        // 移除旧的事件监听器（如果存在）
        const newEl = el.cloneNode(true);
        el.parentNode.replaceChild(newEl, el);
        
        newEl.addEventListener('mouseenter', function(e) {
          e.stopPropagation();
          const toggle = this.querySelector('[data-bs-toggle="dropdown"]');
          if (!toggle || !window.bootstrap || !bootstrap.Dropdown) return;
          const dd = bootstrap.Dropdown.getOrCreateInstance(toggle, { 
            autoClose: false, 
            popperConfig: { strategy: 'fixed' } 
          });
          dd.show();
        });
        
        newEl.addEventListener('mouseleave', function(e) {
          e.stopPropagation();
          const toggle = this.querySelector('[data-bs-toggle="dropdown"]');
          const dd = (toggle && window.bootstrap) ? bootstrap.Dropdown.getInstance(toggle) : null;
          if (dd) dd.hide();
        });
      });
      
      submenuHandlersBound = true;
    }
    
    initSubmenuHandlers();

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