<?php
$rootPath = dirname(__DIR__) . '/119dts.tncfd.gov.tw';

// Function to recursively find all JSON files in docs directory
function findCaseFiles($dir) {
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'json' && $file->getFilename() !== 'list.json') {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

// Function to migrate a single file
function migrateFile($filePath) {
    $content = file_get_contents($filePath);
    $data = json_decode($content, true);

    if (!$data) {
        echo "Failed to decode JSON in {$filePath}\n";
        return false;
    }

    // Check if already in new format
    if (isset($data['history'])) {
        echo "Already migrated: {$filePath}\n";
        return true;
    }

    // Check if it has required fields for migration
    if (!isset($data['id']) || !isset($data['status']) || !isset($data['datetime'])) {
        echo "Missing required fields in {$filePath}\n";
        return false;
    }

    // Convert to new format
    $newData = [
        'id' => $data['id'],
        'case_type' => $data['case_type'] ?? '',
        'location' => $data['location'] ?? '',
        'unit' => $data['unit'] ?? '',
        'history' => [
            [
                'datetime' => $data['datetime'],
                'status' => $data['status'],
                'updated' => $data['datetime']
            ]
        ]
    ];

    // Write back to file
    $result = file_put_contents($filePath, json_encode($newData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    if ($result === false) {
        echo "Failed to write {$filePath}\n";
        return false;
    }

    echo "Migrated: {$filePath}\n";
    return true;
}

// Main migration process
echo "Starting migration of case files...\n";

$docsPath = $rootPath . '/docs';
if (!is_dir($docsPath)) {
    die("Docs directory not found: {$docsPath}\n");
}

$files = findCaseFiles($docsPath);
echo "Found " . count($files) . " case files to check\n\n";

$migrated = 0;
$skipped = 0;
$errors = 0;

foreach ($files as $file) {
    $result = migrateFile($file);
    if ($result === true) {
        if (strpos(file_get_contents($file), '"history"') !== false) {
            $migrated++;
        } else {
            $skipped++;
        }
    } else {
        $errors++;
    }
}

echo "\nMigration completed:\n";
echo "- Migrated: {$migrated} files\n";
echo "- Already migrated: {$skipped} files\n";
echo "- Errors: {$errors} files\n";
echo "- Total processed: " . count($files) . " files\n";
