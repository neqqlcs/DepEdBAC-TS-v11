<?php
session_start();
require 'config.php'; // Ensure this file properly connects to your database using PDO

// Check that the user is logged in.
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

// Get the projectID from GET parameters.
$projectID = isset($_GET['projectID']) ? intval($_GET['projectID']) : 0;
if ($projectID <= 0) {
    die("Invalid Project ID");
}

// Define the ordered list of stages. This should be consistent across index.php and edit_project.php
$stagesOrder = [
    'Purchase Request',
    'RFQ 1',
    'RFQ 2',
    'RFQ 3',
    'Abstract of Quotation',
    'Purchase Order',
    'Notice of Award',
    'Notice to Proceed'
];

// Permission Variables - define early for consistent use
$isAdmin = ($_SESSION['admin'] == 1);
$isProjectCreator = false; // Initialize, will be set after fetching project details


// --- Function to fetch project details ---
// This function will be called initially and after any successful update
function fetchProjectDetails($pdo, $projectID) {
    $stmt = $pdo->prepare("SELECT p.*, u.firstname AS creator_firstname, u.lastname AS creator_lastname, o.officename
                            FROM tblproject p
                            LEFT JOIN tbluser u ON p.userID = u.userID
                            LEFT JOIN officeid o ON u.officeID = o.officeID
                            WHERE p.projectID = ?");
    $stmt->execute([$projectID]);
    return $stmt->fetch();
}

// --- Function to fetch project stages ---
// This function will be called initially and after any successful stage update
function fetchProjectStages($pdo, $projectID, $stagesOrder) {
    $stmt2 = $pdo->prepare("SELECT * FROM tblproject_stages
                             WHERE projectID = ?
                             ORDER BY FIELD(stageName, 'Purchase Request','RFQ 1','RFQ 2','RFQ 3','Abstract of Quotation','Purchase Order','Notice of Award','Notice to Proceed')");
    $stmt2->execute([$projectID]);
    $stages = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    // If no stages exist, create records for every stage. (Handles new projects)
    if (empty($stages)) {
        foreach ($stagesOrder as $stageName) {
            $insertCreatedAt = null;
            if ($stageName === 'Purchase Request') {
                $insertCreatedAt = date("Y-m-d H:i:s");
            }
            $stmtInsert = $pdo->prepare("INSERT INTO tblproject_stages (projectID, stageName, office, createdAt) VALUES (?, ?, ?, ?)");
            $stmtInsert->execute([$projectID, $stageName, "", $insertCreatedAt]);
        }
        // Re-fetch stages after creation
        $stmt2->execute([$projectID]);
        $stages = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    }
    return $stages;
}

// --- Initial Data Fetch ---
$project = fetchProjectDetails($pdo, $projectID);
if (!$project) {
    die("Project not found");
}
$isProjectCreator = ($project['userID'] == $_SESSION['userID']); // Now set after project is fetched

$stages = fetchProjectStages($pdo, $projectID, $stagesOrder);

// Map stages by stageName for easy access and find the last submitted stage.
$stagesMap = [];
$noticeToProceedSubmitted = false;
$lastSubmittedStageIndex = -1;

foreach ($stages as $index => $s) {
    $stagesMap[$s['stageName']] = $s;
    if ($s['isSubmitted'] == 1) {
        $stageIndexInOrder = array_search($s['stageName'], $stagesOrder);
        if ($stageIndexInOrder !== false && $stageIndexInOrder > $lastSubmittedStageIndex) {
            $lastSubmittedStageIndex = $stageIndexInOrder;
        }
    }
    if ($s['stageName'] === 'Notice to Proceed' && $s['isSubmitted'] == 1) {
        $noticeToProceedSubmitted = true;
    }
}
$lastSubmittedStageName = ($lastSubmittedStageIndex !== -1) ? $stagesOrder[$lastSubmittedStageIndex] : null;


// Process Project Header update (available ONLY for admins).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_project_header'])) {
    if ($isAdmin) { // Check if current user is an admin
        $prNumber = trim($_POST['prNumber']);
        $projectDetails = trim($_POST['projectDetails']);
        if (empty($prNumber) || empty($projectDetails)) {
            $errorHeader = "PR Number and Project Details are required.";
        } else {
            // Admin updates header, so update editedAt/editedBy
            $stmtUpdate = $pdo->prepare("UPDATE tblproject
                                          SET prNumber = ?, projectDetails = ?, editedAt = CURRENT_TIMESTAMP, editedBy = ?, lastAccessedAt = CURRENT_TIMESTAMP, lastAccessedBy = ?
                                          WHERE projectID = ?");
            $stmtUpdate->execute([$prNumber, $projectDetails, $_SESSION['userID'], $_SESSION['userID'], $projectID]); // Update both
            
            $successHeader = "Project details updated successfully.";
            // Re-fetch project and stages data immediately after update
            $project = fetchProjectDetails($pdo, $projectID);
            $stages = fetchProjectStages($pdo, $projectID, $stagesOrder); // Re-fetch stages too if relevant
        }
    } else {
        $errorHeader = "You do not have permission to update project details."; // Error message for non-admins
    }
}

// Process individual stage submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_stage'])) {
    $stageName = $_POST['stageName'];
    $safeStage = str_replace(' ', '_', $stageName);

    $currentStageDataForPost = $stagesMap[$stageName] ?? null;
    $currentIsSubmittedInDB = ($currentStageDataForPost && $currentStageDataForPost['isSubmitted'] == 1);

    // Retrieve new inputs from datetime-local fields.
    $formCreated = isset($_POST["created_$safeStage"]) && !empty($_POST["created_$safeStage"]) ? $_POST["created_$safeStage"] : null;
    $approvedAt = isset($_POST['approvedAt']) && !empty($_POST['approvedAt']) ? $_POST['approvedAt'] : null;
    $office = isset($_POST["office_$safeStage"]) ? trim($_POST["office_$safeStage"]) : "";
    $remark = isset($_POST['remark']) ? trim($_POST['remark']) : "";

    // Determine if this is a "Submit" or "Unsubmit" action
    $isSubmittedVal = 1; // Default to submit
    if ($isAdmin && $currentIsSubmittedInDB && $stageName === $lastSubmittedStageName) {
        // If admin is unsubmitting, and it's the last submitted stage, set isSubmitted to 0
        $isSubmittedVal = 0;
    }

    // --- Validation Logic ---
    $validationFailed = false;
    if ($isSubmittedVal == 1) { // Only validate on "Submit" action
        // 'Approved', 'Office', and 'Remark' are always required for submission
        if (empty($approvedAt) || empty($office) || empty($remark)) {
            $validationFailed = true;
        }
        // 'Created' is required for admin submission (except PR, which is auto-set)
        if ($isAdmin && $stageName !== 'Purchase Request' && empty($formCreated)) {
            $validationFailed = true;
        }
    }

    if ($validationFailed) {
        $stageError = "All fields (Approved, Office, and Remark) are required for stage '$stageName' to be submitted.";
        if ($isAdmin && $stageName !== 'Purchase Request' && empty($formCreated)) {
            $stageError = "All fields (Created, Approved, Office, and Remark) are required for stage '$stageName' to be submitted.";
        }
    } else {
        // Prepare createdAt for update:
        $currentCreatedAtInDB = $currentStageDataForPost['createdAt'] ?? null;
        $actualCreatedAt = $currentCreatedAtInDB; // Start with the existing value from DB

        if ($isAdmin) {
            // If admin, they can override the value if they provide one
            // BUT NOT for 'Purchase Request' stage (as per new rule)
            if ($stageName !== 'Purchase Request' && !empty($formCreated)) {
                $actualCreatedAt = $formCreated;
            } else if ($isSubmittedVal == 1 && empty($currentCreatedAtInDB) && $stageName !== 'Purchase Request') {
                // If admin submitting and field was empty, auto-set to now (except for PR)
                $actualCreatedAt = date("Y-m-d H:i:s");
            }
            // For 'Purchase Request', createdAt should remain its initial auto-set value or null if not yet set.
            // Admins cannot change it.
        } else {
            // Non-admin: The field is disabled for them.
            // It should be auto-populated if submitting and currently empty.
            if ($isSubmittedVal == 1 && empty($currentCreatedAtInDB)) {
                $actualCreatedAt = date("Y-m-d H:i:s");
            }
        }

        // Convert datetime-local values ("Y-m-d\TH:i") to MySQL datetime ("Y-m-d H:i:s").
        $created_dt = $actualCreatedAt ? date("Y-m-d H:i:s", strtotime($actualCreatedAt)) : null;
        // If unsubmitting, clear approvedAt, office, and remarks
        if ($isSubmittedVal == 0) {
            $approved_dt = null;
            $office = "";
            $remark = "";
        } else {
            $approved_dt = $approvedAt ? date("Y-m-d H:i:s", strtotime($approvedAt)) : null;
        }


        $stmtStageUpdate = $pdo->prepare("UPDATE tblproject_stages
                                           SET createdAt = ?, approvedAt = ?, office = ?, remarks = ?, isSubmitted = ?
                                           WHERE projectID = ? AND stageName = ?");
        $stmtStageUpdate->execute([$created_dt, $approved_dt, $office, $remark, $isSubmittedVal, $projectID, $stageName]);
        $stageSuccess = "Stage '$stageName' updated successfully.";

        // If this is a "Submit" action (isSubmittedVal == 1), auto-update the next stage's createdAt if empty.
        if ($isSubmittedVal == 1) {
            $index = array_search($stageName, $stagesOrder);
            if ($index !== false && $index < count($stagesOrder) - 1) {
                $nextStageName = $stagesOrder[$index + 1];
                // Only update if the next stage's createdAt is currently empty or null
                if (!(isset($stagesMap[$nextStageName]) && !empty($stagesMap[$nextStageName]['createdAt']))) {
                    $now = date("Y-m-d H:i:s");
                    $stmtNext = $pdo->prepare("UPDATE tblproject_stages SET createdAt = ? WHERE projectID = ? AND stageName = ?");
                    $stmtNext->execute([$now, $projectID, $nextStageName]);
                }
            }
        }

        // IMPORTANT FIX: When a stage is submitted by ANY user, update editedAt/editedBy.
        // This ensures 'Last Updated' reflects the actual editor for stage changes.
        // Also update lastAccessed for consistency, though editedBy/editedAt will be prioritized for display
        $pdo->prepare("UPDATE tblproject SET editedAt = CURRENT_TIMESTAMP, editedBy = ?, lastAccessedAt = CURRENT_TIMESTAMP, lastAccessedBy = ? WHERE projectID = ?")
            ->execute([$_SESSION['userID'], $_SESSION['userID'], $projectID]);

        // Re-fetch ALL data immediately after stage update/submission
        $project = fetchProjectDetails($pdo, $projectID); // IMPORTANT: Re-fetch project to get latest editedAt/By
        $stages = fetchProjectStages($pdo, $projectID, $stagesOrder); // Re-fetch stages to get latest status

        // Re-map stages after re-fetching to ensure latest status is used for rendering
        $stagesMap = [];
        $noticeToProceedSubmitted = false;
        $lastSubmittedStageIndex = -1;
        foreach ($stages as $index => $s) {
            $stagesMap[$s['stageName']] = $s;
            if ($s['isSubmitted'] == 1) {
                $stageIndexInOrder = array_search($s['stageName'], $stagesOrder);
                if ($stageIndexInOrder !== false && $stageIndexInOrder > $lastSubmittedStageIndex) {
                    $lastSubmittedStageIndex = $stageIndexInOrder;
                }
            }
            if ($s['stageName'] === 'Notice to Proceed' && $s['isSubmitted'] == 1) {
                $noticeToProceedSubmitted = true;
            }
        }
        $lastSubmittedStageName = ($lastSubmittedStageIndex !== -1) ? $stagesOrder[$lastSubmittedStageIndex] : null;

    }
}

// --- Pre-fetch names for display: Edited By and Last Accessed By ---
// Now, we only need to fetch the editedByName as it will be the primary source for "Last Updated"
$editedByName = "N/A";
if (!empty($project['editedBy'])) { // Use !empty() for more robust check
    $stmtUser = $pdo->prepare("SELECT firstname, lastname FROM tbluser WHERE userID = ?");
    $stmtUser->execute([$project['editedBy']]);
    $user = $stmtUser->fetch();
    if ($user) {
        $editedByName = htmlspecialchars($user['firstname'] . " " . $user['lastname']);
    }
}

// --- Determine the "Next Unsubmitted Stage" for strict sequential access ---
$firstUnsubmittedStageName = null;
foreach ($stagesOrder as $stage) {
    if (isset($stagesMap[$stage]) && $stagesMap[$stage]['isSubmitted'] == 0) {
        $firstUnsubmittedStageName = $stage;
        break; // Found the first unsubmitted stage, stop searching
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Project - DepEd BAC Tracking System</title>
    <link rel="stylesheet" href="assets/css/background.css">
    <link rel="stylesheet" href="assets/css/edit_project.css">

</head>
<body>
    <?php
    // Include your header.php file here.
    // This will insert the header HTML, its inline styles, and its inline JavaScript.
    include 'header.php';
    ?>
    
    <div class="dashboard-container">
        <a href="index.php" class="back-btn">&larr; Back to Dashboard</a>

        <h2>Edit Project</h2>

        <?php
            if (isset($errorHeader)) { echo "<p style='color:red;'>$errorHeader</p>"; }
            if (isset($successHeader)) { echo "<p style='color:green;'>$successHeader</p>"; }
            if (isset($stageError)) { echo "<p style='color:red;'>$stageError</p>"; }
        ?>

        <div class="project-header">
            <label for="prNumber">PR Number:</label>
            <?php if ($isAdmin): ?> <!-- Only Admins can edit here -->
            <form action="edit_project.php?projectID=<?php echo $projectID; ?>" method="post" style="margin-bottom:10px;">
                <input type="text" name="prNumber" id="prNumber" value="<?php echo htmlspecialchars($project['prNumber']); ?>" required>
            <?php else: ?>
                <div class="readonly-field"><?php echo htmlspecialchars($project['prNumber']); ?></div>
            <?php endif; ?>

            <label for="projectDetails">Project Details:</label>
            <?php if ($isAdmin): ?> <!-- Only Admins can edit here -->
                <textarea name="projectDetails" id="projectDetails" required><?php echo htmlspecialchars($project['projectDetails']); ?></textarea>
            <?php else: ?>
                <div class="readonly-field"><?php echo htmlspecialchars($project['projectDetails']); ?></div>
            <?php endif; ?>

            <label>Created By:</label> <!-- Changed label to be more specific -->
            <!-- Display creator's full name and office -->
            <p><?php echo htmlspecialchars($project['creator_firstname'] . " " . $project['creator_lastname'] . " | Office: " . ($project['officename'] ?? 'N/A')); ?></p>

            <label>Date Created:</label>
            <p><?php echo date("m-d-Y h:i A", strtotime($project['createdAt'])); ?></p>

            <label>Last Updated:</label> <!-- Changed label -->
            <p>
                <?php
                $lastUpdatedInfo = "Not Available";
                $lastUpdatedTimestamp = null;
                $lastUpdatedUserFullName = "N/A";

                // Determine the most recent timestamp and corresponding user ID
                $mostRecentTimestamp = null;
                $mostRecentUserId = null;

                $editedTs = !empty($project['editedAt']) ? strtotime($project['editedAt']) : 0;
                $lastAccessedTs = !empty($project['lastAccessedAt']) ? strtotime($project['lastAccessedAt']) : 0;

                // Determine which timestamp is more recent
                if ($editedTs > 0 && ($editedTs >= $lastAccessedTs || $lastAccessedTs === 0)) {
                    $mostRecentTimestamp = $editedTs;
                    $mostRecentUserId = $project['editedBy'];
                } else if ($lastAccessedTs > 0) {
                    $mostRecentTimestamp = $lastAccessedTs;
                    $mostRecentUserId = $project['lastAccessedBy'];
                }

                // Now, fetch the name for the determined user
                if (!empty($mostRecentUserId)) {
                    $stmtUser = $pdo->prepare("SELECT firstname, lastname FROM tbluser WHERE userID = ?");
                    $stmtUser->execute([$mostRecentUserId]);
                    $userWhoUpdated = $stmtUser->fetch();
                    if ($userWhoUpdated) {
                        $lastUpdatedUserFullName = htmlspecialchars($userWhoUpdated['firstname'] . " " . $userWhoUpdated['lastname']);
                    }
                }
                
                if ($lastUpdatedUserFullName !== "N/A" && $mostRecentTimestamp) {
                    $lastUpdatedInfo = $lastUpdatedUserFullName . ", on " . date("m-d-Y h:i A", $mostRecentTimestamp);
                }
                echo $lastUpdatedInfo;
                ?>
            </p>
            <?php if ($isAdmin): ?> <!-- Only Admins can see this button -->
                <button type="submit" name="update_project_header" class="update-project-details-btn">
                    <span>Update Project Details</span>
                </button>
            </form>
            <?php endif; ?>
        </div>

        <h3>Project Stages</h3>
        <?php
            // Display Project Status
            $projectStatusClass = $noticeToProceedSubmitted ? 'finished' : 'in-progress';
            $projectStatusText = $noticeToProceedSubmitted ? 'Status: Finished' : 'Status: In Progress';
            echo '<div class="project-status ' . $projectStatusClass . '">' . $projectStatusText . '</div>';
        ?>
        <?php if (isset($stageSuccess)) { echo "<p style='color:green;'>$stageSuccess</p>"; } ?>
        <div class="table-wrapper">
            <table id="stagesTable">
                <thead>
                    <tr>
                        <th style="width: 15%;">Stage</th>
                        <th style="width: 20%;">Created</th>
                        <th style="width: 20%;">Approved</th>
                        <th style="width: 15%;">Office</th>
                        <th style="width: 15%;">Remark</th>
                        <th style="width: 15%;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // --- Determine the "Next Unsubmitted Stage" for strict sequential access ---
                    // This finds the first stage that is not yet submitted.
                    // Only this stage (and previous submitted ones for viewing) can be interacted with.
                    $firstUnsubmittedStageName = null;
                    foreach ($stagesOrder as $stageNameCheck) {
                        if (isset($stagesMap[$stageNameCheck]) && $stagesMap[$stageNameCheck]['isSubmitted'] == 0) {
                            $firstUnsubmittedStageName = $stageNameCheck;
                            break;
                        }
                    }

                    foreach ($stagesOrder as $index => $stage):
                        $safeStage = str_replace(' ', '_', $stage);
                        $currentStageData = $stagesMap[$stage] ?? null;

                        $currentSubmitted = ($currentStageData && $currentStageData['isSubmitted'] == 1);

                        $value_created = ($currentStageData && !empty($currentStageData['createdAt']))
                                                 ? date("Y-m-d\TH:i", strtotime($currentStageData['createdAt'])) : "";
                        $value_approved = ($currentStageData && !empty($currentStageData['approvedAt']))
                                                  ? date("Y-m-d\TH:i", strtotime($currentStageData['approvedAt'])) : "";
                        $value_office = ($currentStageData && !empty($currentStageData['office']))
                                                ? htmlspecialchars($currentStageData['office']) : "";
                        $value_remark = ($currentStageData && !empty($currentStageData['remarks']))
                                                ? htmlspecialchars($currentStageData['remarks']) : "";

                        // Determine if this stage is the "last processed" one (only relevant for Unsubmit button for admin)
                        $isLastProcessedStage = ($stage === $lastSubmittedStageName);

                        // === NEW STRICT DISABLING LOGIC FOR ALL USERS (INCLUDING ADMIN) ===
                        $disableFields = true;
                        $disableCreatedField = true; // Created field is always disabled by default for non-admins

                        // A stage is editable ONLY if it is the 'firstUnsubmittedStageName'
                        if ($stage === $firstUnsubmittedStageName) {
                            $disableFields = false;
                            // Admins can always edit 'Created' if it's the current unsubmitted stage and not PR (which is auto-set)
                            if ($isAdmin && $stage !== 'Purchase Request') { // Removed empty($value_created) condition
                                $disableCreatedField = false;
                            }
                            // PR's CreatedAt is always disabled if set, even for admin, as it's the project creation timestamp.
                            if ($stage === 'Purchase Request' && !empty($value_created)) {
                                $disableCreatedField = true;
                            }
                        }
                        
                        // If current stage is submitted, all fields are disabled for it
                        if ($currentSubmitted) {
                            $disableFields = true;
                            $disableCreatedField = true;
                        }
                        // If not the first unsubmitted stage and not submitted, also disable
                        if ($stage !== $firstUnsubmittedStageName && !$currentSubmitted) {
                             $disableFields = true;
                             $disableCreatedField = true;
                        }


                    ?>
                    <form action="edit_project.php?projectID=<?php echo $projectID; ?>" method="post" class="stage-form">
                        <tr data-stage="<?php echo htmlspecialchars($stage); ?>">
                            <td><?php echo htmlspecialchars($stage); ?></td>
                            <td>
                                <input type="datetime-local" name="created_<?php echo $safeStage; ?>"
                                        value="<?php echo $value_created; ?>"
                                        <?php if ($disableCreatedField) echo "disabled"; ?>>
                            </td>
                            <td>
                                <input type="datetime-local" name="approvedAt"
                                        value="<?php echo $value_approved; ?>"
                                        <?php if ($disableFields) echo "disabled"; ?>>
                            </td>
                            <td>
                                <input type="text" name="office_<?php echo $safeStage; ?>"
                                        value="<?php echo $value_office; ?>"
                                        <?php if ($disableFields) echo "disabled"; ?>>
                            </td>
                            <td>
                                <input type="text" name="remark"
                                        value="<?php echo $value_remark; ?>"
                                        <?php if ($disableFields) echo "disabled"; ?>>
                            </td>
                            <td>
                                <input type="hidden" name="stageName" value="<?php echo htmlspecialchars($stage); ?>">
                                <div style="margin-top:10px;">
                                    <?php
                                    if ($currentSubmitted) {
                                        // Stage is submitted
                                        if ($isAdmin && $isLastProcessedStage) {
                                            // Admin and this is the last submitted stage: show Unsubmit
                                            echo '<button type="submit" name="submit_stage" class="submit-stage-btn unsubmit-btn">Unsubmit</button>';
                                        } else {
                                            // Stage is submitted, show Finished (disabled)
                                            echo '<button type="button" class="submit-stage-btn" disabled>Finished</button>';
                                        }
                                    } else {
                                        // Stage is not submitted
                                        // Allow submission ONLY if it is the first unsubmitted stage
                                        if ($stage === $firstUnsubmittedStageName) {
                                            echo '<button type="submit" name="submit_stage" class="submit-stage-btn">Submit</button>';
                                        } else {
                                            echo '<button type="button" class="submit-stage-btn" disabled>Pending</button>';
                                        }
                                    }
                                    ?>
                                </div>
                            </td>
                        </tr>
                    </form>
                    <?php endforeach; ?>
                </tbody>
            </table>
    </div>

    <div class="card-view">
    <?php foreach ($stagesOrder as $index => $stage):
        $safeStage = str_replace(' ', '_', $stage);
        $currentStageData = $stagesMap[$stage] ?? null;
        $currentSubmitted = ($currentStageData && $currentStageData['isSubmitted'] == 1);

        $value_created = ($currentStageData && !empty($currentStageData['createdAt'])) ? date("Y-m-d\TH:i", strtotime($currentStageData['createdAt'])) : "";
        $value_approved = ($currentStageData && !empty($currentStageData['approvedAt'])) ? date("Y-m-d\TH:i", strtotime($currentStageData['approvedAt'])) : "";
        $value_office = ($currentStageData && !empty($currentStageData['office'])) ? htmlspecialchars($currentStageData['office']) : "";
        $value_remark = ($currentStageData && !empty($currentStageData['remarks'])) ? htmlspecialchars($currentStageData['remarks']) : "";

        // Determine if this stage is the "last processed" one (only relevant for Unsubmit button for admin)
        $isLastProcessedStage = ($stage === $lastSubmittedStageName);

        // === NEW STRICT DISABLING LOGIC FOR ALL USERS (INCLUDING ADMIN) FOR CARD VIEW ===
        $disableFields = true;
        $disableCreatedField = true; // Created field is always disabled by default for non-admins

        if ($stage === $firstUnsubmittedStageName) {
            $disableFields = false;
            if ($isAdmin && $stage !== 'Purchase Request') { // Removed empty($value_created) condition
                $disableCreatedField = false;
            }
            if ($stage === 'Purchase Request' && !empty($value_created)) {
                $disableCreatedField = true;
            }
        }
        
        if ($currentSubmitted) {
            $disableFields = true;
            $disableCreatedField = true;
        }
        if ($stage !== $firstUnsubmittedStageName && !$currentSubmitted) {
             $disableFields = true;
             $disableCreatedField = true;
        }

    ?>
    <form action="edit_project.php?projectID=<?php echo $projectID; ?>" method="post" class="stage-form">
        <div class="stage-card">
            <h4><?php echo htmlspecialchars($stage); ?></h4>

            <label>Created At:</label>
            <input type="datetime-local" name="created_<?php echo $safeStage; ?>" value="<?php echo $value_created; ?>" <?php if ($disableCreatedField) echo "disabled"; ?>>

            <label>Approved At:</label>
            <input type="datetime-local" name="approvedAt" value="<?php echo $value_approved; ?>" <?php if ($disableFields) echo "disabled"; ?>>

            <label>Office:</label>
            <input type="text" name="office_<?php echo $safeStage; ?>" value="<?php echo $value_office; ?>" <?php if ($disableFields) echo "disabled"; ?>>

            <label>Remark:</label>
            <input type="text" name="remark" value="<?php echo $value_remark; ?>" <?php if ($disableFields) echo "disabled"; ?>>

            <input type="hidden" name="stageName" value="<?php echo htmlspecialchars($stage); ?>">
            <div style="margin-top:10px;">
                <?php
                if ($currentSubmitted) {
                    // Stage is submitted
                    if ($isAdmin && $isLastProcessedStage) {
                        // Admin and this is the last submitted stage: show Unsubmit
                        echo '<button type="submit" name="submit_stage" class="submit-stage-btn unsubmit-btn">Unsubmit</button>';
                    } else {
                        echo '<button type="button" class="submit-stage-btn" disabled>Finished</button>';
                    }
                } else {
                    // Stage is not submitted
                    if ($stage === $firstUnsubmittedStageName) { // Allow submission ONLY if it is the first unsubmitted stage
                        echo '<button type="submit" name="submit_stage" class="submit-stage-btn">Submit</button>';
                    } else {
                        echo '<button type="button" class="submit-stage-btn" disabled>Pending</button>';
                    }
                }
                ?>
            </div>
        </div>
    </form>
    <?php endforeach; ?>
</div>

</body>
</html>
