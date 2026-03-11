/**
 * 指導老師線上評分頁面
 * API: pages/teacher_online_scoring_data.php
 */
(function () {
    "use strict";
    var API = "../pages/teacher_online_scoring_data.php";
    var periodListEl = document.getElementById("tosPeriodList");
    var suggestFormCard = document.getElementById("tosSuggestFormCard");
    var teamSection = document.getElementById("tosTeamSection");
    var teamBody = document.getElementById("tosTeamBody");
    var emptyHint = document.getElementById("tosEmpty");
    var scoreModal = document.getElementById("tosScoreModal");
    var modalTeamName = document.getElementById("tosModalTeamName");
    var modalSfId = document.getElementById("tosModalSfId");
    var modalTeamId = document.getElementById("tosModalTeamId");
    var modalHistoryBlock = document.getElementById("tosModalHistoryBlock");
    var modalHistoryList = document.getElementById("tosModalHistoryList");
    var modalHistoryEmpty = document.getElementById("tosModalHistoryEmpty");
    var modalSuggest = document.getElementById("tosModalSuggest");
    var modalScore = document.getElementById("tosModalScore");
    var studentScoresDiv = document.getElementById("tosStudentScores");
    var tosSuggestScoreTbody = document.getElementById("tosSuggestScoreTbody");
    var modalSaveBtn = document.getElementById("tosModalSave");

    var currentCohortId = "";
    var currentSfId = "";
    var currentTeams = [];
    var studentsExpanded = false;
    var currentStudents = [];
    var currentStudentReviews = [];
    var currentPeriodEnded = false;
    var currentPeriodNotStarted = false; // 評分尚未開始時為 true
    var currentPeriodSubmitted = false;  // 該老師是否已送出此評分時段（送出後變唯讀）
    var periodListData = [];            // listSuggestForms 回傳的列表（含 submitted_by_me）

    function fetchApi(action, params, method) {
        method = method || "GET";
        var url = API + "?action=" + encodeURIComponent(action);
        var body = null;
        if (method === "GET" && params) {
            Object.keys(params).forEach(function (k) {
                url += "&" + encodeURIComponent(k) + "=" + encodeURIComponent(params[k]);
            });
        } else if (method === "POST" && params) {
            body = new FormData();
            body.append("action", action);
            Object.keys(params).forEach(function (k) {
                body.append(k, params[k]);
            });
        }
        return fetch(url, { method: method, body: body, credentials: "same-origin" })
            .then(function (res) {
                var ct = res.headers.get("Content-Type") || "";
                return res.text().then(function (text) {
                    if (ct.indexOf("application/json") !== -1 || (text.trim().length > 0 && (text.trim()[0] === "{" || text.trim()[0] === "["))) {
                        try {
                            return JSON.parse(text);
                        } catch (e) {
                            return { success: false, msg: "伺服器回傳格式錯誤，請稍後再試" };
                        }
                    }
                    return { success: false, msg: "伺服器回傳格式錯誤，請稍後再試" };
                });
            });
    }

    function loadSuggestForms() {
        currentSfId = "";
        if (!currentCohortId) return;
        if (periodListEl) {
            periodListEl.innerHTML = "<div class=\"text-center p-3 text-muted\"><i class=\"fas fa-spinner fa-spin\"></i> 載入中...</div>";
        }
        fetchApi("listSuggestForms", { cohort_ID: currentCohortId }).then(function (r) {
            if (!r.success) {
                if (typeof Toast !== "undefined") Toast.fire({ icon: "error", title: r.msg || "載入失敗" });
                if (periodListEl) {
                    periodListEl.innerHTML = "<div class=\"text-center p-3 text-danger\"><i class=\"fas fa-exclamation-triangle\"></i><br>載入失敗，請重新整理頁面</div>";
                }
                return;
            }
            var list = r.data || [];
            periodListData = list;
            if (suggestFormCard) suggestFormCard.style.display = "block";
            if (periodListEl) {
                if (list.length === 0) {
                    periodListEl.innerHTML = "<div class=\"text-center p-3 text-muted\"><i class=\"fas fa-inbox\"></i><br>此屆尚無評分時段</div>";
                } else {
                    var html = "<div class=\"list-group list-group-flush\">";
                    list.forEach(function (s) {
                        var sfId = String(s.sf_ID);
                        var name = s.sf_name || "建議表 " + s.sf_ID;
                        var submittedLabel = s.submitted_by_me ? " <span class=\"badge bg-success ms-1\">已送出</span>" : "";
                        html += "<button type=\"button\" class=\"list-group-item list-group-item-action tos-period-item\" data-sf-id=\"" + escapeAttr(sfId) + "\" data-submitted=\"" + (s.submitted_by_me ? "1" : "0") + "\">" +
                            "<div class=\"d-flex justify-content-between align-items-start\">" +
                            "<div class=\"flex-grow-1\">" +
                            "<h6 class=\"mb-1\">" + escapeHtml(name) + submittedLabel + "</h6>" +
                            "<p class=\"mb-0 small text-muted tos-period-time\"></p>" +
                            "</div>" +
                            "</div>" +
                            "</button>";
                    });
                    html += "</div>";
                    periodListEl.innerHTML = html;
                    periodListEl.querySelectorAll(".tos-period-item").forEach(function (btn) {
                        btn.addEventListener("click", function () {
                            selectSuggestForm(btn.dataset.sfId);
                        });
                    });
                    currentSfId = String(list[0].sf_ID);
                    currentPeriodSubmitted = !!(list[0].submitted_by_me);
                    loadSubmissionPeriod().then(function () {
                        loadTeams();
                    });
                    setActivePeriodItem(currentSfId);
                }
            }
            if (list.length === 0) {
                currentPeriodEnded = false;
                applyPeriodEndedClass();
            }
        });
    }

    function setActivePeriodItem(sfId) {
        if (!periodListEl) return;
        periodListEl.querySelectorAll(".tos-period-item").forEach(function (item) {
            if (item.dataset.sfId === String(sfId)) {
                item.classList.add("active");
            } else {
                item.classList.remove("active");
            }
        });
    }

    function selectSuggestForm(sfId) {
        currentSfId = sfId || "";
        var item = periodListEl && periodListEl.querySelector(".tos-period-item[data-sf-id=\"" + currentSfId + "\"]");
        currentPeriodSubmitted = item && item.getAttribute("data-submitted") === "1";
        setActivePeriodItem(currentSfId);
        teamSection.style.display = "none";
        if (emptyHint) {
            emptyHint.style.display = "block";
            emptyHint.innerHTML = "<i class=\"fas fa-spinner fa-spin me-2\"></i>載入團隊列表中...";
        }
        loadSubmissionPeriod().then(function () {
            loadTeams();
        });
    }

    function loadSubmissionPeriod() {
        if (!currentCohortId || !currentSfId) {
            currentPeriodEnded = false;
            currentPeriodNotStarted = false;
            applyPeriodEndedClass();
            return Promise.resolve();
        }
        return fetchApi("getSubmissionPeriod", { sf_ID: currentSfId, cohort_ID: currentCohortId }).then(function (r) {
            var startText = "";
            var endText = "";
            if (r.success && r.data) {
                var d = r.data;
                currentPeriodEnded = (d.status === "已結束");
                currentPeriodNotStarted = (d.status === "未開始");
                startText = d.time_start_display || "";
                endText = d.time_end_plus1_display || "";
            } else {
                // 無法取得時段時（例如無對應時程表），視為僅能查看，避免已截止的評分無法查看
                currentPeriodEnded = r.success ? true : false;
                currentPeriodNotStarted = false;
            }

            // 將開始 / 截止時間顯示在左側清單中目前選取的時段標題下方
            if (periodListEl && currentSfId) {
                var btn = periodListEl.querySelector(".tos-period-item[data-sf-id=\"" + currentSfId + "\"]");
                if (btn) {
                    var timeEl = btn.querySelector(".tos-period-time");
                    if (timeEl) {
                        if (startText && endText) {
                            timeEl.textContent = "開始：" + startText + "　截止：" + endText;
                        } else {
                            timeEl.textContent = "";
                        }
                    }
                }
            }

            applyPeriodEndedClass();
            return r;
        });
    }

    function applyPeriodEndedClass() {
        var page = document.querySelector(".teacher-scoring-page");
        if (page) {
            var readOnly = currentPeriodEnded || currentPeriodSubmitted || currentPeriodNotStarted;
            if (readOnly) page.classList.add("tos-period-ended");
            else page.classList.remove("tos-period-ended");
        }
    }

    function loadTeams() {
        if (!currentCohortId || !currentSfId) return;
        loadSubmissionPeriod().then(function () {
            return fetchApi("listTeams", { sf_ID: currentSfId, cohort_ID: currentCohortId });
        }).then(function (r) {
            if (!r.success) {
                if (typeof Toast !== "undefined") Toast.fire({ icon: "error", title: r.msg || "載入團隊失敗" });
                return;
            }
            currentTeams = r.data || [];
            renderTeams();
            teamSection.style.display = currentTeams.length ? "block" : "none";
            emptyHint.style.display = currentTeams.length ? "none" : "block";
            if (emptyHint.style.display === "block" && currentTeams.length === 0) {
                emptyHint.innerHTML = "<i class=\"fa-solid fa-info-circle me-2\"></i>載入完成，此屆目前尚無團隊資料。";
            }
        });
    }

    function initByCohort() {
        var cohortId = (typeof window.TEACHER_COHORT_ID !== "undefined" && window.TEACHER_COHORT_ID) ? String(window.TEACHER_COHORT_ID) : "";
        currentCohortId = cohortId;
        currentSfId = "";
        teamSection.style.display = "none";
        emptyHint.style.display = "block";
        if (!currentCohortId) {
            emptyHint.innerHTML = "<i class=\"fa-solid fa-info-circle me-2\"></i>請從上方切換身份選擇屆別後再使用本頁面。";
            return;
        }
        emptyHint.innerHTML = "<i class=\"fa-solid fa-info-circle me-2\"></i>選擇左側評分時段後將自動顯示團隊列表。";
        loadSuggestForms();
    }

    function renderTeams() {
        teamBody.innerHTML = "";
        var readOnly = currentPeriodEnded || currentPeriodSubmitted || currentPeriodNotStarted;
        var btnText = readOnly ? (currentPeriodNotStarted ? "尚未開始" : "查看") : "評分";
        var btnIcon = readOnly && !currentPeriodNotStarted ? "fa-eye" : "fa-pen-to-square";
        currentTeams.forEach(function (team, idx) {
            var tr = document.createElement("tr");
            tr.dataset.teamId = team.team_ID;
            var sortNo = team.time_sort_no != null ? team.time_sort_no : idx + 1;
            var disabledAttr = currentPeriodNotStarted ? " disabled" : "";
            tr.innerHTML =
                "<td>" + sortNo + "</td>" +
                "<td>" + escapeHtml(team.team_project_name || "團隊 " + team.team_ID) + "</td>" +
                "<td>" + escapeHtml(team.group_name || "") + "</td>" +
                "<td><span class=\"tos-status-badge\" data-team-id=\"" + team.team_ID + "\">—</span></td>" +
                "<td><button type=\"button\" class=\"btn btn-sm btn-outline-primary tos-btn-score\" data-team-id=\"" + team.team_ID + "\" data-team-name=\"" + escapeAttr(team.team_project_name || "團隊 " + team.team_ID) + "\"" + disabledAttr + "><i class=\"fa-solid " + btnIcon + " me-1\"></i>" + btnText + "</button></td>";
            teamBody.appendChild(tr);
        });
        currentTeams.forEach(function (team) {
            updateTeamStatusBadge(team.team_ID);
        });
        teamBody.querySelectorAll(".tos-btn-score").forEach(function (btn) {
            btn.addEventListener("click", openScoreModal);
        });
        var submitBtn = document.getElementById("tosSubmitScoreBtn");
        if (submitBtn) {
            if (readOnly || currentTeams.length === 0) {
                submitBtn.style.display = "none";
            } else {
                submitBtn.style.display = "inline-block";
            }
        }
    }

    function updateTeamStatusBadge(teamId) {
        var badge = document.querySelector(".tos-status-badge[data-team-id=\"" + teamId + "\"]");
        if (!badge) return;
        fetchApi("getMyReview", { sf_ID: currentSfId, team_ID: teamId }).then(function (r) {
            if (r.success && r.data && (r.data.score != null || (r.data.suggest_text && r.data.suggest_text.trim()))) {
                badge.textContent = "已評分";
                badge.classList.add("bg-success", "text-white");
                badge.classList.remove("bg-secondary");
            } else {
                badge.textContent = "未評分";
                badge.classList.add("bg-secondary", "text-white");
                badge.classList.remove("bg-success");
            }
        });
    }

    function escapeHtml(s) {
        if (s == null) return "";
        var div = document.createElement("div");
        div.textContent = s;
        return div.innerHTML;
    }
    function escapeAttr(s) {
        if (s == null) return "";
        return String(s)
            .replace(/&/g, "&amp;")
            .replace(/"/g, "&quot;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;");
    }

    function openScoreModal(e) {
        var btn = e.target.closest(".tos-btn-score");
        if (!btn) return;
        // 若評分尚未開始，禁止開啟評分視窗
        if (currentPeriodNotStarted) {
            if (typeof Toast !== "undefined") {
                Toast.fire({
                    icon: "info",
                    title: "評分尚未開始",
                    text: "開始時間尚未到，請在評分開始後再進行評分。"
                });
            } else {
                alert("評分尚未開始，請在評分開始後再進行評分。");
            }
            return;
        }
        var teamId = btn.dataset.teamId;
        var teamName = btn.dataset.teamName || ("團隊 " + teamId);
        modalSfId.value = currentSfId;
        modalTeamId.value = teamId;
        modalTeamName.textContent = teamName;
        if (modalSuggest) modalSuggest.value = "";
        if (modalScore) modalScore.value = "";
        removeStudentRows();
        studentScoresDiv.innerHTML = "";
        studentScoresDiv.classList.add("d-none");
        currentStudents = [];
        currentStudentReviews = [];

        if (modalHistoryBlock) modalHistoryBlock.style.display = "none";
        if (modalHistoryList) modalHistoryList.innerHTML = "";
        if (modalHistoryEmpty) modalHistoryEmpty.style.display = "block";
        if (currentCohortId && teamId) {
            Promise.all([
                fetchApi("getTeamSuggestHistory", { cohort_ID: currentCohortId, team_ID: teamId, sf_ID: currentSfId }),
                fetchApi("getMyReview", { sf_ID: currentSfId, team_ID: teamId }),
                fetchApi("listTeamStudents", { team_ID: teamId }),
                fetchApi("getMyStudentReviews", { sf_ID: currentSfId, team_ID: teamId })
            ]).then(function (results) {
                var suggestHistoryRes = results[0];
                var myReviewRes = results[1];
                var studentsRes = results[2];
                var reviewsRes = results[3];
                var list = (suggestHistoryRes.success && suggestHistoryRes.data) ? suggestHistoryRes.data : [];
                var myReview = (myReviewRes.success && myReviewRes.data) ? myReviewRes.data : null;
                currentStudents = (studentsRes.success && studentsRes.data) ? studentsRes.data : [];
                var reviews = (reviewsRes.success && reviewsRes.data) ? reviewsRes.data : [];
                var byStudent = {};
                reviews.forEach(function (rev) {
                    byStudent[rev.student_u_ID] = { score: rev.score, suggest_text: rev.suggest_text || "" };
                });
                currentStudentReviews = byStudent;

                if (myReview) {
                    if (modalSuggest) modalSuggest.value = myReview.suggest_text || "";
                    if (modalScore && myReview.score != null && myReview.score !== "") modalScore.value = myReview.score;
                }

                if (list.length > 0 && modalHistoryBlock && modalHistoryList) {
                    modalHistoryBlock.style.display = "block";
                    if (modalHistoryEmpty) modalHistoryEmpty.style.display = "none";
                    var html = "";
                    list.forEach(function (row) {
                        var title = escapeHtml(row.title || "—");
                        var comment = escapeHtml((row.comment || "").trim() || "—");
                        var status = escapeHtml(row.status || "—");
                        html += "<div class=\"tos-history-item\"><div class=\"tos-history-period\">" + title + "</div><div class=\"tos-history-content\">" + comment + "</div><div class=\"tos-history-score\">審查結果：" + status + "</div></div>";
                    });
                    modalHistoryList.innerHTML = html;
                } else if (modalHistoryEmpty) {
                    modalHistoryEmpty.style.display = "block";
                    if (modalHistoryBlock) modalHistoryBlock.style.display = "none";
                }

                renderStudentRows(currentStudents, byStudent, currentPeriodEnded || currentPeriodSubmitted);
            });
        } else {
            fetchApi("getMyReview", { sf_ID: currentSfId, team_ID: teamId }).then(function (r) {
                if (r.success && r.data) {
                    var d = r.data;
                    if (modalSuggest) modalSuggest.value = d.suggest_text || "";
                    if (modalScore && d.score != null && d.score !== "") modalScore.value = d.score;
                }
            });
            Promise.all([
                fetchApi("listTeamStudents", { team_ID: teamId }),
                fetchApi("getMyStudentReviews", { sf_ID: currentSfId, team_ID: teamId })
            ]).then(function (results) {
                var studentsRes = results[0];
                var reviewsRes = results[1];
                currentStudents = (studentsRes.success && studentsRes.data) ? studentsRes.data : [];
                var reviews = (reviewsRes.success && reviewsRes.data) ? reviewsRes.data : [];
                var byStudent = {};
                reviews.forEach(function (rev) {
                    byStudent[rev.student_u_ID] = { score: rev.score, suggest_text: rev.suggest_text || "" };
                });
                renderStudentRows(currentStudents, byStudent, currentPeriodEnded || currentPeriodSubmitted);
            });
        }

        // 若 Modal 在 #content 內，移到 body 避免背板蓋住視窗（AJAX 載入時層疊問題）
        var contentEl = document.getElementById("content");
        var modalInContent = contentEl ? contentEl.querySelector("#tosScoreModal") : null;
        var modalEl = modalInContent || document.getElementById("tosScoreModal");
        if (!modalEl) return;
        // 若 body 上有舊的評分 Modal（從前一次頁面留下的），先移除
        var inBody = document.body.querySelector("#tosScoreModal");
        if (inBody && inBody !== modalEl) inBody.remove();
        if (modalEl.parentNode && modalEl.parentNode !== document.body) {
            document.body.appendChild(modalEl);
        }
        // 使用單一實例，避免重複建立導致背板殘留
        var bsModal = window.bootstrap && window.bootstrap.Modal
            ? (window.bootstrap.Modal.getInstance(modalEl) || new window.bootstrap.Modal(modalEl))
            : null;
        if (bsModal) bsModal.show();

        // 已超過評分時段：僅能查看，不可編輯
        var readonly = currentPeriodEnded || currentPeriodSubmitted;
        if (modalSuggest) {
            modalSuggest.readOnly = readonly;
            if (readonly) modalSuggest.classList.add("bg-light"); else modalSuggest.classList.remove("bg-light");
        }
        if (modalScore) {
            modalScore.readOnly = readonly;
            if (readonly) modalScore.classList.add("bg-light"); else modalScore.classList.remove("bg-light");
        }
        modalSaveBtn.style.display = readonly ? "none" : "";
        // 僅檢視時標題改為「查看」
        var titleLabel = document.getElementById("tosScoreModalLabel");
        if (titleLabel && titleLabel.firstChild) {
            titleLabel.firstChild.textContent = readonly ? "查看 — " : "評分 — ";
        }
    }

    function removeStudentRows() {
        if (!tosSuggestScoreTbody) return;
        var rows = tosSuggestScoreTbody.querySelectorAll("tr.tos-student-row");
        rows.forEach(function (r) { r.remove(); });
    }

    function renderStudentRows(students, byStudent, readonly) {
        if (!tosSuggestScoreTbody || !students || !students.length) return;
        students.forEach(function (s) {
            var rev = byStudent[s.u_ID] || {};
            var tr = document.createElement("tr");
            tr.className = "tos-student-row";
            if (readonly) {
                tr.innerHTML = "<td class=\"align-top\"><div class=\"tos-student-readonly-content small py-2\"><span class=\"text-muted d-block mb-1\">" + escapeHtml(s.u_name || "") + "</span><span class=\"text-muted\">建議：</span> " + escapeHtml(rev.suggest_text || "—") + "</div></td>" +
                    "<td class=\"tos-score-cell align-top\"><div class=\"small py-2\">" + (rev.score != null && rev.score !== "" ? escapeHtml(String(rev.score)) : "—") + "</div></td>";
            } else {
                tr.innerHTML = "<td class=\"p-0 align-top\"><div class=\"tos-student-label small text-muted mb-1\">" + escapeHtml(s.u_name || "") + "</div><input type=\"text\" class=\"form-control form-control-sm tos-student-suggest\" data-student-id=\"" + escapeAttr(s.u_ID) + "\" placeholder=\"建議\" value=\"" + escapeAttr(rev.suggest_text || "") + "\"></td>" +
                    "<td class=\"tos-score-cell align-top\"><input type=\"number\" class=\"form-control form-control-sm tos-student-score\" data-student-id=\"" + escapeAttr(s.u_ID) + "\" min=\"0\" max=\"100\" placeholder=\"評分\" value=\"" + (rev.score != null && rev.score !== "" ? escapeAttr(String(rev.score)) : "") + "\"></td>";
            }
            tosSuggestScoreTbody.appendChild(tr);
        });
    }

    modalSaveBtn.addEventListener("click", function () {
        var sfId = modalSfId.value;
        var teamId = modalTeamId.value;
        if (!sfId || !teamId) return;
        modalSaveBtn.disabled = true;
        var suggestText = (modalSuggest && modalSuggest.value) ? modalSuggest.value.trim() : "";
        var scoreVal = (modalScore && modalScore.value) ? modalScore.value.trim() : "";

        fetchApi("saveMyReview", {
            sf_ID: sfId,
            team_ID: teamId,
            suggest_text: suggestText,
            score: scoreVal || ""
        }, "POST").then(function (r) {
            if (!r.success) {
                modalSaveBtn.disabled = false;
                if (typeof Toast !== "undefined") Toast.fire({ icon: "error", title: r.msg || "儲存失敗" });
                return;
            }
            var studentRows = (tosSuggestScoreTbody && tosSuggestScoreTbody.querySelectorAll) ? tosSuggestScoreTbody.querySelectorAll(".tos-student-row") : [];
            if (studentRows.length === 0) {
                doneSave(sfId, teamId);
                return;
            }
            var reviews = [];
            if (tosSuggestScoreTbody) {
                tosSuggestScoreTbody.querySelectorAll(".tos-student-suggest").forEach(function (el) {
                var sid = el.dataset.studentId;
                if (!sid) return;
                var row = el.closest(".tos-student-row");
                var scoreEl = row ? row.querySelector(".tos-student-score") : null;
                var score = scoreEl && scoreEl.value.trim() !== "" ? scoreEl.value.trim() : "";
                var text = el.value.trim();
                    if (text !== "" || score !== "") {
                        reviews.push({ student_u_ID: sid, suggest_text: text, score: score });
                    }
                });
            }
            if (reviews.length === 0) {
                doneSave(sfId, teamId);
                return;
            }
            return fetchApi("saveMyStudentReviews", {
                sf_ID: sfId,
                team_ID: teamId,
                reviews: JSON.stringify(reviews)
            }, "POST").then(function (r2) {
                doneSave(sfId, teamId, r2);
            });
        }).then(function () {
            modalSaveBtn.disabled = false;
        });

        function doneSave(sfId, teamId, r2) {
            if (typeof Toast !== "undefined") Toast.fire({ icon: "success", title: "已儲存" });
            updateTeamStatusBadge(teamId);
            var modalEl = document.getElementById("tosScoreModal");
            if (modalEl && window.bootstrap && window.bootstrap.Modal) {
                var inst = window.bootstrap.Modal.getInstance(modalEl);
                if (inst) inst.hide();
            }
            modalSaveBtn.disabled = false;
        }
    });

    var tosSubmitScoreBtn = document.getElementById("tosSubmitScoreBtn");
    if (tosSubmitScoreBtn) {
        tosSubmitScoreBtn.addEventListener("click", function () {
            if (!currentSfId || currentPeriodSubmitted || currentPeriodEnded) return;
            if (typeof Swal !== "undefined") {
                Swal.fire({
                    icon: "question",
                    title: "確定要送出評分嗎？",
                    text: "送出後將通知召集人，且此評分時段將變為唯讀，無法再修改。",
                    showCancelButton: true,
                    confirmButtonText: "確定送出",
                    cancelButtonText: "取消"
                }).then(function (res) {
                    if (res.isConfirmed) doSubmitTeacherScoring();
                });
            } else {
                if (confirm("確定要送出評分嗎？送出後將通知召集人，且此評分時段將變為唯讀。")) doSubmitTeacherScoring();
            }
        });
    }
    function doSubmitTeacherScoring() {
        fetchApi("submitTeacherScoring", { sf_ID: currentSfId }, "POST").then(function (r) {
            if (r.success) {
                currentPeriodSubmitted = true;
                var item = periodListEl && periodListEl.querySelector(".tos-period-item[data-sf-id=\"" + currentSfId + "\"]");
                if (item) {
                    item.setAttribute("data-submitted", "1");
                    var h6 = item.querySelector("h6");
                    if (h6 && !h6.querySelector(".badge")) {
                        var badge = document.createElement("span");
                        badge.className = "badge bg-success ms-1";
                        badge.textContent = "已送出";
                        h6.appendChild(badge);
                    }
                }
                renderTeams();
                applyPeriodEndedClass();
                if (typeof Toast !== "undefined") Toast.fire({ icon: "success", title: r.msg || "已送出，已通知召集人" });
            } else {
                if (typeof Toast !== "undefined") Toast.fire({ icon: "error", title: r.msg || "送出失敗" });
            }
        });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initByCohort);
    } else {
        initByCohort();
    }
})();
