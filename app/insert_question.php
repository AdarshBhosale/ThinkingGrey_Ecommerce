<?php
// insert_question.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

// Handle preflight
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
  http_response_code(200);
  exit();
}

// Only allow POST
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  echo json_encode(["success" => false, "message" => "Only POST requests allowed"]);
  exit;
}

// Check file and productName
if (!isset($_FILES['uploaded_file']) || !isset($_POST['productName'])) {
  echo json_encode(["success" => false, "message" => "File or Product Name missing"]);
  exit;
}

$fileTmpPath = $_FILES['uploaded_file']['tmp_name'];
$productName = $_POST['productName'];

// ✅ STEP 1: Define configuration for each product
$productConfig = [
  'kbc' => [
    'db_host' => 'localhost',
    'db_user' => 'root',
    'db_pass' => '',
    'db_name' => 'kbc_game',
    'table' => 'question_kbc'
  ],
  'archery' => [
    'db_host' => 'localhost',
    'db_user' => 'root',
    'db_pass' => '',
    'db_name' => 'demo_archery',
    'table' => 'questions'
  ],
  'football' => [
    'db_host' => 'localhost',
    'db_user' => 'root',
    'db_pass' => '',
    'db_name' => 'football_db',
    'table' => 'football_questions'
  ],
  // Add more products here
];

// ✅ STEP 2: Validate productName
if (!array_key_exists($productName, $productConfig)) {
  echo json_encode(["success" => false, "message" => "Invalid product name"]);
  exit;
}

// ✅ STEP 3: Extract config and connect
$config = $productConfig[$productName];
$conn = new mysqli($config['db_host'], $config['db_user'], $config['db_pass'], $config['db_name']);

if ($conn->connect_error) {
  echo json_encode(["success" => false, "message" => "Database connection failed", "error" => $conn->connect_error]);
  exit;
}

$table_name = $config['table'];

// ✅ STEP 4: Load Excel
try {
  $spreadsheet = IOFactory::load($fileTmpPath);
  $worksheet = $spreadsheet->getActiveSheet();
} catch (Exception $e) {
  echo json_encode(["success" => false, "message" => "Error reading Excel file", "error" => $e->getMessage()]);
  exit;
}

// ✅ STEP 5: Get Headers from Excel
$highestColumn = $worksheet->getHighestColumn();
$highestRow = $worksheet->getHighestRow();

$excelHeaders = [];
for ($col = 'A'; $col <= $highestColumn; $col++) {
  $headerValue = $worksheet->getCell($col . '1')->getValue();
  if (!$headerValue)
    break;
  $excelHeaders[] = trim($headerValue);
}

// ✅ STEP 6: Get DB Columns
$dbColumnsResult = $conn->query("SHOW COLUMNS FROM `$table_name`");
$dbColumns = [];
while ($col = $dbColumnsResult->fetch_assoc()) {
  $dbColumns[] = $col['Field'];
}

// ✅ STEP 7: Match Columns
$matchingColumns = array_intersect($excelHeaders, $dbColumns);
if (count($matchingColumns) === 0) {
  echo json_encode(["success" => false, "message" => "No matching columns found between Excel and Database"]);
  exit;
}

// ✅ STEP 8: Prepare and Insert
$insertColumns = implode(", ", array_map(fn($col) => "`$col`", $matchingColumns));
$placeholders = implode(", ", array_fill(0, count($matchingColumns), '?'));
$stmt = $conn->prepare("INSERT INTO `$table_name` ($insertColumns) VALUES ($placeholders)");

if (!$stmt) {
  echo json_encode(["success" => false, "message" => "Statement preparation failed", "error" => $conn->error]);
  exit;
}

$types = str_repeat('s', count($matchingColumns));
$params = array_fill(0, count($matchingColumns), null);
$stmt->bind_param($types, ...$params);

// ✅ STEP 9: Insert rows
$insertedCount = 0;
for ($row = 2; $row <= $highestRow; $row++) {
  foreach ($matchingColumns as $index => $colName) {
    $colIndex = array_search($colName, $excelHeaders);
    $excelCol = Coordinate::stringFromColumnIndex($colIndex + 1);
    $cellRef = $excelCol . $row;
    $cellValue = $worksheet->getCell($cellRef)->getValue();
    $params[$index] = $cellValue ?? '';
  }
  $stmt->execute();
  $insertedCount++;
}

// ✅ STEP 10: Final response
echo json_encode([
  "success" => true,
  "message" => "Data inserted successfully!",
  "rows_inserted" => $insertedCount,
]);

$conn->close();
