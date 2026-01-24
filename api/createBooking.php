<?php
$data = json_decode(file_get_contents("php://input"), true);

$service = $data["service"];
$method  = $data["method"];
$date    = $data["date"];
$time    = $data["time"];
$price   = $data["price"];

$db = new PDO("sqlite:" . __DIR__ . "/../data/appointments.db");

$stmt = $db->prepare("SELECT COUNT(*) FROM bookings WHERE date=? AND time=?");
$stmt->execute([$date, $time]);
if ($stmt->fetchColumn() > 0) {
    echo "This time slot is already booked.";
    exit;
}

$stmt = $db->prepare("INSERT INTO bookings (service, method, date, time, price) VALUES (?, ?, ?, ?, ?)");
$stmt->execute([$service, $method, $date, $time, $price]);

echo "Your appointment has been booked for a $method session at \$$price/hr!";