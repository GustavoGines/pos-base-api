<?php

require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Validator;
use Illuminate\Http\UploadedFile;

echo "=== QUESTION 3 VERIFICATION: ARBITRARY FILE UPLOAD IN SupplierInvoiceController ===\n";

// Create a fake file with .php extension
$tempFile = tempnam(sys_get_temp_dir(), 'test');
file_put_contents($tempFile, '<?php echo "arbitrary code execution";');

$uploadedFile = new UploadedFile(
    $tempFile,
    'exploit.php',
    'application/x-php',
    null,
    true // test mode
);

$rules = [
    'file' => 'required|file|max:10240',
];

$validator = Validator::make(['file' => $uploadedFile], $rules);

echo "Testing file 'exploit.php' against rule 'required|file|max:10240'...\n";
if ($validator->passes()) {
    echo ">>> RESULT: VULNERABILITY CONFIRMED! The validator PASSES for 'exploit.php' without MIME or extension checks.\n";
    echo "Any file (PHP scripts, HTML, SVG, executable) is accepted and stored via store('supplier_invoices', 'public').\n";
} else {
    echo ">>> RESULT: Validation failed:\n";
    print_r($validator->errors()->all());
}

unlink($tempFile);
