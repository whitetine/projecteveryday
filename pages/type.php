<style>
/* ===== 基礎變數與重設 ===== */
#type_app {
    --primary: #2563eb;
    --success: #10b981;
    --danger: #ef4444;
    --text-main: #1e293b;
    --text-sub: #64748b;
    --bg-card: #ffffff;
    --border-color: #e2e8f0;
    
    font-family: system-ui, -apple-system, sans-serif;
    padding: 1.5rem;
    color: var(--text-main);
    max-width: 1200px;
    margin: 0 auto;
}

/* ===== Header 區塊 ===== */
.type-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 2px solid var(--border-color);
    padding-bottom: 1rem;
    margin-bottom: 2rem;
}
.type-header h1 { font-size: 1.5rem; font-weight: 800; margin: 0; }
.type-header p { color: var(--text-sub); font-size: 0.9rem; margin: 0.2rem 0 0 0; }

/* ===== 區塊容器 (Section) ===== */
.type-section {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    margin-bottom: 1.5rem;
    overflow: hidden;
}
.section-title {
    padding: 1rem 1.25rem;
    background: #f8fafc;
    border-bottom: 1px solid var(--border-color);
    font-weight: 700;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

/* ===== 簡化後的表單 (Grid) ===== */
.type-form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 1.25rem;
    padding: 1.25rem;
}
.field-group { display: flex; flex-direction: column; gap: 0.5rem; }
.field-group label { font-size: 0.85rem; font-weight: 600; }
.field-group input, .field-group select {
    padding: 0.5rem;
    border: 1px solid var(--border-color);
    border-radius: 6px;
}
.form-footer {
    padding: 0 1.25rem 1.25rem;
    display: flex;
    gap: 0.75rem;
}

/* ===== 響應式表格 (RWD 重要修正) ===== */
.table-responsive { width: 100%; overflow-x: auto; }
table.type-table {
    width: 100%;
    border-collapse: collapse;
    text-align: left;
}
table.type-table th {
    background: #f8fafc;
    padding: 1rem;
    font-size: 0.85rem;
    color: var(--text-sub);
    border-bottom: 1px solid var(--border-color);
}
table.type-table td {
    padding: 1rem;
    border-bottom: 1px solid #f1f5f9;
}

