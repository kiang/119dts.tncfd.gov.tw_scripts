<?php
$rootPath = dirname(__DIR__) . '/119dts.tncfd.gov.tw';
// Set UTF-8 encoding
mb_internal_encoding('UTF-8');

// Function to create year/monthday directories
function createDirectories($year, $monthday) {
    global $rootPath;
    $path = "{$rootPath}/docs/{$year}/{$monthday}";
    if (!file_exists($path)) {
        mkdir($path, 0777, true);
    }
    return $path;
}

// Function to archive old data to raw folder
function archiveOldData() {
    global $rootPath;
    $docsPath = "{$rootPath}/docs";
    $rawPath = "{$rootPath}/raw";

    // Create raw directory if it doesn't exist
    if (!file_exists($rawPath)) {
        mkdir($rawPath, 0777, true);
    }

    // Calculate the cutoff date (30 days ago)
    $cutoffDate = new DateTime();
    $cutoffDate->sub(new DateInterval('P30D'));

    // Process year directories
    $yearDirs = glob("{$docsPath}/20*");
    foreach ($yearDirs as $yearDir) {
        $year = basename($yearDir);
        $monthDayDirs = glob("{$yearDir}/*");

        foreach ($monthDayDirs as $monthDayDir) {
            $monthDay = basename($monthDayDir);

            // Parse the directory date
            $dirDate = DateTime::createFromFormat('Ymd', $year . $monthDay);
            if (!$dirDate) continue;

            // Check if directory is older than cutoff
            if ($dirDate < $cutoffDate) {
                // Create corresponding raw directory
                $rawYearPath = "{$rawPath}/{$year}";
                $rawMonthDayPath = "{$rawYearPath}/{$monthDay}";

                if (!file_exists($rawYearPath)) {
                    mkdir($rawYearPath, 0777, true);
                }

                // Move the directory to raw
                if (file_exists($monthDayDir) && !file_exists($rawMonthDayPath)) {
                    echo "Archiving {$year}/{$monthDay} to raw folder...\n";
                    rename($monthDayDir, $rawMonthDayPath);
                }
            }
        }

        // Remove empty year directories
        if (is_dir($yearDir)) {
            $remaining = glob("{$yearDir}/*");
            if (empty($remaining)) {
                rmdir($yearDir);
            }
        }
    }
}

// Function to process datetime string
function processDateTime($datetime) {
    $dt = DateTime::createFromFormat('Y/m/d H:i:s', $datetime);
    if (!$dt) {
        throw new Exception("Invalid datetime format: {$datetime}");
    }
    return $dt;
}

// Fetch the HTML content
$url = 'https://119dts.tncfd.gov.tw/DTS/caselist/html';
$html = file_get_contents($url);

if ($html === false) {
    die("Failed to fetch data from {$url}\n");
}

// Convert HTML to UTF-8 if needed
$html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');

// Create a DOM document
$dom = new DOMDocument('1.0', 'UTF-8');
@$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

// Create XPath
$xpath = new DOMXPath($dom);

// Find all table rows
$rows = $xpath->query('//table[@id="dataTable"]/tr');

$allData = [];
$currentRow = 0;

// Process each row
foreach ($rows as $row) {
    $currentRow++;
    if ($currentRow === 1) continue; // Skip header row

    $cells = $row->getElementsByTagName('td');
    if ($cells->length < 7) continue; // Skip rows without enough cells

    $caseType = trim($cells->item(3)->textContent);
    $caseNumber = trim($cells->item(1)->textContent);
    $datetime = trim($cells->item(2)->textContent);
    $location = trim($cells->item(4)->textContent);
    $unit = trim($cells->item(5)->textContent);
    $status = trim($cells->item(6)->textContent);

    try {
        $dt = processDateTime($datetime);
        $year = $dt->format('Y');
        $monthday = $dt->format('md');

        $data = [
            'id' => $caseNumber,
            'datetime' => $datetime,
            'case_type' => $caseType,
            'location' => $location,
            'unit' => $unit,
            'status' => $status
        ];

        // Save individual case file with history
        $dir = createDirectories($year, $monthday);
        $filePath = "{$dir}/{$caseNumber}.json";

        // Check if file exists and load existing data
        $caseData = [];
        if (file_exists($filePath)) {
            $existingContent = file_get_contents($filePath);
            $existingData = json_decode($existingContent, true);

            // Handle both old format (single record) and new format (with history)
            if (isset($existingData['history'])) {
                $caseData = $existingData;
            } else {
                // Convert old format to new format
                $caseData = [
                    'id' => $existingData['id'],
                    'case_type' => $existingData['case_type'],
                    'location' => $existingData['location'],
                    'unit' => $existingData['unit'],
                    'history' => [
                        [
                            'datetime' => $existingData['datetime'],
                            'status' => $existingData['status'],
                            'updated' => $existingData['datetime']
                        ]
                    ]
                ];
            }
        } else {
            // New case file
            $caseData = [
                'id' => $caseNumber,
                'case_type' => $caseType,
                'location' => $location,
                'unit' => $unit,
                'history' => []
            ];
        }

        // Check if this is a new status update
        $lastEntry = end($caseData['history']);
        $needsUpdate = false;

        if (!$lastEntry || $lastEntry['status'] !== $status || $lastEntry['datetime'] !== $datetime) {
            $needsUpdate = true;
        }

        if ($needsUpdate) {
            $caseData['history'][] = [
                'datetime' => $datetime,
                'status' => $status,
                'updated' => date('Y/m/d H:i:s')
            ];
        }

        file_put_contents($filePath, json_encode($caseData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // Add to all data array
        $allData[] = $data;

    } catch (Exception $e) {
        echo "Error processing row {$currentRow}: " . $e->getMessage() . "\n";
        continue;
    }
}

// Save complete list
file_put_contents($rootPath . '/docs/list.json', json_encode($allData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

// Archive old data to raw folder
archiveOldData();

echo "Processing completed. Total cases processed: " . count($allData) . "\n";
