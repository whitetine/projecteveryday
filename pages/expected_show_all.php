    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.9.3/html2pdf.bundle.min.js"></script>
    <link rel="stylesheet" href="css/expected_outcome.css?v=<?= time() ?>">
    <link rel="stylesheet" href="css/task.css?v=<?= time() ?>">
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
    <style>
        .outcome-summary-table th,
        .outcome-summary-table td {
            text-align: center;
            vertical-align: middle;
        }

        .outcome-summary-table .suggest-cell,
        .outcome-summary-table th[style*="text-align:left"] {
            text-align: left !important;
        }

        .outcome-summary-table .sortable {
            cursor: pointer;
            user-select: none;
            white-space: nowrap;
        }

        .outcome-summary-table .sortable:hover {
            background: #f8f9fa;
        }

        .ai-loading {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.35);
            z-index: 999999;

            display: flex;
            justify-content: center;
            /* 水平置中 */
            align-items: center;
            /* 垂直置中 */
        }

        .ai-loading.show {
            display: flex;
        }

        .ai-loading-box {
            background: #fff;
            padding: 30px 40px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .ai-loading-text {
            margin-top: 12px;
            font-size: 18px;
            font-weight: 600;
        }

        .spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #ddd;
            border-top: 5px solid #0d6efd;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from {
                transform: rotate(0deg);
            }

            to {
                transform: rotate(360deg);
            }
        }
    </style>
    <?php session_start(); ?>
    <div id="outcome_app">
        <div class="page-header">
            <h1 class="page-title">
                <i class="fa-solid fa-file-lines me-2" style="color: #ffc107;"></i>預期成果評分進度檢視
            </h1>
            <button class="btn btn-primary" type="button" @click="go_back" v-if="role_ID == 6 || role_ID == 4">返回</button>&emsp;
        </div>

        <!-- 篩選區 -->
        <div class="card p-3 mb-3">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-6 col-lg-2" v-if="role_ID != 6 && role_ID!=4">
                    <label class="form-label mb-1">類組名稱</label>
                    <select class="form-select form-select-sm" v-model="filter_group">
                        <option value="">全部</option>
                        <option v-for="g in group_options" :key="g" :value="g">{{ g }}</option>
                    </select>
                </div>

                <div class="col-12 col-md-6 col-lg-2" v-if="role_ID != 6">
                    <label class="form-label mb-1">指導老師</label>
                    <select class="form-select form-select-sm" v-model="filter_teacher" :disabled="role_ID == 4">
                        <option value="">全部</option>
                        <option v-for="t in teacher_options" :key="t" :value="t">{{ t }}</option>
                    </select>
                </div>

                <div class="col-12 col-md-6 col-lg-2">
                    <label class="form-label mb-1">紀錄標題</label>
                    <select class="form-select form-select-sm" v-model="filter_sfd_name">
                        <option value="">全部</option>
                        <option v-for="n in sfd_name_options" :key="n" :value="n">{{ n }}</option>
                    </select>
                </div>

                <div class="col-12 col-md-6 col-lg-2" v-if="role_ID != 6">
                    <label class="form-label mb-1">專題名稱</label>
                    <select class="form-select form-select-sm" v-model="filter_project_name">
                        <option value="">全部</option>
                        <option v-for="p in project_name_options" :key="p" :value="p">{{ p }}</option>
                    </select>
                </div>

                <div class="col-12 col-lg-4" v-if="role_ID != 6">
                    <label class="form-label mb-1">關鍵字搜尋</label>
                    <input
                        type="text"
                        class="form-control form-control-sm"
                        v-model.trim="keyword"
                        placeholder="搜尋專題名稱或組員名稱">
                </div>
            </div>

            <div class="mt-2 d-flex flex-wrap align-items-center gap-2">
                <div class="d-flex flex-wrap align-items-center gap-2 small">
                    <span class="fw-bold me-1">顯示欄位：</span>

                    <label class="me-1 mb-0">
                        <input type="checkbox" v-model="show_cols.group_name"> 類組名稱
                    </label>

                    <label class="me-1 mb-0">
                        <input type="checkbox" v-model="show_cols.teacher_names"> 指導老師
                    </label>

                    <label class="me-1 mb-0">
                        <input type="checkbox" v-model="show_cols.member_names"> 組員名稱
                    </label>

                    <label class="me-1 mb-0">
                        <input type="checkbox" v-model="show_cols.sfd_name"> 紀錄標題
                    </label>

                    <label class="me-2 mb-0">
                        <input type="checkbox" v-model="show_cols.sfd_suggest"> 建議
                    </label>

                    <label class="me-1 mb-0">
                        <input type="radio" value="both" v-model="chart_display_mode" :disabled="view_mode === 'chart'"> 同時顯示完成度與排名
                    </label>

                    <label class="me-1 mb-0">
                        <input type="radio" value="score" v-model="chart_display_mode"> 僅完成度
                    </label>

                    <label class="me-1 mb-0">
                        <input type="radio" value="rank" v-model="chart_display_mode"> 僅排名
                    </label>
                </div>

                <div class="ms-auto d-flex gap-2">
                    <button :class="view_mode=='table'?'btn btn-sm btn-primary':'btn btn-sm btn-outline-primary'" @click="view_mode='table'">清單</button>
                    <button :class="view_mode!='table'?'btn btn-sm btn-primary':'btn btn-sm btn-outline-primary'" @click="switchToChart">統計圖表</button>
                </div>
            </div>
        </div>

        <!-- 表格 -->
        <div v-if="view_mode === 'table'">
            <div v-if="sorted_AI.length" style="max-height:60vh; overflow:auto; margin-top:10px;">
                <div class="outcome-table-wrap modal-table-wrap">
                    <table class="outcome-table fixed outcome-summary-table">
                        <thead>
                            <tr>
                                <th
                                    v-if="role_ID != 4 && show_cols.group_name"
                                    class="sortable"
                                    @click="toggleSort('group_name')">
                                    類組名稱
                                    <span v-if="sort_key === 'group_name'">{{ sort_order === 'asc' ? '▲' : '▼' }}</span>
                                </th>

                                <th v-if="show_cols.teacher_names">指導老師</th>
                                <th v-if="show_cols.member_names">組員名稱</th>
                                <th v-if="role_ID != 6">專題名稱</th>
                                <th v-if="show_cols.sfd_name">紀錄標題</th>

                                <th
                                    v-if="show_score_col"
                                    class="sortable"
                                    @click="toggleSort('sfd_score')">
                                    完成度
                                    <span v-if="sort_key === 'sfd_score'">{{ sort_order === 'asc' ? '▲' : '▼' }}</span>
                                </th>

                                <th
                                    v-if="show_rank_col"
                                    class="sortable"
                                    @click="toggleSort('sfd_rank')">
                                    排名
                                    <span v-if="sort_key === 'sfd_rank'">{{ sort_order === 'asc' ? '▲' : '▼' }}</span>
                                </th>

                                <th
                                    v-if="show_cols.sfd_suggest"
                                    style="text-align:left;">
                                    建議
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in sorted_AI" :key="r.sfd_ID">
                                <td v-if="show_cols.group_name">{{ r.group_name || '—' }}</td>
                                <td v-if="show_cols.teacher_names">{{ r.teacher_names || '—' }}</td>
                                <td v-if="show_cols.member_names">{{ r.member_names || '—' }}</td>
                                <td v-if="role_ID != 6">{{ r.team_project_name || '—' }}</td>
                                <td v-if="show_cols.sfd_name">{{ r.sfd_name || '—' }}</td>
                                <td v-if="show_score_col">{{ r.sfd_score ?? '—' }}</td>
                                <td v-if="show_rank_col">
                                    {{
            (r.sfd_rank !== null && r.sfd_rank !== '' && r.total_teams !== null && r.total_teams !== '')
                ? `${r.sfd_rank}/${r.total_teams}`
                : (r.sfd_rank ?? '—')
        }}
                                </td>
                                <td
                                    v-if="show_cols.sfd_suggest"
                                    class="text-start suggest-cell"
                                    style="white-space:pre-wrap;">
                                    {{ (r.sfd_suggest && r.sfd_suggest.trim()) ? r.sfd_suggest : '（尚無建議）' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-else class="text-center text-muted py-4">
                目前沒有符合條件的 AI 評分紀錄
            </div>
        </div>

        <!-- 折線圖 -->
        <div v-if="view_mode === 'chart'" class="card p-3 mt-3">
            <div v-if="chart_datasets.length">
                <div class="mb-2 text-muted">
                    <template>
                        當有多筆紀錄標題時，圖表為折線圖可瀏覽趨勢變化，否則將以長條圖顯示。
                    </template>
                </div>
                <canvas id="aiLineChart" height="100"></canvas>
            </div>
            <div v-else class="text-center text-muted py-4">
                沒有可顯示的圖表資料
            </div>
        </div>
        <div id="ai_loading" class="ai-loading">
            <div class="ai-loading-box">
                <div class="spinner"></div>
                <div class="ai-loading-text">AI評分中...請稍後</div>
            </div>
        </div>
        <script>
            function toast({
                type = 'info',
                title = '',
                text = '',
                ms = 3000
            } = {}) {
                Swal.fire({
                    toast: true,
                    position: 'bottom-end',
                    icon: type,
                    title: title,
                    html: text ? `<small>${text}</small>` : '',
                    timer: ms,
                    timerProgressBar: true,
                    showConfirmButton: false,
                    allowEscapeKey: false,
                    allowOutsideClick: false,
                    customClass: {
                        popup: 'my-toast'
                    }
                });
            }

            if (window.outcomeVueApp && typeof window.outcomeVueApp.unmount === 'function') {
                try {
                    window.outcomeVueApp.unmount();
                } catch (e) {
                    console.warn('卸載 outcome app 時出錯:', e);
                }
            }

            window.outcomeVueApp = null;

            if (!window.outcomeVueApp) {
                window.outcomeVueApp = Vue.createApp({
                    data() {
                        return {
                            role_ID: <?= isset($_SESSION["role_ID"]) ? json_encode((int)$_SESSION["role_ID"]) : 'null' ?>,
                            u_name: <?= isset($_SESSION["u_name"]) ? json_encode($_SESSION["u_name"]) : 'null' ?>,
                            u_ID: <?= isset($_SESSION["u_ID"]) ? json_encode($_SESSION["u_ID"]) : 'null' ?>,
                            all_expected: [],

                            filter_group: '',
                            filter_teacher: '',
                            filter_sfd_name: '',
                            filter_project_name: '',
                            keyword: '',

                            view_mode: 'table',
                            chartObj: null,

                            sort_key: '',
                            sort_order: '',

                            show_cols: {
                                group_name: true,
                                teacher_names: true,
                                member_names: true,
                                sfd_name: true,
                                sfd_suggest: false
                            },
                            chart_display_mode: 'both',
                        }
                    },
                    computed: {
                        group_options() {
                            return [...new Set(
                                this.all_expected
                                .map(x => x.group_name)
                                .filter(Boolean)
                            )].sort();
                        },
                        teacher_options() {
                            return [...new Set(
                                this.all_expected
                                .flatMap(x => (x.teacher_names || '').split('、'))
                                .map(x => x.trim())
                                .filter(Boolean)
                            )].sort();
                        },
                        sfd_name_options() {
                            return [...new Set(
                                this.all_expected
                                .map(x => x.sfd_name)
                                .filter(Boolean)
                            )].sort();
                        },
                        project_name_options() {
                            return [...new Set(
                                this.all_expected
                                .map(x => x.team_project_name)
                                .filter(Boolean)
                            )].sort((a, b) => a.localeCompare(b, 'zh-Hant'));
                        },
                        filtered_AI() {
                            return this.all_expected.filter(r => {
                                const matchGroup = !this.filter_group || r.group_name === this.filter_group;

                                const teacherArr = (r.teacher_names || '')
                                    .split('、')
                                    .map(x => x.trim())
                                    .filter(Boolean);

                                const matchTeacher = !this.filter_teacher || teacherArr.includes(this.filter_teacher);

                                const matchSfdName = !this.filter_sfd_name || r.sfd_name === this.filter_sfd_name;
                                const matchProjectName = !this.filter_project_name || r.team_project_name === this.filter_project_name;

                                const kw = this.keyword.trim().toLowerCase();
                                const matchKeyword = !kw ||
                                    (r.team_project_name || '').toLowerCase().includes(kw) ||
                                    (r.member_names || '').toLowerCase().includes(kw);

                                return matchGroup && matchTeacher && matchSfdName && matchProjectName && matchKeyword;
                            });
                        },

                        sorted_AI() {
                            const rows = this.filtered_AI.slice();

                            if (!this.sort_key || !this.sort_order) {
                                return rows;
                            }

                            rows.sort((a, b) => {
                                let av = a[this.sort_key];
                                let bv = b[this.sort_key];

                                if (this.sort_key === 'group_name') {
                                    av = (av || '').toString();
                                    bv = (bv || '').toString();
                                    const result = av.localeCompare(bv, 'zh-Hant');
                                    return this.sort_order === 'asc' ? result : -result;
                                }

                                if (this.sort_key === 'sfd_score' || this.sort_key === 'sfd_rank') {
                                    const aNull = av === null || av === undefined || av === '';
                                    const bNull = bv === null || bv === undefined || bv === '';

                                    if (aNull && bNull) return 0;
                                    if (aNull) return 1;
                                    if (bNull) return -1;

                                    av = Number(av);
                                    bv = Number(bv);

                                    return this.sort_order === 'asc' ? av - bv : bv - av;
                                }

                                return 0;
                            });

                            return rows;
                        },

                        chart_labels() {
                            if (this.isSingleSfdName) {
                                return this.filtered_AI
                                    .map(x => x.team_project_name)
                                    .filter(Boolean);
                            }

                            return [...new Set(
                                this.filtered_AI
                                .map(x => x.sfd_name)
                                .filter(Boolean)
                            )];
                        },
                        isSingleSfdName() {
                            const names = [...new Set(
                                this.filtered_AI
                                .map(x => x.sfd_name)
                                .filter(Boolean)
                            )];

                            return names.length === 1;
                        },
                        chart_datasets() {
                            const rows = this.filtered_AI.filter(x => x.sfd_name);

                            if (!rows.length) return [];

                            // ✅ 只有一個紀錄標題時：只畫完成度長條，排名改用標籤顯示
                            if (this.isSingleSfdName) {
                                const colorList = [
                                    '#4e73df', '#e74a3b', '#1cc88a', '#f6c23e', '#36b9cc',
                                    '#6f42c1', '#fd7e14', '#20c997', '#d63384', '#198754',
                                    '#0d6efd', '#dc3545', '#6610f2', '#ffc107', '#0dcaf0'
                                ];

                                const bgColors = this.singleBarChartRows.map((_, idx) => colorList[idx % colorList.length]);
                                const datasets = [];

                                if (this.chart_display_mode === 'both' || this.chart_display_mode === 'score') {
                                    datasets.push({
                                        label: '完成度',
                                        data: this.singleBarChartRows.map(x => x.score),
                                        backgroundColor: bgColors,
                                        borderColor: bgColors,
                                        borderWidth: 1,
                                        yAxisID: 'y'
                                    });
                                }

                                if (this.chart_display_mode === 'both' || this.chart_display_mode === 'rank') {
                                    datasets.push({
                                        label: '排名',
                                        data: this.singleBarChartRows.map(x => x.rank),
                                        backgroundColor: bgColors.map(c => c + 'cc'),
                                        borderColor: bgColors,
                                        borderWidth: 1,
                                        yAxisID: this.chart_display_mode === 'both' ? 'y1' : 'y'
                                    });
                                }

                                return datasets;
                            }

                            const teamMap = {};

                            rows.forEach(r => {
                                const teamKey = r.team_project_name || `team_${r.sfd_team_ID}`;

                                if (!teamMap[teamKey]) {
                                    teamMap[teamKey] = {};
                                }

                                teamMap[teamKey][r.sfd_name] = {
                                    score: r.sfd_score !== null && r.sfd_score !== '' ? Number(r.sfd_score) : null,
                                    rank: r.sfd_rank !== null && r.sfd_rank !== '' ? Number(r.sfd_rank) : null
                                };
                            });

                            const colorList = [
                                '#4e73df', '#e74a3b', '#1cc88a', '#f6c23e', '#36b9cc',
                                '#6f42c1', '#fd7e14', '#20c997', '#d63384', '#198754',
                                '#0d6efd', '#dc3545', '#6610f2', '#ffc107', '#0dcaf0'
                            ];

                            const datasets = [];
                            let colorIndex = 0;

                            Object.keys(teamMap).forEach(teamName => {
                                const color = colorList[colorIndex % colorList.length];
                                colorIndex++;

                                const scoreData = this.chart_labels.map(label => {
                                    const row = teamMap[teamName][label];
                                    return row ? row.score : null;
                                });

                                const rankData = this.chart_labels.map(label => {
                                    const row = teamMap[teamName][label];
                                    return row ? row.rank : null;
                                });

                                if (this.chart_display_mode === 'score') {
                                    datasets.push({
                                        label: `${teamName}`,
                                        data: scoreData,
                                        borderColor: color,
                                        backgroundColor: color,
                                        tension: 0.25,
                                        spanGaps: true,
                                        yAxisID: 'y',
                                        borderWidth: 3
                                    });
                                }

                                if (this.chart_display_mode === 'rank') {
                                    datasets.push({
                                        label: `${teamName}`,
                                        data: rankData,
                                        borderColor: color,
                                        backgroundColor: color,
                                        tension: 0.25,
                                        spanGaps: true,
                                        yAxisID: 'y',
                                        borderDash: [6, 4],
                                        borderWidth: 2
                                    });
                                }
                            });

                            return datasets;
                        },
                        show_score_col() {
                            return this.chart_display_mode === 'both' || this.chart_display_mode === 'score';
                        },
                        show_rank_col() {
                            return this.chart_display_mode === 'both' || this.chart_display_mode === 'rank';
                        },
                        singleBarDatasets() {
                            if (!this.isSingleSfdName) return [];

                            const rows = this.filtered_AI.filter(x => x.team_project_name);

                            if (!rows.length) return [];

                            const colorList = [
                                '#4e73df', '#e74a3b', '#1cc88a', '#f6c23e', '#36b9cc',
                                '#6f42c1', '#fd7e14', '#20c997', '#d63384', '#198754',
                                '#0d6efd', '#dc3545', '#6610f2', '#ffc107', '#0dcaf0'
                            ];

                            const scoreData = rows.map(x =>
                                x.sfd_score !== null && x.sfd_score !== '' ? Number(x.sfd_score) : null
                            );

                            const rankData = rows.map(x =>
                                x.sfd_rank !== null && x.sfd_rank !== '' ? Number(x.sfd_rank) : null
                            );

                            const bgColors = rows.map((_, idx) => colorList[idx % colorList.length]);

                            const datasets = [];

                            if (this.chart_display_mode === 'both' || this.chart_display_mode === 'score') {
                                datasets.push({
                                    label: '完成度',
                                    data: scoreData,
                                    backgroundColor: bgColors,
                                    borderColor: bgColors,
                                    borderWidth: 1,
                                    yAxisID: 'y',
                                    datalabelType: 'score'
                                });
                            }

                            if (this.chart_display_mode === 'both' || this.chart_display_mode === 'rank') {
                                datasets.push({
                                    label: '排名',
                                    data: rankData,
                                    backgroundColor: bgColors.map(c => c + 'cc'),
                                    borderColor: bgColors,
                                    borderWidth: 1,
                                    yAxisID: 'y1',
                                    datalabelType: 'rank'
                                });
                            }

                            return datasets;
                        },
                        singleBarChartRows() {
                            if (!this.isSingleSfdName) return [];

                            return this.filtered_AI
                                .filter(x => x.team_project_name)
                                .map(x => ({
                                    team_project_name: x.team_project_name,
                                    score: x.sfd_score !== null && x.sfd_score !== '' ? Number(x.sfd_score) : 0,
                                    rank: x.sfd_rank !== null && x.sfd_rank !== '' ? Number(x.sfd_rank) : null
                                }));
                        },
                    },
                    methods: {
                        get_expected() {
                            return $.post("../modules/expected_show_all.php?do=get_exresultdata", {
                                u_name: this.u_name,
                                role_ID: this.role_ID
                            }).done(
                                item => {
                                    this.all_expected = item;
                                },
                                "json"
                            );
                        },

                        toggleSort(key) {
                            if (this.sort_key !== key) {
                                this.sort_key = key;

                                if (key === 'sfd_rank') {
                                    this.sort_order = 'asc';
                                } else if (key === 'group_name') {
                                    this.sort_order = 'desc';
                                } else if (key === 'sfd_score') {
                                    this.sort_order = 'desc';
                                }
                                return;
                            }

                            this.sort_order = this.sort_order === 'asc' ? 'desc' : 'asc';
                        },

                        switchToChart() {
                            this.view_mode = 'chart';

                            // 折線圖預設顯示「僅完成度」
                            if (!this.isSingleSfdName) {
                                this.chart_display_mode = 'score';
                            }

                            this.$nextTick(() => {
                                this.renderChart();
                            });
                        },

                        renderChart() {
                            if (this.chartObj) {
                                this.chartObj.destroy();
                                this.chartObj = null;
                            }

                            if (!this.chart_datasets.length || !this.chart_labels.length) return;

                            const ctx = document.getElementById('aiLineChart');
                            if (!ctx) return;

                            const isBarMode = this.isSingleSfdName;

                            this.chartObj = new Chart(ctx, {
                                type: isBarMode ? 'bar' : 'line',
                                data: {
                                    labels: this.chart_labels,
                                    datasets: this.chart_datasets
                                },
                                plugins: [ChartDataLabels],
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: true,
                                    interaction: {
                                        mode: 'index',
                                        intersect: false
                                    },
                                    plugins: {
                                        legend: {
                                            position: 'bottom'
                                        },
                                        datalabels: {
                                            display: false
                                        }
                                    },
                                    scales: isBarMode ? (
                                        this.chart_display_mode === 'both' ? {
                                            x: {
                                                title: {
                                                    display: true,
                                                    text: '專題名稱'
                                                }
                                            },
                                            y: {
                                                type: 'linear',
                                                position: 'left',
                                                beginAtZero: true,
                                                title: {
                                                    display: true,
                                                    text: '完成度'
                                                },
                                                ticks: {
                                                    precision: 0
                                                }
                                            },
                                            y1: {
                                                type: 'linear',
                                                position: 'right',
                                                beginAtZero: true,
                                                reverse: true,
                                                grid: {
                                                    drawOnChartArea: false
                                                },
                                                title: {
                                                    display: true,
                                                    text: '排名'
                                                },
                                                ticks: {
                                                    precision: 0,
                                                    stepSize: 1
                                                }
                                            }
                                        } : {
                                            x: {
                                                title: {
                                                    display: true,
                                                    text: '專題名稱'
                                                }
                                            },
                                            y: {
                                                type: 'linear',
                                                position: 'left',
                                                beginAtZero: true,
                                                reverse: this.chart_display_mode === 'rank',
                                                title: {
                                                    display: true,
                                                    text: this.chart_display_mode === 'score' ? '完成度' : '排名'
                                                },
                                                ticks: {
                                                    precision: 0,
                                                    stepSize: this.chart_display_mode === 'rank' ? 1 : undefined
                                                }
                                            }
                                        }
                                    ) : {
                                        y: {
                                            type: 'linear',
                                            position: 'left',
                                            beginAtZero: this.chart_display_mode === 'score',
                                            reverse: this.chart_display_mode === 'rank',
                                            title: {
                                                display: true,
                                                text: this.chart_display_mode === 'score' ? '完成度' : '排名'
                                            },
                                            ticks: {
                                                precision: 0,
                                                stepSize: this.chart_display_mode === 'rank' ? 1 : undefined
                                            }
                                        }
                                    }
                                }
                            });
                        },
                        go_back() {
                            if (this.role_ID == 4) {
                                window.location.href = "main.php#pages/expected_teacher.php";
                            } else if (this.role_ID == 6) {
                                window.location.href = "main.php#pages/expected_outcome.php";
                            }
                        }
                    },
                    watch: {
                        filtered_AI: {
                            handler() {
                                if (this.view_mode === 'chart') {
                                    this.$nextTick(() => {
                                        this.renderChart();
                                    });
                                }
                            },
                            deep: true
                        },
                        chart_display_mode() {
                            if (this.view_mode === 'chart') {
                                this.$nextTick(() => {
                                    this.renderChart();
                                });
                            }
                        },
                        isSingleSfdName(val) {
                            if (this.view_mode === 'chart') {
                                if (!val && this.chart_display_mode === 'both') {
                                    this.chart_display_mode = 'score';
                                    return;
                                }

                                this.$nextTick(() => {
                                    this.renderChart();
                                });
                            }
                        },
                    },
                    mounted() {
                        $("#ai_loading").show()

                        $.post("../modules/expected_outcome.php?do=auto_AI_score")
                            .done(res => {
                                console.log("AI評分完成")
                            })
                            .always(() => {
                                $("#ai_loading").hide()
                                this.get_expected();
                            })


                        if (this.role_ID === 4 && this.u_name) {
                            this.filter_teacher = this.u_name;
                        }
                        this.show_cols.group_name = this.role_ID != 6 && this.role_ID != 4;
                        this.show_cols.teacher_names = this.role_ID != 6 && this.role_ID != 4;
                        this.show_cols.member_names = this.role_ID != 6;
                        this.show_cols.sfd_suggest = this.role_ID == 6;
                    }
                }).mount("#outcome_app")
            }
        </script>
    </div>