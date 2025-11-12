// js/login.js
// 需求：head.php 應該已載入 Vue 3 (global) 與 Bootstrap 5 JS；Font Awesome/SweetAlert2 可選
const { createApp, ref, reactive, computed, onMounted } = Vue;

const tpl = `
  <div class="login-container">
    <!-- 額外波浪 + 粒子層（與 techbg-host 疊加） -->
    <div class="fx-background">
      <div class="wave wave1"></div>
      <div class="wave wave2"></div>
      <div class="wave wave3"></div>
      <div class="particles">
        <div class="particle"
             v-for="i in 24" :key="i"
             :style="{ left: (Math.random()*100)+'%', animationDelay: (Math.random()*5)+'s' }"></div>
      </div>
    </div>

    <div class="form-overlay">
      <Transition name="fade-slide">
        <form v-if="showForm" class="login-form" @submit.prevent="loginSubmit">
          <h1 class="title">專題日總彙</h1>

          <div class="input-group">
            <!-- <label for="acc">帳號</label> -->
            <input id="acc" v-model.trim="login.acc" type="text" inputmode="text"
                   autocomplete="username" placeholder="請輸入帳號" @keyup.enter="focusPassword" />
          </div>

          <div class="input-group" v-if="hasAccount">
            <!-- <label for="pas">密碼</label> -->
            <input :type="showPassword ? 'text':'password'"
                   id="pas" v-model.trim="login.pas" autocomplete="current-password"
                   placeholder="請輸入密碼" @keyup.enter="loginSubmit" />
            <i v-if="login.pas"
               class="fa-solid toggle-eye"
               :class="showPassword ? 'fa-eye-slash' : 'fa-eye'"
               @click="showPassword = !showPassword"></i>
          </div>

          <button class="btn btn-info submit-btn" type="submit" :disabled="loading">
            {{ loading ? '登入中…' : '登入' }}
          </button>

          <p v-if="error" class="error">{{ error }}</p>
          <p class="forgot">忘記密碼？ <a href="#" @click.prevent="openForgot">重設</a></p>
        </form>
      </Transition>
    </div>

    <!-- 忘記密碼 Modal -->
    <div class="modal fade" id="forgotModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-body text-center">
            <h5 class="mb-3">忘記密碼</h5>
            <input v-model.trim="forgotAccount" type="text" class="form-control mb-3" placeholder="請輸入帳號">
            <div class="d-grid gap-2">
              <button class="btn btn-info" @click="sendForgot">送出</button>
              <button class="btn btn-outline-secondary" data-bs-dismiss="modal">取消</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
`;

createApp({
  template: tpl,
  setup(){
    const showForm = ref(true);
    const loading  = ref(false);
    const error    = ref('');
    const showPassword = ref(false);

    const login = reactive({ acc:'', pas:'' });
    const hasAccount = computed(()=> !!login.acc);

    const forgotAccount = ref('');
    let modalForgot = null;

    onMounted(()=>{
      const el = document.getElementById('forgotModal');
      if (window.bootstrap && el) modalForgot = new bootstrap.Modal(el);
      // 進頁面時把焦點放在帳號
      queueMicrotask(()=> document.getElementById('acc')?.focus());
    });

    const focusPassword = ()=> document.getElementById('pas')?.focus();

    const openForgot = ()=>{
      forgotAccount.value = login.acc || '';
      modalForgot?.show();
    };

    const sendForgot = async ()=>{
      if (!forgotAccount.value) {
        setError('請先輸入帳號再送出。');
        return;
      }
      try{
        clearError();
        loading.value = true;
        // TODO：接你的後端忘記密碼 API
        // 例如：
        // await fetch('api.php?do=forgot_password', {
        //   method:'POST',
        //   headers:{ 'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8' },
        //   body: new URLSearchParams({ acc: forgotAccount.value })
        // });
        await sleep(800);
        modalForgot?.hide();
        notify('已送出','請至註冊信箱或聯絡系統管理員協助重設。','success');
      }catch(e){
        setError('送出失敗，請稍後再試。');
      }finally{
        loading.value = false;
      }
    };

const loginSubmit = async ()=>{
  clearError();
  if (!login.acc) { setError('請先輸入帳號'); focusAccount(); return; }
  if (!login.pas) { setError('請輸入密碼'); focusPassword(); return; }

  loading.value = true;
  try{
    const res = await fetch('api.php?do=login_sub', {
      method:'POST',
      headers:{ 'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8' },
      body: new URLSearchParams({ acc: login.acc, pas: login.pas })
    });
    const data = await res.json();

    if (!res.ok || !data.ok) {
      if (data.code === 'ACCOUNT_NOT_FOUND') focusAccount();
      if (data.code === 'WRONG_PASSWORD') focusPassword();
      return setError(data.msg || '登入失敗');
    }

    // 成功
    location.href = 'main.php#pages/new.php';
  }catch(e){
    setError('伺服器錯誤，請稍後再試');
    // setError("此為未註冊帳號，請重新輸入");
  }finally{
    loading.value = false;
  }
};

    // 小工具
    const sleep = (ms)=> new Promise(r=>setTimeout(r, ms));
    const setError = (msg)=>{ error.value = msg; if (!window.Swal) return; Swal.fire('提醒', msg, 'warning'); };
    const clearError = ()=> error.value = '';
    const notify = (title, text, icon='info')=>{
      if (window.Swal) Swal.fire(title, text, icon);
      else alert(`${title}\n${text}`);
    };

    return {
      showForm, loading, error, showPassword,
      login, hasAccount,
      forgotAccount,
      focusPassword, openForgot, sendForgot,
      loginSubmit
    };
  }
}).mount('#app');
