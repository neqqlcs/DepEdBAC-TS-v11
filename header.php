<?php
// header.php

// Start the session if it hasn't been started yet.
// This is crucial if your header relies on $_SESSION variables.
// It's safe to run this multiple times; PHP will only start it once.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// IMPORTANT: Do NOT include <html>, <head>, or <body> tags here.
// This file will be INCLUDED within the <body> section of your main pages.
// Only put the HTML for the header itself, plus its inline styles and scripts.

// Define $isLoginPage if not already set (e.g., when included in pages that aren't login)
if (!isset($isLoginPage)) {
    $isLoginPage = false;
}
// Define $showTitleRight if not already set
if (!isset($showTitleRight)) {
    $showTitleRight = false;
}
?>
<div class="header">
    <a href="index.php" class="header-link-wrapper">
        <img src="assets/images/DEPED-LAOAG_SEAL_Glow.png" alt="DepEd Logo" class="header-logo">
        <!-- This div will now handle both left and right titles -->
        <div class="header-text">
            <div class="title-left">
                SCHOOLS DIVISION OF LAOAG CITY<br>DEPARTMENT OF EDUCATION
            </div>
            <?php if ($showTitleRight): ?>
                <div class="title-right">
                    Bids and Awards <br> Committee Tracking System
                </div>
            <?php endif; ?>
        </div>
    </a>
    
    <?php if (!$isLoginPage): ?>
        <div class="user-menu">
            <span class="user-name"><?php echo htmlspecialchars($_SESSION['username'] ?? 'Guest'); ?></span>
            <div class="dropdown" id="profileDropdown">
                <img src="https://cdn-icons-png.flaticon.com/512/149/149071.png" alt="User Icon" class="user-icon" id="profileIcon">
                <span id="dropdownArrow" style="cursor:pointer; margin-left:4px;"></span> <!-- Arrow character removed -->
                <div class="dropdown-content">
                    <?php if (isset($_SESSION['admin']) && $_SESSION['admin'] == 1): ?>
                        <a href="create_account.php">Create Account</a>
                        <a href="manage_accounts.php">Manage Accounts</a>
                    <?php else: ?>
                        <a href="edit_account.php">Change Password</a>
                    <?php endif; ?>
                    <a href="logout.php" id="logoutBtn">Log out</a>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
    /* Header styling (moved from index to header.php) */
    .header {
        background-color: #c62828;
        color: white;
        padding: 10px 20px; /* Base padding */
        display: flex;
        align-items: center;
        justify-content: space-between;
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        z-index: 10;
        padding-left: 1vw; /* Example responsive horizontal padding */
        padding-right: 1vw; /* Example responsive horizontal padding */
        box-sizing: border-box;
    }

    /* Styles for the link wrapper containing logo and titles */
    .header-link-wrapper {
        display: flex;
        align-items: center;
        text-decoration: none;
        color: inherit;
        flex-grow: 1; /* Allow it to take available space */
        justify-content: flex-start; /* Default alignment */
    }

    .header-logo {
        width: 50px;
        height: auto;
        margin-right: 10px; /* Space between logo and text */
    }
    
    /* This is the container for both left and right titles */
    .header-text {
        display: flex; /* Make it a flex container */
        align-items: center;
        flex-grow: 1; /* Allow it to take remaining space */
        justify-content: space-between; /* Pushes title-left and title-right apart */
    }

    .title-left {
        font-size: 14px;
        line-height: 1.2;
        text-align: left; /* Ensure left alignment */
        white-space: nowrap; /* Prevent wrapping for the left title */
    }
    
    .title-right {
        font-size: 14px;
        line-height: 1.2;
        text-align: right; /* Align the text itself to the right */
        white-space: nowrap; /* Prevent wrapping for the right title */
        margin-left: auto; /* Pushes it to the far right within header-text */
    }

    .user-menu {
        display: flex;
        align-items: center;
        position: relative;
        margin-left: 20px; /* Space between titles and user menu */
    }
    .user-name {
        margin-right: 10px;
    }
    .user-icon {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        cursor: pointer;
    }
    .dropdown-content {
        display: none;
        position: absolute;
        right: 0;
        background-color: #fff;
        box-shadow: 0 8px 16px rgba(0,0,0,0.15);
        border-radius: 6px;
        z-index: 100;
        top: 40px; /* Adjust based on your user-icon/dropdown-arrow height */
    }
    .dropdown-content a {
        display: block;
        padding: 8px 20px;
        text-decoration: none;
        color: #333;
        white-space: nowrap;
    }
    .dropdown.open .dropdown-content {
        display: block;
    }

    /* Media query for smaller screens (e.g., up to 768px) */
    @media (max-width: 768px) {
        .header {
            flex-direction: row; /* Keep content on one line */
            justify-content: space-between; /* Space out the logo/title and user menu */
            align-items: center; /* Vertically align items */
            padding: 10px 15px; /* Adjust padding for smaller screens */
        }
        .header-link-wrapper {
            flex-grow: 1; /* Allow this section to grow */
            justify-content: flex-start; /* Keep logo and left title to the left */
            margin-bottom: 0; /* Remove vertical margin */
        }
        .header-logo {
             margin-right: 5px; /* Less space on small screens */
             width: 45px; /* Slightly smaller logo */
        }
        .header-text {
            /* On smaller screens, allow header-text to condense */
            flex-direction: row; /* Keep left and right titles on same line */
            align-items: center;
            flex-grow: 1; /* Allow it to take up space */
            justify-content: flex-start; /* Default to left alignment to allow max-width on title-left */
            margin-left: 0;
        }
        .title-left {
            font-size: 13px; /* Slightly smaller font for better fit */
            text-align: left;
            white-space: normal; /* Allow text to wrap for smaller screens */
            max-width: 180px; /* Max width for the left title to prevent overflow */
            overflow: hidden; /* Hide overflow content */
            text-overflow: ellipsis; /* Add ellipsis for hidden content */
        }
        .title-right {
            font-size: 13px; /* Smaller font */
            text-align: right;
            white-space: nowrap; /* Keep this on one line if space allows */
            margin-left: auto; /* Push to the right */
        }
        .user-menu {
            flex-shrink: 0; /* Prevent user menu from shrinking too much */
            margin-left: 10px; /* Keep some space between title and user menu */
            width: auto; /* Let it take its natural width */
            justify-content: flex-end; /* Keep user-menu elements to the right */
            margin-top: 0;
        }
        .dropdown-content {
            top: 35px; /* Adjust dropdown position for smaller user-menu height */
        }
        .user-name {
            font-size: 13px; /* Adjust font size for user name */
            margin-right: 5px;
        }
        .user-icon {
            width: 28px;
            height: 28px;
        }
    }

    /* Media query for very small mobile devices (e.g., up to 480px) */
    @media (max-width: 480px) {
        .header {
            padding: 8px 10px; /* Even less padding */
        }
        .header-logo {
            width: 35px; /* Even smaller logo */
        }
        .title-left {
            font-size: 11px; /* Further decrease font size */
            max-width: 150px; /* Reduce max-width for very small screens */
            line-height: 1.1;
        }
        .title-right {
            font-size: 11px; /* Further decrease font size */
            max-width: 150px; /* Reduce max-width for very small screens */
        }
        .user-name {
            font-size: 11px; /* Further decrease font size for user name */
        }
        .user-icon {
            width: 24px;
            height: 24px;
        }
    }
</style>

<?php if (!$isLoginPage): ?>
<script>
    // Profile dropdown toggle logic (moved from index to header.php)
    const profileIcon = document.getElementById('profileIcon');
    const dropdownArrow = document.getElementById('dropdownArrow');
    const profileDropdown = document.getElementById('profileDropdown');

    function toggleDropdown(event) {
        event.stopPropagation(); // Prevent document click from immediately closing it
        if (profileDropdown) {
            profileDropdown.classList.toggle('open');
        }
    }
    // Check if elements exist before adding event listeners to prevent errors
    if (profileIcon) {
        profileIcon.addEventListener('click', toggleDropdown);
    }
    if (dropdownArrow) { // This will still exist but without an arrow character
        dropdownArrow.addEventListener('click', toggleDropdown);
    }

    document.addEventListener('click', function(event) {
        // Close dropdown if click outside
        if (profileDropdown && !profileDropdown.contains(event.target) && profileDropdown.classList.contains('open')) {
            profileDropdown.classList.remove('open');
        }
    });
</script>
<?php endif; ?>