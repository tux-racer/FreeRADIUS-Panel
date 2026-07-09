<?php
session_start();

/*************************************************
 * FreeRADIUS Test Panel
 * Single File Version (Fixed)
 *************************************************/

$config = [
    'host'  => 'localhost',
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

// PROSES CRUD: DELETE USER
$action = $_GET['action'] ?? '';
if ($action == 'delete' && isset($_GET['u'])) {
    $user = $_GET['u'];
    $pdo->prepare("DELETE FROM radcheck WHERE username=?")->execute([$user]);
    $pdo->prepare("DELETE FROM radreply WHERE username=?")->execute([$user]);

    header("Location: radius-test.php");
    exit;
}

// PROSES CRUD: SAVE / UPDATE USER
if (isset($_POST['save'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $expire   = trim($_POST['expire']);
    $simultaneous = trim($_POST['simultaneous']); // Input baru
    $speed    = trim($_POST['speed']);
    $timeout  = trim($_POST['timeout']);

    if ($username != "") {
        // Hapus data lama agar tidak duplikat saat update
        $pdo->prepare("DELETE FROM radcheck WHERE username=?")->execute([$username]);
        $pdo->prepare("DELETE FROM radreply WHERE username=?")->execute([$username]);

        // Simpan Cleartext-Password
        $pdo->prepare("INSERT INTO radcheck (username,attribute,op,value) VALUES (?,?,?,?)")
            ->execute([$username, 'Cleartext-Password', ':=', $password]);

        // Simpan Expiration (jika diisi)
        if ($expire != "") {
            $pdo->prepare("INSERT INTO radcheck (username,attribute,op,value) VALUES (?,?,?,?)")
                ->execute([$username, 'Expiration', ':=', date("d M Y", strtotime($expire))]);
        }

        // Simpan Simultaneous-Use (jika diisi) -> Masuk ke radcheck
        if ($simultaneous != "") {
            $pdo->prepare("INSERT INTO radcheck (username,attribute,op,value) VALUES (?,?,?,?)")
                ->execute([$username, 'Simultaneous-Use', ':=', $simultaneous]);
        }

        // Simpan Session-Timeout (jika diisi)
        if ($timeout != "") {
            $pdo->prepare("INSERT INTO radreply (username,attribute,op,value) VALUES (?,?,?,?)")
                ->execute([$username, 'Session-Timeout', ':=', $timeout]);
        }

        // Simpan Mikrotik Rate Limit (jika diisi)
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
        <a href="?logout=1" class="btn btn-danger btn-sm">Logout</a>
    </div>
</nav>

<div class="container mt-4">

    <div class="card mb-4 shadow-sm">
        <div class="card-header">Add / Update User</div>
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
                    <input type="number" name="simultaneous" class="form-control" placeholder="Simultaneous Use (e.g. 1)" min="1">
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

    <div class="card shadow-sm">
        <div class="card-header">User List</div>
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