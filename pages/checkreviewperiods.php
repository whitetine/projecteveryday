<?php
session_start();
$sort = $_GET['sort'] ?? 'created';  
?>

<div class="page-header">
    <h1 class="page-title">
        <i class="fa-solid fa-clock-rotate-left me-2" style="color: #ffc107;"></i>評分時段管理
    </h1>
</div>

<!-- 模式選擇 -->
<div class="d-flex align-items-center gap-3 flex-wrap mb-3">
  <label class="form-label mb-0">模式：</label>
    <div class="dropdown">
    <button class="btn btn-primary dropdown-toggle" type="button" id="modeDropdown" data-bs-toggle="dropdown" aria-expanded="false">
      <span id="modeLabel">請選擇模式</span>
      </button>
      <ul class="dropdown-menu" aria-labelledby="modeDropdown">
      <li>
        <button class="dropdown-item mode-option" type="button" data-mode="in" data-hint="同一團隊成員彼此互評，適合隊內檢視。">
          團隊內互評
        </button>
      </li>
      <li>
        <button class="dropdown-item mode-option" type="button" data-mode="cross" data-hint="不同團隊之間互評，適合跨隊交流與評比。">
          團隊間互評
        </button>
      </li>
      </ul>
    </div>
  <input type="hidden" id="mode_value" name="pe_mode" value="in" form="periodForm">
  <small class="text-muted" id="modeHint">請選擇模式以查看說明</small>
  </div>

  <!-- 表單 -->
  <div class="card mb-4">
    <div class="card-body">
      <form id="periodForm" method="post" action="pages/checkreviewperiods_data.php" class="row g-3">
        <input type="hidden" name="action" id="form_action" value="create">
        <input type="hidden" name="period_ID" id="period_ID">
        <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">

        <div class="col-md-3">
          <label class="form-label">開始日</label>
          <input type="datetime-local" class="form-control" name="period_start_d" id="period_start_d" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">結束日</label>
          <input type="datetime-local" class="form-control" name="period_end_d" id="period_end_d" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">標題</label>
          <input type="text" class="form-control" name="period_title" id="period_title" required>
        </div>

        <div class="col-md-4">
          <label class="form-label">屆別</label>
          <select class="form-select multi-select-list" id="cohortSelect" multiple size="6" aria-label="屆別多選">
            <option value="">載入中...</option>
          </select>
          <small class="text-muted d-block mt-1">可多選，按住 Ctrl/Cmd 鍵；優先使用第一個屆別建立時段。</small>
          <input type="hidden" name="cohort_values" id="cohort_values" value="">
          <input type="hidden" name="cohort_primary" id="cohort_primary" value="">
        </div>
        <div class="col-md-4">
          <label class="form-label">班級</label>
          <select class="form-select multi-select-list" id="classSelect" multiple size="6" aria-label="班級多選">
            <option value="">載入中...</option>
          </select>
          <small class="text-muted d-block mt-1">可多選，按住 Ctrl/Cmd 鍵；未選表示全部班級。</small>
          <input type="hidden" name="pe_class_ID" id="class_primary" value="">
        </div>
        <div class="col-md-4">
          <label class="form-label">指定團隊</label>
          <div class="team-picker-input" id="teamPickerTrigger" role="button" tabindex="0" aria-label="指定團隊挑選">
            <div class="team-picker-content">
              <span id="teamPickerSummary" class="placeholder">請先選擇屆別</span>
              <div class="team-picker-tags" id="teamPickerTags"></div>
            </div>
            <button type="button" class="clear-btn" id="teamPickerClear" aria-label="清除指定團隊">&times;</button>
          </div>
          <input type="hidden" name="pe_target_ID" id="team_input" value="ALL">

          <div class="mt-3 d-none" id="receiveTeamField">
            <label class="form-label">被評分團隊</label>
            <div class="team-picker-input" id="receivePickerTrigger" role="button" tabindex="0" aria-label="被評分團隊挑選">
              <div class="team-picker-content">
                <span id="receivePickerSummary" class="placeholder">僅團隊間互評可設定</span>
                <div class="team-picker-tags" id="receivePickerTags"></div>
              </div>
              <button type="button" class="clear-btn" id="receivePickerClear" aria-label="清除被評分團隊">&times;</button>
            </div>
            <input type="hidden" id="team_receive_input" value="">
          </div>
        </div>

        <input type="hidden" name="pe_status" id="pe_status" value="1">

        <div class="col-12 d-flex gap-2">
          <button class="btn btn-primary" type="submit" id="submitBtn">新增</button>
          <button class="btn btn-secondary" type="button" onclick="resetForm()">清空</button>
          <button class="btn btn-outline-secondary d-none" type="button" id="cancelEditBtn" onclick="resetForm()">取消編輯</button>
        </div>
      </form>
    </div>
 

  <!-- 資料表 -->
  <div id="periodTable"></div>
  
  <!-- 頁碼 -->
  <div class="pager-bar" id="periodPagerBar">
    <span class="active">1</span>
  </div>
</div>
<!-- 指定團隊選擇 Modal -->
<div class="team-picker-modal" id="teamPickerModal" aria-hidden="true">
  <div class="team-picker-dialog" aria-live="polite">
    <button type="button" class="btn-close" id="teamPickerClose" aria-label="關閉">×</button>
    <div class="team-picker-header">
      <div class="team-picker-header-text">
        <h5 class="team-picker-title mb-1">指定團隊</h5>
        <small class="text-muted">可多選；未選則預設全部團隊</small>
      </div>
      <button type="button" class="btn btn-outline-primary" id="teamPickerDualToggle">
        指定被評分團隊
      </button>
    </div>
    <div class="team-dual-panel">
      <div class="team-panel assign">
        <div class="panel-title">
          <span>指定團隊</span>
          <small id="teamModalAssignHint">未選擇（儲存後等同全部團隊）</small>
        </div>
        <div class="selected-display" id="teamModalAssignSelected"></div>
      </div>
      <div class="panel-arrow">
        <button type="button" id="teamPickerMirror" class="mirror-btn" title="套用至被評分">➜</button>
      </div>
      <div class="team-panel receive">
        <div class="panel-title">
          <span>被評分團隊</span>
          <small id="teamModalReceiveHint">未選擇（儲存後等同全部團隊）</small>
        </div>
        <div class="selected-display" id="teamModalReceiveSelected"></div>
      </div>
    </div>
    <div class="team-picker-body">
      <div class="team-modal-placeholder" id="teamModalPlaceholder">請先選擇屆別與班級</div>
      <div class="team-panel-body assign" id="teamModalAssignList"></div>
      <div class="team-panel-body receive" id="teamModalReceiveList"></div>
    </div>
    <div class="team-picker-footer">
      <button type="button" class="btn btn-outline-secondary" id="teamPickerCancel">取消</button>
      <button type="button" class="btn btn-primary" id="teamPickerSave">儲存</button>
    </div>
  </div>
</div>

<link rel="stylesheet" href="css/checkreviewperiods.css">
<script src="js/checkreviewperiods.js"></script>

