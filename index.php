<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('BASE_URL', 'https://examkits.com/jamb/cbt/solu/');
define('CACHE_DIR', __DIR__ . '/cache');

// Create cache directory if missing
if (!file_exists(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0777, true);
}

// ========== IMPROVED URL CHECK ==========
function checkUrlExists($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        CURLOPT_NOBODY => true, // HEAD request only
        CURLOPT_HEADER => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Also accept 302, 303, 307 redirects as "exists"
    return in_array($httpCode, [200, 302, 303, 307]);
}

// ========== UTILITY: FETCH DATA WITH CACHE ==========
function fetchData($url, $cacheKey = null, $ttl = 3600) {
    $cacheFile = $cacheKey ? CACHE_DIR . '/' . md5($cacheKey) . '.cache' : null;

    // Use cached data if valid
    if ($cacheFile && file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        return file_get_contents($cacheFile);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && !empty($response)) {
        if ($cacheFile) {
            file_put_contents($cacheFile, $response);
        }
        return $response;
    }

    return false;
}

// ========== GET SUBJECTS FROM ACTUAL WEBSITE ==========
function getSubjects() {
    $html = fetchData(BASE_URL, 'subjects_list', 86400);
    
    if (!$html) {
        // Fallback subjects if website is unreachable
        return ['ENG', 'MAT', 'PHY', 'CHE', 'BIO', 'CRS', 'LIT', 'ACC', 'COM', 'GOV', 'GEO', 'ECO'];
    }

    $subjects = [];
    
    // Look for subject links in the HTML
    preg_match_all('/href="\/jamb\/cbt\/solu\/([A-Z]{2,4})\/?/i', $html, $matches);
    
    if (!empty($matches[1])) {
        $subjects = array_unique($matches[1]);
        sort($subjects);
    } else {
        // Alternative pattern
        preg_match_all('/<a[^>]*href="[^"]*\/([A-Z]{2,4})(?:\/|"[^>]*>)/i', $html, $matches);
        if (!empty($matches[1])) {
            $subjects = array_unique($matches[1]);
            sort($subjects);
        } else {
            // Final fallback
            $subjects = ['ENG', 'MAT', 'PHY', 'CHE', 'BIO', 'CRS', 'LIT', 'ACC', 'COM', 'GOV', 'GEO', 'ECO'];
        }
    }
    
    return $subjects;
}

// ========== GET YEARS FOR A SUBJECT ==========
function getYears($subject) {
    $url = BASE_URL . $subject . '/';
    $html = fetchData($url, "years_{$subject}", 86400);
    
    if (!$html) {
        return [];
    }

    $years = [];
    
    // Pattern to match: /jamb/cbt/solu/SUBJECT/SUBJECTYEAR/
    $pattern = '/href="\/jamb\/cbt\/solu\/' . preg_quote($subject, '/') . '\/([A-Z]+\d{4})\/?/i';
    preg_match_all($pattern, $html, $matches);
    
    if (!empty($matches[1])) {
        $years = array_unique($matches[1]);
        rsort($years);
    } else {
        // Alternative pattern
        preg_match_all('/href="[^"]*\/(' . preg_quote($subject, '/') . '\d{4})\/?/i', $html, $matches);
        if (!empty($matches[1])) {
            $years = array_unique($matches[1]);
            rsort($years);
        }
    }
    
    return $years;
}

// ========== CHECK VIDEO AVAILABILITY WITH BETTER ERROR HANDLING ==========
function getQuestionData($subject, $year) {
    $questions = [];
    
    // Clear any existing cache for this subject/year to force fresh check
    for ($i = 1; $i <= 50; $i++) {
        $cacheKey = "video_check_{$subject}_{$year}_q{$i}";
        $cacheFile = CACHE_DIR . '/' . md5($cacheKey) . '.cache';
        if (file_exists($cacheFile)) {
            unlink($cacheFile); // Clear old cache
        }
    }
    
    // Check Q1 to Q50 for direct MP4 files
    for ($i = 1; $i <= 50; $i++) {
        $videoUrl = BASE_URL . $subject . '/' . $year . '/' . $year . 'q' . $i . '.mp4';
        $cacheKey = "video_check_{$subject}_{$year}_q{$i}";
        $cacheFile = CACHE_DIR . '/' . md5($cacheKey) . '.cache';
        
        $exists = false;
        
        // Always check fresh (we cleared cache above)
        $exists = checkUrlExists($videoUrl);
        
        // Cache the result
        file_put_contents($cacheFile, $exists ? '1' : '0');
        
        $questions[] = [
            'questionNumber' => $i,
            'status' => $exists ? 'uploaded' : 'missing',
            'uploadDate' => $exists ? date('Y-m-d H:i:s') : null,
            'url' => $exists ? $videoUrl : null,
            'checkedUrl' => $videoUrl // For debugging
        ];
        
        // Small delay to avoid overwhelming the server
        usleep(50000); // 0.05 second delay
    }
    
    return $questions;
}

