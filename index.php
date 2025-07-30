<?php


$auth_code = 'change_this_for_validating_specified_computers';
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
    CREATE TABLE IF NOT EXISTS future_absences (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        member_id INTEGER NOT NULL,
        absence_date TEXT NOT NULL,
        reason TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(member_id) REFERENCES members(id)
    )
");

// Change this to a more secure admin password
$adminPassword = 'admin';

$adminCookieName = 'admin_auth';

$stage = 'login'; 
$validStages = ['login', 'clock', 'admin_login', 'admin'];
if (isset($_GET['stage']) && in_array($_GET['stage'], $validStages)) {
    $stage = $_GET['stage'];
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'login':
            $pin = $_POST['pin'] ?? '';
            if ($pin) {
                $member = $db->querySingle("SELECT * FROM members WHERE pin = '" . $db->escapeString($pin) . "'", true);
                if ($member) {
                    $_SESSION['member_id'] = $member['id'];
                    $_SESSION['member_name'] = $member['full_name'];
                    header("Location: ?stage=clock");
                    exit;
                } else {
                    header("Location: ?stage=login&error=Invalid PIN");
                    exit;
                }
            } else {
                header("Location: ?stage=login&error=Please enter your PIN");
                exit;
            }

        case 'admin_login':
            $password = $_POST['password'] ?? '';
            if ($password === $adminPassword) {
                setcookie($adminCookieName, '1', time() + (86400 * 30), "/"); 
                header("Location: ?stage=admin");
                exit;
            } else {
                header("Location: ?stage=admin_login&error=Invalid password");
                exit;
            }

        case 'logout':
            session_destroy();
            header("Location: ?stage=login");
            exit;

        case 'clock_in':
            if (isset($_SESSION['member_id'])) {
                $activeSession = $db->querySingle("SELECT id FROM sessions WHERE active = 1 ORDER BY id DESC LIMIT 1");
                if ($activeSession) {
                    $memberId = $_SESSION['member_id'];

                    $existing = $db->querySingle("SELECT id FROM attendance WHERE member_id = $memberId AND session_id = $activeSession AND clock_out_time IS NULL");
                    if (!$existing) {
                        $db->exec("INSERT INTO attendance (member_id, session_id, clock_in_time) VALUES ($memberId, $activeSession, datetime('now'))");
                        header("Location: ?stage=clock&message=Clocked in");
                        exit;
                    } else {
                        header("Location: ?stage=clock&error=Already clocked in");
                        exit;
                    }
                } else {
                    header("Location: ?stage=clock&error=No active session");
                    exit;
                }
            } else {
                header("Location: ?stage=login");
                exit;
            }

        case 'clock_out':
            if (isset($_SESSION['member_id'])) {
                $activeSession = $db->querySingle("SELECT id FROM sessions WHERE active = 1 ORDER BY id DESC LIMIT 1");
                if ($activeSession) {
                    $memberId = $_SESSION['member_id'];

                    $attendance = $db->querySingle("SELECT id FROM attendance WHERE member_id = $memberId AND session_id = $activeSession AND clock_out_time IS NULL", true);
                    if ($attendance) {
                        $db->exec("UPDATE attendance SET clock_out_time = datetime('now') WHERE id = {$attendance['id']}");
                        header("Location: ?stage=clock&message=Clocked out");
                        exit;
                    } else {
                        header("Location: ?stage=clock&error=Not clocked in");
                        exit;
                    }
                } else {
                    header("Location: ?stage=clock&error=No active session");
                    exit;
                }
            } else {
                header("Location: ?stage=login");
                exit;
            }

        case 'mark_absence':
            if (isset($_SESSION['member_id'])) {
                $absenceDate = $_POST['absence_date'] ?? '';
                $reason = $_POST['reason'] ?? '';
                if ($absenceDate && strtotime($absenceDate) >= strtotime(date('Y-m-d'))) {
                    $memberId = $_SESSION['member_id'];
                    
                    // Check if absence already exists for this date
                    $existing = $db->querySingle("SELECT id FROM future_absences WHERE member_id = $memberId AND absence_date = '" . $db->escapeString($absenceDate) . "'");
                    if (!$existing) {
                        $stmt = $db->prepare("INSERT INTO future_absences (member_id, absence_date, reason) VALUES (:member_id, :absence_date, :reason)");
                        $stmt->bindValue(':member_id', $memberId, SQLITE3_INTEGER);
                        $stmt->bindValue(':absence_date', $absenceDate, SQLITE3_TEXT);
                        $stmt->bindValue(':reason', $reason, SQLITE3_TEXT);
                        $stmt->execute();
                        header("Location: ?stage=clock&message=Absence marked for " . date('M j, Y', strtotime($absenceDate)));
                        exit;
                    } else {
                        header("Location: ?stage=clock&error=Absence already marked for this date");
                        exit;
                    }
                } else {
                    header("Location: ?stage=clock&error=Please select a future date");
                    exit;
                }
            } else {
                header("Location: ?stage=login");
                exit;
            }
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
                header("Location: ?stage=admin&message=Session ended and all users clocked out");
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
                        header("Location: ?stage=admin&error=Error adding member");
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
                    $db->exec("DELETE FROM members WHERE id = $memberId");
                    header("Location: ?stage=admin&message=User and all statistics deleted");
                    exit;
                } else {
                    header("Location: ?stage=admin&error=Invalid password or missing member ID");
                    exit;
                }

            case 'clear_absence':
                $absenceId = $_POST['absence_id'] ?? '';
                if ($absenceId) {
                    $db->exec("DELETE FROM future_absences WHERE id = " . $db->escapeString($absenceId));
                    header("Location: ?stage=admin&message=Absence cleared");
                    exit;
                } else {
                    header("Location: ?stage=admin&error=Missing absence ID");
                    exit;
                }
        }
    }
}

