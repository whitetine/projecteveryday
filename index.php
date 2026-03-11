<!DOCTYPE html>
<html lang="zh-Hant">
<?php
session_start();
// 防呆機制：如果已經登入，清空 session 的 u_ID，強制重新登入
if (isset($_SESSION['u_ID']) && !empty($_SESSION['u_ID'])) {
    // 清空登入相關的 session 資料
    unset($_SESSION['u_ID']);
    unset($_SESSION['u_name']);
    unset($_SESSION['u_img']);
    unset($_SESSION['role_ID']);
    unset($_SESSION['role_name']);
    // 不重定向，讓用戶看到登入頁面
}
?>
<head name="app-base" content="/myprojecteverydaysforlasttest/">
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>專題總彙</title>

  <?php include "head.php"; ?>
  <link rel="stylesheet" href="css/login.css?v=<?= time() ?>" />
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      overflow-x: hidden;
    }

    .home-container {
      position: relative;
      width: 100vw;
      min-height: 100vh;
      overflow: hidden;
      font-family: Arial, Helvetica, sans-serif;
      background: linear-gradient(135deg, #0f0f0f 0%, #1a1a2e 50%, #16213e 100%);
      color: #fff;
      transition: background 0.6s ease;
    }

    /* 顯示登入表單時的背景變化 */
    .home-container.login-mode {
      background: linear-gradient(135deg, #0a0a0a 0%, #151528 50%, #0f1a2e 100%);
    }

    /* Nav 列 */
    .home-nav {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      padding: 1rem 2rem;
      z-index: 100;
      background: rgba(15, 15, 15, 0.7);
      backdrop-filter: blur(10px);
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
      display: flex;
      justify-content: space-between;
      align-items: center;
      opacity: 0;
      transform: translateY(-100%);
      transition: all 0.6s ease;
    }

    .home-nav.visible {
      opacity: 1;
      transform: translateY(0);
    }

    .nav-title {
      font-size: 1.5rem;
      font-weight: bold;
      background: linear-gradient(45deg, #4a90e2, #93c5fd);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      letter-spacing: 0.1em;
      margin: 0;
      cursor: pointer;
    }

    .nav-login-btn {
      display: inline-block;
      padding: 0.6rem 1.5rem;
      font-size: 1rem;
      background: linear-gradient(45deg, #4a90e2, #357abd);
      color: #fff;
      border: none;
      border-radius: 25px;
      cursor: pointer;
      text-decoration: none;
      transition: all 0.3s ease;
      box-shadow: 0 2px 10px rgba(74, 144, 226, 0.3);
    }

    .nav-login-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 15px rgba(74, 144, 226, 0.5);
      color: #fff;
      text-decoration: none;
    }

    /* 初始標題（在正中間） */
    .initial-title {
      position: fixed;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      font-size: 4rem;
      font-weight: bold;
      background: linear-gradient(45deg, #4a90e2, #93c5fd);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      letter-spacing: 0.1em;
      z-index: 50;
      text-align: center;
      white-space: nowrap;
      transition: all 1.2s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .initial-title.slide-up {
      top: 1.5rem;
      left: 2rem;
      transform: translate(0, 0);
      font-size: 1.5rem;
    }

    /* 中間內容區域 */
    .main-content {
      position: relative;
      width: 100%;
      min-height: 100vh;
      display: flex;
      align-items: flex-start;
      justify-content: center;
      padding: 5rem 0 0 0;
      z-index: 10;
      overflow-y: auto;
    }

    /* 主內容的淡出動畫 - 優化以避免閃爍 */
    .fade-leave-active .main-content {
      transition: opacity 0.2s ease-out;
    }

    .fade-leave-from .main-content {
      opacity: 1;
    }

    .fade-leave-to .main-content {
      opacity: 0;
    }
    
    /* 主內容的淡入動畫 - 優化以避免閃爍 */
    .fade-enter-active .main-content {
      transition: opacity 0.2s ease-in;
    }

    .fade-enter-from .main-content {
      opacity: 0;
    }

    .fade-enter-to .main-content {
      opacity: 1;
    }

    .content-wrapper {
      max-width: 1200px;
      width: 100%;
      text-align: center;
    }

    .content-title {
      font-size: 2.5rem;
      font-weight: bold;
      margin-bottom: 1.5rem;
      color: #fff;
    }

    .content-subtitle {
      font-size: 1.25rem;
      color: rgba(255, 255, 255, 0.8);
      margin-bottom: 3rem;
      line-height: 1.8;
    }

    .features-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 2rem;
      margin-top: 3rem;
    }

    .feature-card {
      background: rgba(255, 255, 255, 0.08);
      backdrop-filter: blur(10px);
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: 15px;
      padding: 2rem;
      transition: all 0.3s ease;
    }

    .feature-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 8px 25px rgba(74, 144, 226, 0.3);
      border-color: rgba(74, 144, 226, 0.5);
    }

    .feature-icon {
      font-size: 3rem;
      margin-bottom: 1rem;
      background: linear-gradient(45deg, #4a90e2, #93c5fd);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .feature-title {
      font-size: 1.5rem;
      margin-bottom: 1rem;
      color: #fff;
    }

    .feature-desc {
      color: rgba(255, 255, 255, 0.7);
      line-height: 1.6;
    }

    /* 登入表單區域（iframe 容器） */
    .login-content {
      position: relative;
      width: 100%;
      min-height: 100vh;
      display: flex !important;
      align-items: center;
      justify-content: center;
      padding: 6rem 2rem 2rem;
      z-index: 10;
    }

    .login-iframe-wrapper {
      width: 100%;
      max-width: 420px;
      min-height: 500px;
      border: none;
      background: transparent;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .login-iframe {
      width: 100%;
      min-height: 500px;
      border: none;
      background: transparent;
      overflow: hidden;
    }

    /* 粒子效果 */
    .particles-home {
      position: fixed;
      inset: 0;
      z-index: 2;
      pointer-events: none;
    }

    .particle-home {
      position: absolute;
      width: 4px;
      height: 4px;
      background: rgba(255, 255, 255, 0.5);
      border-radius: 50%;
      animation: float 6s ease-in-out infinite;
    }

    @keyframes float {
      0%, 100% {
        transform: translateY(100vh) scale(0);
        opacity: 0;
      }
      10% {
        opacity: 1;
      }
      90% {
        opacity: 1;
      }
      100% {
        transform: translateY(-100px) scale(1);
        opacity: 0;
      }
    }

    /* 波浪效果 */
    .wave-home {
      position: fixed;
      bottom: 0;
      width: 100%;
      height: 100px;
      background: linear-gradient(45deg, rgba(255, 255, 255, 0.08), rgba(100, 149, 237, 0.10));
      border-radius: 50% 50% 0 0;
      animation: wave 10s ease-in-out infinite;
      z-index: 1;
    }

    .wave-home1 {
      animation-delay: 0s;
      opacity: 0.6;
    }

    .wave-home2 {
      animation-delay: 2s;
      opacity: 0.4;
      height: 120px;
    }

    .wave-home3 {
      animation-delay: 4s;
      opacity: 0.3;
      height: 80px;
    }

    @keyframes wave {
      0%, 100% {
        transform: translateX(0) translateY(0);
      }
      50% {
        transform: translateX(-25%) translateY(-20px);
      }
    }

    /* 登入表單動畫 - 優化以避免閃爍 */
    .fade-enter-active {
      transition: opacity 0.2s ease-in;
    }

    .fade-leave-active {
      transition: opacity 0.15s ease-out;
    }

    .fade-enter-from {
      opacity: 0;
    }

    .fade-enter-to {
      opacity: 1;
    }

    .fade-leave-from {
      opacity: 1;
    }

    .fade-leave-to {
      opacity: 0;
    }

    @media (max-width: 768px) {
      .initial-title {
        font-size: 2.5rem;
      }

      .initial-title.slide-up {
        font-size: 1.2rem;
        top: 1rem;
        left: 1rem;
      }

      .home-nav {
        padding: 0.75rem 1rem;
      }

      .nav-title {
        font-size: 1.2rem;
      }

      .nav-login-btn {
        padding: 0.5rem 1rem;
        font-size: 0.9rem;
      }

      .main-content {
        padding: 5rem 1rem 2rem;
      }

      .content-title {
        font-size: 2rem;
      }

      .content-subtitle {
        font-size: 1rem;
      }

      .features-grid {
        grid-template-columns: 1fr;
        gap: 1.5rem;
      }
    }
  </style>
</head>

<body id="indexbody">
  <!-- 科技背景 -->
  <div id="techbg-host"
       class="position-fixed top-0 start-0 w-100 h-100"
       data-mode="login" data-speed="1.12" data-density="1.35"
       data-contrast="bold"
       style="z-index:0; pointer-events:none;"></div>

  <div id="app">
    <div class="home-container" :class="{ 'login-mode': showLogin }">
    <!-- 波浪效果 -->
    <div class="wave-home wave-home1"></div>
    <div class="wave-home wave-home2"></div>
    <div class="wave-home wave-home3"></div>

    <!-- 粒子效果 -->
    <div class="particles-home" id="particles-home"></div>

    <!-- Nav 列 -->
    <nav class="home-nav" id="homeNav">
      <h1 class="nav-title" @click="showLogin = false">專題日總彙</h1>
      <button class="nav-login-btn" @click="showLogin = true" v-if="!showLogin">
        <i class="fa-solid fa-right-to-bracket me-2"></i>登入
      </button>
    </nav>

    <!-- 初始標題（在正中間，會滑動到nav） -->
    <h1 class="initial-title" id="initialTitle">專題日總彙</h1>

    <!-- 中間內容區域 -->
    <Transition name="fade" mode="out-in" appear>
      <div class="main-content" id="mainContent" v-if="!showLogin && showMainContent" key="main-content" v-show="!showLogin && showMainContent">
      <div class="content-wrapper" style="width: 100%; max-width: 100%;">
        <!-- 歷屆專題瀏覽區塊 -->
        <?php
        // 檢查是否為公開模式
        $isPublic = true; // 首頁預設為公開模式
        $isEmbed = true; // 嵌入模式
        
        // 檢查權限（公開模式或已登入用戶都可以訪問）
        $role_ID = $_SESSION['role_ID'] ?? null;
        $u_ID = $_SESSION['u_ID'] ?? null;
        
        // 載入資料庫連接
        require 'includes/pdo.php';
        ?>
        <!-- CSS 預載入，防止跑版 -->
        <link rel="stylesheet" href="css/project_browse.css?v=<?= time() ?>" id="projectBrowseCSS" media="print" onload="this.media='all'">
        <noscript><link rel="stylesheet" href="css/project_browse.css?v=<?= time() ?>"></noscript>
        <style>
            .project-browse-container {
                padding: 0 !important;
                margin: 0 !important;
                background: transparent !important;
                min-height: auto !important;
            }
            .browse-header {
                margin-bottom: 20px !important;
                border-radius: 0 !important;
                margin-left: 0 !important;
                margin-right: 0 !important;
                padding: 20px 30px !important;
            }
            .project-display-section {
                padding: 20px !important;
                border-radius: 0 !important;
                margin-left: 0 !important;
                margin-right: 0 !important;
            }
            .search-filter-section {
                margin-left: 30px !important;
                margin-right: 30px !important;
            }
            .search-filter-content {
                justify-content: flex-start !important;
            }
        </style>
        
        <div class="project-browse-container" style="width: 100vw; margin-left: calc(-50vw + 50%); margin-right: calc(-50vw + 50%); padding: 0;">
            <!-- 頁面標題 -->
            <div class="browse-header" style="width: 100%; margin: 0; padding: 0; position: relative; z-index: 10; display: flex; align-items: center; justify-content: center;">
                <div class="neon-banner-wrapper" style="position: relative; width: 100%; max-width: 100%; padding: 28px 40px; display: flex; align-items: center; justify-content: center;">
                    <!-- 霓虹發光橫幅 -->
                    <div class="neon-banner" style="position: relative; background: linear-gradient(135deg, #2d1b4e 0%, #1a0d2e 100%); padding: 24px 48px; border-radius: 12px; box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.1); border: 2px solid transparent; width: 100%; max-width: 100%; overflow: hidden;">
                        <!-- 霓虹發光邊框 - 從粉紅色（左上）到藍色（右下） -->
                        <div class="neon-border-wrapper" style="position: absolute; inset: -2px; border-radius: 12px; z-index: -1; padding: 2px; background: linear-gradient(135deg, #ff00ff 0%, #ff00ff 25%, #00ffff 75%, #00ffff 100%); filter: blur(2px); opacity: 0.9; animation: neonGlow 2s ease-in-out infinite alternate;">
                            <div style="width: 100%; height: 100%; background: linear-gradient(135deg, #2d1b4e 0%, #1a0d2e 100%); border-radius: 10px;"></div>
                        </div>
                        <div class="neon-border-strong" style="position: absolute; inset: -1px; border-radius: 12px; z-index: -1; padding: 1px; background: linear-gradient(135deg, #ff00ff 0%, #ff00ff 25%, #00ffff 75%, #00ffff 100%);">
                            <div style="width: 100%; height: 100%; background: linear-gradient(135deg, #2d1b4e 0%, #1a0d2e 100%); border-radius: 11px;"></div>
                        </div>
                        
                        <h1 class="browse-title" style="margin: 0; position: relative; z-index: 2; display: flex; align-items: center; gap: 16px; color: #ffffff; font-size: 36px; font-weight: 800; letter-spacing: 2px; text-shadow: 0 0 20px rgba(255, 255, 255, 0.5), 0 0 40px rgba(255, 0, 255, 0.3); justify-content: center;">
                            <i class="fa-solid fa-graduation-cap" style="color: #ffffff; font-size: 32px; filter: drop-shadow(0 0 10px rgba(255, 255, 255, 0.8));"></i>
                            <span>歷屆專題瀏覽</span>
                        </h1>
                    </div>
                </div>
            </div>

            <!-- 搜尋與篩選區域 -->
            <div class="search-filter-section" style="opacity: 0 !important; visibility: hidden !important;">
                <div class="search-filter-header">
                    <i class="fa-solid fa-filter"></i>
                    <span>搜尋與篩選</span>
                </div>
                <div class="search-filter-content">
                    <div class="search-group" style="flex: 1; min-width: 300px;">
                        <label for="searchInput">
                            <i class="fa-solid fa-magnifying-glass"></i> 搜尋專題
                        </label>
                        <input 
                            type="text" 
                            id="searchInput" 
                            class="filter-select" 
                            placeholder="輸入專題名稱或關鍵字"
                            autocomplete="off"
                        >
                        <small class="search-hint" style="display: block; margin-top: 4px; color: #666; font-size: 14px;">
                            <i class="fa-solid fa-lightbulb"></i> 提示：可搜尋專題名稱或簡介內容，關鍵字不需要完全匹配
                        </small>
                    </div>
                    <div class="search-group">
                        <label for="cohortFilter">屆別</label>
                        <select id="cohortFilter" class="filter-select">
                            <option value="0">全部屆別</option>
                        </select>
                    </div>
                    <div class="search-actions">
                        <button type="button" class="btn-clear" id="clearFiltersBtn">
                            <i class="fa-solid fa-rotate-left"></i> 清除篩選
                        </button>
                    </div>
                </div>
            </div>

            <!-- 專題展示區域 -->
            <div class="project-display-section">
                <div id="projectDisplayArea">
                    <div class="empty-state">
                        <i class="fa-solid fa-folder-open"></i>
                        <p>載入中...</p>
                    </div>
                </div>
            </div>
        </div>
      </div>
    </div>

    <!-- 登入表單區域（嵌入 login.php，login.php 會重定向到 index.php） -->
    <Transition name="fade" mode="out-in" appear>
      <div class="login-content" v-if="showLogin" key="login-form" v-show="showLogin">
        <div class="login-iframe-wrapper">
          <iframe 
            id="loginIframe" 
            class="login-iframe" 
            src="login.php?iframe=1"
            frameborder="0"
            scrolling="no">
          </iframe>
        </div>
      </div>
    </Transition>
    </div>
  </div>
  <script>
    // 確保 Vue 已載入
    let vueRetryCount = 0;
    const MAX_VUE_RETRIES = 50; // 最多重試 50 次（5 秒）
    
    function initVueApp() {
      // 檢查是否已標記為載入失敗
      if (window.VueLoadFailed) {
        console.error('Vue.js 載入失敗，請檢查網路連線或 CDN 是否可訪問');
        if (document.getElementById('app')) {
          document.getElementById('app').innerHTML = 
            '<div style="text-align: center; padding: 50px; color: #fff;">' +
            '<h2>載入失敗</h2>' +
            '<p>無法載入必要的資源，請重新整理頁面</p>' +
            '<button onclick="location.reload()" style="padding: 10px 20px; margin-top: 20px; cursor: pointer;">重新載入</button>' +
            '</div>';
        }
        return;
      }
      
      if (typeof Vue === 'undefined') {
        vueRetryCount++;
        if (vueRetryCount < MAX_VUE_RETRIES) {
          setTimeout(initVueApp, 100);
        } else {
          console.error('Vue.js 載入超時，請檢查網路連線或 CDN 是否可訪問');
          // 顯示用戶友好的錯誤訊息
          if (document.getElementById('app')) {
            document.getElementById('app').innerHTML = 
              '<div style="text-align: center; padding: 50px; color: #fff;">' +
              '<h2>載入失敗</h2>' +
              '<p>無法載入必要的資源，請重新整理頁面</p>' +
              '<button onclick="location.reload()" style="padding: 10px 20px; margin-top: 20px; cursor: pointer;">重新載入</button>' +
              '</div>';
          }
        }
        return;
      }

      const { createApp, ref, reactive, computed, onMounted, watch, nextTick } = Vue;

      // 生成粒子效果
      const particlesContainer = document.getElementById('particles-home');
      if (particlesContainer) {
        for (let i = 0; i < 24; i++) {
          const particle = document.createElement('div');
          particle.className = 'particle-home';
          particle.style.left = Math.random() * 100 + '%';
          particle.style.animationDelay = Math.random() * 5 + 's';
          particlesContainer.appendChild(particle);
        }
      }

      // Vue App
      createApp({
      setup() {
        const showLogin = ref(false);
        const showMainContent = ref(false);

        onMounted(() => {
          // 標題動畫：從中間滑動到nav
          const initialTitle = document.getElementById('initialTitle');
          const homeNav = document.getElementById('homeNav');

          // 1.2秒後開始動畫（從2秒縮短）
          setTimeout(() => {
            // 標題滑動到nav位置
            initialTitle.classList.add('slide-up');
            
            // 顯示nav（從600ms縮短到400ms）
            setTimeout(() => {
              homeNav.classList.add('visible');
              // 標題淡出（因為nav中已經有標題了）（從300ms縮短到200ms）
              setTimeout(() => {
                initialTitle.style.opacity = '0';
                // 延遲顯示中間內容，避免卡頓（從500ms縮短到300ms）
                setTimeout(() => {
                  showMainContent.value = true;
                }, 300);
              }, 200);
            }, 400);
          }, 1200);

          // 監聽來自 login.php iframe 的消息
          window.addEventListener('message', function(event) {
            if (event.data && event.data.type === 'closeLogin') {
              showLogin.value = false;
            }
          });

          // 監聽來自 login.php iframe 的高度調整消息
          window.addEventListener('message', function(event) {
            if (event.data && event.data.type === 'resizeIframe') {
              const loginIframe = document.getElementById('loginIframe');
              if (loginIframe && event.data.height) {
                loginIframe.style.height = event.data.height + 'px';
              }
            }
          });
          
          // ========== 防呆機制：防止從其他頁面返回時繞過登入檢查 ==========
          
          // 清空 session 的函數
          const clearSession = async () => {
            try {
              await fetch('api.php?do=clear_session', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                  'Content-Type': 'application/x-www-form-urlencoded'
                }
              });
            } catch (e) {
              console.error('清空 session 失敗:', e);
            }
          };
          
          // 1. 監聽 popstate 事件（當用戶點擊瀏覽器的返回/前進按鈕時觸發）
          if (window.history && window.history.pushState) {
            // 將當前頁面加入歷史記錄
            window.history.pushState(null, '', window.location.href);
            
            window.addEventListener('popstate', async function(event) {
              // 如果用戶嘗試返回，清空 session 並重新載入頁面
              await clearSession();
              window.location.reload();
            });
          }
          
          // 2. 監聽 pageshow 事件（當頁面顯示時，包括從緩存中恢復）
          window.addEventListener('pageshow', async function(event) {
            // 如果頁面是從緩存中載入的（例如返回按鈕）
            if (event.persisted) {
              // 清空 session 並重新載入頁面
              await clearSession();
              window.location.reload();
            }
          });
          
          // 3. 監聽 focus 事件（當窗口獲得焦點時）
          window.addEventListener('focus', async function() {
            // 當窗口重新獲得焦點時，檢查登入狀態
            try {
              const response = await fetch('api.php?do=check_session', {
                method: 'GET',
                credentials: 'same-origin'
              });
              const data = await response.json();
              
              // 如果已經登入，清空 session 並重新載入頁面
              if (data && data.logged_in) {
                await clearSession();
                window.location.reload();
              }
            } catch (e) {
              console.error('檢查 session 失敗:', e);
            }
          });
          
          // 4. 監聽 visibilitychange 事件（當標籤頁可見性變化時）
          document.addEventListener('visibilitychange', async function() {
            if (!document.hidden) {
              // 當頁面重新可見時，檢查登入狀態
              try {
                const response = await fetch('api.php?do=check_session', {
                  method: 'GET',
                  credentials: 'same-origin'
                });
                const data = await response.json();
                
                // 如果已經登入，清空 session 並重新載入頁面
                if (data && data.logged_in) {
                  await clearSession();
                  window.location.reload();
                }
              } catch (e) {
                console.error('檢查 session 失敗:', e);
              }
            }
          });
          
          // 5. 防止頁面被緩存
          if (window.history && window.history.replaceState) {
            window.history.replaceState(null, '', window.location.href);
          }
          
          // 6. 設置頁面不緩存的 meta 標籤
          if (!document.querySelector('meta[http-equiv="Cache-Control"]')) {
            const meta = document.createElement('meta');
            meta.httpEquiv = 'Cache-Control';
            meta.content = 'no-cache, no-store, must-revalidate';
            document.getElementsByTagName('head')[0].appendChild(meta);
          }
          if (!document.querySelector('meta[http-equiv="Pragma"]')) {
            const meta = document.createElement('meta');
            meta.httpEquiv = 'Pragma';
            meta.content = 'no-cache';
            document.getElementsByTagName('head')[0].appendChild(meta);
          }
          if (!document.querySelector('meta[http-equiv="Expires"]')) {
            const meta = document.createElement('meta');
            meta.httpEquiv = 'Expires';
            meta.content = '0';
            document.getElementsByTagName('head')[0].appendChild(meta);
          }
        });

        /**
         * 初始化歷屆專題瀏覽
         */
        function initProjectBrowse() {
          // 設置配置（確保在腳本載入前就設置好）
          window.PROJECT_BROWSE_CONFIG = {
            u_ID: '<?= htmlspecialchars($u_ID ?? '', ENT_QUOTES) ?>',
            role_ID: <?= $role_ID ?? 'null' ?>,
            isPublic: true
          };
          
          // 初始化功能
          if (typeof window.ProjectBrowse !== 'undefined' && typeof window.ProjectBrowse.init === 'function') {
            window.ProjectBrowse.init();
          } else {
            // 如果腳本還沒載入，等待一下再試（最多嘗試 50 次，即 5 秒）
            let attempts = 0;
            const maxAttempts = 50;
            const checkInterval = setInterval(() => {
              attempts++;
              if (typeof window.ProjectBrowse !== 'undefined' && typeof window.ProjectBrowse.init === 'function') {
                clearInterval(checkInterval);
                window.ProjectBrowse.init();
              } else if (attempts >= maxAttempts) {
                clearInterval(checkInterval);
                console.error('無法載入歷屆專題瀏覽功能');
                const container = document.getElementById('projectDisplayArea');
                if (container) {
                  container.innerHTML = '<div class="empty-state"><i class="fa-solid fa-exclamation-triangle"></i><p>載入失敗，請重新整理頁面</p></div>';
                }
              }
            }, 100);
          }
        }

        // 監聽 showLogin 的變化，當從登入頁面返回時，確保顯示主內容
        watch(showLogin, async (newVal, oldVal) => {
          if (!newVal && oldVal) {
            // 如果從登入頁面返回（從 true 變為 false）
            // 確保主內容顯示
            showMainContent.value = true;
            
            // 等待 Vue 渲染完成後，檢查並重新初始化
            await nextTick();
            // 使用 requestAnimationFrame 確保 DOM 已更新
            requestAnimationFrame(() => {
              requestAnimationFrame(() => {
                // 檢查容器是否存在
                const container = document.getElementById('projectDisplayArea');
                if (container) {
                  // 檢查容器內容
                  const isEmpty = container.innerHTML.includes('載入中') || 
                                  container.innerHTML.includes('folder-open') ||
                                  container.innerHTML.trim() === '' ||
                                  container.querySelector('.empty-state') !== null;
                  
                  // 如果容器是空的或顯示"載入中..."，重新初始化
                  if (isEmpty) {
                    // 如果腳本已經載入，直接初始化或載入數據
                    if (typeof window.ProjectBrowse !== 'undefined') {
                      try {
                        // 優先使用 loadProjects 載入數據
                        if (window.ProjectBrowse.loadProjects && typeof window.ProjectBrowse.loadProjects === 'function') {
                          window.ProjectBrowse.loadProjects();
                        } else if (window.ProjectBrowse.init && typeof window.ProjectBrowse.init === 'function') {
                          window.ProjectBrowse.init();
                        }
                      } catch (e) {
                        console.error('載入專題失敗:', e);
                        // 如果失敗，重新載入腳本
                        loadProjectBrowseScript();
                      }
                    } else {
                      // 如果腳本還沒載入，重新載入
                      loadProjectBrowseScript();
                    }
                  } else {
                    // 如果已經有內容，不需要重新初始化（避免閃爍）
                    // 內容應該已經存在，不需要額外操作
                  }
                } else {
                  // 如果容器不存在，等待一下再試
                  setTimeout(() => {
                    loadProjectBrowseScript();
                  }, 200);
                }
              });
            });
          }
        });

        return {
          showLogin,
          showMainContent
        };
      }
      }).mount('#app');
      
      // 先設置配置，確保腳本載入時就能使用
      window.PROJECT_BROWSE_CONFIG = {
        u_ID: '<?= htmlspecialchars($u_ID ?? '', ENT_QUOTES) ?>',
        role_ID: <?= $role_ID ?? 'null' ?>,
        isPublic: true
      };
      
      // 等待 Vue 渲染完成後再載入腳本
      function loadProjectBrowseScript() {
        // 🔹 檢查腳本是否已經載入，避免重複載入
        if (window.ProjectBrowseScriptLoaded || document.querySelector('script[src*="project_browse.js"]')) {
          // 如果腳本已經載入，直接初始化
          if (typeof window.ProjectBrowse !== 'undefined' && typeof window.ProjectBrowse.init === 'function') {
            window.ProjectBrowse.init();
          }
          return;
        }
        
        // 檢查 projectDisplayArea 是否存在（確保 Vue 已渲染）
        const container = document.getElementById('projectDisplayArea');
        if (!container) {
          // 如果還沒渲染，等待一下再試
          setTimeout(loadProjectBrowseScript, 100);
          return;
        }
        
        // 標記腳本正在載入，避免重複載入
        window.ProjectBrowseScriptLoaded = true;
        
        // 載入歷屆專題瀏覽的 JavaScript
        const projectBrowseScript = document.createElement('script');
        projectBrowseScript.src = 'js/project_browse.js?v=' + Date.now();
        projectBrowseScript.onload = function() {
          // 腳本載入完成後立即初始化
          if (typeof window.ProjectBrowse !== 'undefined' && typeof window.ProjectBrowse.init === 'function') {
            window.ProjectBrowse.init();
          } else {
            // 如果還沒準備好，等待一下
            setTimeout(() => {
              if (typeof window.ProjectBrowse !== 'undefined' && typeof window.ProjectBrowse.init === 'function') {
                window.ProjectBrowse.init();
              } else {
                console.error('ProjectBrowse 初始化失敗');
                const container = document.getElementById('projectDisplayArea');
                if (container) {
                  container.innerHTML = '<div class="empty-state"><i class="fa-solid fa-exclamation-triangle"></i><p>初始化失敗，請重新整理頁面</p></div>';
                }
              }
            }, 200);
          }
        };
        projectBrowseScript.onerror = function() {
          console.error('載入歷屆專題瀏覽腳本失敗');
          const container = document.getElementById('projectDisplayArea');
          if (container) {
            container.innerHTML = '<div class="empty-state"><i class="fa-solid fa-exclamation-triangle"></i><p>載入失敗，請重新整理頁面</p></div>';
          }
        };
        document.head.appendChild(projectBrowseScript);
      }
      
      // 在 Vue 掛載後開始載入腳本
      setTimeout(loadProjectBrowseScript, 200);
    }

    // 當 DOM 載入完成後初始化 Vue
    // Vue.js 應該已經在 head.php 中同步載入
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', function() {
        // 給 Vue.js 一點時間載入
        setTimeout(initVueApp, 50);
      });
    } else {
      // DOM 已經載入完成，給 Vue.js 一點時間載入
      setTimeout(initVueApp, 50);
    }
  </script>
</body>
</html>
