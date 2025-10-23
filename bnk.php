<?php
/* --------------------------------------------------------------
   Ndalama Village Bank – Treasurer Module (XAMPP + MySQLi)
   -------------------------------------------------------------- */
session_start();

// ---------- 1. DATABASE CONNECTION ----------
$host = 'localhost';
$db   = 'ndalama_vb';
$user = 'root';          // default XAMPP
$pass = '';              // default XAMPP

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("DB error: " . $e->getMessage());
}

// ---------- 2. CREATE DB + TABLES (run once) ----------
$pdo->exec("CREATE DATABASE IF NOT EXISTS `$db`");
$pdo->exec("USE `$db`");

$pdo->exec("CREATE TABLE IF NOT EXISTS treasurer (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS members (
    id VARCHAR(20) PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    address TEXT,
    join_date DATE NOT NULL
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS shares (
    id VARCHAR(20) PRIMARY KEY,
    member_id VARCHAR(20) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    share_date DATE NOT NULL,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS loans (
    id VARCHAR(20) PRIMARY KEY,
    member_id VARCHAR(20) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    interest_rate DECIMAL(5,2) NOT NULL,
    term_months INT NOT NULL,
    issue_date DATE NOT NULL,
    due_date DATE NOT NULL,
    status ENUM('Active','Repaid') DEFAULT 'Active',
    repaid_amount DECIMAL(12,2) DEFAULT 0,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    description TEXT NOT NULL,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Default treasurer (username: alex / password: kaya)
if ($pdo->query("SELECT COUNT(*) FROM treasurer")->fetchColumn() == 0) {
    $hash = password_hash('kaya', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO treasurer (username,password) VALUES (?,?)")
         ->execute(['alex', $hash]);
}

// ---------- 3. HELPERS ----------
function generateID($prefix = 'M') {
    return $prefix . substr(str_shuffle('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, 8);
}
function requireLogin() {
    if (!isset($_SESSION['treasurer_id'])) {
        header('Location: ?page=login');
        exit;
    }
}
function jsonResponse($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// ---------- 4. AJAX HANDLER ----------
if (isset($_GET['ajax'])) {
    requireLogin();
    $action = $_GET['ajax'];

    switch ($action) {
        // ---- MEMBERS ----
        case 'members_list':
            $stmt = $pdo->query("SELECT * FROM members ORDER BY join_date DESC");
            jsonResponse($stmt->fetchAll(PDO::FETCH_ASSOC));

        case 'member_add':
            $data = json_decode(file_get_contents('php://input'), true);
            $id = generateID('MEM');
            $stmt = $pdo->prepare("INSERT INTO members (id,name,phone,address,join_date) VALUES (?,?,?,?,CURDATE())");
            $stmt->execute([$id, $data['name'], $data['phone'], $data['address']]);
            logActivity("New member registered: {$data['name']} ($id)");
            jsonResponse(['success'=>true, 'id'=>$id]);

        case 'member_edit':
            $data = json_decode(file_get_contents('php://input'), true);
            $stmt = $pdo->prepare("UPDATE members SET name=?, phone=?, address=? WHERE id=?");
            $stmt->execute([$data['name'], $data['phone'], $data['address'], $data['id']]);
            logActivity("Member {$data['id']} details updated");
            jsonResponse(['success'=>true]);

        case 'member_delete':
            $id = $_GET['id'];
            $member = $pdo->query("SELECT name FROM members WHERE id='$id'")->fetchColumn();
            $pdo->prepare("DELETE FROM members WHERE id=?")->execute([$id]);
            logActivity("Member $member ($id) deleted");
            jsonResponse(['success'=>true]);

        // ---- SHARES ----
        case 'shares_list':
            $stmt = $pdo->query("SELECT s.*, m.name AS member_name FROM shares s JOIN members m ON s.member_id=m.id ORDER BY s.share_date DESC");
            jsonResponse($stmt->fetchAll(PDO::FETCH_ASSOC));

        case 'share_add':
            $data = json_decode(file_get_contents('php://input'), true);
            $id = generateID('SH');
            $stmt = $pdo->prepare("INSERT INTO shares (id,member_id,amount,share_date) VALUES (?,?,?,?)");
            $stmt->execute([$id, $data['member_id'], $data['amount'], $data['date']]);
            $member = $pdo->query("SELECT name FROM members WHERE id='{$data['member_id']}'")->fetchColumn();
            logActivity("Shares recorded for $member: {$data['amount']} units");
            jsonResponse(['success'=>true]);

        // ---- LOANS ----
        case 'loans_list':
            $stmt = $pdo->query("SELECT l.*, m.name AS member_name FROM loans l JOIN members m ON l.member_id=m.id ORDER BY l.issue_date DESC");
            jsonResponse($stmt->fetchAll(PDO::FETCH_ASSOC));

        case 'loan_add':
            $data = json_decode(file_get_contents('php://input'), true);
            $id = generateID('LN');
            $due = date('Y-m-d', strtotime($data['issue_date'] . " + {$data['term']} months"));
            $stmt = $pdo->prepare("INSERT INTO loans (id,member_id,amount,interest_rate,term_months,issue_date,due_date) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$id, $data['member_id'], $data['amount'], $data['interest'], $data['term'], $data['issue_date'], $due]);
            $member = $pdo->query("SELECT name FROM members WHERE id='{$data['member_id']}'")->fetchColumn();
            logActivity("Loan issued to $member: {$data['amount']} ($id)");
            jsonResponse(['success'=>true]);

        case 'loan_repay':
            $data = json_decode(file_get_contents('php://input'), true);
            $stmt = $pdo->prepare("SELECT amount,interest_rate,term_months,repaid_amount FROM loans WHERE id=?");
            $stmt->execute([$data['loan_id']]);
            $loan = $stmt->fetch(PDO::FETCH_ASSOC);

            $newRepaid = $loan['repaid_amount'] + $data['amount'];
            $totalDue = $loan['amount'] + ($loan['amount'] * $loan['interest_rate']/100) * ($loan['term_months']/12);
            $status = ($newRepaid >= $totalDue) ? 'Repaid' : 'Active';

            $upd = $pdo->prepare("UPDATE loans SET repaid_amount=?, status=? WHERE id=?");
            $upd->execute([$newRepaid, $status, $data['loan_id']]);
            logActivity("Repayment {$data['amount']} on loan {$data['loan_id']}");
            jsonResponse(['success'=>true]);

        // ---- ACTIVITY ----
        case 'activity_list':
            $stmt = $pdo->query("SELECT * FROM activity_log ORDER BY timestamp DESC LIMIT 10");
            jsonResponse($stmt->fetchAll(PDO::FETCH_ASSOC));

        default:
            jsonResponse(['error'=>'unknown action']);
    }
}

// ---------- 5. LOGIN HANDLER ----------
if (isset($_POST['login'])) {
    $stmt = $pdo->prepare("SELECT id,password FROM treasurer WHERE username=?");
    $stmt->execute([$_POST['username']]);
    $user = $stmt->fetch();
    if ($user && password_verify($_POST['password'], $user['password'])) {
        $_SESSION['treasurer_id'] = $user['id'];
        header('Location: ?page=dashboard');
        exit;
    } else {
        $loginError = "Invalid credentials";
    }
}

// ---------- 6. LOGOUT ----------
if (isset($_GET['page']) && $_GET['page']==='logout') {
    session_destroy();
    header('Location: ?');
    exit;
}

// ---------- 7. LOG ACTIVITY ----------
function logActivity($desc) {
    global $pdo;
    $pdo->prepare("INSERT INTO activity_log (description) VALUES (?)")->execute([$desc]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ndalama Village Bank - Treasurer Module</title>
    <style>
        /* ---------- SAME BEAUTIFUL CSS FROM YOUR FILE ---------- */
        :root{--primary-color:#2c5f2d;--secondary-color:#97bc62;--accent-color:#fccb06;--light-color:#f5f5f5;--dark-color:#333;--danger-color:#e74c3c;--success-color:#2ecc71;}
        *{margin:0;padding:0;box-sizing:border-box;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;}
        body{background:#f9f9f9;color:var(--dark-color);line-height:1.6;}
        .container{width:90%;max-width:1200px;margin:0 auto;padding:20px;}
        header{background:var(--primary-color);color:white;padding:15px 0;box-shadow:0 2px 5px rgba(0,0,0,.1);}
        .header-content{display:flex;justify-content:space-between;align-items:center;}
        .logo-container{display:flex;align-items:center;gap:15px;}
        .logo{width:60px;height:60px;background:var(--accent-color);border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:bold;color:var(--primary-color);font-size:24px;}
        .logo-text h1{font-size:1.5rem;margin-bottom:5px;}
        .logo-text p{font-size:.9rem;opacity:.9;}
        nav ul{display:flex;list-style:none;gap:20px;}
        nav a{color:white;text-decoration:none;padding:8px 15px;border-radius:4px;transition:.3s;}
        nav a:hover,nav a.active{background:rgba(255,255,255,.2);}
        .logout-btn{background:var(--accent-color);color:var(--primary-color);border:none;padding:8px 15px;border-radius:4px;cursor:pointer;font-weight:bold;transition:.3s;}
        .logout-btn:hover{opacity:.9;}
        main{min-height:calc(100vh - 160px);padding:30px 0;}
        .page{display:none;}
        .page.active{display:block;}
        .page-header{margin-bottom:30px;padding-bottom:15px;border-bottom:1px solid #ddd;}
        .page-header h2{color:var(--primary-color);font-size:1.8rem;}
        .stats-container{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:20px;margin-bottom:30px;}
        .stat-card{background:white;border-radius:8px;padding:25px;box-shadow:0 3px 10px rgba(0,0,0,.1);text-align:center;transition:.3s;}
        .stat-card:hover{transform:translateY(-5px);}
        .stat-card h3{font-size:1rem;color:#666;margin-bottom:10px;}
        .stat-value{font-size:2.5rem;font-weight:bold;color:var(--primary-color);}
        .recent-activity{background:white;border-radius:8px;padding:25px;box-shadow:0 3px 10px rgba(0,0,0,.1);}
        .recent-activity h3{margin-bottom:15px;color:var(--primary-color);}
        .activity-list{list-style:none;}
        .activity-item{padding:10px 0;border-bottom:1px solid #eee;display:flex;justify-content:space-between;}
        .activity-item:last-child{border-bottom:none;}
        .form-container{background:white;border-radius:8px;padding:25px;box-shadow:0 3px 10px rgba(0,0,0,.1);max-width:600px;margin:0 auto;}
        .form-group{margin-bottom:20px;}
        .form-group label{display:block;margin-bottom:8px;font-weight:500;}
        .form-control{width:100%;padding:12px;border:1px solid #ddd;border-radius:4px;font-size:1rem;}
        .form-control:focus{outline:none;border-color:var(--secondary-color);}
        .btn{background:var(--primary-color);color:white;border:none;padding:12px 20px;border-radius:4px;cursor:pointer;font-size:1rem;transition:.3s;}
        .btn:hover{background:#234a23;}
        .btn-secondary{background:#6c757d;}
        .btn-success{background:var(--success-color);}
        .table-container{background:white;border-radius:8px;padding:25px;box-shadow:0 3px 10px rgba(0,0,0,.1);overflow-x:auto;}
        table{width:100%;border-collapse:collapse;}
        th,td{padding:12px 15px;text-align:left;border-bottom:1px solid #ddd;}
        th{background:#f8f9fa;color:var(--primary-color);font-weight:600;}
        tr:hover{background:#f8f9fa;}
        .action-buttons{display:flex;gap:8px;}
        .btn-sm{padding:6px 12px;font-size:.875rem;}
        .login-container{display:flex;justify-content:center;align-items:center;min-height:100vh;background:var(--light-color);}
        .login-box{background:white;border-radius:8px;padding:40px;box-shadow:0 5px 15px rgba(0,0,0,.1);width:100%;max-width:400px;}
        .login-logo{text-align:center;margin-bottom:30px;}
        .login-logo .logo{margin:0 auto 15px;width:80px;height:80px;font-size:32px;}
        .alert{padding:12px 15px;border-radius:4px;margin-bottom:20px;}
        .alert-danger{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;}
        footer{background:var(--dark-color);color:white;padding:20px 0;text-align:center;}
        @media (max-width:768px){.header-content{flex-direction:column;gap:15px;}nav ul{flex-wrap:wrap;justify-content:center;}.stats-container{grid-template-columns:1fr;}}
    </style>
</head>
<body>

<?php if (!isset($_SESSION['treasurer_id'])): ?>
<!-- ==================== LOGIN PAGE ==================== -->
<div class="login-container">
    <div class="login-box">
        <div class="login-logo">
            <div class="logo">NVB</div>
            <h1>Ndalama Village Bank</h1>
            <p>Treasurer Login</p>
        </div>
        <?php if (isset($loginError)): ?>
            <div class="alert alert-danger"><?= $loginError ?></div>
        <?php endif; ?>
        <form method="post">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <button type="submit" name="login" class="btn" style="width:100%;">Login</button>
        </form>
    </div>
</div>
<?php else: ?>
<!-- ==================== MAIN APP ==================== -->
<div id="app">
    <header>
        <div class="container header-content">
            <div class="logo-container">
                <div class="logo">NVB</div>
                <div class="logo-text">
                    <h1>Ndalama Village Bank</h1>
                    <p>Treasurer Module</p>
                </div>
            </div>
            <nav>
                <ul>
                    <li><a href="#" class="nav-link active" data-page="dashboard">Dashboard</a></li>
                    <li><a href="#" class="nav-link" data-page="members">Members</a></li>
                    <li><a href="#" class="nav-link" data-page="shares">Shares</a></li>
                    <li><a href="#" class="nav-link" data-page="loans">Loans</a></li>
                    <li><a href="#" class="nav-link" data-page="reports">Reports</a></li>
                </ul>
            </nav>
            <div class="user-info">
                <span>Welcome, Treasurer</span>
                <a href="?page=logout" class="logout-btn">Logout</a>
            </div>
        </div>
    </header>

    <main>
        <div class="container">

            <!-- DASHBOARD -->
            <div id="dashboard" class="page active">
                <?php include 'pages/dashboard.php'; ?>
            </div>

            <!-- MEMBERS -->
            <div id="members" class="page">
                <?php include 'pages/members.php'; ?>
            </div>

            <!-- SHARES -->
            <div id="shares" class="page">
                <?php include 'pages/shares.php'; ?>
            </div>

            <!-- LOANS -->
            <div id="loans" class="page">
                <?php include 'pages/loans.php'; ?>
            </div>

            <!-- REPORTS -->
            <div id="reports" class="page">
                <?php include 'pages/reports.php'; ?>
            </div>

        </div>
    </main>

    <footer>
        <div class="container">
            <p>© <?= date('Y') ?> Ndalama Village Bank. All rights reserved.</p>
        </div>
    </footer>
</div>
<?php endif; ?>

<script>
/* -------------------------------------------------
   CLIENT-SIDE LOGIC (same UX, now talks to PHP AJAX)
   ------------------------------------------------- */
const API = (action, data = null, method = 'GET') => {
    const url = `?ajax=${action}` + (data ? '' : '');
    return fetch(url, {
        method: method,
        headers: {'Content-Type':'application/json'},
        body: data ? JSON.stringify(data) : null
    }).then(r=>r.json());
};

/* ---------- NAVIGATION ---------- */
document.querySelectorAll('.nav-link').forEach(l=>{
    l.addEventListener('click', e=>{
        e.preventDefault();
        document.querySelectorAll('.nav-link').forEach(n=>n.classList.remove('active'));
        l.classList.add('active');
        document.querySelectorAll('.page').forEach(p=>p.classList.remove('active'));
        document.getElementById(l.dataset.page).classList.add('active');
        window['load_'+l.dataset.page]();
    });
});

/* ---------- COMMON HELPERS ---------- */
const today = new Date().toISOString().split('T')[0];
const fmt = n=>new Intl.NumberFormat().format(n);

/* ---------- DASHBOARD ---------- */
async function load_dashboard(){
    const [members, shares, loans, activity] = await Promise.all([
        API('members_list'),
        API('shares_list'),
        API('loans_list'),
        API('activity_list')
    ]);
    document.getElementById('total-members').textContent = members.length;
    const totShares = shares.reduce((s,r)=>s+r.amount,0);
    document.getElementById('total-shares').textContent = fmt(totShares);
    const totLoans = loans.reduce((s,r)=>s+r.amount,0);
    document.getElementById('total-loans').textContent = fmt(totLoans);
    const active = loans.filter(l=>l.status==='Active').length;
    document.getElementById('active-loans').textContent = active;

    const list = document.getElementById('activity-list');
    list.innerHTML = '';
    activity.forEach(a=>{
        const li=document.createElement('li');
        li.className='activity-item';
        li.innerHTML=`<span>${a.description}</span><span>${new Date(a.timestamp).toLocaleString()}</span>`;
        list.appendChild(li);
    });
}

/* ---------- MEMBERS ---------- */
async function load_members(){
    const members = await API('members_list');
    // dropdowns
    ['share-member','loan-member'].forEach(id=>{
        const sel=document.getElementById(id);
        sel.innerHTML='<option value="">Select member</option>';
        members.forEach(m=>sel.add(new Option(`${m.name} (${m.id})`,m.id)));
    });
    // table
    const tbody=document.querySelector('#members-table tbody');
    tbody.innerHTML='';
    members.forEach(m=>{
        const tr=document.createElement('tr');
        tr.innerHTML=`
            <td>${m.id}</td><td>${m.name}</td><td>${m.phone||'—'}</td><td>${m.address||'—'}</td><td>${m.join_date}</td>
            <td class="action-buttons">
                <button class="btn btn-sm btn-secondary" onclick="editMember('${m.id}')">Edit</button>
                <button class="btn btn-sm btn-danger" onclick="deleteMember('${m.id}')">Delete</button>
            </td>`;
        tbody.appendChild(tr);
    });
}
async function addMember(){
    const f=document.getElementById('add-member-form');
    const data={name:f['member-name'].value, phone:f['member-phone'].value, address:f['member-address'].value};
    await API('member_add',data,'POST');
    f.reset(); load_members(); load_dashboard(); load_reports();
}
function editMember(id){
    const m = prompt('New name,phone,address (comma separated)', '');
    if(!m) return;
    const [name,phone,address] = m.split(',').map(s=>s.trim());
    API('member_edit', {id,name,phone,address},'POST').then(()=>{load_members();load_reports();});
}
function deleteMember(id){
    if(confirm('Delete member?')) API('member_delete&id='+id).then(()=>{load_members();load_dashboard();load_reports();});
}

/* ---------- SHARES ---------- */
async function load_shares(){
    const shares = await API('shares_list');
    const tbody=document.querySelector('#shares-table tbody');
    tbody.innerHTML='';
    shares.forEach(s=>{
        const tr=document.createElement('tr');
        tr.innerHTML=`<td>${s.id}</td><td>${s.member_name} (${s.member_id})</td><td>${fmt(s.amount)}</td><td>${s.share_date}</td>`;
        tbody.appendChild(tr);
    });
}
async function recordShare(){
    const f=document.getElementById('record-shares-form');
    const data={member_id:f['share-member'].value, amount:f['share-amount'].value, date:f['share-date'].value};
    await API('share_add',data,'POST');
    f.reset(); f['share-date'].value=today;
    load_shares(); load_dashboard(); load_reports();
}

/* ---------- LOANS ---------- */
async function load_loans(){
    const loans = await API('loans_list');
    const tbody=document.querySelector('#loans-table tbody');
    tbody.innerHTML='';
    loans.forEach(l=>{
        const tr=document.createElement('tr');
        tr.innerHTML=`
            <td>${l.id}</td><td>${l.member_name} (${l.member_id})</td><td>${fmt(l.amount)}</td>
            <td>${l.interest_rate}%</td><td>${l.term_months} mo</td><td>${l.issue_date}</td><td>${l.due_date}</td>
            <td>${l.status}</td>
            <td class="action-buttons">
                <button class="btn btn-sm btn-success" onclick="repayLoan('${l.id}')">Repay</button>
                <button class="btn btn-sm btn-secondary" onclick="viewLoan('${l.id}')">Details</button>
            </td>`;
        tbody.appendChild(tr);
    });
}
async function issueLoan(){
    const f=document.getElementById('issue-loan-form');
    const data={
        member_id:f['loan-member'].value,
        amount:f['loan-amount'].value,
        interest:f['loan-interest'].value,
        term:f['loan-term'].value,
        issue_date:f['loan-date'].value
    };
    await API('loan_add',data,'POST');
    f.reset(); f['loan-interest'].value=10; f['loan-term'].value=12; f['loan-date'].value=today;
    load_loans(); load_dashboard(); load_reports();
}
function repayLoan(id){
    const amt=prompt('Repayment amount:');
    if(amt) API('loan_repay', {loan_id:id, amount:parseFloat(amt)},'POST')
           .then(()=>{load_loans();load_dashboard();load_reports();});
}
function viewLoan(id){
    API('loans_list').then(loans=>{
        const l=loans.find(x=>x.id===id);
        const totalDue = l.amount + (l.amount * l.interest_rate/100)*(l.term_months/12);
        const remaining = totalDue - l.repaid_amount;
        alert(`Loan ${id}
Member: ${l.member_name}
Amount: ${l.amount}
Interest: ${l.interest_rate}% (${l.term_months} mo)
Issued: ${l.issue_date} | Due: ${l.due_date}
Status: ${l.status}
Repaid: ${l.repaid_amount||0}
Remaining: ${remaining.toFixed(2)}`);
    });
}

/* ---------- REPORTS ---------- */
async function load_reports(){
    const [members,shares,loans] = await Promise.all([
        API('members_list'), API('shares_list'), API('loans_list')
    ]);
    document.getElementById('report-total-members').textContent = members.length;
    const totS = shares.reduce((a,b)=>a+b.amount,0);
    document.getElementById('report-total-shares').textContent = fmt(totS);
    const totL = loans.reduce((a,b)=>a+b.amount,0);
    document.getElementById('report-total-loans').textContent = fmt(totL);
    const act = loans.filter(l=>l.status==='Active').length;
    document.getElementById('report-active-loans').textContent = act;

    // financial summary
    const interest = loans.reduce((s,l)=>s + (l.amount * l.interest_rate/100)*(l.term_months/12),0);
    const repaid = loans.reduce((s,l)=>s + (l.repaid_amount||0),0);
    document.getElementById('summary-total-shares').textContent = fmt(totS);
    document.getElementById('summary-total-loans').textContent = fmt(totL);
    document.getElementById('summary-total-interest').textContent = fmt(Math.round(interest));
    document.getElementById('summary-total-repayments').textContent = fmt(repaid);
    document.getElementById('summary-net-position').textContent = fmt(totS + repaid - totL);

    // member shares table
    const tbody=document.querySelector('#member-shares-table tbody');
    tbody.innerHTML='';
    members.forEach(m=>{
        const memShares = shares.filter(s=>s.member_id===m.id).reduce((a,b)=>a+b.amount,0);
        const last = shares.filter(s=>s.member_id===m.id).sort((a,b)=>b.share_date.localeCompare(a.share_date))[0];
        const tr=document.createElement('tr');
        tr.innerHTML=`<td>${m.id}</td><td>${m.name}</td><td>${fmt(memShares)}</td><td>${last?last.share_date:'—'}</td>`;
        tbody.appendChild(tr);
    });
}

/* ---------- FORM SUBMITS ---------- */
document.getElementById('add-member-form')?.addEventListener('submit', e=>{e.preventDefault();addMember();});
document.getElementById('record-shares-form')?.addEventListener('submit', e=>{e.preventDefault();recordShare();});
document.getElementById('issue-loan-form')?.addEventListener('submit', e=>{e.preventDefault();issueLoan();});

/* ---------- INITIAL LOAD ---------- */
window.load_dashboard = load_dashboard;
window.load_members = load_members;
window.load_shares = load_shares;
window.load_loans = load_loans;
window.load_reports = load_reports;

// Load first page
<?php if (isset($_GET['page']) && $_GET['page']==='dashboard'): ?>
load_dashboard();
<?php endif; ?>
</script>
</body>
</html>
<?php
/* -------------------------------------------------
   PAGE TEMPLATES (kept in separate PHP files for clarity)
   ------------------------------------------------- */
file_put_contents('pages/dashboard.php', <<<HTML
<div class="page-header"><h2>Dashboard</h2></div>
<div class="stats-container">
    <div class="stat-card"><h3>Total Members</h3><div class="stat-value" id="total-members">0</div></div>
    <div class="stat-card"><h3>Total Shares</h3><div class="stat-value" id="total-shares">0</div></div>
    <div class="stat-card"><h3>Total Loans</h3><div class="stat-value" id="total-loans">0</div></div>
    <div class="stat-card"><h3>Active Loans</h3><div class="stat-value" id="active-loans">0</div></div>
</div>
<div class="recent-activity"><h3>Recent Activity</h3><ul class="activity-list" id="activity-list"></ul></div>
HTML);

file_put_contents('pages/members.php', <<<HTML
<div class="page-header"><h2>Member Management</h2></div>
<div class="form-container">
    <h3>Add New Member</h3>
    <form id="add-member-form">
        <div class="form-group"><label>Full Name</label><input type="text" id="member-name" class="form-control" required></div>
        <div class="form-group"><label>Phone Number</label><input type="tel" id="member-phone" class="form-control"></div>
        <div class="form-group"><label>Address</label><textarea id="member-address" class="form-control" rows="3"></textarea></div>
        <button type="submit" class="btn">Add Member</button>
    </form>
</div>
<div class="table-container" style="margin-top:30px;">
    <h3>All Members</h3>
    <table id="members-table"><thead><tr><th>ID</th><th>Name</th><th>Phone</th><th>Address</th><th>Date Joined</th><th>Actions</th></tr></thead><tbody></tbody></table>
</div>
HTML);

file_put_contents('pages/shares.php', <<<HTML
<div class="page-header"><h2>Share Management</h2></div>
<div class="form-container">
    <h3>Record Share Purchase</h3>
    <form id="record-shares-form">
        <div class="form-group"><label>Select Member</label><select id="share-member" class="form-control" required><option value="">Select member</option></select></div>
        <div class="form-group"><label>Share Amount</label><input type="number" id="share-amount" class="form-control" min="1" required></div>
        <div class="form-group"><label>Date</label><input type="date" id="share-date" class="form-control" value="$today" required></div>
        <button type="submit" class="btn">Record Shares</button>
    </form>
</div>
<div class="table-container" style="margin-top:30px;">
    <h3>Share Transactions</h3>
    <table id="shares-table"><thead><tr><th>ID</th><th>Member</th><th>Amount</th><th>Date</th></tr></thead><tbody></tbody></table>
</div>
HTML);

file_put_contents('pages/loans.php', <<<HTML
<div class="page-header"><h2>Loan Management</h2></div>
<div class="form-container">
    <h3>Issue New Loan</h3>
    <form id="issue-loan-form">
        <div class="form-group"><label>Select Member</label><select id="loan-member" class="form-control" required><option value="">Select member</option></select></div>
        <div class="form-group"><label>Loan Amount</label><input type="number" id="loan-amount" class="form-control" min="1" required></div>
        <div class="form-group"><label>Interest Rate (%)</label><input type="number" id="loan-interest" class="form-control" step="0.5" value="10" required></div>
        <div class="form-group"><label>Loan Term (months)</label><input type="number" id="loan-term" class="form-control" min="1" max="24" value="12" required></div>
        <div class="form-group"><label>Issue Date</label><input type="date" id="loan-date" class="form-control" value="$today" required></div>
        <button type="submit" class="btn">Issue Loan</button>
    </form>
</div>
<div class="table-container" style="margin-top:30px;">
    <h3>Active Loans</h3>
    <table id="loans-table"><thead><tr><th>ID</th><th>Member</th><th>Amount</th><th>Interest</th><th>Term</th><th>Issue</th><th>Due</th><th>Status</th><th>Actions</th></tr></thead><tbody></tbody></table>
</div>
HTML);

file_put_contents('pages/reports.php', <<<HTML
<div class="page-header"><h2>Reports & Summary</h2></div>
<div class="stats-container">
    <div class="stat-card"><h3>Total Members</h3><div class="stat-value" id="report-total-members">0</div></div>
    <div class="stat-card"><h3>Total Shares Value</h3><div class="stat-value" id="report-total-shares">0</div></div>
    <div class="stat-card"><h3>Total Loans Issued</h3><div class="stat-value" id="report-total-loans">0</div></div>
    <div class="stat-card"><h3>Active Loans</h3><div class="stat-value" id="report-active-loans">0</div></div>
</div>
<div class="table-container" style="margin-top:30px;">
    <h3>Financial Summary</h3>
    <table id="financial-summary-table">
        <thead><tr><th>Description</th><th>Amount</th></tr></thead>
        <tbody>
            <tr><td>Total Shares Collected</td><td id="summary-total-shares">0</td></tr>
            <tr><td>Total Loans Issued</td><td id="summary-total-loans">0</td></tr>
            <tr><td>Total Interest Earned</td><td id="summary-total-interest">0</td></tr>
            <tr><td>Total Repayments Received</td><td id="summary-total-repayments">0</td></tr>
            <tr><td>Net Position</td><td id="summary-net-position">0</td></tr>
        </tbody>
    </table>
</div>
<div class="table-container" style="margin-top:30px;">
    <h3>Member Shares Summary</h3>
    <table id="member-shares-table"><thead><tr><th>ID</th><th>Name</th><th>Total Shares</th><th>Last Contribution</th></tr></thead><tbody></tbody></table>
</div>
HTML);
?>