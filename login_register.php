<?php

require_once __DIR__ . '/includes/app.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}   

if (!csrf_valid()) {
    flash('error', 'Your session expired. Please try again.');
    redirect('index.php');
}

if (isset($_POST['register'])) {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $errors = [];

    if ($username === '' || mb_strlen($username) > 100) {
        $errors[] = 'Username is required (max 100 characters)';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email address is required';
    }
    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters';
    }
    if ($password !== $confirm_password) {
        $errors[] = 'Passwords do not match';
    }

    if (!$errors && db_one('SELECT id FROM users WHERE email = ? OR username = ?', 'ss', [$email, $username])) {
        $errors[] = 'Email or username already exists';
    }

    if (!$errors) {
        // The very first account becomes an active admin so the system can be bootstrapped.
        // Everyone after that registers as inactive staff and waits for an admin.
        $isFirstUser = (int) db_value('SELECT COUNT(*) FROM users') === 0;
        $role = $isFirstUser ? 'admin' : 'staff';
        $active = $isFirstUser ? 1 : 0;

        db_exec(
            'INSERT INTO users (username, email, password, role, is_active) VALUES (?, ?, ?, ?, ?)',
            'ssssi',
            [$username, $email, password_hash($password, PASSWORD_DEFAULT), $role, $active]
        );

        flash('success', $isFirstUser
            ? 'Admin account created. You can sign in now.'
            : 'Registration successful. An admin must activate your account before you can sign in.');
        $_SESSION['auth_form'] = 'login';
        redirect('index.php');
    }

    flash('error', implode('. ', $errors) . '.');
    $_SESSION['auth_form'] = 'register';
    $_SESSION['auth_old'] = ['username' => $username, 'email' => $email];
    redirect('index.php');
}

if (isset($_POST['login'])) {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $_SESSION['auth_form'] = 'login';
    $_SESSION['auth_old'] = ['email' => $email];

    if ($email === '' || $password === '') {
        flash('error', 'Email and password are required.');
        redirect('index.php');
    }

    $user = db_one('SELECT id, username, email, password, role, is_active FROM users WHERE email = ?', 's', [$email]);

    // Same message for unknown email and wrong password so emails can't be probed.
    if (!$user || !password_verify($password, $user['password'])) {
        flash('error', 'Invalid email or password.');
        redirect('index.php');
    }

    if (!$user['is_active']) {
        flash('error', 'Your account is pending admin approval or has been disabled.');
        redirect('index.php');
    }

    // Prevent session fixation
    session_regenerate_id(true);

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['role'] = normalizeUserRole($user['role']);
    unset($_SESSION['auth_form'], $_SESSION['auth_old']);

    redirect('home_page.php');
}

redirect('index.php');
