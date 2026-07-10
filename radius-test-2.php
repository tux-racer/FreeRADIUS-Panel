<?php
session_start();

/*************************************************
 * FreeRADIUS Test Panel + Active Monitor
 * Single File Version
 *************************************************/

$config = [
    'host'  => '10.10.0.15',
    'db'    => 'radius',
    'user'  => 'radius',
    'pass'  => 'rahasia',

    // password login panel
    'admin_password' => 'admin123'
];

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['db']};charset=utf8mb4",
        $config['user'],
        $config['pass']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("Database Connection Error: " . $e->getMessage());
}

function logged()
{
    return isset($_SESSION['login']) && $_SESSION['login'] === true;
}

// Proses Login
if (isset($_POST['login'])) {
    if ($_POST['password'] == $config['admin_password']) {
        $_SESSION['login'] = true;
        header("Location: radius-test.php");
        exit;
    }
    $error = "Password salah";
}

// Proses Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: radius-test.php");
    exit;
}

// JIKA BELUM LOGIN -> TAMPILKAN HALAMAN LOGIN & EXIT
if (!logged()) {
    ?>
    <!doctype html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <title>Radius Test - Login</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-4 mt-5">
                    <div class="card shadow-sm">
                        <div class="card-header text-center bg-dark text-white">
                            FreeRADIUS Test Panel
                        </div>
                        <div class="card-body">
                            <?php if (isset($error)): ?>
                                <div class='alert alert-danger py-2'><?= htmlspecialchars($error) ?></div>
                            <?php endif; ?>
                            <form method="post">
                                <input type="password" name="password" class="form-control mb-3" placeholder="Admin Password" required>
                                <button class="btn btn-primary w-100" name="login">Login</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// =========================================================================
// BAGIAN DI BAWAH INI HANYA AKAN DIEKSEKUSI JIKA SUDAH LOGIN
// =========================================================================

$action = $_GET['action'] ?? '';

// PROSES CRUD: DELETE USER
if ($action == 'delete' && isset($_GET['u'])) {
    $user = $_GET['u'];
    $pdo->prepare("DELETE FROM radcheck WHERE username=?")->execute([$user]);
    $pdo->prepare("DELETE FROM radreply WHERE username=?")->execute([$user]);

    header("Location: radius-test.php");
    exit;
}

// PROSES MONITOR: KICK / DISCONNECT USER (Menutup sesi secara paksa di DB)
if ($action == 'kick' && isset($_GET['session'])) {
    $sessionid = $_GET['session'];
    // Mengeset acctstoptime untuk menandai sesi telah berakhir jika perangkat tidak mengirimkan paket stop
    $pdo->prepare("UPDATE radacct SET acctstoptime = NOW(), acctterminatecause = 'Admin Reset' WHERE radacctid = ? AND acctstoptime IS NULL")
        ->execute([$sessionid]);
    
    header("Location: radius-test.php");
    exit;
}

// PROSES CRUD: SAVE / UPDATE USER
if (isset($_POST['save'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $expire   = trim($_POST['expire']);
    $simultaneous = trim($_POST['simultaneous']);
    $speed    = trim($_POST['speed']);
    $timeout  = trim($_POST['timeout']);

    if ($username != "") {
        $pdo->prepare("DELETE FROM radcheck WHERE username=?")->execute([$username]);
        $pdo->prepare("DELETE FROM radreply WHERE username=?")->execute([$username]);

        $pdo->prepare("INSERT INTO radcheck (username,attribute,op,value) VALUES (?,?,?,?)")
            ->execute([$username, 'Cleartext-Password', ':=', $password]);

        if ($expire != "") {
            $pdo->prepare("INSERT INTO radcheck (username,attribute,op,value) VALUES (?,?,?,?)")
                ->execute([$username, 'Expiration', ':=', date("d M Y", strtotime($expire))]);
        }

        if ($simultaneous != "") {
            $pdo->prepare("INSERT INTO radcheck (username,attribute,op,value) VALUES (?,?,?,?)")
                ->execute([$username, 'Simultaneous-Use', ':=', $simultaneous]);
        }

        if ($timeout != "") {
            $pdo->prepare("INSERT INTO radreply (username,attribute,op,value) VALUES (?,?,?,?)")
                ->execute([$username, 'Session-Timeout', ':=', $timeout]);
        }

        if ($speed != "") {
            $pdo->prepare("INSERT INTO radreply (username,attribute,op,value) VALUES (?,?,?,?)")
                ->execute([$username, 'Mikrotik-Rate-Limit', ':=', $speed]);
        }

        header("Location: radius-test.php");
        exit;
    }
}

// AMBIL DATA USER UNTUK TABEL
$sql = "
    SELECT 
        c.username,
        MAX(CASE WHEN c.attribute='Expiration' THEN c.value END) as expire,
        MAX(CASE WHEN c.attribute='Simultaneous-Use' THEN c.value END) as simultaneous,
        MAX(CASE WHEN r.attribute='Session-Timeout' THEN r.value END) as timeout,
        MAX(CASE WHEN r.attribute='Mikrotik-Rate-Limit' THEN r.value END) as speed
    FROM radcheck c
    LEFT JOIN radreply r ON c.username = r.username
    GROUP BY c.username
    ORDER BY c.username
";
$users = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// QUERY MONITORING: AMBIL DATA CLIENT YANG SEDANG AKTIF (ONLINE)
$sql_active = "
    SELECT 
        radacctid,
        username, 
        nasipaddress, 
        framedipaddress, 
        acctstarttime, 
        nasportid,
        (UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(acctstarttime)) AS duration
    FROM radacct 
    WHERE acctstoptime IS NULL
    ORDER BY acctstarttime DESC
";
$active_users = $pdo->query($sql_active)->fetchAll(PDO::FETCH_ASSOC);
$total_active = count($active_users);
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>FreeRADIUS Test Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark">
    <div class="container-fluid">
        <span class="navbar-brand">FreeRADIUS Test Panel</span>
        <span class="badge bg-success me-auto ms-2">Online Clients: <?= $total_active ?></span>
        <a href="?logout=1" class="btn btn-danger btn-sm">Logout</a>
    </div>
</nav>

<div class="container mt-4">

    <div class="card mb-4 shadow-sm">
        <div class="card-header bg-secondary text-white">Add / Update User</div>
        <div class="card-body">
            <form method="post" class="row g-2">
                <div class="col-md-2">
                    <input type="text" name="username" class="form-control" placeholder="Username" required>
                </div>
                <div class="col-md-2">
                    <input type="text" name="password" class="form-control" placeholder="Password" required>
                </div>
                <div class="col-md-2">
                    <input type="date" name="expire" class="form-control" title="Expire Date">
                </div>
                <div class="col-md-2">
                    <input type="number" name="simultaneous" class="form-control" placeholder="Simultaneous Use" min="1">
                </div>
                <div class="col-md-2">
                    <input type="text" name="timeout" class="form-control" placeholder="Timeout (sec)">
                </div>
                <div class="col-md-2">
                    <input type="text" name="speed" class="form-control" placeholder="Rate Limit (e.g. 10M/10M)">
                </div>
                <div class="col-md-12 mt-3">
                    <button name="save" class="btn btn-primary w-100">Save User</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card mb-4 border-success shadow-sm">
        <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
            <span><strong>Active Sessions (Monitoring)</strong></span>
            <span class="badge bg-white text-success fw-bold"><?= $total_active ?> User Online</span>
        </div>
        <div class="card-body table-responsive">
            <table class="table table-bordered table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Username</th>
                        <th>NAS / Router IP</th>
                        <th>Client IP Address</th>
                        <th>Login Time</th>
                        <th>Uptime (Duration)</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($total_active > 0): ?>
                    <?php foreach($active_users as $au): ?>
                        <?php 
                            // Mengonversi detik ke format Jam:Menit:Detik
                            $hours = floor($au['duration'] / 3650);
                            $minutes = floor(($au['duration'] / 60) % 60);
                            $seconds = $au['duration'] % 60;
                            $uptime = sprintf("%02dh %02dm %02ds", $hours, $minutes, $seconds);
                        ?>
                        <tr class="table-success-light">
                            <td><strong><?= htmlspecialchars($au['username']) ?></strong></td>
                            <td><?= htmlspecialchars($au['nasipaddress']) ?></td>
                            <td><span class="badge bg-info text-dark"><?= htmlspecialchars($au['framedipaddress'] ?? 'N/A') ?></span></td>
                            <td><?= htmlspecialchars($au['acctstarttime']) ?></td>
                            <td><?= $uptime ?></td>
                            <td>
                                <a href="?action=kick&session=<?= $au['radacctid'] ?>" 
                                   class="btn btn-sm btn-warning"
                                   onclick="return confirm('Paksa putuskan koneksi user ini di database?')">
                                   Kick
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-3">Tidak ada client yang aktif saat ini.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-dark text-white">User List</div>
        <div class="card-body table-responsive">
            <table class="table table-bordered table-striped align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>Username</th>
                        <th>Expire</th>
                        <th>Simultaneous</th>
                        <th>Timeout</th>
                        <th>Rate Limit Up/Down</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($users) > 0): ?>
                    <?php foreach($users as $u): ?>
                        <tr>
                            <td><?= htmlspecialchars($u['username']) ?></td>
                            <td><?= htmlspecialchars($u['expire'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($u['simultaneous'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($u['timeout'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($u['speed'] ?? '-') ?></td>
                            <td>
                                <a href="?action=delete&u=<?= urlencode($u['username']) ?>"
                                   class="btn btn-sm btn-danger"
                                   onclick="return confirm('Apakah Anda yakin ingin menghapus user ini?')">
                                   Delete
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted">Belum ada data user.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

</body>
</html>