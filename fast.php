<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('BASE_URL', 'https://examkits.com/jamb/cbt/solu/');
define('CACHE_DIR', __DIR__ . '/cache');

// Create cache directory if missing
if (!file_exists(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0777, true);
}

// ========== OPTIMIZED URL CHECK WITH MULTI-CURL ==========
function checkMultipleUrls($urls) {
    $multiHandle = curl_multi_init();
    $handles = [];
    $results = [];
    
    foreach ($urls as $i => $url) {
        $handles[$i] = curl_init($url);
        curl_setopt_array($handles[$i], [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_NOBODY => true
        ]);
        curl_multi_add_handle($multiHandle, $handles[$i]);
    }
    
    // Execute all queries simultaneously
    do {
        $status = curl_multi_exec($multiHandle, $active);
        if ($active) {
            curl_multi_select($multiHandle);
        }
    } while ($active && $status == CURLM_OK);
    
    // Get results
    foreach ($handles as $i => $handle) {
        $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $results[$i] = $httpCode === 200;
        curl_multi_remove_handle($multiHandle, $handle);
        curl_close($handle);
    }
    
    curl_multi_close($multiHandle);
    return $results;
}

// ========== OPTIMIZED CACHING ==========
function getCachedData($key, $ttl = 3600) {
    $cacheFile = CACHE_DIR . '/' . md5($key) . '.cache';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        return file_get_contents($cacheFile);
    }
    return false;
}

function setCachedData($key, $data) {
    $cacheFile = CACHE_DIR . '/' . md5($key) . '.cache';
    file_put_contents($cacheFile, $data);
}

// ========== OPTIMIZED DATA FETCHING ==========
function fetchData($url, $cacheKey = null, $ttl = 3600) {
    if ($cacheKey && ($cached = getCachedData($cacheKey, $ttl))) {
        return $cached;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && !empty($response)) {
        if ($cacheKey) {
            setCachedData($cacheKey, $response);
        }
        return $response;
    }

    return false;
}

// ========== OPTIMIZED SUBJECTS FETCH ==========
function getSubjects() {
    $html = fetchData(BASE_URL, 'subjects_list', 86400);
    
    if (!$html) {
        return ['ENG', 'MAT', 'PHY', 'CHE', 'BIO', 'CRS', 'LIT', 'ACC', 'COM', 'GOV', 'GEO', 'ECO'];
    }

    preg_match_all('/href="\/jamb\/cbt\/solu\/([A-Z]{2,4})\/?/i', $html, $matches);
    
    if (!empty($matches[1])) {
        $subjects = array_unique($matches[1]);
        sort($subjects);
        return $subjects;
    }
    
    return ['ENG', 'MAT', 'PHY', 'CHE', 'BIO', 'CRS', 'LIT', 'ACC', 'COM', 'GOV', 'GEO', 'ECO'];
}

// ========== OPTIMIZED YEARS FETCH ==========
function getYears($subject) {
    $url = BASE_URL . $subject . '/';
    $html = fetchData($url, "years_{$subject}", 86400);
    
    if (!$html) {
        return [];
    }

    $pattern = '/href="\/jamb\/cbt\/solu\/' . preg_quote($subject, '/') . '\/([A-Z]+\d{4})\/?/i';
    preg_match_all($pattern, $html, $matches);
    
    if (!empty($matches[1])) {
        $years = array_unique($matches[1]);
        rsort($years);
        return $years;
    }
    
    return [];
}

// ========== ULTRA-FAST VIDEO CHECK WITH BATCH PROCESSING ==========
function getQuestionData($subject, $year) {
    $questions = [];
    $urls = [];
    
    // Prepare all URLs for batch checking
    for ($i = 1; $i <= 50; $i++) {
        $urls[$i] = BASE_URL . $subject . '/' . $year . '/' . $year . 'q' . $i . '.mp4';
    }
    
    // Check cached results first
    $cacheKey = "batch_video_{$subject}_{$year}";
    $cachedResults = getCachedData($cacheKey, 86400);
    
    if ($cachedResults !== false) {
        $cachedArray = json_decode($cachedResults, true);
        if (is_array($cachedArray)) {
            return $cachedArray;
        }
    }
    
    // Batch check all URLs simultaneously using multi-curl
    $results = checkMultipleUrls($urls);
    
    // Prepare questions array
    foreach ($urls as $i => $url) {
        $questions[] = [
            'questionNumber' => $i,
            'status' => $results[$i] ? 'uploaded' : 'missing'
        ];
    }
    
    // Cache the entire batch result
    setCachedData($cacheKey, json_encode($questions));
    
    return $questions;
}

