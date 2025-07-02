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
            $memberId = $_POST['member_id'] ?? '';
            $pin = $_POST['pin'] ?? '';
            if ($memberId && $pin) {
                $member = $db->querySingle("SELECT * FROM members WHERE id = " . $db->escapeString($memberId), true);
                if ($member && $member['pin'] === $pin) {
                    $_SESSION['member_id'] = $member['id'];
                    $_SESSION['member_name'] = $member['full_name'];
                    header("Location: ?stage=clock");
                    exit;
                } else {
                    header("Location: ?stage=login&error=Invalid credentials");
                    exit;
                }
            } else {
                header("Location: ?stage=login&error=Missing fields");
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
    }

    if (isset($_COOKIE[$adminCookieName]) && $_COOKIE[$adminCookieName] === '1') {
        switch ($action) {
            case 'start_session':
                $db->exec("INSERT INTO sessions (active, start_time) VALUES (1, datetime('now'))");
                header("Location: ?stage=admin&message=Session started");
                exit;

            case 'end_session':
                // End the session
                $activeSession = $db->querySingle("SELECT id FROM sessions WHERE active = 1 ORDER BY id DESC LIMIT 1");
                if ($activeSession) {
                    // Clock out all users who are still clocked in
                    $db->exec("UPDATE attendance SET clock_out_time = datetime('now') WHERE session_id = $activeSession AND clock_out_time IS NULL");
                    // Mark session as inactive
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

    // Change this to your webhook to recieve data
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

// Get all sessions for admin panel
$allSessions = [];
if (isset($_COOKIE[$adminCookieName]) && $_COOKIE[$adminCookieName] === '1') {
    $result = $db->query("SELECT * FROM sessions ORDER BY id DESC");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $allSessions[] = $row;
    }
}

$loggedIn = isset($_SESSION['member_id']);
$memberName = $loggedIn ? $_SESSION['member_name'] : '';
$memberId = $loggedIn ? $_SESSION['member_id'] : null;

$clockedIn = false;
if ($loggedIn && $currentSession) {
    $clockedIn = $db->querySingle("SELECT id FROM attendance WHERE member_id = $memberId AND session_id = {$currentSession['id']} AND clock_out_time IS NULL");
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
            margin-bottom: 10px;
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
                <form autocomplete="off" method="post">
                    <div class="form-group">
                        <label for="member_id">Select your account</label>
                        <select id="member_id" name="member_id" required>
                            <option value="">Select your account</option>
                            <?php foreach ($members as $member): ?>
                                <option value="<?= $member['id'] ?>"><?= htmlspecialchars($member['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="pin">Enter your PIN</label>
                        <input type="password" id="pin" name="pin" autocomplete="new-password" required>
                    </div>
                    <button type="submit" name="action" value="login" class="btn">Login</button>
                </form>
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
                        <p>You are currently <strong class="success">clocked in</strong></p>
                        <form autocomplete="off" method="post">
                            <button type="submit" name="action" value="clock_out" class="btn-danger">Clock Out</button>
                        </form>
                    <?php else: ?>
                        <p>You are currently <strong class="error">clocked out</strong></p>
                        <form autocomplete="off" method="post">
                            <button type="submit" name="action" value="clock_in" class="btn-success">Clock In</button>
                        </form>
                    <?php endif; ?>
                <?php else: ?>
                    <p>There is currently no active lab session.</p>
                <?php endif; ?>
            </div>
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
                            <label for="pin">PIN</label>
                            <input type="text" id="pin" name="pin" autocomplete="new-password" required>
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
                            <label for="update_pin">PIN</label>
                            <input type="text" id="update_pin" name="pin" required>
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
                <h2>Current Members</h2>
                <div class="member-list">
                    <?php if (empty($members)): ?>
                        <p>No members found.</p>
                    <?php else: ?>
                        <?php foreach ($members as $member): ?>
                            <div class="member-card">
                                <strong><?= htmlspecialchars($member['full_name']) ?></strong>
                                <div>Username: <?= htmlspecialchars($member['username']) ?></div>
                                <div>PIN: <?= htmlspecialchars($member['pin']) ?></div>
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
    </script>
</body>
</html>
