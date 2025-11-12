<?php
global $conn;
$p  = $_POST;
$do = $_GET['do'] ?? '';

switch ($do) {
    case 'submit_rating':
        $review_a_u_ID = strval($_SESSION['u_ID']);

        $sqlPeriod = "
        (
          SELECT period_ID FROM ReviewPeriods
          WHERE is_active = 1
            AND CURRENT_DATE BETWEEN period_start_d AND period_end_d
          ORDER BY period_start_d DESC
          LIMIT 1
        )
        UNION ALL
        (
          SELECT period_ID FROM ReviewPeriods
          WHERE is_active = 1
            AND period_start_d > CURRENT_DATE
          ORDER BY period_start_d ASC
          LIMIT 1
        )
        UNION ALL
        (
          SELECT period_ID FROM ReviewPeriods
          WHERE is_active = 1
            AND period_end_d < CURRENT_DATE
          ORDER BY period_end_d DESC
          LIMIT 1
        )
        LIMIT 1";
        $period_ID = $conn->query($sqlPeriod)->fetchColumn();

        if (!$period_ID) { http_response_code(200); echo '目前沒有可用的互評時段'; break; }
        $period_ID = (int)$period_ID;

        $sqlCheck = "SELECT COUNT(*) FROM peerreview WHERE period_ID = ? AND review_a_u_ID = ?";
        $ratedCount = $conn->prepare($sqlCheck);
        $ratedCount->execute([$period_ID, $review_a_u_ID]);
        if ($ratedCount->fetchColumn() > 0) { http_response_code(200); echo "您已經完成評分，無法再次提交"; break; }

        $raw  = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!$data || !is_array($data)) { http_response_code(200); echo '資料格式錯誤'; break; }

        $sql = "
        INSERT INTO peerreview
          (period_ID, review_a_u_ID, review_b_u_ID, score, review_comment, review_created_d)
        VALUES
          (:pid, :a, :b, :s, :c, NOW())
        ON DUPLICATE KEY UPDATE
          score = VALUES(score),
          review_comment = VALUES(review_comment),
          review_created_d = NOW()
        ";
        $stmt = $conn->prepare($sql);

        try {
          foreach ($data as $review_b_u_ID => $entry) {
            $score   = isset($entry['score']) ? (int)$entry['score'] : 0;
            $comment = isset($entry['comment']) ? trim($entry['comment']) : '';
            if ($score >= 1 && $score <= 5) {
              $stmt->execute([
                ':pid' => $period_ID,
                ':a'   => $review_a_u_ID,
                ':b'   => strval($review_b_u_ID),
                ':s'   => $score,
                ':c'   => $comment
              ]);
            }
          }
          echo 'ok';
        } catch (PDOException $e) {
          http_response_code(200);
          echo '資料寫入失敗：' . $e->getMessage();
        }
        break;

    case 'get_active_period':
        $sql = "
        (
          SELECT period_ID, period_title, period_start_d, period_end_d
          FROM ReviewPeriods
          WHERE is_active = 1
            AND CURRENT_DATE BETWEEN period_start_d AND period_end_d
          ORDER BY period_start_d DESC
          LIMIT 1
        )
        UNION ALL
        (
          SELECT period_ID, period_title, period_start_d, period_end_d
          FROM ReviewPeriods
          WHERE is_active = 1
            AND period_start_d > CURRENT_DATE
          ORDER BY period_start_d ASC
          LIMIT 1
        )
        UNION ALL
        (
          SELECT period_ID, period_title, period_start_d, period_end_d
          FROM ReviewPeriods
          WHERE is_active = 1
            AND period_end_d < CURRENT_DATE
          ORDER BY period_end_d DESC
          LIMIT 1
        )
        LIMIT 1";
        $period = $conn->query($sql)->fetch();
        echo json_encode($period ?: null, JSON_UNESCAPED_UNICODE);
        break;

    case 'set_active_period':
        $pid = intval($p['period_ID'] ?? 0);
        if ($pid <= 0) { http_response_code(200); echo 'period_ID 缺漏'; break; }
        $conn->beginTransaction();
        $conn->exec("UPDATE ReviewPeriods SET is_active=0");
        $stmt = $conn->prepare("UPDATE ReviewPeriods SET is_active=1 WHERE period_ID=?");
        $stmt->execute([$pid]);
        $conn->commit();
        echo "ok";
        break;

    case 'has_rated':
        $u = strval($_SESSION['u_ID']);
        $pid = $conn->query("
        (
          SELECT period_ID FROM ReviewPeriods
          WHERE is_active = 1
            AND CURRENT_DATE BETWEEN period_start_d AND period_end_d
          ORDER BY period_start_d DESC
          LIMIT 1
        )
        UNION ALL
        (
          SELECT period_ID FROM ReviewPeriods
          WHERE is_active = 1
            AND period_start_d > CURRENT_DATE
          ORDER BY period_start_d ASC
          LIMIT 1
        )
        UNION ALL
        (
          SELECT period_ID FROM ReviewPeriods
          WHERE is_active = 1
            AND period_end_d < CURRENT_DATE
          ORDER BY period_end_d DESC
          LIMIT 1
        )
        LIMIT 1")->fetchColumn();

        if(!$pid){
          echo json_encode(['rated'=>false,'reason'=>'no_period']);
          break;
        }

        $chk= $conn->prepare("SELECT COUNT(*) FROM peerreview WHERE period_ID=? AND review_a_u_ID=?");
        $chk->execute([(int)$pid,$u]);
        $rated = $chk->fetchColumn()>0;
        echo json_encode(['rated'=>$rated,'period_ID'=>(int)$pid], JSON_UNESCAPED_UNICODE);
        break;
}
