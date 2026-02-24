<?php 

echo "<h1> MISSION 5 </h1>";

$stock_actu = 12;
$stock_cible = 100;
$deliverycount = 8;
$requiredDeliveries = 0;

while ($stock_actu < $stock_cible) {
    $stock_actu += $deliverycount;
    $requiredDeliveries++;
    echo "livraison " . $requiredDeliveries .  "<br>";
}
echo "On aura donc besoin de ". $requiredDeliveries. " livraison ";

echo "<hr>";


$months = [
    "Janvier",
    "Février",
    "Mars",
    "Avril",
    "Mai",
    "Juin",
    "Juillet",
    "Août",
    "Septembre",
    "Octobre",
    "Novembre",
    "Décembre"
];

$jeuxPhares = [
    "Cyber Race",
    "Pixel Quest",
    "Block Master",
    "Sky Pilot",
    "Dungeon Crawl",
    "Mystic Lands",
    "Battle Arena",
    "Escape Room",
    "Neural Rush",
    "Horror House",
    "Festival Games",
    "Winter Sports"
];

for ($i=0; $i < count($months); $i++) { 
    echo $months[$i] . " : ". $jeuxPhares[$i]  . "<br>";
    }   
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
</head>
<body>
    
</body>
</html>