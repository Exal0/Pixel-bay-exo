<?php
const boutique = "PixelBay";
$stock_ini = 500;
$prix_moyen = 45;


echo "<h1> MISSION 1 </h1>";

#Mission 1
$vente = $stock_ini * 0.25;
$chiffres_affaires = $vente * $prix_moyen;
echo "Il reste " . $vente . " jeu en stock ";
echo "<br>";
echo " le chiffre d'affaire de cette vente est " . $chiffres_affaires;
echo "<br>";

echo $vente . " jeux ont été vendu a " . $prix_moyen . "€ le jeu ce qui nous fait un chiffre d'affaire de " . $chiffres_affaires . "€";

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