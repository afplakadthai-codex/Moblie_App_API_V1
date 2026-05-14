<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/mailer.php';

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function old(array $data, string $key, string $default = ''): string
{
    return isset($data[$key]) ? trim((string)$data[$key]) : $default;
}

function generate_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function verify_csrf_token(?string $token): bool
{
    return isset($_SESSION['csrf_token']) && is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
}

if (!empty($_SESSION['user_id'])) {
    header('Location: /member/index.php');
    exit;
}

$errors = [];
$form = [
    'first_name'   => '',
    'last_name'    => '',
    'email'        => '',
    'phone'        => '',
    'whatsapp'     => '',
    'address_line1'=> '',
    'address_line2'=> '',
    'road'         => '',
    'subdistrict'  => '',
    'district'     => '',
    'province'     => '',
    'postal_code'  => '',
    'country'      => 'Thailand',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['first_name']    = trim((string)($_POST['first_name'] ?? ''));
    $form['last_name']     = trim((string)($_POST['last_name'] ?? ''));
    $form['email']         = trim((string)($_POST['email'] ?? ''));
    $form['phone']         = trim((string)($_POST['phone'] ?? ''));
    $form['whatsapp']      = trim((string)($_POST['whatsapp'] ?? ''));
    $form['address_line1'] = trim((string)($_POST['address_line1'] ?? ''));
    $form['address_line2'] = trim((string)($_POST['address_line2'] ?? ''));
    $form['road']          = trim((string)($_POST['road'] ?? ''));
    $form['subdistrict']   = trim((string)($_POST['subdistrict'] ?? ''));
    $form['district']      = trim((string)($_POST['district'] ?? ''));
    $form['province']      = trim((string)($_POST['province'] ?? ''));
    $form['postal_code']   = trim((string)($_POST['postal_code'] ?? ''));
    $form['country']       = trim((string)($_POST['country'] ?? ''));
    $password              = (string)($_POST['password'] ?? '');
    $password_confirm      = (string)($_POST['password_confirm'] ?? '');
    $csrf_token            = (string)($_POST['csrf_token'] ?? '');

    if (!verify_csrf_token($csrf_token)) {
        $errors[] = 'Session security check failed. Please refresh the page and try again.';
    }

    if ($form['first_name'] === '') {
        $errors[] = 'Please enter your first name.';
    } elseif (mb_strlen($form['first_name']) > 100) {
        $errors[] = 'First name is too long.';
    }

    if ($form['last_name'] === '') {
        $errors[] = 'Please enter your last name.';
    } elseif (mb_strlen($form['last_name']) > 100) {
        $errors[] = 'Last name is too long.';
    }

    if ($form['email'] === '') {
        $errors[] = 'Please enter your email address.';
    } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    } elseif (mb_strlen($form['email']) > 190) {
        $errors[] = 'Email is too long.';
    }

    if ($form['phone'] === '') {
        $errors[] = 'Please enter your phone number.';
    } elseif (mb_strlen($form['phone']) > 30) {
        $errors[] = 'Phone number is too long.';
    }

    if ($form['whatsapp'] !== '' && mb_strlen($form['whatsapp']) > 30) {
        $errors[] = 'WhatsApp number is too long.';
    }

    if ($form['address_line1'] === '') {
        $errors[] = 'Please enter your address line 1.';
    } elseif (mb_strlen($form['address_line1']) > 255) {
        $errors[] = 'Address line 1 is too long.';
    }

    if ($form['address_line2'] !== '' && mb_strlen($form['address_line2']) > 255) {
        $errors[] = 'Address line 2 is too long.';
    }

    if ($form['road'] !== '' && mb_strlen($form['road']) > 255) {
        $errors[] = 'Road is too long.';
    }

    if ($form['subdistrict'] === '') {
        $errors[] = 'Please enter your subdistrict.';
    } elseif (mb_strlen($form['subdistrict']) > 150) {
        $errors[] = 'Subdistrict is too long.';
    }

    if ($form['district'] === '') {
        $errors[] = 'Please enter your district.';
    } elseif (mb_strlen($form['district']) > 150) {
        $errors[] = 'District is too long.';
    }

    if ($form['province'] === '') {
        $errors[] = 'Please enter your province.';
    } elseif (mb_strlen($form['province']) > 150) {
        $errors[] = 'Province is too long.';
    }

    if ($form['postal_code'] === '') {
        $errors[] = 'Please enter your postal code.';
    } elseif (mb_strlen($form['postal_code']) > 20) {
        $errors[] = 'Postal code is too long.';
    }

    if ($form['country'] === '') {
        $errors[] = 'Please enter your country.';
    } elseif (mb_strlen($form['country']) > 120) {
        $errors[] = 'Country is too long.';
    }

    if ($password === '') {
        $errors[] = 'Please enter a password.';
    } elseif (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    if ($password_confirm === '') {
        $errors[] = 'Please confirm your password.';
    } elseif ($password !== $password_confirm) {
        $errors[] = 'Password confirmation does not match.';
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $stmt->execute([
                ':email' => $form['email'],
            ]);

            if ($stmt->fetch()) {
                $errors[] = 'This email is already registered.';
            }

            if (empty($errors)) {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare('
                    INSERT INTO users (
                        first_name,
                        last_name,
                        email,
                        phone,
                        whatsapp,
                        address_line1,
                        address_line2,
                        road,
                        subdistrict,
                        district,
                        province,
                        postal_code,
                        country,
                        password_hash,
                        role,
                        account_status,
                        created_at,
                        updated_at
                    ) VALUES (
                        :first_name,
                        :last_name,
                        :email,
                        :phone,
                        :whatsapp,
                        :address_line1,
                        :address_line2,
                        :road,
                        :subdistrict,
                        :district,
                        :province,
                        :postal_code,
                        :country,
                        :password_hash,
                        :role,
                        :account_status,
                        NOW(),
                        NOW()
                    )
                ');

                $stmt->execute([
                    ':first_name'     => $form['first_name'],
                    ':last_name'      => $form['last_name'],
                    ':email'          => $form['email'],
                    ':phone'          => $form['phone'],
                    ':whatsapp'       => $form['whatsapp'] !== '' ? $form['whatsapp'] : null,
                    ':address_line1'  => $form['address_line1'],
                    ':address_line2'  => $form['address_line2'] !== '' ? $form['address_line2'] : null,
                    ':road'           => $form['road'] !== '' ? $form['road'] : null,
                    ':subdistrict'    => $form['subdistrict'],
                    ':district'       => $form['district'],
                    ':province'       => $form['province'],
                    ':postal_code'    => $form['postal_code'],
                    ':country'        => $form['country'],
                    ':password_hash'  => $password_hash,
                    ':role'           => 'user',
                    ':account_status' => 'active',
                ]);

                $newUserId = (int)$pdo->lastInsertId();

                $_SESSION['user_id']            = $newUserId;
                $_SESSION['user_first_name']    = $form['first_name'];
                $_SESSION['user_last_name']     = $form['last_name'];
                $_SESSION['user_name']          = trim($form['first_name'] . ' ' . $form['last_name']);
                $_SESSION['user_email']         = $form['email'];
                $_SESSION['user_role']          = 'user';
                $_SESSION['user_phone']         = $form['phone'];
                $_SESSION['user_whatsapp']      = $form['whatsapp'];
                $_SESSION['user_country']       = $form['country'];
                $_SESSION['user_address_line1'] = $form['address_line1'];

                $mailResult = [
                    'ok' => false,
                    'transport' => 'none',
                    'message' => 'not attempted',
                ];

                try {
                    $mailResult = bv_send_registration_welcome_email(
                        $form['email'],
                        $form['first_name'],
                        $form['last_name']
                    );
                } catch (Throwable $mailError) {
                    error_log('register welcome email exception: ' . $mailError->getMessage());
                }

                if (!empty($mailResult['ok'])) {
                    $_SESSION['member_flash_success'] =
                        'Your account has been created successfully. A welcome email has been sent to your inbox.';
                } else {
                    $_SESSION['member_flash_success'] =
                        'Your account has been created successfully, but the welcome email could not be confirmed as delivered yet.';
                    error_log(
                        'register welcome email not sent'
                        . ' | email=' . $form['email']
                        . ' | transport=' . ($mailResult['transport'] ?? 'unknown')
                        . ' | message=' . ($mailResult['message'] ?? '')
                    );
                }

                header('Location: /member/index.php?welcome=1');
                exit;
            }
        } catch (Throwable $e) {
            $errors[] = 'Unable to create your account right now.';
            error_log('register failed: ' . $e->getMessage());
        }
    }
}

