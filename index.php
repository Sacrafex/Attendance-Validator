<?php

// Change this
$auth_code = 'replace_this';

// Do Not Change
$cookie_name = 'auth_pass';

if (!isset($_COOKIE[$cookie_name]) || $_COOKIE[$cookie_name] !== $auth_code) {

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auth_code'])) {
        if ($_POST['auth_code'] === $auth_code) {
            setcookie($cookie_name, $auth_code, time() + (86400 * 30), "/");
            header("Location: " . $_SERVER['REQUEST_URI']); 
            exit;
        } else {
            echo "❌ Wrong code. Try again.<br>";
        }
    }

    ?>
    <!DOCTYPE html>
    <html>
    <body>
    <p>No Auth Header. Please request one.</p>
    <form autocomplete="off" method="POST">
        <input type="text" name="auth_code" placeholder="Enter Authentication Code" autofocus>
        <input type="submit" value="Bypass">
    </form>
    </body>
    </html>
    <?php
    exit;
}

$dbFile = 'attendance.db';
$db = new SQLite3($dbFile);

$db->exec("
    CREATE TABLE IF NOT EXISTS members (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        pin TEXT NOT NULL,
        full_name TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

$db->exec("
    CREATE TABLE IF NOT EXISTS sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        active INTEGER DEFAULT 0,
        start_time TEXT,
        end_time TEXT
    )
");

$db->exec("
    CREATE TABLE IF NOT EXISTS attendance (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        member_id INTEGER NOT NULL,
        session_id INTEGER NOT NULL,
        clock_in_time TEXT NOT NULL,
        clock_out_time TEXT,
        FOREIGN KEY(member_id) REFERENCES members(id),
        FOREIGN KEY(session_id) REFERENCES sessions(id)
    )
");

$db->exec("
    CREATE TABLE IF NOT EXISTS extra_hours (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        member_id INTEGER NOT NULL,
        clock_in_time TEXT NOT NULL,
        clock_out_time TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(member_id) REFERENCES members(id)
    )
");

$db->exec("
    CREATE TABLE IF NOT EXISTS future_absences (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        member_id INTEGER NOT NULL,
        absence_date TEXT NOT NULL,
        reason TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(member_id) REFERENCES members(id)
    )
");

// Change These to a long string of random characters.
$adminPassword = 'root';
$adminCode = 'root-login';
$startSessionCode = 'startsession';
$endSessionCode = 'endsession';

$adminCookieName = 'admin_auth';

$stage = 'login'; 
$validStages = ['login', 'admin'];
if (isset($_GET['stage']) && in_array($_GET['stage'], $validStages)) {
    $stage = $_GET['stage'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'login':
            $pin = $_POST['pin'] ?? '';
            if ($pin) {
                if ($pin === $adminCode) {
                    setcookie($adminCookieName, '1', time() + (86400 * 30), "/"); 
                    header("Location: ?stage=admin");
                    exit;
                } elseif ($pin === $startSessionCode) {
                    
                    
                    // Start session
                    
                    
                    $db->exec("INSERT INTO sessions (active, start_time) VALUES (1, datetime('now'))");
                    
                    // Extra Hours > Session Hours change
                    
                    $newSessionId = $db->lastInsertRowID();
                    $extraHoursUsers = $db->query("SELECT * FROM extra_hours WHERE clock_out_time IS NULL");
                    while ($extraUser = $extraHoursUsers->fetchArray(SQLITE3_ASSOC)) {
                        
                        // Clock out Extra Hours
                        $db->exec("UPDATE extra_hours SET clock_out_time = datetime('now') WHERE id = {$extraUser['id']}");
                        
                        // Clock into Session
                        $db->exec("INSERT INTO attendance (member_id, session_id, clock_in_time) VALUES ({$extraUser['member_id']}, $newSessionId, datetime('now'))");
                    }
                    
                    header("Location: ?stage=login&message=Session started - Extra Hours Users Moved to Session Time");
                    exit;
                } elseif ($pin === $endSessionCode) {
                    // End session
                    $activeSession = $db->querySingle("SELECT id FROM sessions WHERE active = 1 ORDER BY id DESC LIMIT 1");
                    if ($activeSession) {
                        $db->exec("UPDATE attendance SET clock_out_time = datetime('now') WHERE session_id = $activeSession AND clock_out_time IS NULL");
                        $db->exec("UPDATE sessions SET active = 0, end_time = datetime('now') WHERE id = $activeSession");
                        
                        // Auto discord webhook info
                        
                        sendDailyReport($db);
                        
                        header("Location: ?stage=login&message=Session ended, all users clocked out, and report sent");
                    } else {
                        header("Location: ?stage=login&error=No active session to end");
                    }
                    exit;
                }
                
                // Regular member login
                $member = $db->querySingle("SELECT * FROM members WHERE pin = '" . $db->escapeString($pin) . "'", true);
                if ($member) {
                    $activeSession = $db->querySingle("SELECT id FROM sessions WHERE active = 1 ORDER BY id DESC LIMIT 1");
                    
                    if ($activeSession) {
                        // Session is active - handle session attendance
                        $existing = $db->querySingle("SELECT id FROM attendance WHERE member_id = {$member['id']} AND session_id = $activeSession AND clock_out_time IS NULL");
                        if ($existing) {
                            // Clock out of session
                            $db->exec("UPDATE attendance SET clock_out_time = datetime('now') WHERE id = $existing");
                            header("Location: ?stage=login&message=" . htmlspecialchars($member['full_name']) . " clocked out of session");
                        } else {
                            // Check if they're currently in extra hours and switch them
                            $extraHours = $db->querySingle("SELECT id FROM extra_hours WHERE member_id = {$member['id']} AND clock_out_time IS NULL");
                            if ($extraHours) {
                                // Clock out of extra hours and into session
                                $db->exec("UPDATE extra_hours SET clock_out_time = datetime('now') WHERE id = $extraHours");
                                $db->exec("INSERT INTO attendance (member_id, session_id, clock_in_time) VALUES ({$member['id']}, $activeSession, datetime('now'))");
                                header("Location: ?stage=login&message=" . htmlspecialchars($member['full_name']) . " switched from extra hours to session");
                            } else {
                                // Clock into session
                                $db->exec("INSERT INTO attendance (member_id, session_id, clock_in_time) VALUES ({$member['id']}, $activeSession, datetime('now'))");
                                header("Location: ?stage=login&message=" . htmlspecialchars($member['full_name']) . " clocked into session");
                            }
                        }
                    } else {
                        // No active session - handle extra hours
                        $existingExtra = $db->querySingle("SELECT id FROM extra_hours WHERE member_id = {$member['id']} AND clock_out_time IS NULL");
                        if ($existingExtra) {
                            // Clock out of extra hours
                            $db->exec("UPDATE extra_hours SET clock_out_time = datetime('now') WHERE id = $existingExtra");
                            header("Location: ?stage=login&message=" . htmlspecialchars($member['full_name']) . " clocked out of extra hours");
                        } else {
                            // Clock into extra hours
                            $db->exec("INSERT INTO extra_hours (member_id, clock_in_time) VALUES ({$member['id']}, datetime('now'))");
                            header("Location: ?stage=login&message=" . htmlspecialchars($member['full_name']) . " clocked into extra hours");
                        }
                    }
                    exit;
                } else {
                    $wrongMessages = array("Wrong. Idk what you did, but it's wrong.", "Try again.", "Scan it again.", "You failure... Scan again.", "A baby could figure this out. C'mon.", "Scan again man...");
                    $randomKey = array_rand($wrongMessages);
                    $message = $wrongMessages[$randomKey];
                    header("Location: ?stage=login&error=".$message);
                    exit;
                }
            } else {
                header("Location: ?stage=login&error=Please enter your PIN");
                exit;
            }

        case 'logout':
            unset($_COOKIE[$adminCookieName]);
            setcookie($adminCookieName, '', time() - 3600, "/");
            header("Location: ?stage=login");
            exit;
    }

    if (isset($_COOKIE[$adminCookieName]) && $_COOKIE[$adminCookieName] === '1') {
        switch ($action) {
            case 'start_session':
                $db->exec("INSERT INTO sessions (active, start_time) VALUES (1, datetime('now'))");
                header("Location: ?stage=admin&message=Session started");
                exit;

            case 'end_session':
                $activeSession = $db->querySingle("SELECT id FROM sessions WHERE active = 1 ORDER BY id DESC LIMIT 1");
                if ($activeSession) {
                    $db->exec("UPDATE attendance SET clock_out_time = datetime('now') WHERE session_id = $activeSession AND clock_out_time IS NULL");
                    $db->exec("UPDATE sessions SET active = 0, end_time = datetime('now') WHERE id = $activeSession");
                }
                header("Location: ?stage=admin&message=Session ended and all users Clocked Out");
                exit;

            case 'send_report':
                sendDailyReport($db);
                header("Location: ?stage=admin&message=Report sent");
                exit;

            case 'add_member':
                $username = $_POST['username'] ?? '';
                $fullName = $_POST['full_name'] ?? '';
                $pin = $_POST['pin'] ?? '';
                if ($username && $fullName && $pin) {
                    $existingPin = $db->querySingle("SELECT id FROM members WHERE pin = '" . $db->escapeString($pin) . "'");
                    if ($existingPin) {
                        header("Location: ?stage=admin&error=PIN already exists. Please choose a unique PIN.");
                        exit;
                    }
                    
                    try {
                        $stmt = $db->prepare("INSERT INTO members (username, pin, full_name) VALUES (:username, :pin, :full_name)");
                        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
                        $stmt->bindValue(':pin', $pin, SQLITE3_TEXT);
                        $stmt->bindValue(':full_name', $fullName, SQLITE3_TEXT);
                        $stmt->execute();
                        header("Location: ?stage=admin&message=Member added");
                        exit;
                    } catch (Exception $e) {
                        header("Location: ?stage=admin&error=Error adding member, contact KJ.");
                        exit;
                    }
                } else {
                    header("Location: ?stage=admin&error=Missing fields");
                    exit;
                }

            case 'update_member':
                $memberId = $_POST['member_id'] ?? '';
                $username = $_POST['username'] ?? '';
                $fullName = $_POST['full_name'] ?? '';
                $pin = $_POST['pin'] ?? '';
                if ($memberId && $username && $fullName && $pin) {
                    $existingPin = $db->querySingle("SELECT id FROM members WHERE pin = '" . $db->escapeString($pin) . "' AND id != " . $db->escapeString($memberId));
                    if ($existingPin) {
                        header("Location: ?stage=admin&error=PIN already exists for another member. Please choose a unique PIN.");
                        exit;
                    }
                    
                    $stmt = $db->prepare("UPDATE members SET username = :username, full_name = :full_name, pin = :pin WHERE id = :id");
                    $stmt->bindValue(':id', $memberId, SQLITE3_INTEGER);
                    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
                    $stmt->bindValue(':full_name', $fullName, SQLITE3_TEXT);
                    $stmt->bindValue(':pin', $pin, SQLITE3_TEXT);
                    $stmt->execute();
                    header("Location: ?stage=admin&message=Member updated");
                    exit;
                } else {
                    header("Location: ?stage=admin&error=Missing fields");
                    exit;
                }

            case 'delete_member':
                $memberId = $_POST['member_id'] ?? '';
                if ($memberId) {
                    $db->exec("DELETE FROM attendance WHERE member_id = $memberId");
                    $db->exec("DELETE FROM extra_hours WHERE member_id = $memberId");
                    $db->exec("DELETE FROM members WHERE id = $memberId");
                    header("Location: ?stage=admin&message=Member deleted");
                    exit;
                } else {
                    header("Location: ?stage=admin&error=Missing member ID");
                    exit;
                }
                
            case 'clear_session_data':
                $sessionId = $_POST['session_id'] ?? '';
                if ($sessionId) {
                    $db->exec("DELETE FROM attendance WHERE session_id = $sessionId");
                    $db->exec("DELETE FROM sessions WHERE id = $sessionId");
                    header("Location: ?stage=admin&message=Session data cleared");
                    exit;
                } else {
                    header("Location: ?stage=admin&error=Missing session ID");
                    exit;
                }

            case 'clear_user_stats':
                $memberId = $_POST['member_id'] ?? '';
                $confirmPassword = $_POST['confirm_password'] ?? '';
                if ($memberId && $confirmPassword === $adminPassword) {
                    $db->exec("DELETE FROM attendance WHERE member_id = $memberId");
                    $db->exec("DELETE FROM extra_hours WHERE member_id = $memberId");
                    header("Location: ?stage=admin&message=User statistics cleared");
                    exit;
                } else {
                    header("Location: ?stage=admin&error=Invalid password or missing member ID");
                    exit;
                }

            case 'delete_user_stats':
                $memberId = $_POST['member_id'] ?? '';
                $confirmPassword = $_POST['confirm_password'] ?? '';
                if ($memberId && $confirmPassword === $adminPassword) {
                    $db->exec("DELETE FROM attendance WHERE member_id = $memberId");
                    $db->exec("DELETE FROM extra_hours WHERE member_id = $memberId");
                    $db->exec("DELETE FROM members WHERE id = $memberId");
                    header("Location: ?stage=admin&message=User and all statistics deleted");
                    exit;
                } else {
                    header("Location: ?stage=admin&error=Invalid password or missing member ID");
                    exit;
                }
        }
    }
}

function sendDailyReport($db) {
    $today = date('Y-m-d') . ' 00:00:00';
    $tomorrow = date('Y-m-d', strtotime('+1 day')) . ' 00:00:00';

    // Get Session Attendance
    
    $result = $db->query("
        SELECT a.*, m.full_name, m.username 
        FROM attendance a 
        JOIN members m ON a.member_id = m.id 
        WHERE a.clock_in_time >= '$today' AND a.clock_in_time < '$tomorrow' 
        ORDER BY a.clock_in_time
    ");

    $todaysSessions = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $todaysSessions[] = $row;
    }

    // Get Extra hours
    
    $result = $db->query("
        SELECT eh.*, m.full_name, m.username 
        FROM extra_hours eh 
        JOIN members m ON eh.member_id = m.id 
        WHERE eh.clock_in_time >= '$today' AND eh.clock_in_time < '$tomorrow' 
        ORDER BY eh.clock_in_time
    ");

    $todaysExtraHours = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $todaysExtraHours[] = $row;
    }

    $memberAttendance = [];
    
    // Session attendance
    
    foreach ($todaysSessions as $session) {
        $memberId = $session['member_id'];
        if (!isset($memberAttendance[$memberId])) {
            $memberAttendance[$memberId] = [
                'username' => $session['username'],
                'full_name' => $session['full_name'],
                'sessions' => [],
                'extraHours' => [],
                'totalSessionSeconds' => 0,
                'totalExtraSeconds' => 0
            ];
        }

        $endTime = $session['clock_out_time'] ?: date('Y-m-d H:i:s');
        $duration = strtotime($endTime) - strtotime($session['clock_in_time']);
        $memberAttendance[$memberId]['sessions'][] = [
            'clock_in' => $session['clock_in_time'],
            'clock_out' => $session['clock_out_time'],
            'duration' => $duration
        ];
        $memberAttendance[$memberId]['totalSessionSeconds'] += $duration;
    }
    
    // Extra hours calculation
    
    foreach ($todaysExtraHours as $extra) {
        $memberId = $extra['member_id'];
        if (!isset($memberAttendance[$memberId])) {
            $memberAttendance[$memberId] = [
                'username' => $extra['username'],
                'full_name' => $extra['full_name'],
                'sessions' => [],
                'extraHours' => [],
                'totalSessionSeconds' => 0,
                'totalExtraSeconds' => 0
            ];
        }

        $endTime = $extra['clock_out_time'] ?: date('Y-m-d H:i:s');
        $duration = strtotime($endTime) - strtotime($extra['clock_in_time']);
        $memberAttendance[$memberId]['extraHours'][] = [
            'clock_in' => $extra['clock_in_time'],
            'clock_out' => $extra['clock_out_time'],
            'duration' => $duration
        ];
        $memberAttendance[$memberId]['totalExtraSeconds'] += $duration;
    }

    $allMembers = [];
    $result = $db->query("SELECT id, username, full_name FROM members");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $allMembers[] = $row;
    }

    $presentMembers = array_keys($memberAttendance);
    $absentMembers = array_filter($allMembers, function($member) use ($presentMembers) {
        return !in_array($member['id'], $presentMembers);
    });

    $message = "**Team Daedalus 2839 - Session Report for " . date('F j, Y') . "**\n\n";
    $message .= "**Present Members:**\n";
    if (empty($memberAttendance)) {
        $message .= "No members were present today\n";
    } else {
        uasort($memberAttendance, function($a, $b) {
            return ($b['totalSessionSeconds'] + $b['totalExtraSeconds']) - ($a['totalSessionSeconds'] + $a['totalExtraSeconds']);
        });

        foreach ($memberAttendance as $member) {
            $sessionTime = formatDurationSeconds($member['totalSessionSeconds']);
            $extraTime = formatDurationSeconds($member['totalExtraSeconds']);
            $totalTime = formatDurationSeconds($member['totalSessionSeconds'] + $member['totalExtraSeconds']);
            
            $sessionCount = count($member['sessions']);
            $extraCount = count($member['extraHours']);
            
            $message .= "- **{$member['full_name']}** (@{$member['username']}): $totalTime total\n";
            if ($sessionCount > 0) {
                $message .= "  └ Session: $sessionTime ($sessionCount session" . ($sessionCount > 1 ? 's' : '') . ")\n";
            }
            if ($extraCount > 0) {
                $message .= "  └ Extra: $extraTime ($extraCount period" . ($extraCount > 1 ? 's' : '') . ")\n";
            }
        }
    }

    $message .= "\n**Absent Members:**\n";
    if (empty($absentMembers)) {
        $message .= "All members were present today!\n";
    } else {
        foreach ($absentMembers as $member) {
            $message .= "- {$member['full_name']} (@{$member['username']})\n";
        }
    }

    // Change Webhook URL
    $webhookUrl = "";
    $ch = curl_init($webhookUrl);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['content' => $message]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}

function formatDurationSeconds($totalSeconds) {
    $hours = floor($totalSeconds / 3600);
    $minutes = floor(($totalSeconds % 3600) / 60);
    $seconds = $totalSeconds % 60;
    return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
}

$currentSession = $db->querySingle("SELECT * FROM sessions WHERE active = 1 ORDER BY id DESC LIMIT 1", true);
$members = [];
$result = $db->query("SELECT * FROM members ORDER BY full_name");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $members[] = $row;
}

$allSessions = [];
$memberStats = [];
if (isset($_COOKIE[$adminCookieName]) && $_COOKIE[$adminCookieName] === '1') {
    $result = $db->query("SELECT * FROM sessions ORDER BY id DESC");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $allSessions[] = $row;
    }
    
    foreach ($members as $member) {
        $memberId = $member['id'];
        
        $timesShowedUp = $db->querySingle("
            SELECT COUNT(DISTINCT session_id) 
            FROM attendance 
            WHERE member_id = $memberId
        ");
        
        $totalSeconds = 0;
        $extraHoursSeconds = 0;
        
        // Calculate session time
        $result = $db->query("
            SELECT clock_in_time, clock_out_time 
            FROM attendance 
            WHERE member_id = $memberId AND clock_out_time IS NOT NULL
        ");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $totalSeconds += strtotime($row['clock_out_time']) - strtotime($row['clock_in_time']);
        }
        
        // Extra hours calculation
        
        $result = $db->query("
            SELECT clock_in_time, clock_out_time 
            FROM extra_hours 
            WHERE member_id = $memberId AND clock_out_time IS NOT NULL
        ");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $extraHoursSeconds += strtotime($row['clock_out_time']) - strtotime($row['clock_in_time']);
        }
        
        $avgSessionTime = $timesShowedUp > 0 ? $totalSeconds / $timesShowedUp : 0;
        
        $lastAttendance = $db->querySingle("
            SELECT MAX(clock_in_time) 
            FROM attendance 
            WHERE member_id = $memberId
        ");
        
        $totalSessions = $db->querySingle("
            SELECT COUNT(*) 
            FROM attendance 
            WHERE member_id = $memberId
        ");
        
        $totalSessionsCount = count($allSessions);
        $attendanceRate = $totalSessionsCount > 0 ? ($timesShowedUp / $totalSessionsCount) * 100 : 0;
        
        $lateArrivals = 0;
        $lateArrivalTimes = [];
        $result = $db->query("
            SELECT a.clock_in_time, s.start_time, s.id as session_id
            FROM attendance a 
            JOIN sessions s ON a.session_id = s.id 
            WHERE a.member_id = $memberId
        ");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $sessionStart = strtotime($row['start_time']);
            $clockIn = strtotime($row['clock_in_time']);
            $timeDiff = $clockIn - $sessionStart;
            
            if ($timeDiff > 3600) {
                $lateArrivals++;
                $lateMinutes = round($timeDiff / 60);
                $lateArrivalTimes[] = [
                    'session_id' => $row['session_id'],
                    'minutes_late' => $lateMinutes,
                    'date' => date('M j', strtotime($row['clock_in_time']))
                ];
            }
        }
        
        $memberStats[$memberId] = [
            'times_showed_up' => $timesShowedUp,
            'total_hours' => formatDurationSeconds($totalSeconds),
            'total_seconds' => $totalSeconds,
            'extra_hours' => formatDurationSeconds($extraHoursSeconds),
            'extra_hours_seconds' => $extraHoursSeconds,
            'avg_session_time' => formatDurationSeconds($avgSessionTime),
            'last_attendance' => $lastAttendance,
            'total_sessions' => $totalSessions,
            'attendance_rate' => round($attendanceRate, 1),
            'late_arrivals' => $lateArrivals,
            'late_arrival_times' => $lateArrivalTimes
        ];
    }
}

$currentTime = date('H:i:s');
$currentDate = date('Y-m-d');

$uptime = '';
if ($currentSession) {
    $startTime = strtotime($currentSession['start_time']);
    $now = time();
    $diff = $now - $startTime;

    $hours = floor($diff / 3600);
    $minutes = floor(($diff % 3600) / 60);
    $seconds = $diff % 60;
    $uptime = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team Daedalus 2839 - <?= $stage === 'admin' ? 'Admin Dashboard' : 'Attendance System' ?></title>
    <style>
        :root {
            --primary: #2c3e50;
            --secondary: #3498db;
            --danger: #e74c3c;
            --success: #27ae60;
            --warning: #f39c12;
            --light: #ecf0f1;
            --dark: #2c3e50;
            --gray: #95a5a6;
        }
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f7fa;
            color: var(--dark);
            line-height: 1.6;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        header {
            background-color: var(--primary);
            color: white;
            padding: 20px 0;
            text-align: center;
            margin-bottom: 30px;
            border-radius: 8px;
        }
        h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }
        h2 {
            font-size: 1.8rem;
            margin-bottom: 20px;
            color: var(--primary);
        }
        .card {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            padding: 25px;
            margin-bottom: 30px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        input, select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
        }
        button {
            background-color: var(--secondary);
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        button:hover {
            background-color: #2980b9;
        }
        .btn-danger {
            background-color: var(--danger);
        }
        .btn-success {
            background-color: var(--success);
        }
        .btn-warning {
            background-color: var(--warning);
        }
        .notification {
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 4px;
        }
        .success {
            background-color: #d4edda;
            color: #155724;
        }
        .error {
            background-color: #f8d7da;
            color: #721c24;
        }
        .session-info {
            padding: 10px;
            margin-bottom: 15px;
            background-color: #e2e3e5;
            border-radius: 4px;
        }
        .admin-panel {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .admin-section {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
        }
        .action-buttons {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 10px;
            margin-bottom: 20px;
        }
        .action-buttons button {
            width: 100%;
        }
        .member-list {
            margin-top: 20px;
        }
        .member-card {
            background-color: #f8f9fa;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 15px;
            border: 2px solid #dee2e6;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .member-card:hover {
            border-color: #3498db;
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 8px;
            margin-top: 10px;
            font-size: 14px;
        }
        .stats-grid div {
            padding: 5px;
            background-color: rgba(255,255,255,0.7);
            border-radius: 3px;
        }
        .nav {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        .nav a {
            padding: 8px 15px;
            background-color: var(--light);
            border-radius: 4px;
            text-decoration: none;
            color: var(--dark);
        }
        .nav a:hover {
            background-color: #d6e0e5;
        }
        .session-card {
            background-color: #f8f9fa;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 10px;
        }
        .session-card h4 {
            margin-bottom: 5px;
        }
        .session-card p {
            margin-bottom: 5px;
        }
        .session-data {
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>Team Daedalus 2839</h1>
            <p><?= $stage === 'admin' ? 'Admin Dashboard' : 'Attendance System' ?></p>
        </header>

        <?php if (isset($_GET['message'])): ?>
            <div class="notification success">
                <?= htmlspecialchars($_GET['message']) ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div class="notification error">
                <?= htmlspecialchars($_GET['error']) ?>
            </div>
        <?php endif; ?>

        <!-- Session info displayed on all pages -->
        
        <div class="session-info">
            <p>Current session status: <strong><?= $currentSession ? 'Active' : 'Inactive' ?></strong></p>
            <?php if ($currentSession): ?>
                <p>Started: <?= date('M j, Y g:i A', strtotime($currentSession['start_time'])) ?></p>
                <p>Uptime: <?= $uptime ?></p>
                <?php
                $currentlyInSession = $db->querySingle("SELECT COUNT(*) FROM attendance WHERE session_id = {$currentSession['id']} AND clock_out_time IS NULL");
                ?>
                <p>Currently in session: <strong><?= $currentlyInSession ?> members</strong></p>
            <?php else: ?>
                <?php
                $currentlyInExtra = $db->querySingle("SELECT COUNT(*) FROM extra_hours WHERE clock_out_time IS NULL");
                ?>
                <p>Currently in extra hours: <strong><?= $currentlyInExtra ?> members</strong></p>
            <?php endif; ?>
        </div>

        <!-- Login Page -->
        
        <?php if ($stage === 'login'): ?>
            <div class="card">
                <h2>Scan Barcode or Enter Code</h2>
                <p style="margin-bottom: 20px; color: #666;">
                    • Scan your member barcode to clock in/out<br>
                    • <strong>Session active:</strong> Clock in/out of session time<br>
                    • <strong>No session:</strong> Clock in/out of extra hours<br>
                </p>
                <form autocomplete="off" method="post">
                    <div class="form-group">
                        <input type="password" id="pin" name="pin" autocomplete="new-password" required autofocus
                               placeholder="Scan barcode or enter code" style="height:50px; font-size: 18px; text-align: center;"
                               onkeydown="if(event.key==='Enter'){ this.form.submit(); }">
                    </div>
                    <button type="submit" name="action" value="login" class="btn" style="width: 100%; padding: 15px; font-size: 18px;">Submit</button>
                </form>
                <div style="margin-top: 20px; padding: 10px; background-color: #e3f2fd; border-radius: 4px; font-size: 14px;">
                    <strong>Need Help?</strong> Contact leadership for assistance with your barcode or credentials.
                </div>
            </div>
        <?php endif; ?>

        <!-- Admin Dashboard -->
        
        <?php if ($stage === 'admin' && isset($_COOKIE[$adminCookieName]) && $_COOKIE[$adminCookieName] === '1'): ?>
            <div class="nav">
                <a href="?stage=login">User Login</a>
            </div>

            <div class="card">
                <h2>System Codes</h2>
                <div class="stats-grid">
                    <div><strong>Admin Access:</strong> <?= $adminCode ?></div>
                    <div><strong>Start Session:</strong> <?= $startSessionCode ?></div>
                    <div><strong>End Session:</strong> <?= $endSessionCode ?></div>
                </div>
            </div>

            <div class="action-buttons">
                <form autocomplete="off" method="post">
                    <button type="submit" name="action" value="send_report" class="btn">Send Daily Report</button>
                </form>
            </div>

            <!-- Team Summary Statistics -->
            <div class="card">
                <h2>Team Summary</h2>
                <?php
                $totalMembers = count($members);
                $totalHoursWorked = array_sum(array_column($memberStats, 'total_seconds')) / 3600;
                $avgAttendanceRate = $totalMembers > 0 ? array_sum(array_column($memberStats, 'attendance_rate')) / $totalMembers : 0;
                $mostActiveMembers = array_slice(array_keys(array_filter($memberStats, function($stats) {
                    return $stats['times_showed_up'] > 0;
                })), 0, 3);
                ?>
                <div class="stats-grid">
                    <div><strong>Total Members:</strong> <?= $totalMembers ?></div>
                    <div><strong>Total Sessions:</strong> <?= count($allSessions) ?></div>
                    <div><strong>Total Hours Logged:</strong> <?= round($totalHoursWorked, 1) ?> hours</div>
                    <div><strong>Average Attendance Rate:</strong> <?= round($avgAttendanceRate, 1) ?>%</div>
                    <div><strong>Active Today:</strong> 
                        <?php 
                        $activeToday = 0;
                        if ($currentSession) {
                            $activeToday = $db->querySingle("SELECT COUNT(DISTINCT member_id) FROM attendance WHERE session_id = {$currentSession['id']}");
                        }
                        echo $activeToday . " members";
                        ?>
                    </div>
                </div>
            </div>

            <div class="admin-panel">
                <div class="admin-section">
                    <h3>Add New Member</h3>
                    <form autocomplete="off" method="post">
                        <div class="form-group">
                            <label for="username">Username</label>
                            <input type="text" id="username" name="username" required>
                        </div>
                        <div class="form-group">
                            <label for="full_name">Full Name</label>
                            <input type="text" id="full_name" name="full_name" required>
                        </div>
                        <div class="form-group">
                <label for="pin">Credential (Must be unique)</label>
                <input type="text" id="pin" name="pin" autocomplete="off" required
                    placeholder="Enter unique credential (PIN, barcode, or password)">
                <small style="color: #666;">Credential must be unique to this member. Letters, numbers, and symbols allowed.</small>
                        </div>
                        <button type="submit" name="action" value="add_member" class="btn">Add Member</button>
                    </form>
                </div>

                <div class="admin-section">
                    <h3>Update Member</h3>
                    <form autocomplete="off" method="post">
                        <div class="form-group">
                            <label for="update_member_id">Member</label>
                            <select id="update_member_id" name="member_id" required>
                                <option value="">Select member</option>
                                <?php foreach ($members as $member): ?>
                                    <option value="<?= $member['id'] ?>"><?= htmlspecialchars($member['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="update_username">Username</label>
                            <input type="text" id="update_username" name="username" required>
                        </div>
                        <div class="form-group">
                            <label for="update_full_name">Full Name</label>
                            <input type="text" id="update_full_name" name="full_name" required>
                        </div>
                        <div class="form-group">
                <label for="update_pin">Credential (Must be unique)</label>
                <input type="text" id="update_pin" name="pin" required autocomplete="off"
                    placeholder="Enter unique credential (PIN, barcode, or password)">
                <small style="color: #666;">Credential must be unique to this member. Letters, numbers, and symbols allowed.</small>
                        </div>
                        <button type="submit" name="action" value="update_member" class="btn-warning">Update Member</button>
                    </form>
                </div>

                <div class="admin-section">
                    <h3>Remove Member</h3>
                    <form autocomplete="off" method="post" onsubmit="return confirm('Are you sure you want to delete this member?')">
                        <div class="form-group">
                            <label for="delete_member_id">Member</label>
                            <select id="delete_member_id" name="member_id" required>
                                <option value="">Select member</option>
                                <?php foreach ($members as $member): ?>
                                    <option value="<?= $member['id'] ?>"><?= htmlspecialchars($member['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" name="action" value="delete_member" class="btn-danger">Remove Member</button>
                    </form>
                </div>
            </div>

            <div class="card">
                <h2>Member Statistics</h2>
                <div class="member-list">
                    <?php if (empty($members)): ?>
                        <p>No members found.</p>
                    <?php else: ?>
                        <?php 
                        usort($members, function($a, $b) use ($memberStats) {
                            return $memberStats[$b['id']]['times_showed_up'] - $memberStats[$a['id']]['times_showed_up'];
                        });
                        ?>
                        <?php foreach ($members as $member): ?>
                            <?php $stats = $memberStats[$member['id']]; ?>
                            <div class="member-card">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                    <strong><?= htmlspecialchars($member['full_name']) ?>
                                        <?php if ($stats['late_arrivals'] > 0): ?>
                                            <span style="color: #e74c3c; font-size: 0.9em;">(Late: <?= $stats['late_arrivals'] ?>x)</span>
                                        <?php endif; ?>
                                    </strong>
                                    <div style="display: flex; gap: 5px;">
                                        <button onclick="showClearStatsModal(<?= $member['id'] ?>, '<?= htmlspecialchars($member['full_name']) ?>')" class="btn-warning" style="padding: 5px 10px; font-size: 12px;">Clear Stats</button>
                                        <button onclick="showDeleteUserModal(<?= $member['id'] ?>, '<?= htmlspecialchars($member['full_name']) ?>')" class="btn-danger" style="padding: 5px 10px; font-size: 12px;">Delete User</button>
                                    </div>
                                </div>
                                <div>Username: <?= htmlspecialchars($member['username']) ?> | PIN: <?= htmlspecialchars($member['pin']) ?></div>
                                <div class="stats-grid">
                                    <div><strong>Times Showed Up:</strong> <?= $stats['times_showed_up'] ?></div>
                                    <div><strong>Session Hours:</strong> <?= $stats['total_hours'] ?></div>
                                    <div><strong>Extra Hours:</strong> <?= $stats['extra_hours'] ?></div>
                                    <div><strong>Avg Session Time:</strong> <?= $stats['avg_session_time'] ?></div>
                                    <div><strong>Attendance Rate:</strong> <?= $stats['attendance_rate'] ?>%</div>
                                    <div><strong>Late Arrivals:</strong> <?= $stats['late_arrivals'] ?></div>
                                    <div><strong>Last Attendance:</strong> 
                                        <?= $stats['last_attendance'] ? date('M j, Y', strtotime($stats['last_attendance'])) : 'Never' ?>
                                    </div>
                                </div>
                                <?php if (!empty($stats['late_arrival_times'])): ?>
                                    <div style="margin-top: 10px; padding: 8px; background-color: #fff2f2; border-radius: 3px; border-left: 3px solid #e74c3c;">
                                        <strong>Late Arrival Details:</strong><br>
                                        <?php foreach ($stats['late_arrival_times'] as $late): ?>
                                            <small>Session #<?= $late['session_id'] ?> (<?= $late['date'] ?>): <?= $late['minutes_late'] ?> min late</small><br>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Session Data Section -->
            <div class="card session-data">
                <h2>Session History</h2>
                <div class="member-list">
                    <?php if (empty($allSessions)): ?>
                        <p>No session history found.</p>
                    <?php else: ?>
                        <?php foreach ($allSessions as $session): ?>
                            <div class="session-card">
                                <h4>Session #<?= $session['id'] ?></h4>
                                <p>Status: <?= $session['active'] ? 'Active' : 'Ended' ?></p>
                                <p>Start Time: <?= date('M j, Y g:i A', strtotime($session['start_time'])) ?></p>
                                <?php if ($session['end_time']): ?>
                                    <p>End Time: <?= date('M j, Y g:i A', strtotime($session['end_time'])) ?></p>
                                <?php endif; ?>
                                
                                <?php if (!$session['active']): ?>
                                    <form autocomplete="off" method="post" style="margin-top: 10px;">
                                        <input type="hidden" name="session_id" value="<?= $session['id'] ?>">
                                        <button type="submit" name="action" value="clear_session_data" class="btn-danger">Clear Session Data</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Footer -->
    <footer style="text-align: center; margin-top: 40px; padding: 20px; color: #666; font-size: 14px;">
        Made by <a href="https://github.com/Sacrafex" target="_blank" style="color: #3498db; text-decoration: none;">github.com/Sacrafex</a>
    </footer>

    <!--Clear Stats Confirmation -->
    <div id="clearStatsModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 1000;">
        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 20px; border-radius: 8px; width: 400px;">
            <h3>Clear User Statistics</h3>
            <p>Are you sure you want to clear all statistics for <strong id="clearStatsUserName"></strong>?</p>
            <p style="color: #e74c3c; font-size: 14px;">This will remove all attendance records but keep the user account.</p>
            <form autocomplete="off" method="post">
                <input type="hidden" id="clearStatsMemberId" name="member_id">
                <div class="form-group">
                    <label for="clearStatsPassword">Enter Admin Password:</label>
                    <input type="password" id="clearStatsPassword" name="confirm_password" required autocomplete="new-password">
                </div>
                <div style="display: flex; gap: 10px; margin-top: 15px;">
                    <button type="submit" name="action" value="clear_user_stats" class="btn-warning">Clear Statistics</button>
                    <button type="button" onclick="hideClearStatsModal()" class="btn">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete User Confirmation -->
    <div id="deleteUserModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 1000;">
        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 20px; border-radius: 8px; width: 400px;">
            <h3>Delete User Account</h3>
            <p>Are you sure you want to permanently delete <strong id="deleteUserName"></strong>?</p>
            <p style="color: #e74c3c; font-size: 14px;">This will remove the user account AND all their statistics permanently.</p>
            <form autocomplete="off" method="post">
                <input type="hidden" id="deleteUserMemberId" name="member_id">
                <div class="form-group">
                    <label for="deleteUserPassword">Enter Admin Password:</label>
                    <input type="password" id="deleteUserPassword" name="confirm_password" required autocomplete="new-password">
                </div>
                <div style="display: flex; gap: 10px; margin-top: 15px;">
                    <button type="submit" name="action" value="delete_user_stats" class="btn-danger">Delete User</button>
                    <button type="button" onclick="hideDeleteUserModal()" class="btn">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.getElementById('update_member_id')?.addEventListener('change', function() {
            const memberId = this.value;
            if (!memberId) return;

            alert("Please manually enter the updated information for the selected member.");
        });

        function showClearStatsModal(memberId, userName) {
            document.getElementById('clearStatsMemberId').value = memberId;
            document.getElementById('clearStatsUserName').textContent = userName;
            document.getElementById('clearStatsPassword').value = '';
            document.getElementById('clearStatsModal').style.display = 'block';
        }

        function hideClearStatsModal() {
            document.getElementById('clearStatsModal').style.display = 'none';
        }

        function showDeleteUserModal(memberId, userName) {
            document.getElementById('deleteUserMemberId').value = memberId;
            document.getElementById('deleteUserName').textContent = userName;
            document.getElementById('deleteUserPassword').value = '';
            document.getElementById('deleteUserModal').style.display = 'block';
        }

        function hideDeleteUserModal() {
            document.getElementById('deleteUserModal').style.display = 'none';
        }

        window.onclick = function(event) {
            if (event.target == document.getElementById('clearStatsModal')) {
                hideClearStatsModal();
            }
            if (event.target == document.getElementById('deleteUserModal')) {
                hideDeleteUserModal();
            }
        }
    </script>
</body>
</html>