function sendDailyReport($db) {
    $today = date('Y-m-d') . ' 00:00:00';
    $tomorrow = date('Y-m-d', strtotime('+1 day')) . ' 00:00:00';

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

    $memberAttendance = [];
    foreach ($todaysSessions as $session) {
        $memberId = $session['member_id'];
        if (!isset($memberAttendance[$memberId])) {
            $memberAttendance[$memberId] = [
                'username' => $session['username'],
                'full_name' => $session['full_name'],
                'sessions' => [],
                'totalSeconds' => 0
            ];
        }

        $endTime = $session['clock_out_time'] ?: date('Y-m-d H:i:s');
        $duration = strtotime($endTime) - strtotime($session['clock_in_time']);
        $memberAttendance[$memberId]['sessions'][] = [
            'clock_in' => $session['clock_in_time'],
            'clock_out' => $session['clock_out_time'],
            'duration' => $duration
        ];
        $memberAttendance[$memberId]['totalSeconds'] += $duration;
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

    $message = "**Team Daedalus 2839 - Daily Report for " . date('F j, Y') . "**\n\n";
    $message .= "**Present Members:**\n";
    if (empty($memberAttendance)) {
        $message .= "No members were present today\n";
    } else {
        uasort($memberAttendance, function($a, $b) {
            return $b['totalSeconds'] - $a['totalSeconds'];
        });

        foreach ($memberAttendance as $member) {
            $totalTime = formatDurationSeconds($member['totalSeconds']);
            $message .= "- **{$member['full_name']}** (@{$member['username']}): $totalTime (".count($member['sessions'])." session" . (count($member['sessions']) > 1 ? 's' : '') . ")\n";
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
$futureAbsences = [];
if (isset($_COOKIE[$adminCookieName]) && $_COOKIE[$adminCookieName] === '1') {
    $result = $db->query("SELECT * FROM sessions ORDER BY id DESC");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $allSessions[] = $row;
    }
    
    $result = $db->query("
        SELECT fa.*, m.full_name, m.username 
        FROM future_absences fa 
        JOIN members m ON fa.member_id = m.id 
        WHERE fa.absence_date >= date('now') 
        ORDER BY fa.absence_date ASC
    ");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $futureAbsences[] = $row;
    }
    
    foreach ($members as $member) {
        $memberId = $member['id'];
        
        $timesShowedUp = $db->querySingle("
            SELECT COUNT(DISTINCT session_id) 
            FROM attendance 
            WHERE member_id = $memberId
        ");
        
        $totalSeconds = 0;
        $result = $db->query("
            SELECT clock_in_time, clock_out_time 
            FROM attendance 
            WHERE member_id = $memberId AND clock_out_time IS NOT NULL
        ");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $totalSeconds += strtotime($row['clock_out_time']) - strtotime($row['clock_in_time']);
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
            'avg_session_time' => formatDurationSeconds($avgSessionTime),
            'last_attendance' => $lastAttendance,
            'total_sessions' => $totalSessions,
            'attendance_rate' => round($attendanceRate, 1),
            'late_arrivals' => $lateArrivals,
            'late_arrival_times' => $lateArrivalTimes
        ];
    }
}

$loggedIn = isset($_SESSION['member_id']);
$memberName = $loggedIn ? $_SESSION['member_name'] : '';
$memberId = $loggedIn ? $_SESSION['member_id'] : null;

$clockedIn = false;
$userAbsences = [];
if ($loggedIn && $currentSession) {
    $clockedIn = $db->querySingle("SELECT id FROM attendance WHERE member_id = $memberId AND session_id = {$currentSession['id']} AND clock_out_time IS NULL");
}

if ($loggedIn) {

    $result = $db->query("SELECT * FROM future_absences WHERE member_id = $memberId AND absence_date >= date('now') ORDER BY absence_date ASC");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $userAbsences[] = $row;
    }
}

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
    <title>Team Daedalus 2839 - <?= ucfirst($stage) ?></title>
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
        .btn-logout {
            background-color: var(--gray);
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
            <p><?= $stage === 'admin' ? 'Admin Dashboard' : ($stage === 'clock' ? 'Clock In/Out' : 'Login') ?></p>
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
            <?php endif; ?>
        </div>

        <!-- Login Page -->
        <?php if ($stage === 'login'): ?>
            <div class="card">
                <h2>Member Login</h2>
                <p style="margin-bottom: 20px; color: #666;">Enter your personal PIN to log in quickly and securely.</p>
                <form autocomplete="off" method="post">
                    <div class="form-group">
                        <label for="pin">Enter your PIN</label>
                        <input type="password" id="pin" name="pin" autocomplete="new-password" required autofocus 
                               placeholder="Enter your unique PIN" style="height:40px; font-size: 12px; text-align: center;">
                    </div>
                    <button type="submit" name="action" value="login" class="btn" style="width: 100%; padding: 15px; font-size: 18px;">Login</button>
                </form>
                <div style="margin-top: 20px; padding: 10px; background-color: #e3f2fd; border-radius: 4px; font-size: 14px;">
                    <strong>Security Note:</strong> Each member has a unique PIN. You cannot log in as another member without their PIN.
                </div>
            </div>
        <?php endif; ?>

        <!-- Clock In/Out Page -->
        <?php if ($stage === 'clock' && $loggedIn): ?>
            <div class="nav">
                <a href="?stage=login&action=logout" class="btn-logout">Logout</a>
            </div>

            <div class="card">
                <h2>Welcome, <?= htmlspecialchars($memberName) ?></h2>

                <?php if ($currentSession): ?>
                    <?php if ($clockedIn): ?>
                        <p>You are currently <strong style="color: var(--success);">clocked in</strong></p>
                        <form autocomplete="off" method="post">
                            <button type="submit" name="action" value="clock_out" class="btn-danger">Clock Out</button>
                        </form>
                    <?php else: ?>
                        <p>You are currently <strong style="color: var(--danger);">clocked out</strong></p>
                        <form autocomplete="off" method="post">
                            <button type="submit" name="action" value="clock_in" class="btn-success">Clock In</button>
                        </form>
                    <?php endif; ?>
                <?php else: ?>
                    <p>There is currently no active lab session.</p>
                <?php endif; ?>
            </div>

            <!-- Mark Future Absence -->
            <div class="card">
                <h2>Mark Future Absence</h2>
                <p style="margin-bottom: 15px; color: #666;">Mark days you won't be attending in advance.</p>
                <form autocomplete="off" method="post">
                    <div class="form-group">
                        <label for="absence_date">Absence Date</label>
                        <input type="date" id="absence_date" name="absence_date" required 
                               min="<?= date('Y-m-d') ?>" style="width: 100%;">
                    </div>
                    <div class="form-group">
                        <label for="reason">Reason (Optional)</label>
                        <input type="text" id="reason" name="reason" 
                               placeholder="e.g., Vacation, Doctor appointment, etc." style="width: 100%;">
                    </div>
                    <button type="submit" name="action" value="mark_absence" class="btn-warning">Mark Absence</button>
                </form>
            </div>

            <!-- Your Future Absences -->
            <?php if (!empty($userAbsences)): ?>
                <div class="card">
                    <h2>Your Scheduled Absences</h2>
                    <div class="member-list">
                        <?php foreach ($userAbsences as $absence): ?>
                            <div class="member-card" style="background-color: #fff3cd; border-color: #ffeaa7;">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <div>
                                        <strong><?= date('l, M j, Y', strtotime($absence['absence_date'])) ?></strong>
                                        <?php if ($absence['reason']): ?>
                                            <br><small style="color: #666;">Reason: <?= htmlspecialchars($absence['reason']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <small style="color: #999;">Marked <?= date('M j', strtotime($absence['created_at'])) ?></small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Admin Login Page -->
        <?php if ($stage === 'admin_login'): ?>
            <div class="card">
                <h2>Admin Login</h2>
                <form autocomplete="off" method="post">
                    <div class="form-group">
                        <label for="password">Admin Password</label>
                        <input type="password" id="password" name="password" autocomplete="new-password" required>
                    </div>
                    <button type="submit" name="action" value="admin_login" class="btn">Login</button>
                </form>
            </div>
        <?php endif; ?>

        <!-- Admin Dashboard -->
        <?php if ($stage === 'admin' && isset($_COOKIE[$adminCookieName]) && $_COOKIE[$adminCookieName] === '1'): ?>
            <div class="nav">
                <a href="?stage=login">User Login</a>
                <a href="?logout">Logout</a>
            </div>

            <div class="action-buttons">
                <form autocomplete="off" method="post">
                    <?php if (!$currentSession): ?>
                        <button type="submit" name="action" value="start_session" style="margin-bottom:5px" class="btn-success">Start Session</button>
                    <?php else: ?>
                        <button type="submit" name="action" value="end_session" style="margin-bottom:5px" class="btn-danger">End Session</button>
                    <?php endif; ?>
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
                            <label for="pin">PIN (Must be unique)</label>
                            <input type="text" id="pin" name="pin" autocomplete="off" required 
                                   placeholder="Enter unique 4-6 digit PIN" maxlength="6" pattern="[0-9]{4,6}">
                            <small style="color: #666;">PIN must be 4-6 digits and unique to this member</small>
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
                            <label for="update_pin">PIN (Must be unique)</label>
                            <input type="text" id="update_pin" name="pin" required autocomplete="off"
                                   placeholder="Enter unique 4-6 digit PIN" maxlength="6" pattern="[0-9]{4,6}">
                            <small style="color: #666;">PIN must be 4-6 digits and unique to this member</small>
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
                                    <div><strong>Total Hours:</strong> <?= $stats['total_hours'] ?></div>
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

            <!-- Future Absences Section -->
            <div class="card">
                <h2>Scheduled Absences</h2>
                <div class="member-list">
                    <?php if (empty($futureAbsences)): ?>
                        <p>No scheduled absences.</p>
                    <?php else: ?>
                        <?php foreach ($futureAbsences as $absence): ?>
                            <div class="member-card" style="background-color: #fff3cd; border-color: #ffeaa7;">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <div>
                                        <strong><?= htmlspecialchars($absence['full_name']) ?></strong> (@<?= htmlspecialchars($absence['username']) ?>)
                                        <br><strong>Date:</strong> <?= date('l, M j, Y', strtotime($absence['absence_date'])) ?>
                                        <?php if ($absence['reason']): ?>
                                            <br><strong>Reason:</strong> <?= htmlspecialchars($absence['reason']) ?>
                                        <?php endif; ?>
                                        <br><small style="color: #666;">Marked on <?= date('M j, Y', strtotime($absence['created_at'])) ?></small>
                                    </div>
                                    <form autocomplete="off" method="post" style="margin: 0;">
                                        <input type="hidden" name="absence_id" value="<?= $absence['id'] ?>">
                                        <button type="submit" name="action" value="clear_absence" class="btn-danger" style="padding: 5px 10px; font-size: 12px;">Clear</button>
                                    </form>
                                </div>
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
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.altKey && e.key === 'd') {
                window.location.href = "?stage=admin_login";
            }
        });

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
