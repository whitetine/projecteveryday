<?php
session_start();
require '../includes/pdo.php';
$p = $_POST;

switch ($_GET["do"]) {

    case "get_exresultdata":
    header("Content-Type: application/json; charset=utf-8");

    $role_ID   = isset($_SESSION["role_ID"]) ? (int)$_SESSION["role_ID"] : 0;
    $u_ID      = isset($_SESSION["u_ID"]) ? $_SESSION["u_ID"] : '';
    $cohort_ID = isset($_SESSION["cohort_ID"]) ? (int)$_SESSION["cohort_ID"] : 0;

    $where = "WHERE e.sfd_submit_d <= CURDATE()";
    $params = [];

    // 學生：只能看自己所在 team
    if ($role_ID === 6 && $u_ID !== '') {
        $where .= "
            AND e.sfd_team_ID IN (
                SELECT DISTINCT tm.team_ID
                FROM teammember tm
                WHERE tm.team_u_ID = ?
                  AND tm.tm_status = 1
            )
        ";
        $params[] = $u_ID;
    }

    // 班導：只能看自己班級「學生」所在的 team，不可因為自己是指導老師而看到其他 team
    if ($role_ID === 3 && $u_ID !== '' && $cohort_ID > 0) {
        $where .= "
            AND e.sfd_team_ID IN (
                SELECT DISTINCT tm.team_ID
                FROM teammember tm
                INNER JOIN enrollmentdata stu_ed
                    ON stu_ed.enroll_u_ID = tm.team_u_ID
                   AND stu_ed.cohort_ID = ?
                   AND stu_ed.enroll_status = 1
                   AND stu_ed.role_ID = 6
                WHERE tm.tm_status = 1
                  AND stu_ed.class_ID = (
                      SELECT tutor_ed.class_ID
                      FROM enrollmentdata tutor_ed
                      WHERE tutor_ed.enroll_u_ID = ?
                        AND tutor_ed.cohort_ID = ?
                        AND tutor_ed.role_ID = 3
                        AND tutor_ed.enroll_status = 1
                      LIMIT 1
                  )
            )
        ";
        $params[] = $cohort_ID;
        $params[] = $u_ID;
        $params[] = $cohort_ID;
    }

    // 指導老師：只能看自己指導的 team
    if ($role_ID === 4 && $u_ID !== '') {
        $where .= "
            AND e.sfd_team_ID IN (
                SELECT DISTINCT tm.team_ID
                FROM teammember tm
                WHERE tm.team_u_ID = ?
                  AND tm.tm_status = 1
            )
        ";
        $params[] = $u_ID;
    }

    $sql = "
        SELECT
            e.sfd_ID,
            e.sfd_name,
            e.sfd_team_ID,
            e.sfd_u_ID,
            e.sfd_start_d,
            e.sfd_submit_d,
            e.sfd_created_d,
            e.sfd_score,
            e.sfd_rank,
            e.sfd_suggest,

            t.team_project_name,
            t.group_ID,
            g.group_name,
            c.total_teams,

            /* 指導老師名稱 */
            (
                SELECT GROUP_CONCAT(DISTINCT u1.u_name ORDER BY u1.u_name SEPARATOR '、')
                FROM teammember tm1
                INNER JOIN userrolesdata ur1
                    ON ur1.ur_u_ID = tm1.team_u_ID
                   AND ur1.role_ID = 4
                   AND ur1.user_role_status = 1
                LEFT JOIN userdata u1
                    ON u1.u_ID = tm1.team_u_ID
                WHERE tm1.team_ID = t.team_ID
                  AND tm1.tm_status = 1
            ) AS teacher_names,

            /* 組員名稱（學生） */
            (
                SELECT GROUP_CONCAT(DISTINCT u2.u_name ORDER BY u2.u_name SEPARATOR '、')
                FROM teammember tm2
                INNER JOIN userrolesdata ur2
                    ON ur2.ur_u_ID = tm2.team_u_ID
                   AND ur2.role_ID = 6
                   AND ur2.user_role_status = 1
                LEFT JOIN userdata u2
                    ON u2.u_ID = tm2.team_u_ID
                WHERE tm2.team_ID = t.team_ID
                  AND tm2.tm_status = 1
            ) AS member_names

        FROM exresultdata e
        LEFT JOIN teamdata t
            ON e.sfd_team_ID = t.team_ID
        LEFT JOIN groupdata g
            ON t.group_ID = g.group_ID
        LEFT JOIN (
            SELECT 
                e2.sfd_submit_d,
                COUNT(DISTINCT e2.sfd_team_ID) AS total_teams
            FROM exresultdata e2
            GROUP BY e2.sfd_submit_d
        ) c
            ON c.sfd_submit_d = e.sfd_submit_d

        $where

        ORDER BY
            g.group_name ASC,
            t.team_project_name ASC,
            e.sfd_submit_d ASC,
            e.sfd_ID ASC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    break;
}
