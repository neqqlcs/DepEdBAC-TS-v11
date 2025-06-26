<?php
session_start();
require 'config.php'; // Ensure your PDO connection is set up correctly

// User must be logged in
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

$userID = $_SESSION['userID'];
$error = "";
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $oldPassword = trim($_POST['old_password'] ?? '');
    $newPassword = trim($_POST['new_password'] ?? '');
    $confirmNewPassword = trim($_POST['confirm_new_password'] ?? '');

    // Fetch current user info to get the stored password
    $stmtUser = $pdo->prepare("SELECT password FROM tbluser WHERE userID = ?");
    $stmtUser->execute([$userID]);
    $user = $stmtUser->fetch();

    if (!$user) {
        $error = "User not found.";
    } elseif (empty($oldPassword) || empty($newPassword) || empty($confirmNewPassword)) {
        $error = "All password fields are required.";
    } elseif ($oldPassword !== $user['password']) { // DIRECT COMPARISON, ASSUMING PLAIN TEXT PASSWORD IN DB
        $error = "Old password does not match.";
    } elseif ($newPassword !== $confirmNewPassword) {
        $error = "New password and confirm new password do not match.";
    } elseif (empty($newPassword)) { // New password cannot be empty
        $error = "New password cannot be empty.";
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE tbluser SET password = ? WHERE userID = ?");
            $stmt->execute([$newPassword, $userID]); // Update with the new password
            $success = true;
        } catch (PDOException $e) {
            $error = "Error updating password: " . $e->getMessage();
        }
    }
}

// Fetch current user info for display (even if not changing password)
$stmt = $pdo->prepare("SELECT u.*, o.officename FROM tbluser u LEFT JOIN officeid o ON u.officeID = o.officeID WHERE u.userID = ?");
$stmt->execute([$userID]);
$user = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Edit Account - DepEd BAC Tracking System</title>
  <link rel="stylesheet" href="assets/css/edit_account.css" />
  <link rel="stylesheet" href="assets/css/background.css" />

</head>
<body>

    <?php
    include 'header.php';
    ?>


  <div class="modal">
    <div class="modal-content">
      <span class="close" onclick="window.location.href='index.php'">&times;</span>
      <h2>Edit Account</h2>

      <?php if ($error): ?>
        <p style="color:red;"><?php echo htmlspecialchars($error); ?></p>
      <?php endif; ?>

      <?php if ($success): ?>
        <h3>Password Updated Successfully!</h3>
        <button onclick="window.location.href='index.php'">Return to Dashboard</button>
      <?php else: ?>
      <form method="post">
        <label>First Name</label>
        <input type="text" value="<?= htmlspecialchars($user['firstname']) ?>" disabled />

        <label>Middle Name</label>
        <input type="text" value="<?= htmlspecialchars($user['middlename']) ?>" disabled />

        <label>Last Name</label>
        <input type="text" value="<?= htmlspecialchars($user['lastname']) ?>" disabled />

        <label>Position</label>
        <input type="text" value="<?= htmlspecialchars($user['position']) ?>" disabled />

        <label>Username</label>
        <input type="text" value="<?= htmlspecialchars($user['username']) ?>" disabled />

        <label>Office</label>
        <input type="text" value="<?= htmlspecialchars($user['officename']) ?>" disabled />

        <label>Old Password*</label>
        <input type="password" name="old_password" required />

        <label>New Password*</label>
        <input type="password" name="new_password" required />

        <label>Confirm New Password*</label>
        <input type="password" name="confirm_new_password" required />

        <button type="submit">Update Password</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>