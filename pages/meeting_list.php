<?php
// pages/meeting_list.php - 此組別開的每次會議（會議歷史紀錄）
session_start();
require '../includes/pdo.php';

$role_ID = $_SESSION['role_ID'] ?? null;
$is_student = ((int)$role_ID === 6);

// team_ID 可選：學生不帶 team_ID 時由 API 自動解析所屬組別
$team_ID = isset($_GET['team_ID']) ? (int)$_GET['team_ID'] : null;
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
        .meeting-list-page { padding: 20px; max-width: 1400px; margin: 0 auto; }
        .meeting-list-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; margin-bottom: 16px; }
        .meeting-list-title { font-size: 1.75rem; font-weight: 800; margin: 0; }
        .btn-create { background: var(--meeting-primary, #2563eb); color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; font-size: 1rem; font-weight: 600; border: none; cursor: pointer; }
        .btn-create:hover { background: #1d4ed8; color: white; }

        .meeting-stat-cards { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; }
        .meeting-stat-card { flex: 1 1 160px; min-width: 0; background: #f8fafc; border-radius: 12px; padding: 12px 14px; border: 1px solid var(--meeting-border, #e2e8f0); display: flex; flex-direction: column; gap: 4px; }
        .meeting-stat-label { font-size: 0.8rem; color: #64748b; }
        .meeting-stat-value { font-size: 1.25rem; font-weight: 700; color: #0f172a; }
        .meeting-stat-sub { font-size: 0.78rem; color: #94a3b8; }

        .meeting-list-table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
        .meeting-list-table th { padding: 12px 16px; text-align: left; font-size: 0.9rem; font-weight: 700; color: var(--meeting-muted, #64748b); background: #f8fafc; border-bottom: 1px solid var(--meeting-border, #e2e8f0); }
        .meeting-list-table td { padding: 12px 16px; border-bottom: 1px solid #f1f5f9; font-size: 0.9rem; color: var(--meeting-text, #334155); vertical-align: top; }
        .meeting-list-table tr:hover { background: #fafbfc; }

        .col-meeting-title { max-width: 260px; }
        .meeting-main-title { font-weight: 700; color: #0f172a; margin-bottom: 2px; }
        .meeting-sub-title { font-size: 0.85rem; color: #64748b; }

        .col-summary { max-width: 360px; font-size: 0.85rem; color: var(--meeting-muted, #64748b); }
        .ai-summary {
            max-width: 360px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .col-attendance { font-size: 0.85rem; }
        .attendance-rate-badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 600;
            background: #dcfce7;
            color: #15803d;
        }
        .attendance-rate-badge.none {
            background: #e5e7eb;
            color: #4b5563;
            font-weight: 500;
        }
        .attendance-meta { margin-top: 4px; font-size: 0.78rem; color: #6b7280; display: flex; align-items: center; gap: 6px; }

        .member-tooltip { cursor: default; border-bottom: 1px dotted #9ca3af; }

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
        <h3 class="meeting-list-title">會議歷史紀錄</h3>
        <button class="btn-create" id="btnCreateMeeting" onclick="openCreateModal()">
            <i class="fa-solid fa-plus"></i> 新增會議
        </button>
    </div>
    <p id="teamInfo" style="color:var(--meeting-muted, #64748b); font-size:15px; margin:0 0 16px 0;">
        <?php if ($team_name): ?>團隊：<?= htmlspecialchars($team_name) ?><?php else: ?>載入中…<?php endif; ?>
    </p>

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
            teamInfo.textContent = '團隊：' + teamName;
        }

        // 統計卡片：總會議數、平均出席率、有 AI 摘要次數
        const statsBox = document.getElementById('meetingStats');
        if (data.list && data.list.length > 0) {
            const totalCount = data.list.length;
            let sumRate = 0;
            let rateCount = 0;
            let aiCount = 0;
            data.list.forEach(m => {
                if (m.attendance_rate !== null && m.attendance_rate !== undefined) {
                    sumRate += Number(m.attendance_rate) || 0;
                    rateCount++;
                }
                if ((m.m_summary || '').trim() !== '') aiCount++;
            });
            const avgRate = rateCount > 0 ? Math.round(sumRate / rateCount) : null;
            statsBox.style.display = 'flex';
            statsBox.innerHTML = `
                <div class="meeting-stat-card">
                    <div class="meeting-stat-label">總會議數</div>
                    <div class="meeting-stat-value">${totalCount} 次</div>
                    <div class="meeting-stat-sub">本組所有已建立的會議</div>
                </div>
                <div class="meeting-stat-card">
                    <div class="meeting-stat-label">平均出席率</div>
                    <div class="meeting-stat-value">${avgRate !== null ? (avgRate + '%') : '—'}</div>
                    <div class="meeting-stat-sub">依所有會議出席紀錄計算</div>
                </div>
                <div class="meeting-stat-card">
                    <div class="meeting-stat-label">有 AI 摘要</div>
                    <div class="meeting-stat-value">${aiCount} 次</div>
                    <div class="meeting-stat-sub">已產生 AI 摘要的會議數</div>
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
                            <th style="width:90px; text-align:right;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            data.list.forEach(m => {
                const summary = (m.m_summary || '').trim() || '尚無摘要';
                const meetingTitle = (m.m_title || '未命名會議').trim();
                const meetingNumber = m.meeting_number ? `第 ${m.meeting_number} 次會議` : '會議';
                const meetingSub = meetingTitle;

                const meetingTime = m.m_start_display || m.m_created_display || (m.m_date && m.m_start_time ? m.m_date + ' ' + m.m_start_time.substring(0, 5) : '—');
                const meetingUrl = (typeof location.hash !== 'undefined' && location.hash)
                    ? '#pages/meeting.php?team_ID=' + resolvedTeamId + '&m_ID=' + m.m_ID
                    : 'meeting.php?team_ID=' + resolvedTeamId + '&m_ID=' + m.m_ID;

                let attendanceHtml = '';
                if (m.attendance_rate !== null && m.attendance_rate !== undefined) {
                    attendanceHtml = '<span class="attendance-rate-badge">' + m.attendance_rate + '%</span>';
                } else {
                    attendanceHtml = '<span class="attendance-rate-badge none">尚無資料</span>';
                }
                let memberCount = 0;
                let memberNamesOk = [];
                if (m.member_attendance && m.member_attendance.length > 0) {
                    memberCount = m.member_attendance.length;
                    memberNamesOk = m.member_attendance
                        .filter(ma => ma.status === 'ok')
                        .map(ma => ma.u_name);
                }
                const tooltipNames = memberNamesOk.length ? memberNamesOk.join('、') : '';
                const memberHint = memberCount
                    ? `<span class="member-tooltip" title="${escapeHtml(tooltipNames || '尚無出席資料')}">👥 ${memberCount} 人</span>`
                    : '<span>—</span>';
                attendanceHtml += `<div class="attendance-meta">${memberHint}</div>`;

                html += `
                    <tr>
                        <td class="col-meeting-title">
                            <div class="meeting-main-title">${escapeHtml(meetingNumber)}</div>
                            <div class="meeting-sub-title">${escapeHtml(meetingSub)}</div>
                        </td>
                        <td>${escapeHtml(meetingTime)}</td>
                        <td class="col-attendance">${attendanceHtml}</td>
                        <td class="col-summary">
                            <div class="ai-summary" title="${escapeHtml(summary)}">${escapeHtml(summary)}</div>
                        </td>
                        <td style="text-align:right;">
                            <a href="${meetingUrl}" class="btn btn-sm btn-primary ajax-link" style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:6px;text-decoration:none;color:#fff;background:var(--meeting-primary,#2563eb);font-weight:600;">
                                <i class="fa-solid fa-file-lines"></i> 查看紀錄
                            </a>
                        </td>
                    </tr>
                `;
            });
            html += '</tbody></table>';
            listDiv.innerHTML = html;
        } else {
            listDiv.innerHTML = '<div style="text-align:center; color:#666; padding:40px; font-size:16px;">' +
                (MEETING_LIST_IS_STUDENT ? '目前沒有會議紀錄。' : '目前沒有會議紀錄，請點擊上方「新增會議」建立。') + '</div>';
        }
    } catch (e) {
        document.getElementById('meetingList').innerHTML = '<div style="text-align:center; color:#dc2626; padding:40px;">載入失敗，請稍後再試。</div>';
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

loadMeetings();
})();
</script>
</body>
</html>