$csrf = generate_csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Create Account | Bettavaro</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <style>
        body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#0f1115;color:#f5f5f5;}
        .wrap{max-width:980px;margin:40px auto;padding:24px;}
        .card{background:#171a21;border:1px solid #2a2f3a;border-radius:16px;padding:28px;box-shadow:0 10px 30px rgba(0,0,0,.25);}
        h1{margin:0 0 10px;font-size:28px;}
        .sub{color:#aab2c0;margin-bottom:24px;}
        .alert{border-radius:12px;padding:14px 16px;margin-bottom:16px;font-size:14px;}
        .alert-error{background:#3a1717;border:1px solid #7a2a2a;color:#ffd3d3;}
        .grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
        .field{margin-bottom:16px;}
        .field.full{grid-column:1 / -1;}
        label{display:block;margin-bottom:8px;font-weight:600;font-size:14px;}
        input{width:100%;box-sizing:border-box;padding:12px 14px;border:1px solid #394150;border-radius:12px;background:#0d1016;color:#fff;font-size:15px;}
        input:focus{outline:none;border-color:#c8a96b;box-shadow:0 0 0 3px rgba(200,169,107,.15);}
        .btn{display:inline-block;width:100%;padding:14px 16px;border:none;border-radius:12px;background:#c8a96b;color:#111;font-size:16px;font-weight:700;cursor:pointer;}
        .btn:hover{opacity:.95;}
        .muted{margin-top:18px;color:#aab2c0;font-size:14px;text-align:center;}
        .muted a{color:#e6c98d;text-decoration:none;}
        ul.error-list{margin:0;padding-left:18px;}
        @media (max-width: 640px){.grid{grid-template-columns:1fr;}}
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>Create Your Account</h1>
        <div class="sub">Register as a buyer/member and complete your contact and address details from the start.</div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <ul class="error-list">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo e($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="/register.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">

            <div class="grid">
                <div class="field">
                    <label for="first_name">First Name</label>
                    <input type="text" id="first_name" name="first_name" value="<?php echo e(old($form, 'first_name')); ?>" maxlength="100" required>
                </div>

                <div class="field">
                    <label for="last_name">Last Name</label>
                    <input type="text" id="last_name" name="last_name" value="<?php echo e(old($form, 'last_name')); ?>" maxlength="100" required>
                </div>

                <div class="field">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" value="<?php echo e(old($form, 'email')); ?>" maxlength="190" required>
                </div>

                <div class="field">
                    <label for="phone">Phone Number</label>
                    <input type="text" id="phone" name="phone" value="<?php echo e(old($form, 'phone')); ?>" maxlength="30" required>
                </div>

                <div class="field">
                    <label for="whatsapp">WhatsApp</label>
                    <input type="text" id="whatsapp" name="whatsapp" value="<?php echo e(old($form, 'whatsapp')); ?>" maxlength="30">
                </div>

                <div class="field">
                    <label for="country">Country</label>
                    <input type="text" id="country" name="country" value="<?php echo e(old($form, 'country', 'Thailand')); ?>" maxlength="120" required>
                </div>

                <div class="field full">
                    <label for="address_line1">Address Line 1</label>
                    <input type="text" id="address_line1" name="address_line1" value="<?php echo e(old($form, 'address_line1')); ?>" maxlength="255" required>
                </div>

                <div class="field full">
                    <label for="address_line2">Address Line 2</label>
                    <input type="text" id="address_line2" name="address_line2" value="<?php echo e(old($form, 'address_line2')); ?>" maxlength="255">
                </div>

                <div class="field full">
                    <label for="road">Road</label>
                    <input type="text" id="road" name="road" value="<?php echo e(old($form, 'road')); ?>" maxlength="255">
                </div>

                <div class="field">
                    <label for="subdistrict">Subdistrict</label>
                    <input type="text" id="subdistrict" name="subdistrict" value="<?php echo e(old($form, 'subdistrict')); ?>" maxlength="150" required>
                </div>

                <div class="field">
                    <label for="district">District</label>
                    <input type="text" id="district" name="district" value="<?php echo e(old($form, 'district')); ?>" maxlength="150" required>
                </div>

                <div class="field">
                    <label for="province">Province</label>
                    <input type="text" id="province" name="province" value="<?php echo e(old($form, 'province')); ?>" maxlength="150" required>
                </div>

                <div class="field">
                    <label for="postal_code">Postal Code</label>
                    <input type="text" id="postal_code" name="postal_code" value="<?php echo e(old($form, 'postal_code')); ?>" maxlength="20" required>
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" minlength="8" required>
                </div>

                <div class="field">
                    <label for="password_confirm">Confirm Password</label>
                    <input type="password" id="password_confirm" name="password_confirm" minlength="8" required>
                </div>

                <div class="field full">
                    <button type="submit" class="btn">Create Account</button>
                </div>
            </div>
        </form>

        <div class="muted">
            Already have an account? <a href="/login.php">Sign in here</a>
        </div>
    </div>
</div>
</body>
</html>