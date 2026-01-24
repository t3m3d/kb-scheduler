<?php
$date = $_GET["date"];
$db = new PDO("sqlite:" . __DIR__ . "/../data/appointments.db");

$allTimes = ["09:00","10:00","11:00","12:00","13:00","14:00","15:00","16:00"];

$stmt = $db->prepare("SELECT time FROM bookings WHERE date=?");
$stmt->execute([$date]);
$booked = $stmt->fetchAll(PDO::FETCH_COLUMN);

$available = array_values(array_diff($allTimes, $booked));

echo json_encode($available);