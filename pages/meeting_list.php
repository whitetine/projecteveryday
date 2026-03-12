<?php
// pages/meeting_list.php - 此組別開的每次會議（會議歷史紀錄）
session_start();
require '../includes/pdo.php';

$role_ID = $_SESSION['role_ID'] ?? null;
$is_student = ((int)$role_ID === 6);

// team_ID 可選：學生不帶 team_ID 時由 API 自動解析所屬組別
$team_ID = isset($_GET['team_ID']) ? (int)$_GET['team_ID'] : null;
$open_create = isset($_GET['create']) && $_GET['create'] !== '' && $_GET['create'] !== '0';
$team_name = '';

if ($team_ID) {
    $stmt = $conn->prepare("SELECT COALESCE(NULLIF(TRIM(team_project_name),''), CONCAT('組別 ', team_ID)) FROM teamdata WHERE team_ID = ?");
    $stmt->execute([$team_ID]);
    $team_name = $stmt->fetchColumn() ?: "未知團隊";
}

$apiBase = 'modules/meeting_api.php';
$cssBase = 'css/meeting.css';
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>會議歷史紀錄</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .meeting-list-page { padding: 20px; max-width: 1400px; margin: 0 auto; font-size: 18px; }
        .meeting-list-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 8px; }
        .meeting-list-title-row { display:flex; align-items: baseline; gap:12px; flex-wrap:wrap; }
        .meeting-list-title { font-size: 20px; font-weight: 800; margin: 0; color:#0f172a; }
        .meeting-list-subtitle { font-size: 22px; font-weight: 600; color:#0f172a; }
        .btn-create { background: var(--meeting-primary, #2563eb); color: white; padding: 12px 20px; border-radius: 8px; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; font-size: 18px; font-weight: 600; border: none; cursor: pointer; white-space: nowrap; }
        .btn-create:hover { background: #1d4ed8; color: white; }
        .btn-back { display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; border-radius: 8px; font-size: 18px; font-weight: 600; background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; text-decoration: none; white-space: nowrap; }
        .btn-back:hover { background: #e2e8f0; color: #334155; }

        .meeting-stat-cards { display: flex; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 12px; }
        .meeting-stat-card { display: flex; align-items: center; gap: 10px; background: #f1f5f9; border-radius: 10px; padding: 10px 16px; border: 1px solid #e2e8f0; min-height: 0; }
        .meeting-stat-card .meeting-stat-icon { font-size: 20px; color: #64748b; }
        .meeting-stat-card .meeting-stat-value { font-size: 18px; font-weight: 700; color: #0f172a; }
        .meeting-stat-card .meeting-stat-label { font-size: 20px; color: #475569; }
        .meeting-stat-sub { display: none; }
        .meeting-stat-member-wrap { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; background: #f1f5f9; border-radius: 10px; padding: 10px 16px; border: 1px solid #e2e8f0; }
        .meeting-stat-member-wrap .meeting-stat-member-title { font-size: 20px; font-weight: 700; color: #475569; }
        .meeting-stat-member-wrap .meeting-stat-member-list { font-size: 18px; color: #0f172a; }

        .meeting-list-table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.06); font-size: 18px; }
        .meeting-list-table th { padding: 14px 16px; text-align: center; font-size: 20px; font-weight: 700; color: var(--meeting-muted, #64748b); background: #f8fafc; border-bottom: 1px solid var(--meeting-border, #e2e8f0); }
        .meeting-list-table td { padding: 14px 16px; border-bottom: 1px solid #f1f5f9; font-size: 18px; color: var(--meeting-text, #111827); vertical-align: top; text-align: center; }
        .meeting-list-table tr:hover { background: #fafbfc; }
        .meeting-list-table a { font-size: 18px; }
        .meeting-list-table .col-meeting-title { text-align: center; }
        .meeting-list-table .col-summary { text-align: center; }
        .meeting-list-table .col-attendance { text-align: center; }
        .meeting-list-table .col-op { text-align: center; }
        .meeting-list-table .btn-view { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 6px 12px; border-radius: 6px; text-decoration: none; color: #fff; background: var(--meeting-primary,#2563eb); font-weight: 600; white-space: nowrap; }

        .col-meeting-title { max-width: 260px; }
        .meeting-main-title { font-weight: 700; font-size: 20px; color: #0f172a; margin-bottom: 2px; }

        .col-summary { max-width: 360px; font-size: 18px; color: var(--meeting-muted, #64748b); }
        .ai-summary { max-width: 360px; font-size: 18px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }

        .col-attendance { font-size: 18px; }
        .attendance-rate-badge { display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 999px; font-size: 18px; font-weight: 600; background: #dcfce7; color: #15803d; }
        .attendance-rate-badge.none { background: #e5e7eb; color: #4b5563; font-weight: 500; }
        .attendance-meta { margin-top: 6px; font-size: 18px; color: #0f172a; }
        .attendance-member-line { display: flex; gap: 16px; margin-bottom: 4px; }
        .attendance-member { font-size: 18px; }
        .attendance-member strong { font-weight: 600; }
        .attendance-member-rate { margin-left: 4px; font-weight: 600; }

        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); display: none; justify-content: center; align-items: center; z-index: 1000; }
        .modal-box { background: #fff; padding: 24px; border-radius: 12px; width: 400px; border: 1px solid var(--meeting-border, #e2e8f0); }
        .modal-box input { width: 100%; padding: 10px; margin: 12px 0; border: 1px solid var(--meeting-border, #e2e8f0); border-radius: 6px; font-size: 15px; }
        .modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 16px; }
        .btn-cancel { background: #e2e8f0; color: #475569; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 0.9rem; }
        .btn-confirm { background: var(--meeting-primary, #2563eb); color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 0.9rem; }

        @media (max-width: 768px) {
            .meeting-list-page { padding: 16px 12px; }
            .meeting-list-table th,
            .meeting-list-table td { padding: 10px 12px; }
            .col-summary, .ai-summary { max-width: 260px; }
        }
    </style>
</head>
<body>
<link rel="stylesheet" href="<?= htmlspecialchars($cssBase) ?>?v=<?= time() ?>" id="meeting-list-css">

<div class="meeting-list-page">
    <div class="meeting-list-header">
        <div class="meeting-list-title-row">
            <h3 class="meeting-list-title">會議歷史紀錄</h3>
            <span id="teamInfo" class="meeting-list-subtitle">
                <?php if ($team_name): ?>組別・<?= htmlspecialchars($team_name) ?><?php else: ?>組別載入中…<?php endif; ?>
            </span>
            <?php if ($team_ID): ?>
            <a href="#pages/meeting.php" id="btnBackToList" class="btn-back ajax-link" title="返回會議總覽">
                <i class="fa-solid fa-arrow-left"></i> 回上一頁
            </a>
            <?php endif; ?>
        </div>
        <div style="flex:1"></div>
    </div>

    <div id="meetingStats" class="meeting-stat-cards" style="display:none;"></div>

    <div id="meetingList">
        <p style="color:#666; text-align:center; padding:40px;">載入中...</p>
    </div>
</div>

<div class="modal-overlay" id="createModal">
    <div class="modal-box">
        <h3 style="margin-top:0">建立新會議</h3>
        <input type="text" id="newMeetingTitle" placeholder="請輸入會議名稱 (例如：第一次指導會議)">
        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeCreateModal()">取消</button>
            <button class="btn-confirm" onclick="submitNewMeeting()">建立並進入</button>
        </div>
    </div>
</div>

<script>
(function() {
const MEETING_LIST_TEAM_ID = <?= json_encode($team_ID) ?>;
const MEETING_LIST_OPEN_CREATE = <?= $open_create ? 'true' : 'false' ?>;
const MEETING_LIST_API = <?= json_encode($apiBase) ?>;
const MEETING_LIST_IS_STUDENT = <?= json_encode($is_student) ?>;

async function loadMeetings() {
    try {
        let url = MEETING_LIST_API + '?do=get_meeting_list';
        if (MEETING_LIST_TEAM_ID) url += '&team_ID=' + MEETING_LIST_TEAM_ID;
        const res = await fetch(url);
        const data = await res.json();
        const listDiv = document.getElementById('meetingList');
        const teamInfo = document.getElementById('teamInfo');

        if (!data.ok) {
            listDiv.innerHTML = '<div style="text-align:center; color:#dc2626; padding:40px;">' + (data.msg || '載入失敗') + '</div>';
            if (teamInfo) teamInfo.textContent = data.msg || '載入失敗';
            return;
        }

        const resolvedTeamId = data.team_ID || MEETING_LIST_TEAM_ID;
        const teamName = data.team_name || '';
        window._meetingListResolvedTeamId = resolvedTeamId;

        if (teamInfo && teamName) {
            teamInfo.textContent = '組別・' + teamName;
        }
        const backBtn = document.getElementById('btnBackToList');
        if (backBtn && resolvedTeamId) {
            const base = (typeof location.hash !== 'undefined' && location.hash) ? '#' : '';
            backBtn.href = base + 'pages/meeting.php';
            backBtn.style.display = 'inline-flex';
        }

        // 統計列：緊湊單列（圖三風格）+ 每位組員平均出席率同一列
        const statsBox = document.getElementById('meetingStats');
        if (data.list && data.list.length > 0) {
            const totalCount = data.list.length;
            let sumRate = 0;
            let rateCount = 0;
            let aiCount = 0;
            const memberAgg = {};
            data.list.forEach(m => {
                if (m.attendance_rate !== null && m.attendance_rate !== undefined) {
                    sumRate += Number(m.attendance_rate) || 0;
                    rateCount++;
                }
                if ((m.m_summary || '').trim() !== '') aiCount++;
                if (m.member_attendance && m.member_attendance.length > 0) {
                    m.member_attendance.forEach(ma => {
                        const key = (ma.u_ID || ma.u_name || '').toString() || ('n_' + (ma.u_name || ''));
                        if (!memberAgg[key]) memberAgg[key] = { name: ma.u_name || '—', ok: 0, no: 0 };
                        if (ma.status === 'ok') memberAgg[key].ok++;
                        else if (ma.status === 'no') memberAgg[key].no++;
                    });
                }
            });
            const avgRate = rateCount > 0 ? Math.round(sumRate / rateCount) : null;
            const memberAvgParts = [];
            Object.keys(memberAgg).forEach(k => {
                const a = memberAgg[k];
                const total = a.ok + a.no;
                const rate = total > 0 ? Math.round((a.ok / total) * 100) : null;
                memberAvgParts.push(escapeHtml(a.name) + ' ' + (rate !== null ? rate + '%' : '—'));
            });
            statsBox.style.display = 'flex';
            statsBox.innerHTML = `
                <div class="meeting-stat-card">
                    <span class="meeting-stat-icon"><i class="fa-solid fa-calendar-days"></i></span>
                    <span class="meeting-stat-value">${totalCount}</span>
                    <span class="meeting-stat-label">次 會議</span>
                </div>
                <div class="meeting-stat-card">
                    <span class="meeting-stat-icon"><i class="fa-solid fa-user-check"></i></span>
                    <span class="meeting-stat-value">${avgRate !== null ? (avgRate + '%') : '—'}</span>
                    <span class="meeting-stat-label">平均出席率</span>
                </div>
                <div class="meeting-stat-card">
                    <span class="meeting-stat-icon"><i class="fa-solid fa-robot"></i></span>
                    <span class="meeting-stat-value">${aiCount}</span>
                    <span class="meeting-stat-label">次 AI摘要</span>
                </div>
                <div class="meeting-stat-member-wrap">
                    <span class="meeting-stat-member-title">組員出席率</span>
                    <span class="meeting-stat-member-list">${memberAvgParts.length ? memberAvgParts.join('　') : '—'}</span>
                </div>
            `;
        } else {
            statsBox.style.display = 'none';
            statsBox.innerHTML = '';
        }

        if (data.list && data.list.length > 0) {
            let html = `
                <table class="meeting-list-table">
                    <thead>
                        <tr>
                            <th style="width:28%;">會議</th>
                            <th style="width:18%;">會議時間</th>
                            <th style="width:16%;">出席</th>
                            <th style="width:32%;">AI 摘要</th>
                            <th style="width:90px;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            data.list.forEach(m => {
                const summary = (m.m_summary || '').trim() || '尚無摘要';
                const meetingTitle = (m.m_title || '未命名會議').trim();
                const meetingNumber = m.meeting_number ? `第 ${m.meeting_number} 次會議` : '會議';
                const meetingDisplayTitle = meetingNumber + (meetingTitle && meetingTitle !== '未命名會議' ? '：' + meetingTitle : '');

                const meetingTime = m.m_start_display || m.m_created_display || (m.m_date && m.m_start_time ? m.m_date + ' ' + m.m_start_time.substring(0, 5) : '—');
                const meetingUrl = (typeof location.hash !== 'undefined' && location.hash)
                    ? '#pages/meeting.php?team_ID=' + resolvedTeamId + '&m_ID=' + m.m_ID
                    : 'meeting.php?team_ID=' + resolvedTeamId + '&m_ID=' + m.m_ID;

                let attendanceHtml = '';
                if (m.attendance_rate !== null && m.attendance_rate !== undefined) {
                    attendanceHtml = '<span class="attendance-rate-badge">' + m.attendance_rate + '%</span>';
                }
                const memberLines = [];
                if (m.member_attendance && m.member_attendance.length > 0) {
                    for (let i = 0; i < m.member_attendance.length; i += 2) {
                        const lineMembers = m.member_attendance.slice(i, i + 2);
                        const lineHtml = lineMembers.map(ma => {
                            const baseName = ma.u_name || '';
                            let statusText = '—';
                            if (ma.status === 'ok') statusText = '出席';
                            else if (ma.status === 'no') statusText = '未出席';
                            return `<span class="attendance-member"><strong>${escapeHtml(baseName)}</strong><span class="attendance-member-rate">${statusText}</span></span>`;
                        }).join('');
                        memberLines.push(`<div class="attendance-member-line">${lineHtml}</div>`);
                    }
                }
                if (memberLines.length) {
                    attendanceHtml += `<div class="attendance-meta">${memberLines.join('')}</div>`;
                }

                html += `
                    <tr>
                        <td class="col-meeting-title">
                            <div class="meeting-main-title">${escapeHtml(meetingDisplayTitle)}</div>
                        </td>
                        <td>${escapeHtml(meetingTime)}</td>
                        <td class="col-attendance">${attendanceHtml}</td>
                        <td class="col-summary">
                            <div class="ai-summary" title="${escapeHtml(summary)}">${escapeHtml(summary)}</div>
                        </td>
                        <td class="col-op">
                            <a href="${meetingUrl}" class="btn-view ajax-link">
                                <i class="fa-solid fa-file-lines"></i> 查看
                            </a>
                        </td>
                    </tr>
                `;
            });
            html += '</tbody></table>';
            listDiv.innerHTML = html;
        } else {
            listDiv.innerHTML = '<div style="text-align:center; color:#666; padding:40px; font-size:25px;">' +
                (MEETING_LIST_IS_STUDENT ? '目前沒有會議紀錄。' : '目前沒有會議紀錄。') + '</div>';
        }
    } catch (e) {
        document.getElementById('meetingList').innerHTML = '<div style="text-align:center; color:#dc2626; padding:40px; font-size:25px;">載入失敗，請稍後再試。</div>';
        console.error(e);
    }
}

function escapeHtml(s) {
    const div = document.createElement('div');
    div.textContent = s;
    return div.innerHTML;
}

function openCreateModal() {
    const tid = MEETING_LIST_TEAM_ID || (window._meetingListResolvedTeamId || 0);
    if (!tid) {
        alert('找不到組別，請稍後再試或重新整理頁面');
        return;
    }
    document.getElementById('createModal').style.display = 'flex';
    document.getElementById('newMeetingTitle').value = '';
    document.getElementById('newMeetingTitle').focus();
}
function closeCreateModal() {
    document.getElementById('createModal').style.display = 'none';
}

async function submitNewMeeting() {
    const title = document.getElementById('newMeetingTitle').value.trim();
    if (!title) return alert('請輸入標題');
    let teamId = MEETING_LIST_TEAM_ID;
    if (!teamId) {
        const d = await fetch(MEETING_LIST_API + '?do=get_meeting_list').then(r=>r.json());
        teamId = d.team_ID;
    }
    if (!teamId) return alert('找不到組別');
    try {
        const res = await fetch(MEETING_LIST_API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ do: 'create_meeting', team_ID: parseInt(teamId, 10), title: title })
        });
        const data = await res.json();
        if (data.ok) {
            closeCreateModal();
            const meetingUrl = (typeof location.hash !== 'undefined' && location.hash)
                ? '#pages/meeting.php?team_ID=' + teamId + '&m_ID=' + data.m_ID
                : 'meeting.php?team_ID=' + teamId + '&m_ID=' + data.m_ID;
            window.location.href = meetingUrl;
        } else {
            alert(data.msg || '建立失敗');
        }
    } catch (e) {
        alert('系統錯誤');
    }
}

loadMeetings().then(function() {
    if (MEETING_LIST_OPEN_CREATE && (MEETING_LIST_TEAM_ID || window._meetingListResolvedTeamId)) {
        const tid = MEETING_LIST_TEAM_ID || window._meetingListResolvedTeamId;
        if (tid) setTimeout(openCreateModal, 300);
    }
});
// 暴露給全域，供 main.php 內嵌時 onclick 呼叫
window.openCreateModal = openCreateModal;
window.closeCreateModal = closeCreateModal;
window.submitNewMeeting = submitNewMeeting;
})();
</script>
</body>
</html>
