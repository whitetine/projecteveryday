<!DOCTYPE html>
<html lang="en">
<?php
include "head.php";
session_start();
if (!isset($_SESSION['u_ID'])) {
    echo "<script>alert('請先登入!');location.href='index.php';</script>";
    exit;
}
?>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>progress.php</title>
</head>

<body id="app">
    <h2>專題進度管理</h2>
    <button v-if="role_ID==1||role_ID==2" @click="new_progress_all_show">新增科上進度項目</button>
    <button v-if="role_ID==4" @click="new_progress_all_show">新增團隊進度項目</button>
    <button>進度一覽表</button>
    <button onclick="location.href='main.php'">返回首頁</button>
    <div class="modal fade" id="new_progress_all" data-backdrop="static" data-keyboard="false" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title" id="roleLabel">
                        <b>新增{{role_ID==1||role_ID==2?"科上":role_ID==4?"團隊":""}}進度項目</b>
                    </h3>
                </div>
                <div class="modal-body text-center">
                    <div class="btn-group" role="group" aria-label="Basic radio toggle button group"
                        v-if="role_ID==1||role_ID==2">
                        <span class="input-group-text"><b>選擇類組</b></span>
                        <template v-for="i in group">
                            <input type="radio" class="btn-check" :name="'btnradio'" :id="i.group_ID" autocomplete="off"
                                :value="i.group_ID" @click="new_progress.group_ID=i.group_ID">
                            <label class="btn btn-outline-primary" :for="i.group_ID">{{ i.group_name }}</label>
                        </template>
                    </div>
                    <div class="btn-group" role="group" aria-label="Basic radio toggle button group" v-if="role_ID==4">
                        <span class="input-group-text"><b>選擇團隊</b></span>
                        <template v-for="i in team">
                            <input type="radio" class="btn-check" :name="'btnradio'" :id="i.team_ID" autocomplete="off"
                                @click="new_progress.team_ID=i.team_ID">
                            <label class="btn btn-outline-primary" :for="i.team_ID">{{ i.team_project_name }}</label>
                        </template>
                    </div>
                    <form ref="new_p" action="api.php?do=new_progress_all" method="post">
                        <input type="hidden" v-model="new_progress.group_ID" name="ID">
                        <table width="100%" style="text-align: center;margin-top: 10px;">
                            <tr>
                                <td>
                                    <div class="input-group">
                                        <span class="input-group-text"><b>進度標題</b></span>
                                        <input type="text" class="form-control" name="title" id="title">
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <div class="input-group">
                                        <span class="input-group-text"><b>進度說明</b></span>
                                        <textarea class="form-control" rows="4" name="describe" style="resize: none;"
                                            id="describe"></textarea>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="2"><span style="color:gray">以下資料非必填</span></td>
                            </tr>
                            <tr>
                                <td>
                                    <div class="input-group">
                                        <span class="input-group-text"><b>設立期限</b></span>
                                        <input type="datetime-local" class="form-control" v-model="deadline"
                                            :min="today">
                                        <button class="btn btn-secondary" id="radio_time" type="button"
                                            @click="setTimeTo1159">設定時間為:晚上11:59</button>
                                        <input type="hidden" name="deadline" :value="deadline">
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <div class="input-group" v-for="i in new_progress.count_number">
                                        <span class="input-group-text"><b>進度量化</b></span>
                                        <input type="text" class="form-control" placeholder="目標(ex:粉絲數)"
                                            :name="'count_one[]'" style="width: 25%;">
                                        <input type="number" class="form-control" placeholder="數字" :name="'count_two[]'"
                                            min="1">
                                        <input type="text" class="form-control" placeholder="單位(ex:人)"
                                            :name="'count_three[]'" style="width: 10%;">
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td><button class="btn btn-primary" @click="this.new_progress.count_number++"
                                        type="button">
                                        <span>新增一列量化進度</span>
                                    </button></td>
                            </tr>
                        </table>
                    </form>
                </div>
                <div class="modal-footer">
                    <button class="btn" style="margin-right: 10px;" @click="new_progress_all_close">關閉</button>
                    <input type="button" class="btn btn-primary" value="確定新增" @click="new_p_submit">
                </div>
            </div>
        </div>
    </div>
    <script>
        let vue = Vue.createApp({
            data() {
                return {
                    role_ID: "<?= $_SESSION["role_ID"]; ?>",
                    group: [],
                    team: [],
                    new_progress: {
                        group_ID: "",
                        count_number: 1
                    },
                    deadline: null,
                    today: ''
                }
            },
            methods: {
                select_group() {
                    $.post("api.php?do=select_group", item => {
                        this.group = JSON.parse(item)
                    })
                }, select_team() {
                    $.post("api.php?do=select_team", item => {
                        this.team = JSON.parse(item)
                    })
                }, new_progress_all_show() {
                    $('#new_progress_all').modal('show')
                }, new_progress_all_close() {
                    $('#new_progress_all').modal('hide')
                    this.new_progress.count_number = 1
                }, new_p_submit() {//送出表單
                    if (!document.getElementById("title").value || !document.getElementById("describe").value || !this.new_progress.group_ID) {
                        Swal.fire({
                            icon: 'error',
                            title: "送出失敗",
                            text: "請輸入完整資料！(類組、標題、說明)"
                        });
                    } else {
                        Swal.fire({
                            icon: 'success',
                            title: '送出成功',
                            text: '新增成功，將跳轉到總覽畫面'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                this.$refs.new_p.submit(); // 使用者按下確認後才 submit
                            }
                        });
                    }
                }, toggleButton() {
                    this.isPressed = !this.isPressed
                }, setTimeTo1159(e) {//設定已經選擇的日期，時間改成23:59
                    if (!this.deadline) return;
                    const dateOnly = this.deadline.split("T")[0];
                    this.deadline = `${dateOnly}T23:59`;
                }, get_today() {//抓今天日期，給日期選擇器做最小值
                    const today = new Date();
                    const y = today.getFullYear();
                    const m = String(today.getMonth() + 1).padStart(2, '0');
                    const d = String(today.getDate()).padStart(2, '0');
                    this.today = `${y}-${m}-${d}T00:00`;
                }
            },
            mounted() {
                this.get_today()
                if (this.role_ID == 1 || this.role_ID == 2) {
                    this.select_group()
                } else if (this.role_ID == 4) {
                    this.select_team()
                }
            }
        }).mount("#app")
    </script>
</body>

</html>