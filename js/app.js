
(function () {
  // ========================
  // 全域 API 設定（未合併 storage 前先用 upload.php；日後改下一行即可）
  // ========================
  window.API_UPLOAD_URL = 'pages/somefunction/upload.php';            // ← 之後合併時改成 'api.php?do=upload'
  window.API_LIST_URL = 'api.php?do=listActiveFiles';
// js/app.js (或你全站共用的那支)
window.switchIdentity = async function(role_ID, role_name, cohort_ID, year_label, cohort_name) {
  try {
    const formData = new FormData();
    formData.append('role_ID', role_ID);
    formData.append('role_name', role_name);

    formData.append('cohort_ID', (cohort_ID === null || cohort_ID === undefined) ? '' : cohort_ID);
    formData.append('year_label', year_label || '');
    formData.append('cohort_name', cohort_name || '');

    const res = await fetch('api.php?do=role_session', { method: 'POST', body: formData });
    const data = await res.json();

    if (data.ok) location.reload();
    else alert(data.msg || '切換失敗');
  } catch (e) {
    console.error(e);
    alert('切換失敗（網路或伺服器錯誤）');
  }
};

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
  // 各子頁初始化入口（不一定用 Vue 的頁面也走這裡）
  // ========================
  function initPageScript(filename) {
    // 帳號管理頁
    if (filename.includes('admin_usermanage.php') && window.renderAdminUsermanagePage) {
      window.renderAdminUsermanagePage();
    }

    // 狀態管理頁
    if (filename.includes('admin_statusmanage.php') && window.renderStatusManagePage) {
      window.renderStatusManagePage();
    }

    // 公告管理頁（admin_notify 使用獨立 init，避免覆寫導致其他頁面異常）
    if (filename.includes('admin_notify.php') && typeof window.initAdminNotifyPage === 'function') {
      window.initAdminNotifyPage();
    }
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
            const res = await fetch(window.API_UPLOAD_URL, { method: 'POST', body: fd });
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
  // 通用的 Vue App 清理函數
  // 注意：只清理內容區域的應用，不影響 sidebar
  // ========================
  function cleanupVueApps() {
    // 清理 currentApp（由 render 函式返回的）
    if (currentApp && typeof currentApp.unmount === 'function') {
      try {
        currentApp.unmount();
      } catch (e) {
        console.warn('清理 currentApp 時出錯:', e);
      }
      currentApp = null;
    }

    // 只清理內容區域中的 Vue 應用實例
    // 確保不影響 sidebar 中的任何內容
    try {
      const contentVueApps = document.querySelectorAll(`${CONTENT_SEL} [data-v-app]`);
      contentVueApps.forEach(el => {
        // 如果元素有 Vue 實例，嘗試卸載（但這通常不需要，因為我們已經清理了 currentApp）
      });
    } catch (e) {
      // 忽略錯誤
    }

    // 觸發自定義事件，讓各個頁面自己清理
    // 注意：這個事件只應該被內容區域的頁面監聽，不應該影響 sidebar
    const unloadEvent = new CustomEvent('pageBeforeUnload', {
      detail: { path: location.hash },
      bubbles: false // 不冒泡，避免影響 sidebar
    });
    // 只在內容區域觸發事件
    const contentEl = document.querySelector(CONTENT_SEL);
    if (contentEl) {
      contentEl.dispatchEvent(unloadEvent);
    }
  }

  // ========================
  // 通用子頁載入器（hash 控制）
  // ========================
  window.loadSubpage = function loadSubpage(path) {
    if (!path || !path.startsWith(BASE_PREFIX)) return;

    // ⭐ 切換頁面時立即清理 modal 狀態，避免殘留 backdrop 覆蓋畫面
    document.querySelectorAll('.modal-backdrop').forEach(function (b) { b.remove(); });
    document.body.classList.remove('modal-open');
    document.body.style.overflow = '';
    document.body.style.paddingRight = '';

    // 先觸發清理事件，讓各個頁面自己清理（包括重置初始化標記）
    cleanupVueApps();

    // 清理頁面特定的初始化標記，允許重新初始化
    if (window.adminFormManage) {
      window.adminFormManage.initialized = false;
    }

    // 清理內容區域中之前載入的 script 標籤，避免重複載入和變數衝突
    const contentEl = document.querySelector(CONTENT_SEL);
    if (contentEl) {
      const oldScripts = contentEl.querySelectorAll('script[src]');
      oldScripts.forEach(script => {
        // 移除 script 標籤
        script.remove();
      });
    }

    // ⭐ 立即設定頁面專用 body class（在載入內容前），避免 team_change 等頁面載入後才套用導致版面大挪動
    // document.body.classList.remove('page-team-change', 'page-integrate');
    // if (path.indexOf('team_change') !== -1) {
    //   document.body.classList.add('page-team-change');
    // } else if (path.indexOf('integrate') !== -1) {
    //   document.body.classList.add('page-integrate');
    // }

    // 顯示載入提示，保持容器高度避免跳動（team_change 等頁面使用與內容區一致高度減少版面位移）
    // const loadingMinHeight = path.indexOf('team_change') !== -1 ? 'calc(100vh - var(--navbar-height, 56px))' : '400px';
    // $(CONTENT_SEL).html(
    //   '<div class="p-5 text-center text-secondary" ' +
    //   'style="min-height: ' + loadingMinHeight + '; display: flex; align-items: center; justify-content: center;">' +
    //   '載入中…</div>'
    // );

    // 加上 cache-bust 參數，避免瀏覽器快取導致修改後的 PHP 內容未更新
    const loadUrl = path + (path.indexOf('?') >= 0 ? '&' : '?') + '_=' + Date.now();
    $(CONTENT_SEL).load(loadUrl, function (response, status, xhr) {
      if (status === 'error') {
        $(CONTENT_SEL).html(
          '<div class="alert alert-danger m-3">載入失敗：' +
          (xhr?.status || '') + ' ' + (xhr?.statusText || '') +
          '</div>'
        );
        return;
      }

      // 移除黃色偵錯區塊（u_ID、role_ID、resolved team_ID）
      $(CONTENT_SEL).find('[class*="alert"], [class*="debug"], div').each(function () {
        const txt = $(this).text() || '';
        if (txt.indexOf('u_ID-') !== -1 && txt.indexOf('role_ID-') !== -1 && txt.indexOf('resolved team_ID') !== -1) {
          $(this).remove();
        }
      });

      // 針對 project_browse.php 做特殊處理，防止閃爍和移除留白
      const projectBrowseContainer = $(CONTENT_SEL).find('.project-browse-container');
      if (projectBrowseContainer.length > 0) {
        // 移除 content 容器的留白
        $(CONTENT_SEL).addClass('project-browse-page');
        $(CONTENT_SEL).css({
          'padding': '0',
          'padding-left': '0',
          'padding-right': '0'
        });
        // 確保容器保持隱藏狀態
        projectBrowseContainer.css({
          'opacity': '0',
          'visibility': 'hidden'
        });

        // 等待 CSS 和 JS 都準備好後再顯示
        function showProjectBrowse() {
          // 使用雙重 requestAnimationFrame 確保樣式已渲染
          requestAnimationFrame(function () {
            requestAnimationFrame(function () {
              projectBrowseContainer.addClass('loaded');
            });
          });
        }

        // 檢查 CSS 是否已載入
        let cssCheckCount = 0;
        const maxChecks = 50; // 最多檢查 50 次（約 500ms）

        const checkCSS = setInterval(function () {
          cssCheckCount++;
          let cssFound = false;

          try {
            const stylesheets = document.styleSheets;
            for (let i = 0; i < stylesheets.length; i++) {
              try {
                const href = stylesheets[i].href || '';
                if (href.includes('project_browse.css')) {
                  cssFound = true;
                  break;
                }
              } catch (e) {
                // 跨域樣式表可能拋出錯誤，忽略
              }
            }
          } catch (e) {
            // 忽略錯誤
          }

          // 如果找到 CSS 或達到最大檢查次數，顯示容器
          if (cssFound || cssCheckCount >= maxChecks) {
            clearInterval(checkCSS);
            // 等待一下讓 CSS 完全應用
            setTimeout(showProjectBrowse, 50);
          }
        }, 10);
      }

      // 確保 CSS 已加載後再顯示內容（防止跑版）
      const userManagementContent = $(CONTENT_SEL).find('#userManagementContent');
      if (userManagementContent.length > 0) {
        requestAnimationFrame(function () {
          requestAnimationFrame(function () {
            setTimeout(function () {
              userManagementContent.css('visibility', 'visible');
            }, 50);
          });
        });
      }

      // 子頁 DOM 進來後，跑共用初始化
      setTimeout(function () {
        if (typeof window.initCommonPageScript === 'function') {
          window.initCommonPageScript();
        }

        // 重新初始化 Bootstrap 下拉（只處理內容區域）
        if (window.bootstrap) {
          $(CONTENT_SEL).find('.dropdown-toggle').each(function () {
            if (!(this instanceof Node)) return;
            if ($(this).closest('#layoutSidenav_nav, #sidenavAccordion, nav, .sb-sidenav').length > 0) {
              return; // 跳過 sidebar/nav
            }
            try {
              const existing = bootstrap.Dropdown.getInstance(this);
              if (!existing) {
                bootstrap.Dropdown.getOrCreateInstance(this, {
                  popperConfig: { strategy: 'fixed' }
                });
              }
            } catch (e) {
              console.warn('初始化 dropdown 時發生錯誤:', e);
            }
          });
        }
      }, 150);

      // 依檔名呼叫對應的 render 函式（若有；建議子頁都用 <div id="app">）
      const fnName = filenameToRenderFn(path);
      const fn = window[fnName];
      if (typeof fn === 'function') {
        const app = fn(`${CONTENT_SEL} #app`);
        if (app && typeof app.unmount === 'function') {
          currentApp = app;
        }
      }

      // 清理重複的 script 標籤（避免同一個腳本被載入多次）
      const contentEl = document.querySelector(CONTENT_SEL);
      if (contentEl) {
        const scripts = contentEl.querySelectorAll('script[src]');
        const seenSrcs = new Set();
        scripts.forEach(script => {
          const src = script.getAttribute('src');
          if (src && seenSrcs.has(src)) {
            // 如果已經載入過這個腳本，移除重複的標籤
            script.remove();
          } else if (src) {
            seenSrcs.add(src);
          }
        });
      }

      // ⭐ 讓各頁自己的初始化跑起來（統一由 initPageScript 依檔名路由，避免子頁覆寫 window.initPageScript 導致切頁後執行錯誤）
      const parts = path.split('/');
      const filename = parts[parts.length - 1];
      if (typeof initPageScript === 'function') {
        initPageScript(filename);
      }

      // 選單高亮（只更新 sidebar 中的連結，不影響內容區域）
      $('#layoutSidenav_nav .ajax-link, #sidenavAccordion .ajax-link, .sb-sidenav .ajax-link')
        .removeClass('active')
        .each(function () {
          const href = $(this).attr('href');
          if (href === path || href === '#' + path) {
            $(this).addClass('active');

            // 如果是在子選單中，確保父選單也是展開狀態
            const parentSubmenu = $(this).closest('.dropdown-submenu');
            if (parentSubmenu.length > 0) {
              parentSubmenu.addClass('active');
              const toggle = parentSubmenu.find('[data-bs-toggle="dropdown"]');
              if (toggle.length > 0 && window.bootstrap) {
                try {
                  const dropdown = bootstrap.Dropdown.getInstance(toggle[0]);
                  if (dropdown && !dropdown._isShown()) {
                    dropdown.show();
                  }
                } catch (e) {
                  // 忽略錯誤
                }
              }
            }
          }
        });

      // 觸發頁面載入完成事件，讓各頁面可以自行初始化
      // 使用 setTimeout 確保 DOM 和腳本都已載入
      setTimeout(function () {
        const event = new CustomEvent('page:loaded', {
          detail: { path: path }
        });
        document.dispatchEvent(event);

        // 同時觸發 jQuery 事件（向後兼容）
        $(document).trigger('pageLoaded', [path]);
      }, 200);
    });
  };


  // ========================
  // 共用初始化（事件委派、SweetAlert 等）
  // 注意：所有操作都應該只針對內容區域，不影響 sidebar
  // ========================
  function initCommonPageScript() {

  }

  window.initCommonPageScript = initCommonPageScript;
  // 攔截 .ajax-link（含 dropdown 裡的）
  // 使用事件委派，但確保點擊 sidebar 中的連結時正常工作
  $(document).on("click", "a.ajax-link", function (e) {
    e.preventDefault();
    const url = $(this).attr("href");
    // 只更新 hash，不影響 sidebar
    window.location.hash = url; // 觸發 hashchange → loadSubpage
    // 確保 sidebar 保持不變
    return false;
  });

  // 監聽 hash 改變
  window.addEventListener('hashchange', function () {
    loadSubpage(location.hash.slice(1));
  });

  // ========================
  // 使用者卡 dropdown 修正（z-index / transform）+ 次選單滑過展開
  // ========================
  document.addEventListener('DOMContentLoaded', () => {
    // 側邊欄收合 - 只綁定到 navbar 中的按鈕，確保不會被 content 區域的按鈕觸發
    // 使用更嚴格的選擇器，確保只選擇 navbar 中的按鈕
    const btn = document.querySelector("nav.navbar #sidebarToggle");
    if (btn) {
      // 移除舊的事件監聽器（如果存在）
      const newBtn = btn.cloneNode(true);
      btn.parentNode.replaceChild(newBtn, btn);

      newBtn.addEventListener("click", function (e) {
        // 確保事件來自這個按鈕本身，而不是其他元素
        if (e.target !== this && !this.contains(e.target)) {
          return;
        }
        e.preventDefault();
        e.stopPropagation(); // 阻止事件冒泡
        e.stopImmediatePropagation(); // 阻止同一元素上的其他監聽器
        document.body.classList.toggle("sb-sidenav-toggled");
      }, true); // 使用捕獲階段，優先處理
    }

    // 初始化导航栏中的用户菜单下拉（使用 Bootstrap 的标准方式）
    if (window.bootstrap && bootstrap.Dropdown) {
      const userMenuBtn = document.getElementById('userMenuBtn');
      if (userMenuBtn && userMenuBtn instanceof Node) {
        try {
          // 使用 Bootstrap 的标准初始化，确保下拉菜单正常工作  
          bootstrap.Dropdown.getOrCreateInstance(userMenuBtn, {
            popperConfig: {
              strategy: 'fixed'
            }
          });
        } catch (e) {
          console.warn('初始化使用者選單 dropdown 時發生錯誤:', e);
        }
      }
    }

    // 「說明」子選單：滑過展開 / 移出關閉
    // 使用事件委托，避免重复绑定
    let submenuHandlersBound = false;
    function initSubmenuHandlers() {
      if (submenuHandlersBound) return;

      document.querySelectorAll('.dropdown-submenu').forEach(el => {
        if (!(el instanceof Node) || !el.parentNode) return;

        try {
          // 移除旧的事件监听器（如果存在）
          const newEl = el.cloneNode(true);
          el.parentNode.replaceChild(newEl, el);

          newEl.addEventListener('mouseenter', function (e) {
            e.stopPropagation();
            const toggle = this.querySelector('[data-bs-toggle="dropdown"]');
            if (!toggle || !(toggle instanceof Node) || !window.bootstrap || !bootstrap.Dropdown) return;
            try {
              // 解法 3：使用 absolute 而不是 fixed，防止創建覆蓋層
              const dd = bootstrap.Dropdown.getOrCreateInstance(toggle, {
                autoClose: false,
                popperConfig: { strategy: 'absolute' } // 改為 absolute，防止 fixed 定位造成覆蓋層
              });
              dd.show();
            } catch (err) {
              console.warn('顯示 dropdown 時發生錯誤:', err);
            }
          });

          newEl.addEventListener('mouseleave', function (e) {
            e.stopPropagation();
            const toggle = this.querySelector('[data-bs-toggle="dropdown"]');
            if (!toggle || !(toggle instanceof Node) || !window.bootstrap) return;
            try {
              const dd = bootstrap.Dropdown.getInstance(toggle);
              if (dd) dd.hide();
            } catch (err) {
              console.warn('隱藏 dropdown 時發生錯誤:', err);
            }
          });
        } catch (err) {
          console.warn('處理 dropdown-submenu 時發生錯誤:', err);
        }
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

    // 清理所有殘留的覆蓋層（modal backdrop, offcanvas backdrop, team-modal-overlay 等）
    function cleanupAllOverlays() {
      // 檢查是否有 modal 打開（Bootstrap 5 使用 .show 或 display:flex）
      const hasOpenModal = document.querySelector('.modal.show, .modal[style*="display: block"], .modal[style*="display: flex"]');

      if (!hasOpenModal) {
        // 沒有打開的 modal，移除所有 modal backdrop
        document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
          backdrop.remove();
        });
        // 移除 modal-open class
        document.body.classList.remove('modal-open');
      }

      // 檢查是否有 offcanvas 打開
      const hasOpenOffcanvas = document.querySelector('.offcanvas.show, .offcanvas.showing');

      if (!hasOpenOffcanvas) {
        // 沒有打開的 offcanvas，移除所有 offcanvas backdrop
        document.querySelectorAll('.offcanvas-backdrop').forEach(backdrop => {
          backdrop.remove();
        });
      }

      // 清理 team-modal-overlay（如果沒有 active class）
      document.querySelectorAll('.team-modal-overlay:not(.active)').forEach(overlay => {
        overlay.style.display = 'none';
        overlay.style.opacity = '0';
        overlay.style.pointerEvents = 'none';
        overlay.style.visibility = 'hidden';
      });

      // 恢復 body 的 overflow 和 padding
      if (!hasOpenModal && !hasOpenOffcanvas) {
        document.body.style.overflow = '';
        document.body.style.paddingRight = '';
      }
    }

    // 監聽 modal 事件，確保 backdrop 正確清理
    document.addEventListener('hidden.bs.modal', function () {
      cleanupAllOverlays();
    });

    // 監聽 offcanvas 事件
    document.addEventListener('hidden.bs.offcanvas', function () {
      cleanupAllOverlays();
    });

    // 定期檢查並清理殘留的覆蓋層（每 500ms 檢查一次）
    setInterval(cleanupAllOverlays, 500);

    // 初始清理
    cleanupAllOverlays();

    // 監聽頁面可見性變化，當頁面重新可見時清理
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) {
        cleanupAllOverlays();
      }
    });
  });
})();