/* 狀態標籤 */
.badge {
    padding: 0.25rem 0.6rem;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 700;
}
.badge-on { background: #dcfce7; color: #166534; }
.badge-off { background: #fee2e2; color: #991b1b; }

/* 手機版適配：將表格轉為卡片 */
@media (max-width: 768px) {
    .type-header { flex-direction: column; align-items: flex-start; gap: 1rem; }
    
    table.type-table thead { display: none; } /* 隱藏表頭 */
    table.type-table tr {
        display: block;
        border: 1px solid var(--border-color);
        margin-bottom: 1rem;
        border-radius: 8px;
    }
    table.type-table td {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem 1rem;
        text-align: right;
        border-bottom: 1px dotted #eee;
    }
    table.type-table td::before {
        content: attr(data-label); /* 使用 data-label 顯示欄位名 */
        font-weight: 700;
        color: var(--text-sub);
        float: left;
    }
    table.type-table td:last-child { border-bottom: none; background: #fafafa; }
}

/* 按鈕樣式優化 */
.btn-main {
    background: var(--primary);
    color: white;
    border: none;
    padding: 0.5rem 1.25rem;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
}
.btn-main:hover { opacity: 0.9; }
</style>

<main id="type_app" v-cloak>
  <header class="type-header">
    <div>
      <h1><i class="fa-solid fa-layer-group"></i> 分類管理</h1>
      <!-- <p>管理 typedata：名稱、屆別、日期與狀態</p>  -->
    </div>
    <div class="badge-on badge">筆數：{{ filtered.length }}</div>
  </header>

  <section class="type-section">
    <header class="section-title">
      <span><i class="fa-solid fa-plus-circle"></i> 新增分類項目</span>
    </header>

    <form @submit.prevent="type_new_submit">
      <div class="type-form-grid">
        <div class="field-group">
          <label>分類名稱</label>
          <input v-model.trim="form.type_name" placeholder="例如：期末" required>
        </div>
        <div class="field-group">
          <label>屆別</label>
          <input type="number" v-model.number="form.type_cohort" placeholder="">
        </div>
        <div class="field-group">
          <label>開始日期</label>
          <input type="date" v-model="form.type_start_d">
        </div>
        <div class="field-group">
          <label>結束日期</label>
          <input type="date" v-model="form.type_end_d">
        </div>
        <div class="field-group">
          <label>預設狀態</label>
          <select v-model.number="form.type_status">
            <option :value="1">啟用</option>
            <option :value="0">停用</option>
          </select>
        </div>
      </div>

      <footer class="form-footer">
        <button type="submit" class="btn-main">立即新增</button>
        <button type="button" @click="resetForm"
                style="background:none;border:1px solid #ccc;cursor:pointer;border-radius:6px;padding:0.5rem 1rem;">
          重置
        </button>
      </footer>
    </form>
  </section>

  <section class="type-section">
    <header class="section-title">
      <span><i class="fa-solid fa-list"></i> 分類清單</span>

      <nav style="display:flex; gap:0.5rem;">
        <select v-model="filters.status"
                style="padding:0.2rem; border-radius:4px; border:1px solid #ddd;">
          <option value="">全部狀態</option>
          <option value="1">啟用</option>
          <option value="0">停用</option>
        </select>
      </nav>
    </header>

    <div class="table-responsive">
      <table class="type-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>分類名稱</th>
            <th>屆別</th>
            <th>日期區間</th>
            <th>狀態</th>
            <th>建立時間</th>
            <th>操作</th>
          </tr>
        </thead>

        <tbody>
          <tr v-for="i in filtered" :key="i.type_ID">
            <td data-label="ID">#{{ i.type_ID }}</td>
            <td data-label="名稱"><strong>{{ i.type_name }}</strong></td>

            <td data-label="屆別">
              <span :class="i.type_cohort ? 'badge-on badge' : 'badge-off badge'">
                {{ i.type_cohort ? i.type_cohort + '級' : '通用' }}
              </span>
            </td>

            <td data-label="日期區間">
              <small>{{ i.type_start_d || '—' }} <br>至 {{ i.type_end_d || '—' }}</small>
            </td>

            <td data-label="狀態">
              <span class="badge" :class="i.type_status==1 ? 'badge-on' : 'badge-off'">
                {{ i.type_status==1 ? '啟用' : '停用' }}
              </span>
            </td>

            <td data-label="建立時間"><small>{{ i.type_created_d }}</small></td>

            <td data-label="操作">
              <button
                @click="type_stop(i.type_ID, i.type_status==1 ? 0 : 1)"
                :style="i.type_status==1 ? 'color:var(--danger)' : 'color:var(--success)'"
                style="background:none;border:1px solid currentColor;padding:0.3rem 0.6rem;border-radius:4px;cursor:pointer;font-weight:bold;"
              >
                {{ i.type_status==1 ? '切換停用' : '切換啟用' }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>

      <!-- ✅ 空狀態放 table 外面（更乾淨） -->
      <div v-if="filtered.length===0" style="padding:3rem; text-align:center; color:#999;">
        查無符合條件的資料
      </div>
    </div>
  </section>
</main>
<script>// ===== type.php 專用掛載（放在 app.js）=====
window.renderTypePage = function renderTypePage() {
  const root = document.querySelector('#type_app');
  if (!root) return; // 沒載入 type.php 就不要做事

  // ✅ 避免重複 mount
  if (window.typeVueApp && typeof window.typeVueApp.unmount === 'function') {
    try { window.typeVueApp.unmount(); } catch (e) {}
    window.typeVueApp = null;
  }

  window.typeVueApp = Vue.createApp({
    data() {
      return {
        all_type: [],
        form: {
          type_name: '',
          type_cohort: null,
          type_start_d: '',
          type_end_d: '',
          type_status: 1
        },
        filters: {
          status: '' // '' / '1' / '0'
        }
      };
    },
    computed: {
      filtered() {
        return (this.all_type || []).filter(r => {
          const okStatus = this.filters.status === '' || String(r.type_status) === String(this.filters.status);
          return okStatus;
        });
      }
    },
    methods: {
      resetForm() {
        this.form = { type_name:'', type_cohort:null, type_start_d:'', type_end_d:'', type_status:1 };
      },

      get_type_all() {
        $.post("../modules/type.php?do=get_type_all", (res) => {
          try {
            const data = typeof res === 'string' ? JSON.parse(res) : res;
            this.all_type = Array.isArray(data) ? data : [];
          } catch (e) {
            this.all_type = [];
            console.error("get_type_all JSON parse failed:", e, res);
          }
        });
      },

      type_new_submit() {
        if (!this.form.type_name.trim()) return;

        // ✅ 日期合理性（可選）
        if (this.form.type_start_d && this.form.type_end_d && this.form.type_start_d > this.form.type_end_d) {
          toast?.({ type:'warning', title:'日期範圍不正確', text:'開始日期不能晚於結束日期' });
          return;
        }

        $.post("../modules/type.php?do=type_new_submit", {
          type_name: this.form.type_name,
          type_cohort: this.form.type_cohort || null,
          type_start_d: this.form.type_start_d || null,
          type_end_d: this.form.type_end_d || null,
          type_status: this.form.type_status
        }).done(() => {
          this.get_type_all();
          toast?.({ type:'success', title:'新增成功' });
          this.resetForm();
        });
      },

      type_stop(type_ID, status) {
        $.post("../modules/type.php?do=type_stop", { type_ID, type_status: status })
          .done(() => {
            this.get_type_all();
            toast?.({ type:'success', title:'狀態已更新' });
          });
      }
    },
    mounted() {
      this.get_type_all();
    }
  }).mount('#type_app');
};
</script>