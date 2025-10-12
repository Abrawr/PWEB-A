<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'];
    $email = $_POST['email'];
    $message = $_POST['message'];

    $data = [
        'name' => $name,
        'email' => $email,
        'message' => $message,
        'time' => date('Y-m-d H:i:s')
    ];

    $file = 'data.json';
    $jsonData = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    $jsonData[] = $data;
    file_put_contents($file, json_encode($jsonData, JSON_PRETTY_PRINT));

    echo "Pesan berhasil disimpan!";
}
?>
