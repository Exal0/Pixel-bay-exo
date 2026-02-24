<?php 

echo "<h1> MISSION 3 </h1>";

$chiffresAffaires = 150;
if ($chiffresAffaires >= 5000) {
    echo "Journée exceptionnelle ! Commander de nouveaux stocks.";
} else if ($chiffresAffaires >= 2500 && $chiffresAffaires < 5000) {
    echo "Bonne journée. Maintenir la stratégie actuelle.";
} else if ($chiffresAffaires >= 500 && $chiffresAffaires < 1999) {
    echo "Journée moyenne. Lancer une promotion sur les réseaux sociaux.";
} else
    echo "Journée difficile. Organiser un événement en magasin.";

echo "<hr>";
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