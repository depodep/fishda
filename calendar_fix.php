<?php
// FIXED Calendar API for schedule_api.php - Emergency replacement
// This replaces the broken get_calendar_events case

            // --- ALL Drying Sessions (SHOW ALL FOR ADMIN) ---
            $stmtS = $dbh->prepare(
                "SELECT ds.session_id, ds.start_time, ds.end_time,
                        ds.set_temp, ds.set_humidity, ds.status,
                        u.username, u.permission,
                        CONCAT('FISDA - ', COALESCE(p.model_name, 'Fishda'), ' + ', COALESCE(p.given_code, 'FD2026')) AS device_info
                 FROM drying_sessions ds
                 JOIN tblusers u ON u.id = ds.user_id
                 LEFT JOIN tbl_prototypes p ON p.id = ds.proto_id
                 ORDER BY ds.start_time DESC
                 LIMIT 50"
            );
            $stmtS->execute([]);
            
            foreach ($stmtS->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $color = '#10b981'; // Green default
                $icon = '🔄';
                
                switch($row['status']) {
                    case 'Completed': $color = '#10b981'; $icon = '✅'; break;
                    case 'Interrupted': $color = '#f97316'; $icon = '⚠️'; break;
                    case 'Running': $color = '#3b82f6'; $icon = '🔄'; break;
                    default: $color = '#6b7280'; $icon = '📊'; break;
                }
                
                $title = "$icon {$row['device_info']}";
                
                $events[] = [
                    'id'              => 'sess_' . $row['session_id'],
                    'title'           => $title,
                    'start'           => $row['start_time'],
                    'end'             => $row['end_time'] ?: $row['start_time'],
                    'backgroundColor' => $color,
                    'borderColor'     => $color,
                    'textColor'       => '#ffffff',
                    'extendedProps'   => [
                        'type'         => 'session',
                        'session_id'   => $row['session_id'],
                        'username'     => $row['username'],
                        'device_info'  => $row['device_info'],
                        'set_temp'     => $row['set_temp'],
                        'set_humidity' => $row['set_humidity'],
                        'status'       => $row['status'],
                    ],
                ];
            }

            resp('success', 'Calendar events fetched.', $events);
?>