// ========== OPTIMIZED MAIN EXECUTION ==========
$startTime = microtime(true);

// ALWAYS load subjects and years for sidebar
$subjects = getSubjects();
$allYears = [];

// Load years for all subjects (this happens on every page load)
foreach ($subjects as $subject) {
    $allYears[$subject] = getYears($subject);
}

$selectedSubject = $_GET['subject'] ?? null;
$selectedYear = $_GET['year'] ?? null;

// Only load video data if a subject and year are selected
if ($selectedSubject && $selectedYear) {
    $videoData = getQuestionData($selectedSubject, $selectedYear);
}

$loadTime = round((microtime(true) - $startTime) * 1000, 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JAMB CBT Video Audit System</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; background: white; border-radius: 15px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); overflow: hidden; }
        header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; }
        header h1 { font-size: 2.5em; margin-bottom: 10px; }
        header p { font-size: 1.1em; opacity: 0.9; }
        .main-content { display: flex; min-height: 600px; }
        .sidebar { width: 300px; background: #f8f9fa; border-right: 2px solid #e9ecef; overflow-y: auto; max-height: calc(100vh - 200px); }
        .sidebar-header { padding: 20px; background: #667eea; color: white; font-weight: bold; text-align: center; }
        .subject-item { border-bottom: 1px solid #dee2e6; }
        .subject-header { padding: 15px 20px; background: white; cursor: pointer; display: flex; justify-content: space-between; align-items: center; transition: all 0.3s; font-weight: 600; color: #495057; }
        .subject-header:hover, .subject-header.active { background: #667eea; color: white; }
        .arrow { transition: transform 0.3s; font-size: 0.8em; }
        .arrow.rotated { transform: rotate(180deg); }
        .years-submenu { max-height: 0; overflow: hidden; transition: max-height 0.3s ease; background: #e9ecef; }
        .years-submenu.open { max-height: 500px; overflow-y: auto; }
        .year-item { padding: 12px 35px; cursor: pointer; transition: all 0.2s; color: #495057; border-bottom: 1px solid #dee2e6; }
        .year-item:hover, .year-item.selected { background: #764ba2; color: white; padding-left: 40px; }
        .no-years { padding: 15px 35px; color: #6c757d; font-style: italic; }
        .content-area { flex: 1; padding: 30px; overflow-y: auto; max-height: calc(100vh - 200px); }
        .welcome-screen { text-align: center; padding: 100px 20px; color: #6c757d; }
        .welcome-screen h2 { font-size: 2em; margin-bottom: 20px; color: #495057; }
        .content-header { margin-bottom: 30px; padding-bottom: 15px; border-bottom: 3px solid #667eea; }
        .content-header h2 { color: #667eea; font-size: 1.8em; margin-bottom: 5px; }
        .content-header p { color: #6c757d; }
        .questions-overview { display: grid; grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); gap: 10px; margin-bottom: 30px; }
        .question-badge { padding: 15px 5px; text-align: center; border-radius: 8px; font-weight: bold; font-size: 0.9em; transition: transform 0.2s; }
        .question-badge.uploaded { background: #d4edda; color: #155724; border: 2px solid #c3e6cb; }
        .question-badge.missing { background: #f8d7da; color: #721c24; border: 2px solid #f5c6cb; }
        .question-badge .number { font-size: 1em; display: block; }
        .question-badge .status { font-size: 0.8em; display: block; margin-top: 5px; }
        .stats-bar { display: flex; gap: 20px; margin-bottom: 30px; padding: 20px; background: #f8f9fa; border-radius: 10px; }
        .stat-item { flex: 1; text-align: center; }
        .stat-number { font-size: 2.5em; font-weight: bold; color: #667eea; }
        .stat-label { color: #6c757d; font-size: 0.9em; margin-top: 5px; }
        .progress-bar { width: 100%; height: 15px; background: #e9ecef; border-radius: 10px; margin: 20px 0; overflow: hidden; }
        .progress-fill { height: 100%; background: #28a745; transition: width 0.3s; }
        .loading { text-align: center; padding: 50px; color: #6c757d; }
        .refresh-btn { background: #28a745; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; margin-top: 20px; }
        .refresh-btn:hover { background: #218838; }
        .last-updated { text-align: center; color: #6c757d; font-size: 0.9em; margin-top: 20px; }
        .load-time { position: fixed; bottom: 10px; right: 10px; background: rgba(0,0,0,0.7); color: white; padding: 5px 10px; border-radius: 5px; font-size: 0.8em; }
        
        @media (max-width: 768px) {
            .main-content { flex-direction: column; }
            .sidebar { width: 100%; max-height: 300px; }
            .questions-overview { grid-template-columns: repeat(auto-fill, minmax(80px, 1fr)); }
            .question-badge { padding: 10px 5px; font-size: 0.8em; }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>📚 JAMB CBT Video Audit System</h1>
            <p>Ultra-Fast Video Availability Check</p>
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
                                <?php $years = $allYears[$subject] ?? []; ?>
                                <?php if (empty($years)): ?>
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
                    <?php if ($selectedSubject && $selectedYear && isset($videoData)): ?>
                        <?php
                        $uploaded = 0;
                        $missing = 0;
                        
                        foreach ($videoData as $q) {
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
                            <p>Video Availability Overview</p>
                        </div>
                        
                        <div class="stats-bar">
                            <div class="stat-item">
                                <div class="stat-number"><?php echo $uploaded; ?>/50</div>
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

                        <h3 style="margin-bottom: 15px; color: #495057;">Questions Overview:</h3>
                        <div class="questions-overview">
                            <?php foreach ($videoData as $q): ?>
                                <div class="question-badge <?php echo $q['status']; ?>" 
                                     title="Question <?php echo $q['questionNumber']; ?> - <?php echo ucfirst($q['status']); ?>">
                                    <span class="number">Q<?php echo $q['questionNumber']; ?></span>
                                    <span class="status"><?php echo $q['status'] === 'uploaded' ? '✓' : '✗'; ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="last-updated">
                            Last checked: <?php echo date('Y-m-d H:i:s'); ?>
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

    <div class="load-time">Loaded in <?php echo $loadTime; ?>ms</div>

    <script>
        <?php if ($selectedSubject): ?>
            document.addEventListener('DOMContentLoaded', function() {
                toggleSubject('<?php echo $selectedSubject; ?>');
            });
        <?php endif; ?>

        function toggleSubject(subject) {
            const submenu = document.getElementById(`submenu-${subject}`);
            const arrow = document.getElementById(`arrow-${subject}`);
            const header = arrow.parentElement;

            document.querySelectorAll('.years-submenu').forEach(menu => {
                if (menu !== submenu && menu.classList.contains('open')) {
                    menu.classList.remove('open');
                    const otherArrow = menu.parentElement.querySelector('.arrow');
                    const otherHeader = menu.parentElement.querySelector('.subject-header');
                    if (otherArrow) otherArrow.classList.remove('rotated');
                    if (otherHeader) otherHeader.classList.remove('active');
                }
            });

            submenu.classList.toggle('open');
            arrow.classList.toggle('rotated');
            header.classList.toggle('active');
        }

        function selectYear(subject, year) {
            const contentDisplay = document.getElementById('contentDisplay');
            contentDisplay.innerHTML = `
                <div class="loading">
                    <h3>Checking videos for ${subject} - ${year}</h3>
                    <p>Fast batch processing in progress...</p>
                </div>
            `;
            
            window.location.href = `?subject=${subject}&year=${year}`;
        }
    </script>
</body>
</html>