// ========== TEST SPECIFIC URL ==========
function testSpecificUrl($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        CURLOPT_HEADER => true,
        CURLOPT_NOBODY => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    return [
        'httpCode' => $httpCode,
        'effectiveUrl' => $effectiveUrl,
        'exists' => in_array($httpCode, [200, 302, 303, 307])
    ];
}

// ========== MAIN EXECUTION ==========
$subjects = getSubjects();
$videoData = [];
$testResult = null;

$selectedSubject = $_GET['subject'] ?? null;
$selectedYear = $_GET['year'] ?? null;
$test = $_GET['test'] ?? null;

// Test a specific URL
if ($test && $selectedSubject && $selectedYear) {
    $testUrl = BASE_URL . $selectedSubject . '/' . $selectedYear . '/' . $selectedYear . 'q1.mp4';
    $testResult = testSpecificUrl($testUrl);
}

// Load data for selected subject and year
if ($selectedSubject && $selectedYear) {
    $videoData[$selectedSubject][$selectedYear] = getQuestionData($selectedSubject, $selectedYear);
}

// Get years for all subjects for the sidebar (with caching)
$allYears = [];
foreach ($subjects as $subject) {
    $allYears[$subject] = getYears($subject);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JAMB CBT Video Audit System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }

        header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }

        header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
        }

        header p {
            font-size: 1.1em;
            opacity: 0.9;
        }

        .main-content {
            display: flex;
            min-height: 600px;
        }

        .sidebar {
            width: 300px;
            background: #f8f9fa;
            border-right: 2px solid #e9ecef;
            overflow-y: auto;
            max-height: calc(100vh - 200px);
        }

        .sidebar-header {
            padding: 20px;
            background: #667eea;
            color: white;
            font-weight: bold;
            text-align: center;
        }

        .subject-item {
            border-bottom: 1px solid #dee2e6;
        }

        .subject-header {
            padding: 15px 20px;
            background: white;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s;
            font-weight: 600;
            color: #495057;
        }

        .subject-header:hover {
            background: #667eea;
            color: white;
        }

        .subject-header.active {
            background: #667eea;
            color: white;
        }

        .arrow {
            transition: transform 0.3s;
            font-size: 0.8em;
        }

        .arrow.rotated {
            transform: rotate(180deg);
        }

        .years-submenu {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
            background: #e9ecef;
        }

        .years-submenu.open {
            max-height: 500px;
            overflow-y: auto;
        }

        .year-item {
            padding: 12px 35px;
            cursor: pointer;
            transition: all 0.2s;
            color: #495057;
            border-bottom: 1px solid #dee2e6;
        }

        .year-item:hover {
            background: #764ba2;
            color: white;
            padding-left: 40px;
        }

        .year-item.selected {
            background: #764ba2;
            color: white;
            font-weight: 600;
        }

        .no-years {
            padding: 15px 35px;
            color: #6c757d;
            font-style: italic;
        }

        .content-area {
            flex: 1;
            padding: 30px;
            overflow-y: auto;
            max-height: calc(100vh - 200px);
        }

        .welcome-screen {
            text-align: center;
            padding: 100px 20px;
            color: #6c757d;
        }

        .welcome-screen h2 {
            font-size: 2em;
            margin-bottom: 20px;
            color: #495057;
        }

        .content-header {
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 3px solid #667eea;
        }

        .content-header h2 {
            color: #667eea;
            font-size: 1.8em;
            margin-bottom: 5px;
        }

        .content-header p {
            color: #6c757d;
        }

        .test-result {
            background: #e9ecef;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #667eea;
        }

        .test-result.success {
            border-left-color: #28a745;
            background: #d4edda;
        }

        .test-result.error {
            border-left-color: #dc3545;
            background: #f8d7da;
        }

        .questions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
        }

        .question-card {
            background: white;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            transition: all 0.3s;
        }

        .question-card.uploaded:hover {
            border-color: #28a745;
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
            transform: translateY(-3px);
        }

        .question-card.missing:hover {
            border-color: #dc3545;
            box-shadow: 0 5px 15px rgba(220, 53, 69, 0.3);
            transform: translateY(-3px);
        }

        .question-number {
            font-size: 1.5em;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 10px;
        }

        .video-status {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9em;
            margin-bottom: 8px;
        }

        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }

        .status-uploaded {
            background: #28a745;
        }

        .status-missing {
            background: #dc3545;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }

        .btn {
            padding: 10px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.9em;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            flex: 1;
        }

        .btn-view {
            background: #28a745;
            color: white;
        }

        .btn-view:hover {
            background: #218838;
        }

        .btn-missing {
            background: #6c757d;
            color: white;
        }

        .btn-missing:hover {
            background: #5a6268;
        }

        .btn-test {
            background: #ffc107;
            color: #212529;
        }

        .btn-test:hover {
            background: #e0a800;
        }

        .video-preview {
            margin-top: 10px;
            font-size: 0.8em;
            color: #495057;
        }

        .stats-bar {
            display: flex;
            gap: 20px;
            margin-bottom: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
        }

        .stat-item {
            flex: 1;
            text-align: center;
        }

        .stat-number {
            font-size: 2em;
            font-weight: bold;
            color: #667eea;
        }

        .stat-label {
            color: #6c757d;
            font-size: 0.9em;
            margin-top: 5px;
        }

        .loading {
            text-align: center;
            padding: 50px;
            color: #6c757d;
        }

        .progress-bar {
            width: 100%;
            height: 10px;
            background: #e9ecef;
            border-radius: 5px;
            margin: 10px 0;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: #28a745;
            transition: width 0.3s;
        }

        .refresh-btn {
            background: #28a745;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            margin-top: 20px;
        }

        .refresh-btn:hover {
            background: #218838;
        }

        @media (max-width: 768px) {
            .main-content {
                flex-direction: column;
            }

            .sidebar {
                width: 100%;
                max-height: 300px;
            }

            .questions-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>📚 JAMB CBT Video Audit System</h1>
            <p>Monitor and manage educational videos across all subjects and years</p>
        </header>

        <div class="main-content">
            <div class="sidebar">
                <div class="sidebar-header">
                    Subjects (<?php echo count($subjects); ?> found)
                </div>
                <div id="subjectsList">
                    <?php foreach ($subjects as $subject): ?>
                        <div class="subject-item">
                            <div class="subject-header" onclick="toggleSubject('<?php echo $subject; ?>')">
                                <span><?php echo $subject; ?></span>
                                <span class="arrow" id="arrow-<?php echo $subject; ?>">▼</span>
                            </div>
                            <div class="years-submenu" id="submenu-<?php echo $subject; ?>">
                                <?php
                                $years = $allYears[$subject] ?? [];
                                if (empty($years)): ?>
                                    <div class="no-years">No years available</div>
                                <?php else: ?>
                                    <?php foreach ($years as $year): ?>
                                        <div class="year-item" 
                                             onclick="selectYear('<?php echo $subject; ?>', '<?php echo $year; ?>')"
                                             <?php if ($selectedSubject == $subject && $selectedYear == $year): ?>class="selected"<?php endif; ?>>
                                            <?php echo $year; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="content-area">
                <div id="contentDisplay">
                    <?php if ($selectedSubject && $selectedYear): ?>
                        
                        <?php if ($testResult): ?>
                        <div class="test-result <?php echo $testResult['exists'] ? 'success' : 'error'; ?>">
                            <h4>🔍 Test Result for Q1:</h4>
                            <p><strong>URL:</strong> <?php echo $testResult['effectiveUrl']; ?></p>
                            <p><strong>HTTP Status:</strong> <?php echo $testResult['httpCode']; ?></p>
                            <p><strong>Exists:</strong> <?php echo $testResult['exists'] ? '✅ YES' : '❌ NO'; ?></p>
                        </div>
                        <?php endif; ?>
                        
                        <?php
                        $questions = $videoData[$selectedSubject][$selectedYear] ?? [];
                        $uploaded = 0;
                        $missing = 0;
                        
                        foreach ($questions as $q) {
                            if ($q['status'] === 'uploaded') {
                                $uploaded++;
                            } else {
                                $missing++;
                            }
                        }
                        
                        $percentage = $uploaded > 0 ? ($uploaded / 50) * 100 : 0;
                        ?>
                        <div class="content-header">
                            <h2><?php echo $selectedSubject; ?> - <?php echo $selectedYear; ?></h2>
                            <p>Video Availability Report - Direct MP4 Files</p>
                            <div style="margin-top: 10px;">
                                <a href="?subject=<?php echo $selectedSubject; ?>&year=<?php echo $selectedYear; ?>&test=1" class="btn btn-test">
                                    🧪 Test Q1 URL
                                </a>
                                <a href="?subject=<?php echo $selectedSubject; ?>&year=<?php echo $selectedYear; ?>" class="btn" style="background: #667eea; color: white;">
                                    🔄 Refresh Check
                                </a>
                            </div>
                        </div>
                        
                        <div class="stats-bar">
                            <div class="stat-item">
                                <div class="stat-number"><?php echo $uploaded; ?></div>
                                <div class="stat-label">Available Videos</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?php echo $missing; ?></div>
                                <div class="stat-label">Missing Videos</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?php echo number_format($percentage, 0); ?>%</div>
                                <div class="stat-label">Completion Rate</div>
                            </div>
                        </div>

                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $percentage; ?>%"></div>
                        </div>

                        <div class="questions-grid">
                            <?php foreach ($questions as $q): ?>
                                <div class="question-card <?php echo $q['status']; ?>">
                                    <div class="question-number">Question <?php echo $q['questionNumber']; ?></div>
                                    <div class="video-status">
                                        <span class="status-dot status-<?php echo $q['status']; ?>"></span>
                                        <span><?php echo ucfirst($q['status']); ?></span>
                                    </div>
                                    <?php if ($q['uploadDate']): ?>
                                        <div style="font-size: 0.85em; color: #6c757d; margin-bottom: 10px;">
                                            Checked: <?php echo $q['uploadDate']; ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="action-buttons">
                                        <?php if ($q['status'] === 'uploaded' && $q['url']): ?>
                                            <a href="<?php echo $q['url']; ?>" target="_blank" class="btn btn-view">
                                                ▶ Play Video
                                            </a>
                                        <?php else: ?>
                                            <button class="btn btn-missing" disabled>
                                                ❌ Video Missing
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($q['status'] === 'uploaded' && $q['url']): ?>
                                        <div class="video-preview">
                                            <small>URL: <?php echo basename($q['url']); ?></small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="welcome-screen">
                            <h2>Welcome to JAMB CBT Video Audit</h2>
                            <p>Select a subject and year from the sidebar to check video availability</p>
                            <div style="margin-top: 30px; color: #667eea;">
                                <strong><?php echo count($subjects); ?> subjects loaded</strong>
                            </div>
                            <button class="refresh-btn" onclick="location.reload()">
                                🔄 Refresh Data
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Auto-open the selected subject on page load
        <?php if ($selectedSubject): ?>
            document.addEventListener('DOMContentLoaded', function() {
                toggleSubject('<?php echo $selectedSubject; ?>');
            });
        <?php endif; ?>

        function toggleSubject(subject) {
            const submenu = document.getElementById(`submenu-${subject}`);
            const arrow = document.getElementById(`arrow-${subject}`);
            const header = arrow.parentElement;

            // Close other open menus
            document.querySelectorAll('.years-submenu').forEach(menu => {
                if (menu !== submenu && menu.classList.contains('open')) {
                    menu.classList.remove('open');
                    const otherArrow = menu.parentElement.querySelector('.arrow');
                    const otherHeader = menu.parentElement.querySelector('.subject-header');
                    if (otherArrow) otherArrow.classList.remove('rotated');
                    if (otherHeader) otherHeader.classList.remove('active');
                }
            });

            // Toggle current menu
            submenu.classList.toggle('open');
            arrow.classList.toggle('rotated');
            header.classList.toggle('active');
        }

        function selectYear(subject, year) {
            // Show loading state
            const contentDisplay = document.getElementById('contentDisplay');
            contentDisplay.innerHTML = `
                <div class="loading">
                    <h3>Checking videos for ${subject} - ${year}</h3>
                    <p>Scanning for MP4 files (this may take a few seconds)...</p>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: 0%"></div>
                    </div>
                </div>
            `;
            
            // Redirect to load the selected year
            window.location.href = `?subject=${subject}&year=${year}`;
        }
    </script>
</body>
</html>