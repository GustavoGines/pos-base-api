<?php
$data = [
    'customer_name' => 'Gines',
    'customer_phone' => '123456789',
    'notes' => 'Some notes',
    'valid_until' => '2026-04-15',
    'user_id' => 1,
    'items' => [
        [
            'product_id' => null,
            'product_name' => 'Tirante Pino 2x4 (por metro)',
            'unit_price' => 3000,
            'quantity' => 2.5
        ]
    ]
];

$ch = curl_init('http://127.0.0.1/Sistema_POS/pos-backend/public/api/quotes');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Content-Type: application/json',
    'Accept: application/json'
));

$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
if(curl_errno($ch)){
    echo 'Curl error: ' . curl_error($ch) . "\n";
}
curl_close($ch);

echo "HTTP Code: $httpcode\n";
echo "Response:\n";
echo $response;
