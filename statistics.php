<?php
session_start();
require 'config.php'; // Ensure your PDO connection is set up correctly

// Redirect if user is not logged in.
// IMPORTANT: For this file, if it's strictly loaded via AJAX into a modal,
// you might *not* want a full page redirect here. Instead, you might want
// to return a specific error message or an empty div if the session is not set.
// However, sticking to your original logic for now.
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

// Define the ordered list of stages for display and processing
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

/* ---------------------------
    Retrieve Projects for Statistics
------------------------------ */
// Fetch all projects along with their 'Notice to Proceed' status
// and their *first* unsubmitted stage. This is crucial for determining current stage.
$sql = "SELECT p.*,
        (SELECT isSubmitted FROM tblproject_stages WHERE projectID = p.projectID AND stageName = 'Notice to Proceed') AS notice_to_proceed_submitted,
        (SELECT s.stageName FROM tblproject_stages s WHERE s.projectID = p.projectID AND s.isSubmitted = 0
            ORDER BY FIELD(s.stageName, 'Purchase Request','RFQ 1','RFQ 2','RFQ 3','Abstract of Quotation','Purchase Order','Notice of Award','Notice to Proceed') ASC
            LIMIT 1) AS first_unsubmitted_stage
        FROM tblproject p";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$projects = $stmt->fetchAll();

// --- Calculate PR Status Counts and Percentages ---
$totalProjects = count($projects);
$finishedProjects = 0;
$ongoingProjects = 0;

// Initialize stage counts
$stageCounts = [];
foreach ($stagesOrder as $stage) {
    $stageCounts[$stage] = 0;
}
$stageCounts['Finished'] = 0;

// This will store the breakdown data for the new nested grid
$ongoingBreakdownData = [];

foreach ($projects as $project) {
    if ($project['notice_to_proceed_submitted'] == 1) {
        $finishedProjects++;
        $stageCounts['Finished']++;
    } else {
        $ongoingProjects++;
        if (!empty($project['first_unsubmitted_stage'])) {
            $stageCounts[$project['first_unsubmitted_stage']]++;
        }
    }
}

// Populate ongoingBreakdownData for the nested grid
foreach ($stagesOrder as $stage) {
    if (!empty($stageCounts[$stage]) && $stageCounts[$stage] > 0) {
        $shortForm = '';
        switch ($stage) {
            case 'Purchase Request': $shortForm = 'PR'; break;
            case 'RFQ 1': $shortForm = 'RFQ1'; break;
            case 'RFQ 2': $shortForm = 'RFQ2'; break;
            case 'RFQ 3': $shortForm = 'RFQ3'; break;
            case 'Abstract of Quotation': $shortForm = 'AoQ'; break;
            case 'Purchase Order': $shortForm = 'PO'; break;
            case 'Notice of Award': $shortForm = 'NoA'; break;
            case 'Notice to Proceed': $shortForm = 'NtP'; break;
            default: $shortForm = $stage; break;
        }
        $ongoingBreakdownData[] = [
            'name' => $shortForm,
            'count' => $stageCounts[$stage]
        ];
    }
}

$percentageDone = ($totalProjects > 0) ? round(($finishedProjects / $totalProjects) * 100, 2) : 0;
$percentageOngoing = ($totalProjects > 0) ? round(($ongoingProjects / $totalProjects) * 100, 2) : 0;

// IMPORTANT: No <html>, <head>, <body> tags here.
// Only the content for the placeholder, and the minimal styles required for that content.
?>
<link rel="stylesheet" href="assets/css/statistics.css">

<div class="stats-content-wrapper">
    <h2>Project Statistics</h2>

    <div class="stats-grid">
        <div class="stat-item">
            <h3>Total Projects</h3>
            <span class="stat-value"><?php echo $totalProjects; ?></span>
        </div>
        <div class="stat-item">
            <h3>Projects Done</h3>
            <span class="stat-value done"><?php echo $finishedProjects; ?></span>
            <span class="stat-percentage">(<?php echo $percentageDone; ?>%)</span>
        </div>
        <div class="stat-item">
            <h3>Projects Ongoing</h3>
            <span class="stat-value ongoing"><?php echo $ongoingProjects; ?></span>
            <span class="stat-percentage">(<?php echo $percentageOngoing; ?>%)</span>
        </div>
        <div class="stat-item breakdown-container">
            <h3>Ongoing Breakdown</h3>
            <?php if ($ongoingProjects > 0): ?>
                <div class="breakdown-mini-grid">
                    <?php foreach ($ongoingBreakdownData as $data): ?>
                        <div class="breakdown-mini-item">
                            <span class="mini-label"><?php echo htmlspecialchars($data['name']); ?></span>
                            <span class="mini-count"><?php echo $data['count']; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <span class="stat-value" style="font-size: 1.1em; color: #f57c00;">All projects finished!</span>
            <?php endif; ?>
        </div>
    </div>

    <h3 style="margin-top: 40px; color: #333;">All Projects by Current Stage (Including Finished)</h3>
    <table class="stage-stats-table">
        <thead>
            <tr>
                <th>Stage Name</th>
                <th>Number of Projects</th>
            </tr>
        </thead>
        <tbody>
            <?php
            foreach ($stagesOrder as $stage) {
                echo "<tr>";
                echo "<td class='stage-name'>" . htmlspecialchars($stage) . "</td>";
                echo "<td>" . ($stageCounts[$stage] ?? 0) . "</td>";
                echo "</tr>";
            }
            echo "<tr>";
            echo "<td class='stage-name'>Finished Projects</td>";
            echo "<td>" . ($stageCounts['Finished'] ?? 0) . "</td>";
            echo "</tr>";
            ?>
        </tbody>
    </table>

    </div>